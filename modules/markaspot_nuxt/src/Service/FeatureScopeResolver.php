<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;

/**
 * Resolves feature flags across platform, tenant, and jurisdiction scopes.
 */
class FeatureScopeResolver {

  /**
   * Authoritative scope assignment for dashboard-managed feature flags.
   */
  public const SCOPE_MAP = [
    'onboardingTour' => 'platform',
    'passwordless' => 'platform',
    'loginLink' => 'platform',
    'piiRedaction' => 'platform',
    'privacyBlockOnFlag' => 'platform',
    'privacyNotice' => 'platform',
    'aiAnalysis' => 'tenant',
    'aiProcessing' => 'tenant',
    'operationsDashboard' => 'tenant',
    'assignmentSyncsOrganisation' => 'tenant',
    'delegationNoteRequired' => 'tenant',
    'dashboard' => 'tenant',
    // Organisation and facility management operate on the root portfolio, so
    // a child workspace inherits the root's opt-in (same as 'dashboard').
    'organisations' => 'tenant',
    'facilities' => 'tenant',
    'dashboardRequestCreate' => 'tenant',
    'photoReporting' => 'jurisdiction',
    'classicReporting' => 'jurisdiction',
    'voting' => 'jurisdiction',
    'formFirst' => 'jurisdiction',
    'objectId' => 'jurisdiction',
    'party' => 'jurisdiction',
    'following' => 'jurisdiction',
    'feedback' => 'jurisdiction',
    'contactForm' => 'jurisdiction',
    'statistics' => 'jurisdiction',
    'pwaInstallPrompt' => 'jurisdiction',
    'moderation' => 'jurisdiction',
    'emergency' => 'jurisdiction',
    'funFacts' => 'jurisdiction',
    'search' => 'jurisdiction',
    'boundaries' => 'jurisdiction',
    'forms.allowParentCategorySelection' => 'jurisdiction',
  ];

  /**
   * Current defaults for flags exposed by the dashboard endpoint.
   */
  private const DEFAULTS = [
    'photoReporting' => TRUE,
    'classicReporting' => FALSE,
    'voting' => FALSE,
    'formFirst' => FALSE,
    'objectId' => FALSE,
    'party' => FALSE,
    'following' => FALSE,
    'feedback' => FALSE,
    'contactForm' => FALSE,
    'statistics' => FALSE,
    'pwaInstallPrompt' => FALSE,
    'moderation' => FALSE,
    // Default ON: photo analysis ships in every edition (incl. OSS) and the
    // citizen frontend always treated a missing key as enabled. The resolver
    // materialises every key explicitly, so a FALSE default here would
    // silently switch photo AI off for every tenant that never stored the
    // flag. Costs are capped by the markaspot_ai budgets, not by this flag.
    'aiAnalysis' => TRUE,
    'aiProcessing' => FALSE,
    'operationsDashboard' => FALSE,
    'assignmentSyncsOrganisation' => FALSE,
    'delegationNoteRequired' => FALSE,
    'dashboard' => TRUE,
    // Conservative constant; the effective default is operating-mode aware
    // via featureDefault(): opt-in on SaaS, enabled on self-hosted.
    'organisations' => FALSE,
    // Conservative constant; the effective default is operating-mode aware
    // via featureDefault(): opt-in on SaaS, enabled on self-hosted.
    'facilities' => FALSE,
    'dashboardRequestCreate' => TRUE,
    'onboardingTour' => FALSE,
    'passwordless' => FALSE,
    'loginLink' => TRUE,
    'piiRedaction' => FALSE,
    'privacyBlockOnFlag' => FALSE,
    'privacyNotice' => FALSE,
    'emergency' => FALSE,
    'funFacts' => FALSE,
    'search' => TRUE,
    'boundaries' => FALSE,
    'forms.allowParentCategorySelection' => FALSE,
  ];

  /**
   * Feature flags additionally constrained by the workspace tier.
   *
   * AiAnalysis is deliberately NOT tier-gated: photo analysis ships in every
   * edition including OSS, and per-tier cost control happens through the
   * markaspot_ai budget limits instead of a hard feature gate.
   */
  public const TIER_GATED = [
    'operationsDashboard',
    'aiProcessing',
  ];

