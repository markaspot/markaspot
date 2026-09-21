<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Validates the central passwordless setting without requiring a login mode.
 *
 * @HealthCheck(
 *   id = "passwordless_feature_missing",
 *   label = @Translation("Passwordless platform configuration"),
 *   severity = "error",
 *   description = @Translation("Validates the central passwordless setting. Enabled, disabled and automatic defaults are supported; tenant flags are not required."),
 *   fix_hint = @Translation("Set markaspot_nuxt.settings platform_features.passwordless to a boolean or null for the operating-mode default."),
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
    protected ConfigFactoryInterface $configFactory,
    protected ?FeatureScopeResolver $featureScopeResolver = NULL,
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
      $container->get('config.factory'),
      $container->has('markaspot_nuxt.feature_scope_resolver')
        ? $container->get('markaspot_nuxt.feature_scope_resolver')
        : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if ($this->featureScopeResolver === NULL) {
      return $this->pass('Nuxt feature resolver not available; check skipped.');
    }
    $features = $this->configFactory->get('markaspot_nuxt.settings')->get('platform_features');
    if ($features !== NULL && (!is_array($features) || ($features !== [] && array_is_list($features)))) {
      return $this->fail(1, 'platform_features must be a configuration mapping.');
    }
    $value = $features['passwordless'] ?? NULL;
    if ($value !== NULL && !is_bool($value)) {
      return $this->fail(1, 'platform_features.passwordless must be boolean or null.');
    }

    $enabled = $this->featureScopeResolver->isPlatformFeatureEnabled('passwordless');
    return $this->pass(sprintf(
      'Passwordless platform configuration is valid; effective mode is %s%s.',
      $enabled ? 'enabled' : 'disabled',
      $value === NULL ? ' (automatic default)' : '',
    ));
  }

}
