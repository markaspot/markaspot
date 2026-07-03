<?php

declare(strict_types=1);

namespace Drupal\markaspot\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ExtensionInstallStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps `drush deploy` / `config:import` non-destructive against stale sync.
 *
 * WHY THIS EXISTS
 * ---------------
 * Mark-a-Spot is a distribution that ships in a cloud base image. A tenant's
 * own `config/sync` directory can lag behind the profile shipped in a newer
 * image. When `drush deploy` (= `updatedb` + `config:import`) runs:
 *
 *   1. `updatedb` executes profile/module update hooks that ENABLE new
 *      profile-required modules (e.g. `jsonapi_resources`, a HARD dependency
 *      in markaspot.info.yml) and CREATE new profile-owned config entities
 *      (e.g. taxonomy.vocabulary.internal_status + its fields,
 *      image.style.ai_analysis, jsonapi_extras resource configs, ...).
 *
 *   2. The subsequent FULL `config:import` then diffs the now-ahead ACTIVE
 *      config against the STALE `config/sync` and concludes:
 *        - `jsonapi_resources` is in active core.extension but NOT in
 *          config/sync/core.extension.yml  =>  plan to UNINSTALL it
 *          =>  HARD ERROR "Unable to uninstall ... the profile requires it".
 *        - the freshly created entities are in active but absent from sync
 *          =>  plan to DELETE them  =>  silent data/feature loss.
 *
 * That is a deploy-blocking footgun that recurs on every tenant whose
 * config/sync was not regenerated after a profile bump. Living in the install
 * profile, this guard ships in the base image and every tenant inherits it on
 * its normal base-tag bump — no per-tenant config/sync edits, no contrib
 * module.
 *
 * HOW THE FIX WORKS
 * -----------------
 * Core funnels the sync storage through ConfigEvents::STORAGE_TRANSFORM_IMPORT
 * (\Drupal\Core\Config\ImportStorageTransformer::transform()) and builds the
 * StorageComparer from the *transformed* storage. The changelist is computed
 * AFTER this subscriber runs, so whatever we write into the import storage is
 * the "source" the comparer sees:
 *
 *   - Module uninstall plan (ConfigImporter::createExtensionChangelist) is
 *     exactly `array_diff_key(active['module'], source['module'])`. By merging
 *     every profile-required module that is in ACTIVE but missing from the
 *     import `core.extension['module']` back into the import map, those modules
 *     drop out of the diff and are NEVER placed on the uninstall list. The
 *     install side is `array_diff_key(source, active)`, so re-adding a key that
 *     already exists in active produces NO spurious install. Net diff: zero.
 *
 *   - Config delete plan: anything present in ACTIVE but absent from the
 *     (transformed) source is a `delete`. We re-inject any config name that
 *     (a) is still SHIPPED by a currently-ENABLED extension (profile/module
 *     config/install + config/optional), (b) exists in ACTIVE, and (c) is
 *     missing from the import storage. That suppresses the delete for
 *     profile/module-owned config while leaving genuinely client-specific
 *     config (shipped by nobody) untouched and deletable.
 *
 * SCOPE & DELIBERATE LIMITATIONS
 * ------------------------------
 *   - "Still shipped by an ENABLED extension" is computed with
 *     ExtensionInstallStorage, whose getAllFolders() filters to extensions
 *     present in active core.extension. Config shipped only by a DISABLED
 *     module is NOT in the shipped set and therefore STAYS deletable — intended
 *     client removals of disabled-module config survive.
 *   - Core-shipped config (system.*, user.role.*, ...) is also in the shipped
 *     set, because ExtensionInstallStorage prepends core's own
 *     config/install + config/optional. It is therefore likewise protected from
 *     outright deletion — generally desirable; a deliberate deletion of a core
 *     config object via config/sync is suppressed by this guard.
 *   - Config the always-enabled PROFILE still ships in its own config/install
 *     IS treated as profile-owned and re-protected. A tenant that wants to
 *     permanently drop such config must make the profile stop shipping it; a
 *     bare deletion in config/sync is reverted by this guard. This is the only
 *     honest, stateless scope.
 *   - INSTALL is unaffected: install does not route through
 *     ImportStorageTransformer, so the event never fires during install. We
 *     additionally hard-guard on InstallerKernel::installationAttempted().
 *   - `drush config:import --partial` bypasses the transformer entirely, so the
 *     guard does not apply there. Use full `cim` / `deploy`.
 *
 * OPERATIONAL NOTE
 * ----------------
 * To intentionally uninstall a profile-required module through config import
 * (e.g. a security-driven removal), first remove it from markaspot.info.yml:
 * this guard re-adds any still-declared profile dependency to core.extension,
 * so a cim uninstall of such a module would otherwise silently no-op. Correct
 * order: drop from info.yml first, then deploy.
 *
 * SAFETY
 * ------
 * The subscriber is a pure function of (active core.extension, import
 * core.extension, shipped-config-set). It never writes active state, never
 * touches the `profile`/`theme`/`_core` keys of core.extension, never throws
 * (any unexpected condition logs and returns, degrading to core's original
 * behaviour — strictly no worse), runs only on the default collection, and is
 * idempotent.
 *
 * RUNTIME API KEY ENTITIES (OPT-IN)
 * ---------------------------------
 * services_api_key_auth stores credentials and the effective API-key owner in
 * config entities, but cloud tenants often need to manage those values at
 * runtime. With $settings['markaspot_preserve_runtime_api_keys'] enabled, this
 * subscriber treats the active `services_api_key_auth.api_key.*` entities as
 * runtime-owned during config import:
 *
 *   - Active API-key entities missing from the import source are re-injected
 *     into the transformed source, so `cim` does not delete UI-created keys.
 *   - Empty `key` / `user_uuid` values from sanitized sync are filled from raw
 *     active config, so deploys do not wipe runtime credentials or owners.
 *
 * The guard reads raw active storage only. Settings.php config overrides such
 * as GEOREPORT_API_KEYS are deliberately not visible here, so env-injected
 * secrets are never copied into the import source or active config.
 *
 * NO ANONYMOUS DRUPAL HTML VIEWS
 * ------------------------------
 * WHY: Mark-a-Spot is a HEADLESS distribution. The citizen-facing UI is the
 * Nuxt frontend talking to Open311 / JSON:API; Drupal itself must never serve
 * an anonymous, server-rendered Views page. A View display left open to
 * anonymous users (e.g. a stale `access: { type: none }` carried in a tenant's
 * config/sync, or a re-injected default) is an unintended public surface that
 * can leak content the headless contract never meant to expose. Because
 * markaspot_nuxt is a HARD profile dependency, this hardening applies to ALL
 * tenants on the 11.9.x profile by design — there is no headless gate to check.
 *
 * HOW: protectViewAccess() runs AFTER protectShippedConfig() (so it also
 * catches any view re-injected from active by the shipped-config guard), walks
 * every `views.view.*` in the import storage, and rewrites anonymous display
 * access to `role: authenticated`. The comparer then imports the tightened
 * source, and markaspot_update_11929() applies the same tightening to
 * already-active views.
 *
 * SCOPE & LIMITATIONS:
 *   - Scope is limited to `views.view.*` config objects in the default
 *     collection. No other entity type is touched.
 *   - "Anonymous" is defined narrowly as a display whose access plugin is
 *     `none`, OR `perm` with the single perm `access content`. A view gated by
 *     some OTHER perm that anonymous happens to hold (e.g.
 *     `access open311 extension`) is INTENTIONALLY NOT touched: that is a
 *     deliberate, named public surface, and silently locking it could break a
 *     legitimate integration. The narrow definition is the honest, non-guessing
 *     floor.
 *   - The lock TARGET is `authenticated` — the portable floor that closes the
 *     anonymous hole on every tenant without assuming any tenant-specific staff
 *     role exists. Stricter per-tenant locks (a display already gated to a
 *     staff role or a stricter perm) are PRESERVED, because only ANONYMOUS
 *     displays are rewritten; the guard only ever TIGHTENS, never loosens.
 *   - Idempotent: a display already locked to a role is not anonymous and is
 *     skipped, so re-running is a no-op.
 */
