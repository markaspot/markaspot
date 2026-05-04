<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

use Drupal\markaspot_group\Service\GroupIntegrityChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for plugins that surface one slice of GroupIntegrityChecker.
 *
 * GroupIntegrityChecker::check() returns an associative array of eight
 * sub-checks, each with its own description and an array of detail rows.
 * Each concrete plugin pins itself to one sub-check by overriding
 * getCheckKey(); the base memoises the upstream service call inside a
 * static property so the eight plugins together trigger the underlying
 * SQL only once per report run.
 *
 * The static cache is request-scoped via an explicit resetCache() call
 * at the top of HealthCheckPluginManager::runAll(). Without that reset
 * the property would survive across requests in long-lived FPM workers
 * and serve stale drift counts after an operator fixed something.
 * Tests must reset the cache in setUp() to avoid bleed across cases.
 */
abstract class GroupIntegrityCheckBase extends HealthCheckPluginBase {

  /**
   * Maximum detail rows surfaced per result.
   */
  protected const DETAIL_LIMIT = 50;

  /**
   * Cached GroupIntegrityChecker::check() result for the current run.
   *
   * @var array<string, array{rows:array<int, array<string, mixed>>, description:string}>|null
   */
  protected static ?array $cachedCheck = NULL;

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected GroupIntegrityChecker $integrityChecker,
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
      $container->get('markaspot_group.integrity_checker'),
    );
  }

  /**
   * Returns the GroupIntegrityChecker::check() key this plugin surfaces.
   */
  abstract protected function getCheckKey(): string;

  /**
   * Resets the request-scoped check cache. Intended for tests.
   */
  public static function resetCache(): void {
    self::$cachedCheck = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (self::$cachedCheck === NULL) {
      self::$cachedCheck = $this->integrityChecker->check();
    }
    $entry = self::$cachedCheck[$this->getCheckKey()] ?? NULL;
    if ($entry === NULL) {
      return $this->pass(sprintf(
        'Check key "%s" not exposed by GroupIntegrityChecker; skipped.',
        $this->getCheckKey(),
      ));
    }
    $rows = $entry['rows'] ?? [];
    $count = count($rows);
    $description = (string) ($entry['description'] ?? $this->label());
    if ($count === 0) {
      return $this->pass($description);
    }
    $details = array_slice($rows, 0, self::DETAIL_LIMIT);
    $truncated = max(0, $count - self::DETAIL_LIMIT);
    return $this->fail($count, $description, $details, $truncated);
  }

}