  /**
   * Tier-specific entitlements for features constrained by TIER_GATED.
   *
   * The ladder is cumulative: upgrading must never remove a feature.
   * Community retains the Operations Dashboard from Professional, while
   * advanced AI processing is a Premium differentiator alongside SMTP/MCP.
   * Quotas are not modelled here because enterprise quantities are contractual.
   */
  public const TIER_FEATURE_ENTITLEMENT = [
    'pro' => self::TIER_GATED,
    'heart' => self::TIER_GATED,
    'community' => ['operationsDashboard'],
    'premium' => self::TIER_GATED,
  ];

  /**
   * Platform flags whose NULL config value derives from the operating mode.
   *
   * NULL means "no operator decision recorded": SaaS installs need these on
   * (OTP login is the primary sign-in path, the tour targets workspaces),
   * self-hosted installs default them off.
   */
  private const MODE_DERIVED_PLATFORM_FLAGS = [
    'onboardingTour',
    'passwordless',
  ];

  /**
   * Constructs a feature scope resolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly ?ModuleHandlerInterface $moduleHandler = NULL,
  ) {}

  /**
   * Resolves the effective feature values for a jurisdiction.
   */
  public function resolveEffectiveFeatures(GroupInterface $jur): array {
    $jurisdiction_config = $this->readNuxtConfig($jur);
    $features = is_array($jurisdiction_config['features'] ?? NULL)
      ? $jurisdiction_config['features']
      : [];
    $legacy_forms = is_array($jurisdiction_config['forms'] ?? NULL)
      ? $jurisdiction_config['forms']
      : [];
    $feature_forms = is_array($features['forms'] ?? NULL) ? $features['forms'] : [];
    if ($legacy_forms !== [] || $feature_forms !== []) {
      $features['forms'] = array_replace($legacy_forms, $feature_forms);
    }

    foreach (self::DEFAULTS as $key => $default) {
      $default = $this->featureDefault($key, $default);
      if ($key === 'boundaries'
        && $jur->hasField('field_boundary')
        && !$jur->get('field_boundary')->isEmpty()) {
        $default = TRUE;
      }
      $value = $this->readFeatureValue($jurisdiction_config, $key, $default);
      $this->writeFeatureValue($features, $key, $value);
    }

    $root = $this->getRootJurisdiction($jur);
    $root_config = $this->readNuxtConfig($root);
    foreach (self::SCOPE_MAP as $key => $scope) {
      if ($scope === 'tenant') {
        $value = $this->readFeatureValue($root_config, $key, $this->featureDefault($key, self::DEFAULTS[$key] ?? FALSE));
        $root_value = $this->readDotPath($root_config, 'features.' . $key);
        if (!str_contains($key, '.')) {
          $features[$key] = is_array($root_value) ? $root_value : $value;
        }
        $this->writeFeatureValue($features, $key, $value);
      }
    }

    foreach (self::SCOPE_MAP as $key => $scope) {
      if ($scope !== 'platform') {
        continue;
      }
      $this->writeFeatureValue($features, $key, $this->isPlatformFeatureEnabled($key));
    }

    $moderation_platform_rule = $this->moderationPlatformRule();
    if ($moderation_platform_rule !== NULL) {
      $this->writeFeatureValue($features, 'moderation', $moderation_platform_rule);
    }

    foreach (self::TIER_GATED as $key) {
      if (!$this->isTierFeatureAllowed($key, $jur)) {
        $this->writeFeatureValue($features, $key, FALSE);
      }
    }

    // Hard platform gate, applied last so no stored root or child opt-in can
    // resurrect an enterprise-only feature on the shared platform.
    if ($this->isSelfServicePlatform()) {
      foreach (self::SELF_SERVICE_EXCLUDED as $key) {
        $this->writeFeatureValue($features, $key, FALSE);
      }
    }

    return $features;
  }

