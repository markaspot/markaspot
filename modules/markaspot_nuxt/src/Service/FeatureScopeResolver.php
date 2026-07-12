<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    'aiAnalysis' => FALSE,
    'aiProcessing' => FALSE,
    'operationsDashboard' => FALSE,
    'assignmentSyncsOrganisation' => FALSE,
    'delegationNoteRequired' => FALSE,
    'dashboard' => TRUE,
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
        $value = $this->readFeatureValue($root_config, $key, self::DEFAULTS[$key]);
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

    if (!$this->canUseTierGatedFeatures($jur)) {
      foreach (self::TIER_GATED as $key) {
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
    if ($scope === 'tenant') {
      $root_id = $this->hierarchyResolver->getRootJurisdictionId((int) $jur->id());
      return $root_id === NULL || $root_id === (int) $jur->id();
    }
    return $scope === 'jurisdiction';
  }

  /**
   * Reads a boolean from the effective feature set.
   */
  public function isEnabledEffective(string $dotPath, GroupInterface $jur, bool $default): bool {
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

    $source = $scope === 'tenant' ? $this->getRootJurisdiction($jur) : $jur;
    $value = $this->readFeatureValue($this->readNuxtConfig($source), $scope_key, $default);
    if (in_array($scope_key, self::TIER_GATED, TRUE)
      && !$this->canUseTierGatedFeatures($jur)) {
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
    return $default ?? (self::DEFAULTS[$key] ?? FALSE);
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
   * Checks the shared tier capability against the workspace root.
   */
  public function canUseTierGatedFeatures(GroupInterface $jur): bool {
    $root = $this->getRootJurisdiction($jur);
    if ($root->hasField('field_tier') && !$root->get('field_tier')->isEmpty()) {
      return in_array((string) $root->get('field_tier')->value, ['pro', 'heart'], TRUE);
    }
    return Settings::get('markaspot_operating_mode', 'self_hosted') !== 'saas';
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
