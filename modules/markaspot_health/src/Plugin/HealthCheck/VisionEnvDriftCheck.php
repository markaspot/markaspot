<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects markaspot_vision running without ENV overrides applied.
 *
 * The vision settings ENV overrides are required to live in settings.php
 * (not local settings) so cloud-image tenants pick them up. When that
 * indirection is missing, the runtime config resolves to whatever was
 * exported into config/sync, which on cloud-image installs is the stock
 * upstream OpenAI host with an empty key. Symptom: the AI categoriser
 * silently fails or hits a generic OpenAI account.
 *
 * @HealthCheck(
 *   id = "vision_env_drift",
 *   label = @Translation("Vision ENV overrides missing"),
 *   severity = "warning",
 *   description = @Translation("Verifies markaspot_vision.settings has a non-empty api_key at runtime; empty means the settings.php ENV override is not wired."),
 *   fix_hint = @Translation("Add the markaspot_vision config_overrides block to docker/settings.php so MARKASPOT_VISION_* envs land in $config."),
 * )
 */
class VisionEnvDriftCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
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
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->moduleHandler->moduleExists('markaspot_vision')) {
      return $this->pass('markaspot_vision not enabled; check skipped.');
    }
    $config = $this->configFactory->get('markaspot_vision.settings');
    if ($config->isNew()) {
      return $this->pass('markaspot_vision.settings not present; check skipped.');
    }

    $apiKey = (string) $config->get('api_key');
    if ($apiKey === '') {
      return $this->fail(1, 'markaspot_vision.settings.api_key is empty at runtime; ENV override is not active.');
    }
    return $this->pass('markaspot_vision ENV override is active.');
  }

}