  /**
   * Checks whether a feature may be edited in a jurisdiction context.
   */
  public function isEditable(string $key, GroupInterface $jur): bool {
    $scope = self::SCOPE_MAP[$key] ?? NULL;
    if ($scope === 'platform') {
      return FALSE;
    }
    if ($key === 'moderation' && $this->moderationPlatformRule() !== NULL) {
      return FALSE;
    }
    // Enterprise-only features must never be advertised as editable on the
    // shared platform: a settings PATCH could store the flag, but the hard
    // gate in resolveEffectiveFeatures() would ignore it, which is exactly
    // the silent-drop contract violation we refuse to ship.
    if ($this->isSelfServicePlatform()
      && in_array($key, self::SELF_SERVICE_EXCLUDED, TRUE)) {
      return FALSE;
    }
    if ($scope === 'tenant') {
      $root_id = $this->hierarchyResolver->getRootJurisdictionId((int) $jur->id());
      return $root_id === NULL || $root_id === (int) $jur->id();
    }
    return $scope === 'jurisdiction';
  }

  /**
   * Reads a boolean from the effective feature set.
   */
  public function isEnabledEffective(string $dotPath, GroupInterface $jur, ?bool $default = NULL): bool {
    $path = str_starts_with($dotPath, 'features.')
      ? substr($dotPath, strlen('features.'))
      : $dotPath;
    $scope_key = str_ends_with($path, '.enabled')
      ? substr($path, 0, -strlen('.enabled'))
      : $path;
    $scope = self::SCOPE_MAP[$scope_key] ?? NULL;

    if ($scope === 'platform') {
      return $this->isPlatformFeatureEnabled($scope_key, $default);
    }
    if ($scope_key === 'moderation') {
      $moderation_platform_rule = $this->moderationPlatformRule();
      if ($moderation_platform_rule !== NULL) {
        return $moderation_platform_rule;
      }
    }

    $source = $scope === 'tenant' ? $this->getRootJurisdiction($jur) : $jur;
    $default ??= $this->featureDefault($scope_key, self::DEFAULTS[$scope_key] ?? FALSE);
    $value = $this->readFeatureValue($this->readNuxtConfig($source), $scope_key, $default);
    if (in_array($scope_key, self::TIER_GATED, TRUE)
      && !$this->isTierFeatureAllowed($scope_key, $jur)) {
      return FALSE;
    }
    // Hard platform gate: enterprise-only features stay off on the shared
    // platform even when a stored opt-in exists (see SELF_SERVICE_EXCLUDED).
    if (in_array($scope_key, self::SELF_SERVICE_EXCLUDED, TRUE)
      && $this->isSelfServicePlatform()) {
      return FALSE;
    }
    return $value;
  }

  /**
   * Reads a platform feature flag from central configuration.
   *
   * A NULL config value on a mode-derived flag means "no operator decision
   * recorded" and resolves from markaspot_operating_mode (saas => TRUE).
   * A NULL privacyNotice keeps the pre-scope-split behaviour: the citizen
   * frontend always showed the disclosure when no tenant had decided, so
   * the visible notice stays on while the stricter GDPR consent coupling
   * only engages on an explicit operator TRUE (see
   * isPlatformFeatureExplicitlyEnabled()). Any other missing or non-boolean
   * value falls back to the caller-supplied default, then to the shared
   * flag defaults.
   */
  public function isPlatformFeatureEnabled(string $key, ?bool $default = NULL): bool {
    $value = $this->readPlatformFeatureRaw($key);
    if ($value === NULL && in_array($key, self::MODE_DERIVED_PLATFORM_FLAGS, TRUE)) {
      return Settings::get('markaspot_operating_mode', 'self_hosted') === 'saas';
    }
    if ($value === NULL && $key === 'privacyNotice') {
      return TRUE;
    }
    if (is_bool($value)) {
      return $value;
    }
    return $default ?? $this->featureDefault($key, self::DEFAULTS[$key] ?? FALSE);
  }

  /**
   * Features excluded from the self-service platform altogether.
   *
   * Product decision (2026-07-30, US-HOA strategy section 11): contractor
   * management, vendor routing and facility/QR management are exclusive to
   * enterprise stacks. On the shared SaaS platform they are FORCED OFF, a
   * stored features.* opt-in does not bring them back; customers who need
   * them buy a dedicated stack. This is a platform gate like TIER_GATED,
   * not a default.
   */
  public const SELF_SERVICE_EXCLUDED = [
    'organisations',
    'facilities',
  ];

