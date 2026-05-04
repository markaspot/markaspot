<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects jurisdiction groups missing the passwordless feature flag.
 *
 * TenantSettingsController reads $features['passwordless'] ?? FALSE from
 * group.field_nuxt_config. When the JSON key is absent, the Pro layer
 * redirects /auth/login back to / with no error visible to the user.
 *
 * @HealthCheck(
 *   id = "passwordless_feature_missing",
 *   label = @Translation("Passwordless feature flag missing"),
 *   severity = "error",
 *   description = @Translation("Verifies every jurisdiction group has features.passwordless set in field_nuxt_config; missing flag breaks /auth/login."),
 *   fix_hint = @Translation("Run the tenant setup.sh fallback or patch features.passwordless into the affected group's field_nuxt_config."),
 * )
 */
class PasswordlessFeatureMissingCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?ConfigFactoryInterface $configFactory = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return $this->pass('Group module not enabled; check skipped.');
    }
    $storage = $this->entityTypeManager->getStorage('group');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->jurisdictionGroupType());
    if (isset($context['jurisdiction'])) {
      $query->condition('id', (int) $context['jurisdiction']);
    }
    $ids = $query->execute();
    if (empty($ids)) {
      return $this->pass('No jurisdiction groups found.');
    }

    $offenders = [];
    foreach ($storage->loadMultiple($ids) as $group) {
      if (!$group->hasField('field_nuxt_config')) {
        $offenders[] = (string) $group->id();
        continue;
      }
      $raw = $group->get('field_nuxt_config')->value;
      if ($raw === NULL || $raw === '') {
        $offenders[] = (string) $group->id();
        continue;
      }
      $config = json_decode((string) $raw, TRUE);
      if (!is_array($config) || !array_key_exists('features', $config) || !is_array($config['features']) || !array_key_exists('passwordless', $config['features'])) {
        $offenders[] = (string) $group->id();
      }
    }

    if ($offenders === []) {
      return $this->pass('All jurisdiction groups have features.passwordless set.');
    }
    return $this->fail(
      count($offenders),
      sprintf('%d jurisdiction(s) missing features.passwordless: %s.', count($offenders), implode(', ', $offenders)),
    );
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  private function jurisdictionGroupType(): string {
    $config = $this->configFactory?->get('markaspot_open311.settings');
    $configured = $config ? $config->get('jurisdiction_group_type') : NULL;

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