final class ProfileConfigGuardSubscriber implements EventSubscriberInterface {

  /**
   * The config name core uses for the installed-extension map.
   */
  private const CORE_EXTENSION = 'core.extension';

  /**
   * Prefix for services_api_key_auth API-key config entities.
   */
  private const API_KEY_CONFIG_PREFIX = 'services_api_key_auth.api_key.';

  /**
   * The markaspot_mail.texts config name.
   */
  private const MAIL_TEXTS_CONFIG = 'markaspot_mail.texts';

  /**
   * Runtime opt-in for API-key entity preservation.
   */
  private const PRESERVE_RUNTIME_API_KEYS_SETTING = 'markaspot_preserve_runtime_api_keys';

  /**
   * Runtime-managed fields on services_api_key_auth API-key config entities.
   */
  private const API_KEY_RUNTIME_FIELDS = ['key', 'user_uuid'];

  /**
   * Memoised set of config names shipped by currently-enabled extensions.
   *
   * Request-scoped only (NOT a persistent cache): the enabled-extension set can
   * change within a deploy run (updatedb may have just enabled a module), so a
   * persistent cache would risk staleness exactly when it matters. Rebuilding
   * per request is two filesystem scans over the enabled extensions — cheap.
   *
   * @var array<string, string>|null
   */
  private ?array $shippedConfigNames = NULL;

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   Provides the per-extension `required_by` reverse-dependency map, the
   *   predicate core's InstallProfileUninstallValidator uses to forbid
   *   uninstalling a profile dependency.
   * @param \Drupal\Core\Config\StorageInterface $activeStorage
   *   The ACTIVE config storage (@config.storage): authoritative active
   *   core.extension and active config payloads.
   * @param string|false|null $installProfile
   *   The install profile machine name (%install_profile%). FALSE/NULL on a
   *   profile-less site.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel for non-fatal degradation diagnostics.
   */
  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly StorageInterface $activeStorage,
    private readonly string|false|null $installProfile,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Late priority so this guard has the last word over any other transform
    // subscriber; the comparer reads the final state, so the value is not
    // strictly load-bearing.
    return [
      ConfigEvents::STORAGE_TRANSFORM_IMPORT => ['onImportTransform', -100],
    ];
  }

  /**
   * Protects profile-required modules and still-shipped config during import.
   *
   * @param \Drupal\Core\Config\StorageTransformEvent $event
   *   The transform event carrying the mutable copy of config/sync that the
   *   StorageComparer treats as the import source.
   */
  public function onImportTransform(StorageTransformEvent $event): void {
    try {
      // Never run during site install. The event structurally does not fire
      // during install, but guard anyway.
      if (InstallerKernel::installationAttempted()) {
        return;
      }

      $importStorage = $event->getStorage();

      // Only act on the default collection. Language/override collections do
      // not carry core.extension and must not be reshaped by this guard.
      if ($importStorage->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
        return;
      }

      $this->protectRequiredModules($importStorage);
      $this->protectShippedConfig($importStorage);
      $this->protectMailTexts($importStorage);
      $this->protectRuntimeApiKeys($importStorage);
      // Run AFTER protectShippedConfig so any view re-injected from active is
      // also screened for anonymous access.
      $this->protectViewAccess($importStorage);
    }
    catch (\Throwable $e) {
      // Never abort a deploy from inside the guard. Degrading to core's
      // original (possibly failing) behaviour is no worse than a throw.
      $this->logger->warning(
        'ProfileConfigGuardSubscriber skipped due to an unexpected error: @message',
        ['@message' => $e->getMessage()],
      );
    }
  }

  /**
   * Re-adds profile-required modules missing from the import core.extension.
   *
   * Prevents config:import from planning to uninstall a module the install
   * profile (transitively) requires, which core rejects with a hard error. We
   * only ADD keys already enabled in ACTIVE; we never fabricate core.extension
   * and never touch `profile`/`theme`/`_core`.
   *
   * @param \Drupal\Core\Config\StorageInterface $importStorage
   *   The mutable import storage (transformed copy of config/sync).
   */
  private function protectRequiredModules(StorageInterface $importStorage): void {
    // Never fabricate a core.extension: if sync lacks it, core early-returns
    // and there is no uninstall plan to suppress.
    if (!$importStorage->exists(self::CORE_EXTENSION)) {
      return;
    }

    $activeExtension = $this->activeStorage->read(self::CORE_EXTENSION);
    if (empty($activeExtension['module']) || !is_array($activeExtension['module'])) {
      return;
    }
    $activeModules = $activeExtension['module'];

    $importExtension = $importStorage->read(self::CORE_EXTENSION);
    // Only merge into an existing module map; do not synthesize one.
    if (!is_array($importExtension) || !isset($importExtension['module']) || !is_array($importExtension['module'])) {
      return;
    }

    $changed = FALSE;
    foreach ($this->getRequiredModuleNames() as $module) {
      // Suppress uninstall only for modules that are required by the profile,
      // currently ENABLED (in active), and MISSING from the import map.
      if (!isset($activeModules[$module])) {
        continue;
      }
      if (array_key_exists($module, $importExtension['module'])) {
        continue;
      }
      // Copy the weight from active so ordering stays coherent.
      $importExtension['module'][$module] = $activeModules[$module];
      $changed = TRUE;
    }

    if ($changed) {
      $importStorage->write(self::CORE_EXTENSION, $importExtension);
    }
  }

  /**
   * Re-injects still-shipped config that active has but the import lacks.
   *
   * @param \Drupal\Core\Config\StorageInterface $importStorage
   *   The mutable import storage (transformed copy of config/sync).
   */
  private function protectShippedConfig(StorageInterface $importStorage): void {
    foreach ($this->getShippedConfigNames() as $name) {
      if ($importStorage->exists($name)) {
        continue;
      }
      if (!$this->activeStorage->exists($name)) {
        continue;
      }
      $data = $this->activeStorage->read($name);
      if ($data === FALSE) {
        continue;
      }
      $importStorage->write($name, $data);
    }
  }

  /**
   * Preserves dashboard-edited markaspot_mail.texts state during import.
   *
   * WHY: markaspot_mail.texts (report_confirmation, status_open, ...) is
   * shipped config, but its ACTIVE value is also dashboard-editable at
   * runtime (markaspot_dashboard's `/api/dashboard/mail-texts` endpoints,
   * and Drupal's own /admin/config/markaspot/mail-texts form). Unlike the
   * DELETE case protectShippedConfig() already covers (import lacks the
   * config entirely), a tenant that has ever run `drush cex` normally DOES
   * have markaspot_mail.texts.yml in its config/sync — just with whatever
   * wording was active at export time. A later full `drush cim` would
   * silently overwrite live, staff-edited wording (or a dashboard-created
   * custom key) back to that stale snapshot, defeating the whole point of
   * the editor. Dashboard edits and the markaspot:mail-texts-migrate
   * command never write to config/sync (see markaspot_mail.texts's own
   * schema doc), so config/sync can only ever be a stale seed for this
   * object, never authoritative.
   *
   * HOW: unconditionally overwrites the import copy of markaspot_mail.texts
   * with the raw ACTIVE data whenever active has the config at all. Unlike
   * protectRuntimeApiKeys()'s per-field "fill only if import is empty"
   * merge (right for redacted secrets), this config object has no
   * redaction concern and every field (including custom-key entries with
   * no counterpart in config/sync) is runtime-managed content, so active
   * wins wholesale — the config_ignore pattern applied to a single,
   * specifically-named config object.
   *
   * SCOPE & LIMITATIONS: exactly one config name. A tenant that genuinely
   * wants to reset markaspot_mail.texts from config/sync must do so
   * out-of-band (e.g. `drush config:import --partial`, which bypasses this
   * transformer entirely) rather than through a full cim/deploy.
   *
   * @param \Drupal\Core\Config\StorageInterface $importStorage
   *   The mutable import storage (transformed copy of config/sync).
   */
  private function protectMailTexts(StorageInterface $importStorage): void {
    if (!$this->activeStorage->exists(self::MAIL_TEXTS_CONFIG)) {
      return;
    }
    $activeData = $this->activeStorage->read(self::MAIL_TEXTS_CONFIG);
    if (!is_array($activeData)) {
      return;
    }
    $importStorage->write(self::MAIL_TEXTS_CONFIG, $activeData);
  }

  /**
   * Preserves runtime-managed API-key config entity state during import.
   *
   * Cloud tenants keep services_api_key_auth config exports secret-free, while
   * keys and effective owners can be created or changed in the running site.
   * When enabled via settings.php, merge that runtime state into the
   * transformed import source so full config imports do not delete active
   * API-key entities or blank their credentials/owners.
   *
   * @param \Drupal\Core\Config\StorageInterface $importStorage
   *   The mutable import storage (transformed copy of config/sync).
   */
  private function protectRuntimeApiKeys(StorageInterface $importStorage): void {
    if (!self::runtimeApiKeyPreservationEnabled()) {
      return;
    }

    foreach ($this->activeStorage->listAll(self::API_KEY_CONFIG_PREFIX) as $name) {
      $activeData = $this->activeStorage->read($name);
      if (!is_array($activeData) || !self::hasRuntimeApiKeyState($activeData)) {
        continue;
      }

      if (!$importStorage->exists($name)) {
        $importStorage->write($name, $activeData);
        continue;
      }

      $importData = $importStorage->read($name);
      if (!is_array($importData)) {
        continue;
      }

      $changed = FALSE;
      foreach (self::API_KEY_RUNTIME_FIELDS as $field) {
        if (!self::hasNonEmptyStringValue($activeData, $field) || self::hasNonEmptyStringValue($importData, $field)) {
          continue;
        }
        $importData[$field] = $activeData[$field];
        $changed = TRUE;
      }

      if ($changed) {
        $importStorage->write($name, $importData);
      }
    }
  }

  /**
   * Locks anonymous Views displays in the import storage to authenticated.
   *
   * Headless hardening: a Drupal-rendered Views page must never be anonymous
   * on a Mark-a-Spot site. Walks every `views.view.*` in the import storage
   * and, for any display whose access is anonymous (see ::accessIsAnonymous()),
   * rewrites the access plugin to `role: authenticated`. Only anonymous
   * displays are touched, so the method only ever TIGHTENS and is idempotent.
   *
   * @param \Drupal\Core\Config\StorageInterface $importStorage
   *   The mutable import storage (transformed copy of config/sync).
   */
  private function protectViewAccess(StorageInterface $importStorage): void {
    foreach ($importStorage->listAll('views.view.') as $name) {
      $data = $importStorage->read($name);
      if (!is_array($data) || !isset($data['display']) || !is_array($data['display'])) {
        continue;
      }

      [$displays, $changed] = self::tightenAnonymousDisplays($data['display']);
      if ($changed) {
        $data['display'] = $displays;
        $importStorage->write($name, $data);
      }
    }
  }

  /**
   * Rewrites anonymous display access to `role: authenticated`.
   *
   * Shared, side-effect-free tightening logic used by both the import-transform
   * guard (::protectViewAccess()) and the entity-based one-shot update hook
   * (markaspot_update_11929()), so the "no anonymous Views" policy has a single
   * definition. Operates on a View's `display` array and returns the (possibly
   * rewritten) array plus whether anything changed. Only ANONYMOUS displays are
   * rewritten — non-anonymous displays (already gated to a role or a stricter
   * perm) are left untouched, so this only ever TIGHTENS and never loosens. A
   * view that exposes a `rest_export` display is skipped entirely: it is a
   * public REST API surface the headless frontend consumes anonymously, not an
   * HTML page (see the body).
   *
   * @param array<string, mixed> $displays
   *   The View's `display` array (display id => display definition).
   *
   * @return array{0: array<string, mixed>, 1: bool}
   *   A tuple of [the displays array, whether any display was rewritten].
   */
  public static function tightenAnonymousDisplays(array $displays): array {
    // A view that exposes a `rest_export` display is a public REST API surface
    // the headless frontend consumes ANONYMOUSLY (e.g. markaspot_stats's
    // stats/categories, stats/status and georeport/stats/requests endpoints,
    // whose rest_export displays carry no own access and inherit the anonymous
    // `default`). Locking such a view would 403 those endpoints and break the
    // public stats widget. Leave the whole view untouched. Only `rest_export`
    // is exempt — `feed`/`block`/`attachment`/`page` displays stay lockable.
    foreach ($displays as $display) {
      if (is_array($display) && ($display['display_plugin'] ?? NULL) === 'rest_export') {
        return [$displays, FALSE];
      }
    }

    $changed = FALSE;
    foreach ($displays as $display_id => $display) {
      if (!is_array($display)) {
        continue;
      }
      $access = $display['display_options']['access'] ?? NULL;
      if (!is_array($access) || !self::accessIsAnonymous($access)) {
        continue;
      }
      $displays[$display_id]['display_options']['access'] = [
        'type' => 'role',
        'options' => [
          'role' => ['authenticated' => 'authenticated'],
        ],
      ];
      $changed = TRUE;
    }

    return [$displays, $changed];
  }

  /**
   * Determines whether a Views display access definition is anonymous.
   *
   * Narrow, deliberate definition: a display is anonymous iff its access plugin
   * is `none`, OR `perm` granting exactly `access content` (a perm anonymous
   * holds by default). Any other perm-gated display (e.g. a deliberate
   * `access open311 extension` public surface) is NOT considered anonymous and
   * is left for the operator to manage.
   *
   * @param array<string, mixed> $access
   *   A display's `display_options.access` definition.
   *
   * @return bool
   *   TRUE if the display is open to anonymous users under this definition.
   */
  private static function accessIsAnonymous(array $access): bool {
    $type = $access['type'] ?? NULL;
    if ($type === 'none') {
      return TRUE;
    }
    if ($type === 'perm') {
      return ($access['options']['perm'] ?? '') === 'access content';
    }

    return FALSE;
  }

  /**
   * Returns whether runtime API-key preservation is enabled.
   */
  private static function runtimeApiKeyPreservationEnabled(): bool {
    try {
      $value = Settings::get(self::PRESERVE_RUNTIME_API_KEYS_SETTING, FALSE);
    }
    catch (\BadMethodCallException) {
      return FALSE;
    }

    if (is_bool($value)) {
      return $value;
    }
    if (is_string($value)) {
      return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? FALSE;
    }

    return (bool) $value;
  }

  /**
   * Returns whether an API-key entity carries raw runtime-managed state.
   *
   * A raw active `key` can come from UI-created config. A raw active
   * `user_uuid` can belong to an env-injected key whose secret lives only in
   * settings.php overrides. Treat either one as a reason to preserve the
   * entity, without ever reading resolved config overrides.
   *
   * @param array<string, mixed> $data
   *   API-key config entity data.
   */
  private static function hasRuntimeApiKeyState(array $data): bool {
    foreach (self::API_KEY_RUNTIME_FIELDS as $field) {
      if (self::hasNonEmptyStringValue($data, $field)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns whether a config field is a non-empty string.
   *
   * @param array<string, mixed> $data
   *   Config data.
   * @param string $field
   *   Field name.
   */
  private static function hasNonEmptyStringValue(array $data, string $field): bool {
    return isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '';
  }

  /**
   * Returns the machine names of all profile-required (transitive) modules.
   *
   * Uses each extension's `required_by` reverse-dependency map — the SAME
   * predicate core's InstallProfileUninstallValidator::validate() applies. The
   * map is the full transitive closure, so dep-of-dep modules are included.
   *
   * @return array<string, string>
   *   Map of module machine name => module machine name.
   */
  private function getRequiredModuleNames(): array {
    $required = [];
    if (empty($this->installProfile) || !is_string($this->installProfile)) {
      return $required;
    }

    // The profile itself is registered as a pseudo-module; always protect it.
    $required[$this->installProfile] = $this->installProfile;

    foreach ($this->moduleExtensionList->getList() as $name => $extension) {
      if (isset($extension->required_by[$this->installProfile])) {
        $required[$name] = $name;
      }
    }

    return $required;
  }

  /**
   * Returns config names shipped by currently-enabled extensions.
   *
   * Scans config/install and config/optional of every ENABLED extension (and
   * the profile's own config) via ExtensionInstallStorage, whose folder map
   * filters to extensions present in active core.extension. Disabled-module
   * config is excluded by construction. Memoised per request.
   *
   * @return array<string, string>
   *   Map of config name => config name.
   */
  private function getShippedConfigNames(): array {
    if ($this->shippedConfigNames !== NULL) {
      return $this->shippedConfigNames;
    }

    $profile = is_string($this->installProfile) ? $this->installProfile : NULL;
    $names = [];
    foreach ([InstallStorage::CONFIG_INSTALL_DIRECTORY, InstallStorage::CONFIG_OPTIONAL_DIRECTORY] as $directory) {
      $storage = new ExtensionInstallStorage(
        $this->activeStorage,
        $directory,
        StorageInterface::DEFAULT_COLLECTION,
        TRUE,
        $profile,
      );
      try {
        foreach ($storage->listAll() as $name) {
          $names[$name] = $name;
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning(
          'ProfileConfigGuardSubscriber could not enumerate shipped config: @message',
          ['@message' => $e->getMessage()],
        );
      }
    }

    return $this->shippedConfigNames = $names;
  }

}