  /**
   * Detects the shared self-service platform.
   *
   * An explicitly configured operating mode always wins: development
   * environments run the fastmap module alongside a self_hosted setting and
   * must keep enterprise platform defaults. The module backstop only
   * classifies containers that never declared a mode. Tierless TIER_GATED
   * features deliberately retain their stricter, independent FastMap
   * backstop in isTierFeatureAllowed().
   */
  public function isSelfServicePlatform(): bool {
    $mode = Settings::get('markaspot_operating_mode');
    if ($mode === 'saas') {
      return TRUE;
    }
    if ($mode === 'self_hosted') {
      return FALSE;
    }
    return $this->moduleHandler !== NULL
      && $this->moduleHandler->moduleExists('markaspot_fastmap');
  }

  /**
   * Resolves the effective default for a feature key.
   *
   * Most keys use the DEFAULTS constant as-is. The SELF_SERVICE_EXCLUDED
   * features default to the platform split: enabled on enterprise stacks,
   * off on the shared platform (where resolveEffectiveFeatures() also forces
   * them off regardless of stored values).
   */
  private function featureDefault(string $key, bool $default): bool {
    if (in_array($key, self::SELF_SERVICE_EXCLUDED, TRUE)) {
      return !$this->isSelfServicePlatform();
    }
    return $default;
  }

  /**
   * Checks whether the operator explicitly enabled a platform flag.
   *
   * Consumers with side effects beyond display (e.g. the GDPR consent
   * requirement coupled to privacyNotice) must only engage on a recorded
   * operator decision, never on a derived default.
   */
  public function isPlatformFeatureExplicitlyEnabled(string $key): bool {
    return $this->readPlatformFeatureRaw($key) === TRUE;
  }

  /**
   * Returns the raw stored platform flag value, or NULL when unset.
   */
  private function readPlatformFeatureRaw(string $key): ?bool {
    $platform_features = $this->configFactory
      ->get('markaspot_nuxt.settings')
      ->get('platform_features');
    $platform_features = is_array($platform_features) ? $platform_features : [];
    $value = $platform_features[$key] ?? NULL;
    return is_bool($value) ? $value : NULL;
  }

  /**
   * Resolves the optional platform-wide citizen flagging rule.
   */
  private function moderationPlatformRule(): ?bool {
    $value = $this->readPlatformFeatureRaw('moderation');
    if ($value !== NULL) {
      return $value;
    }
    return $this->isSelfServicePlatform() ? TRUE : NULL;
  }

  /**
   * Checks whether one tier-gated feature is allowed for a jurisdiction.
   *
   * A child's explicit tier wins so one Premium community cannot elevate its
   * siblings. Only an empty child tier inherits from the portfolio root. When
   * neither carries a tier, the operating-mode/FastMap backstop applies to all
   * tier-gated features together.
   */
  public function isTierFeatureAllowed(string $key, GroupInterface $jur): bool {
    if (!in_array($key, self::TIER_GATED, TRUE)) {
      return FALSE;
    }

    $tier = $this->resolveTier($jur);
    if ($tier !== NULL) {
      $entitled_features = self::TIER_FEATURE_ENTITLEMENT[$tier] ?? [];
      return in_array($key, $entitled_features, TRUE);
    }

    // No tier recorded: only genuine enterprise/self-hosted installs get the
    // full feature set. The fastmap module marks the workspace-SaaS platform
    // even when the operating-mode environment variable is missing, so a
    // misconfigured SaaS container cannot fall back to "everything allowed"
    // (tierless demo workspaces must stay on the free gate).
    if (Settings::get('markaspot_operating_mode', 'self_hosted') === 'saas') {
      return FALSE;
    }
    return $this->moduleHandler === NULL
      || !$this->moduleHandler->moduleExists('markaspot_fastmap');
  }

