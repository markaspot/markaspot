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
 */
final class ProfileConfigGuardSubscriber implements EventSubscriberInterface {

  /**
   * The config name core uses for the installed-extension map.
   */
  private const CORE_EXTENSION = 'core.extension';

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
