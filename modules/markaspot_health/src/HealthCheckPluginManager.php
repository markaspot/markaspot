<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\markaspot_health\Annotation\HealthCheck;

/**
 * Plugin manager for Mark-a-Spot health check plugins.
 */
class HealthCheckPluginManager extends DefaultPluginManager {

  /**
   * Constructs a HealthCheckPluginManager.
   *
   * @param \Traversable $namespaces
   *   Object that implements \Traversable, containing the root paths keyed by
   *   the corresponding namespace.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/HealthCheck',
      $namespaces,
      $module_handler,
      HealthCheckInterface::class,
      HealthCheck::class,
    );

    $this->alterInfo('markaspot_health_check_info');
    $this->setCacheBackend($cache_backend, 'markaspot_health_check_plugins');
  }

  /**
   * Runs all available health checks.
   *
   * @param array<string, mixed> $context
   *   Optional context handed to every plugin's run() method.
   *
   * @return array<int, \Drupal\markaspot_health\HealthCheckResult>
   *   Results in plugin definition order.
   */
  public function runAll(array $context = []): array {
    // FPM workers are reused across requests, so any plugin-side static
    // cache must be cleared at the start of every report run; otherwise
    // a "fixed" tenant keeps showing the previous run's drift counts.
    GroupIntegrityCheckBase::resetCache();

    $results = [];
    foreach ($this->getDefinitions() as $pluginId => $definition) {
      $started = microtime(TRUE);
      try {
        $plugin = $this->createInstance($pluginId);
      }
      catch (\Exception $e) {
        $results[] = $this->failedInstanceResult($pluginId, $definition, $e)
          ->withDuration($this->elapsedMs($started));
        continue;
      }
      if (!$plugin instanceof HealthCheckInterface) {
        continue;
      }
      try {
        $result = $plugin->run($context);
      }
      catch (\Exception $e) {
        $results[] = $this->failedInstanceResult($pluginId, $definition, $e)
          ->withDuration($this->elapsedMs($started));
        continue;
      }
      $results[] = $result->withDuration($this->elapsedMs($started));
    }
    return $results;
  }

  /**
   * Computes elapsed milliseconds since $started, clamped to at least 1.
   *
   * Sub-millisecond runs would otherwise floor to 0, indistinguishable from
   * the "not measured" sentinel ($lastRunDurationMs === null). Clamping to
   * 1 ms preserves the "ran instantly" signal honestly enough for an
   * operator-facing dashboard.
   */
  protected function elapsedMs(float $started): int {
    return max(1, (int) round((microtime(TRUE) - $started) * 1000));
  }

  /**
   * Builds a synthetic error result when a plugin instantiation or run fails.
   *
   * Without this, a single misbehaving plugin (or missing dependency on an
   * adapter target service) would 500 the whole report and the entire
   * detection layer would go dark on the tenant that needs it most.
   */
  protected function failedInstanceResult(string $pluginId, array $definition, \Exception $e): HealthCheckResult {
    $label = isset($definition['label']) ? (string) $definition['label'] : $pluginId;
    $message = sprintf('Plugin %s failed: %s', $pluginId, $e->getMessage());
    return new HealthCheckResult(
      $pluginId,
      $label,
      FALSE,
      'error',
      0,
      $message,
      'Check that all submodules required by this plugin are enabled.',
      NULL,
    );
  }

}
