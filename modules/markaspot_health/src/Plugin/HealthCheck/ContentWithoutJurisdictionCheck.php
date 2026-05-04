<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects service_request nodes without a jurisdiction reference.
 *
 * Reports without field_jurisdiction are invisible to tenant filters,
 * orphaned in the dashboard, and often the result of legacy migrations
 * that did not back-fill the field.
 *
 * @HealthCheck(
 *   id = "content_without_jurisdiction",
 *   label = @Translation("Service requests without jurisdiction"),
 *   severity = "warning",
 *   description = @Translation("Counts published service_request nodes whose field_jurisdiction is empty; these reports are invisible to tenant scoping."),
 *   fix_hint = @Translation("Run the migration back-fill or assign a jurisdiction via bulk-edit on /admin/content."),
 * )
 */
class ContentWithoutJurisdictionCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return $this->pass('Node module not enabled; check skipped.');
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $bundles = $this->entityTypeManager->getStorage('node_type')->getQuery()->accessCheck(FALSE)->execute();
    if (!in_array('service_request', $bundles, TRUE)) {
      return $this->pass('service_request bundle not present; check skipped.');
    }

    $fieldDefinitions = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadByProperties(['entity_type' => 'node', 'bundle' => 'service_request', 'field_name' => 'field_jurisdiction']);
    if ($fieldDefinitions === []) {
      return $this->pass('field_jurisdiction not attached to service_request; check skipped.');
    }

    $count = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->notExists('field_jurisdiction')
      ->count()
      ->execute();

    if ($count === 0) {
      return $this->pass('All service requests have a jurisdiction.');
    }
    return $this->fail($count, sprintf('%d service request(s) without field_jurisdiction.', $count));
  }

}
