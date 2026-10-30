<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports failed screening and a missing or stalled short-interval worker.
 *
 * @HealthCheck(
 *   id = "media_intake_screening",
 *   label = @Translation("Imported image privacy screening"),
 *   severity = "error",
 *   description = @Translation("Checks imported photos waiting for screening."),
 *   fix_hint = @Translation("Check blur availability and run drush queue:run markaspot_media_intake every minute."),
 * )
 */
final class MediaIntakeScreeningCheck extends HealthCheckPluginBase {

  /**
   * Constructs the health check.
   */
  public function __construct(array $configuration, string $plugin_id, $plugin_definition, protected KeyValueFactoryInterface $keyValue, protected TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('keyvalue'), $container->get('datetime.time'));
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    $count = 0;
    $analysisFailures = 0;
    $now = $this->time->getCurrentTime();
    foreach ($this->keyValue->get('markaspot_vision.media_intake')->getAll() as $entry) {
      if (in_array($entry['status'], ['orphaned', 'resolved'], TRUE)) {
        continue;
      }
      if ($entry['status'] === 'failed' || ($entry['status'] !== 'done' && $now - $entry['created'] > 300)) {
        $count++;
      }
      if (in_array($entry['analysis'] ?? '', ['interrupted', 'failed'], TRUE)) {
        $analysisFailures++;
      }
    }
    if ($count) {
      return $this->fail($count, 'Imported images failed screening or have waited more than five minutes.');
    }
    return $analysisFailures
      ? $this->failWithSeverity('warning', $analysisFailures, 'Optional imported image AI enrichment failed or was interrupted; privacy-screened images remain eligible for publication.')
      : $this->pass('No failed or stalled imported image screening.');
  }

}
