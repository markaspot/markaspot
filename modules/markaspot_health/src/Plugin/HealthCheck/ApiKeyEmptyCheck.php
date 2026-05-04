<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects empty API keys on services_api_key_auth credentials.
 *
 * The committed config intentionally ships with key: '' so secrets stay
 * out of the repository; runtime values are injected by settings.php
 * overrides driven by environment variables. When the env override is
 * not wired up, the runtime resolver also returns empty and every
 * georeport/* call answers 401.
 *
 * @HealthCheck(
 *   id = "api_key_empty",
 *   label = @Translation("API keys empty at runtime"),
 *   severity = "error",
 *   description = @Translation("Lists services_api_key_auth credentials whose key resolves to empty at runtime; the ENV override is missing."),
 *   fix_hint = @Translation("Set the corresponding GEOREPORT_API_KEY (or matching) ENV variable and verify settings.php picks it up."),
 * )
 */
class ApiKeyEmptyCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    $names = $this->configFactory->listAll('services_api_key_auth.');
    if ($names === []) {
      return $this->pass('services_api_key_auth not configured; check skipped.');
    }

    $offenders = [];
    foreach ($names as $name) {
      // Skip the meta settings config; only check credential rows.
      if ($name === 'services_api_key_auth.settings') {
        continue;
      }
      $config = $this->configFactory->get($name);
      $key = $config->get('key');
      if ($key === NULL || $key === '') {
        $offenders[] = substr($name, strlen('services_api_key_auth.'));
      }
    }

    if ($offenders === []) {
      return $this->pass('All API key credentials resolve to non-empty values.');
    }
    return $this->fail(
      count($offenders),
      sprintf('%d API key credential(s) empty at runtime: %s.', count($offenders), implode(', ', $offenders)),
    );
  }

}