  /**
   * Checks whether all currently tier-gated features are allowed.
   *
   * Kept as a backwards-compatible aggregate for consumers that genuinely
   * require the complete tier-gated set. Feature-specific consumers must use
   * isTierFeatureAllowed() instead.
   */
  public function canUseTierGatedFeatures(GroupInterface $jur): bool {
    foreach (self::TIER_GATED as $key) {
      if (!$this->isTierFeatureAllowed($key, $jur)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Resolves the child-first tier, inheriting from the root only when empty.
   */
  private function resolveTier(GroupInterface $jur): ?string {
    $tier = $this->readTier($jur);
    if ($tier !== NULL) {
      return $tier;
    }

    $root = $this->getRootJurisdiction($jur);
    if ((int) $root->id() === (int) $jur->id()) {
      return NULL;
    }
    return $this->readTier($root);
  }

  /**
   * Reads a non-empty tier value from a jurisdiction.
   */
  private function readTier(GroupInterface $jur): ?string {
    if (!$jur->hasField('field_tier') || $jur->get('field_tier')->isEmpty()) {
      return NULL;
    }
    return (string) $jur->get('field_tier')->value;
  }

  /**
   * Checks whether this installation uses organisation groups at all.
   *
   * Organisation management only exists in enterprise/self-hosted setups;
   * the SaaS platform has no org creation or administration. The check is
   * data-driven (are there any org groups?) instead of relying on the
   * operating-mode environment, so the dashboard never offers org-coupled
   * toggles that have nothing to act on.
   */
  public function hasOrganisationFeatures(): bool {
    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->condition('type', 'org')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    return $ids !== [];
  }

  /**
   * Loads the root jurisdiction, falling back to the supplied group.
   */
  private function getRootJurisdiction(GroupInterface $jur): GroupInterface {
    $root_id = $this->hierarchyResolver->getRootJurisdictionId((int) $jur->id());
    if ($root_id === NULL || $root_id === (int) $jur->id()) {
      return $jur;
    }
    $root = $this->entityTypeManager->getStorage('group')->load($root_id);
    return $root instanceof GroupInterface ? $root : $jur;
  }

  /**
   * Reads decoded Nuxt configuration from the untranslated group entity.
   */
  private function readNuxtConfig(GroupInterface $jur): array {
    $source = $jur;
    if (!$jur->isDefaultTranslation()) {
      $untranslated = $jur->getUntranslated();
      if ($untranslated instanceof GroupInterface) {
        $source = $untranslated;
      }
    }
    if (!$source->hasField('field_nuxt_config') || $source->get('field_nuxt_config')->isEmpty()) {
      return [];
    }
    $field = $source->get('field_nuxt_config');
    $items = method_exists($field, 'getValue') ? $field->getValue() : [];
    $raw = $items[0]['value'] ?? ($field->value ?? NULL);
    if (!is_string($raw) || $raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Reads a feature value, including legacy form and boundary defaults.
   */
  private function readFeatureValue(array $config, string $key, bool $default): bool {
    if ($key === 'boundaries') {
      $stored = $this->readDotPath($config, 'features.boundaries');
      if ($stored === NULL) {
        return $default;
      }
    }
    if ($key === 'forms.allowParentCategorySelection') {
      $legacy = $this->readDotPath($config, 'forms.allowParentCategorySelection');
      $stored = $this->readDotPath($config, 'features.forms.allowParentCategorySelection');
      return is_bool($stored) ? $stored : (is_bool($legacy) ? $legacy : $default);
    }
    $stored = $this->readDotPath($config, 'features.' . $key);
    if (is_bool($stored)) {
      return $stored;
    }
    if (is_array($stored) && is_bool($stored['enabled'] ?? NULL)) {
      return $stored['enabled'];
    }
    return $default;
  }

  /**
   * Writes a resolved boolean while preserving nested response shapes.
   */
  private function writeFeatureValue(array &$features, string $key, bool $value): void {
    if ($key === 'facilities') {
      $features[$key] = $value;
      return;
    }
    if (in_array($key, ['emergency', 'funFacts', 'search', 'boundaries', 'privacyNotice'], TRUE)) {
      $existing = is_array($features[$key] ?? NULL) ? $features[$key] : [];
      $features[$key] = ['enabled' => $value] + $existing;
      return;
    }
    if ($key === 'forms.allowParentCategorySelection') {
      $features['forms'] = is_array($features['forms'] ?? NULL) ? $features['forms'] : [];
      $features['forms']['allowParentCategorySelection'] = $value;
      return;
    }
    if (is_array($features[$key] ?? NULL)) {
      $features[$key] = ['enabled' => $value] + $features[$key];
      return;
    }
    $features[$key] = $value;
  }

  /**
   * Walks a dot-separated path into an array.
   */
  private function readDotPath(array $data, string $path): mixed {
    $value = $data;
    foreach (explode('.', $path) as $segment) {
      if (!is_array($value) || !array_key_exists($segment, $value)) {
        return NULL;
      }
      $value = $value[$segment];
    }
    return $value;
  }

}
