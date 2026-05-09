<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\markaspot_health\Annotation\SmokeCheck;

/**
 * Plugin manager for Mark-a-Spot smoke check plugins.
 */
class SmokeCheckPluginManager extends DefaultPluginManager {

  /**
   * Constructs a SmokeCheckPluginManager.
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
      'Plugin/SmokeCheck',
      $namespaces,
      $module_handler,
      SmokeCheckInterface::class,
      SmokeCheck::class,
    );

    $this->alterInfo('markaspot_smoke_check_info');
    $this->setCacheBackend($cache_backend, 'markaspot_smoke_check_plugins');
  }

  /**
   * Runs all available smoke checks honouring mode and category filters.
   *
   * @param array<string, mixed> $context
   *   Run context. Recognised keys:
   *   - mode: 'read-only' (default) or 'full'. Mutating plugins are skipped
   *     in read-only mode.
   *   - category: optional string to filter the catalog.
   *   - jurisdiction: optional int handed to plugins.
   *
   * @return array<int, \Drupal\markaspot_health\SmokeCheckResult>
   *   Results in plugin definition order.
   */
  public function runAll(array $context = []): array {
    $mode = $context['mode'] ?? SmokeCheckResult::MODE_READ_ONLY;
    if ($mode !== SmokeCheckResult::MODE_FULL) {
      $mode = SmokeCheckResult::MODE_READ_ONLY;
      $context['mode'] = $mode;
    }
    $categoryFilter = isset($context['category']) && is_string($context['category']) && $context['category'] !== ''
      ? $context['category']
      : NULL;

    $results = [];
    foreach ($this->getDefinitions() as $pluginId => $definition) {
      if ($categoryFilter !== NULL && ($definition['category'] ?? 'drupal_internal') !== $categoryFilter) {
        continue;
      }

      $started = microtime(TRUE);

      // Mutating plugins are skipped in read-only mode without instantiation
      // to keep prod-safe runs from triggering side-effectful constructors.
      if (!empty($definition['mutates']) && $mode === SmokeCheckResult::MODE_READ_ONLY) {
        $results[] = $this->skipResult($pluginId, $definition, $mode, 'Mutating check skipped under --mode=read-only.')
          ->withDuration($this->elapsedMs($started));
        continue;
      }

      try {
        $plugin = $this->createInstance($pluginId);
      }
      catch (\Throwable $e) {
        $results[] = $this->failedInstanceResult($pluginId, $definition, $mode, $e)
          ->withDuration($this->elapsedMs($started));
        continue;
      }
      if (!$plugin instanceof SmokeCheckInterface) {
        continue;
      }

      try {
        $result = $plugin->run($context);
      }
      catch (\Throwable $e) {
        $results[] = $this->failedInstanceResult($pluginId, $definition, $mode, $e)
          ->withDuration($this->elapsedMs($started));
        continue;
      }
      $results[] = $result->withDuration($this->elapsedMs($started));
    }

    return $results;
  }

  /**
   * Computes elapsed milliseconds since $started, clamped to at least 1.
   */
  protected function elapsedMs(float $started): int {
    return max(1, (int) round((microtime(TRUE) - $started) * 1000));
  }

  /**
   * Builds a synthetic skip result.
   */
  protected function skipResult(string $pluginId, array $definition, string $mode, string $message): SmokeCheckResult {
    return new SmokeCheckResult(
      $pluginId,
      isset($definition['label']) ? (string) $definition['label'] : $pluginId,
      SmokeCheckResult::STATUS_SKIP,
      (string) ($definition['severity'] ?? 'warning'),
      (string) ($definition['category'] ?? 'drupal_internal'),
      !empty($definition['mutates']),
      $mode,
      0,
      $message,
    );
  }

  /**
   * Builds a synthetic error result when a plugin instantiation or run fails.
   *
   * Without this, a single misbehaving plugin would crash the whole report
   * and the smoke gate would go dark on the tenant that needs it most.
   */
  protected function failedInstanceResult(string $pluginId, array $definition, string $mode, \Throwable $e): SmokeCheckResult {
    return new SmokeCheckResult(
      $pluginId,
      isset($definition['label']) ? (string) $definition['label'] : $pluginId,
      SmokeCheckResult::STATUS_FAIL,
      'error',
      (string) ($definition['category'] ?? 'drupal_internal'),
      !empty($definition['mutates']),
      $mode,
      0,
      sprintf('Plugin %s failed: %s', $pluginId, substr($e->getMessage(), 0, 200)),
      // Stack traces would expose absolute filesystem paths and internal
      // class structure to any HTTP caller with view smoke checks. Keep
      // evidence to the exception class plus a truncated message; full
      // traces remain in PHP error logs for operator triage.
      ['exception' => $e::class, 'message_excerpt' => substr($e->getMessage(), 0, 200)],
      'Check that all submodules required by this plugin are enabled.',
    );
  }

}
