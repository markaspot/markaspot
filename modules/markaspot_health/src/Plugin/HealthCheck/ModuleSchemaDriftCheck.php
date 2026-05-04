<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects modules listed in core.extension whose schema is 0 or missing.
 *
 * Migrations that SQL-INSERTed module rows directly into the database
 * (instead of using drush en or the module installer) leave system.schema
 * with no entry, so hook_update_N never fires for those modules. Symptom:
 * passwordless login 500s, mail builders missing, JSON:API endpoints 401.
 *
 * @HealthCheck(
 *   id = "module_schema_drift",
 *   label = @Translation("Module schema drift"),
 *   severity = "error",
 *   description = @Translation("Lists modules in core.extension whose system.schema entry is missing or 0; update hooks never ran for these."),
 *   fix_hint = @Translation("drush en <module> --no-cache-clear or run drush updb to repair schema entries."),
 * )
 */
class ModuleSchemaDriftCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected KeyValueFactoryInterface $keyValueFactory,
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
      $container->get('keyvalue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    $modules = (array) $this->configFactory->get('core.extension')->get('module');
    if ($modules === []) {
      return $this->pass('core.extension lists no modules; check skipped.');
    }
    $schemas = $this->keyValueFactory->get('system.schema')->getMultiple(array_keys($modules));

    $offenders = [];
    foreach ($modules as $name => $weight) {
      $schema = $schemas[$name] ?? NULL;
      if ($schema === NULL || $schema === 0 || $schema === '0') {
        $offenders[] = $name;
      }
    }

    if ($offenders === []) {
      return $this->pass(sprintf('%d module(s) tracked, all with non-zero schemas.', count($modules)));
    }
    sort($offenders);
    return $this->fail(
      count($offenders),
      sprintf('%d module(s) with missing or zero schema: %s.', count($offenders), implode(', ', $offenders)),
    );
  }

}
