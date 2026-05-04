<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects PII providers that are known to time out.
 *
 * The local_nlp provider (phi3:mini) times out under realistic PII payloads
 * with "client prematurely closed connection". azure or ionos respond in
 * sub-second time and should be the production default.
 *
 * @HealthCheck(
 *   id = "pii_provider_timeout",
 *   label = @Translation("PII provider configured for timeout"),
 *   severity = "warning",
 *   description = @Translation("Flags markaspot_pii configured with the local_nlp provider, which times out on realistic payloads."),
 *   fix_hint = @Translation("drush cset markaspot_pii.settings provider azure (or ionos)."),
 * )
 */
class PiiProviderTimeoutCheck extends HealthCheckPluginBase {

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
    if (!$this->moduleHandler->moduleExists('markaspot_pii')) {
      return $this->pass('markaspot_pii not enabled; check skipped.');
    }
    $config = $this->configFactory->get('markaspot_pii.settings');
    if ($config->isNew()) {
      return $this->pass('markaspot_pii.settings not configured; check skipped.');
    }
    $provider = (string) $config->get('provider');
    if ($provider === 'local_nlp') {
      return $this->fail(1, 'PII provider is local_nlp, which is known to time out.');
    }
    return $this->pass(sprintf('PII provider is %s.', $provider !== '' ? $provider : '<unset>'));
  }

}
