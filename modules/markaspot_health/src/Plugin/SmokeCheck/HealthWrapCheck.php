<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\markaspot_health\HealthCheckPluginManager;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wraps the health check suite so smoke is the single CI gate.
 *
 * Runs all HealthCheck plugins via the existing HealthCheckPluginManager and
 * collapses their results into a single smoke outcome:
 * - PASS when all health checks pass.
 * - FAIL severity error when at least one error-severity health check fails.
 * - WARNING when only warnings/infos failed (no error-severity).
 *
 * Detail evidence carries the per-plugin breakdown so operators see why the
 * wrap failed without running drush markaspot:health separately.
 *
 * @SmokeCheck(
 *   id = "health_wrap",
 *   label = @Translation("Health suite (wrapped)"),
 *   severity = "error",
 *   category = "wrap",
 *   description = @Translation("Runs all markaspot_health drift checks and aggregates the result so smoke is the single gate."),
 *   fix_hint = @Translation("Run drush markaspot:health for the full per-check report."),
 * )
 */
class HealthWrapCheck extends SmokeCheckPluginBase {

  /**
   * Maximum number of failing health entries kept in evidence.
   */
  protected const EVIDENCE_LIMIT = 20;

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected HealthCheckPluginManager $healthManager,
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
      $container->get('plugin.manager.markaspot_health_check'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);

    try {
      $results = $this->healthManager->runAll($context);
    }
    catch (\Throwable $e) {
      return $this->warning(
        sprintf('Health manager threw: %s.', $e->getMessage()),
        ['exception' => $e::class],
        $mode,
      );
    }

    $errorFails = [];
    $nonErrorFails = [];
    foreach ($results as $result) {
      if ($result->passed) {
        continue;
      }
      $entry = [
        'id' => $result->id(),
        'severity' => $result->severity,
        'count' => $result->count,
        'message' => $result->message,
      ];
      if ($result->severity === 'error') {
        $errorFails[] = $entry;
      }
      else {
        $nonErrorFails[] = $entry;
      }
    }

    if ($errorFails === [] && $nonErrorFails === []) {
      return $this->pass(
        sprintf('%d health check(s) all passed.', count($results)),
        ['health_total' => count($results)],
        $mode,
      );
    }

    $evidence = [
      'health_total' => count($results),
      'health_error_fails' => array_slice($errorFails, 0, self::EVIDENCE_LIMIT),
      'health_non_error_fails' => array_slice($nonErrorFails, 0, self::EVIDENCE_LIMIT),
    ];

    if ($errorFails !== []) {
      return $this->fail(
        count($errorFails),
        sprintf('%d error-severity health check(s) failed.', count($errorFails)),
        $evidence,
        $mode,
      );
    }
    return $this->warning(
      sprintf('%d non-error health check(s) failed.', count($nonErrorFails)),
      $evidence,
      $mode,
    );
  }

}
