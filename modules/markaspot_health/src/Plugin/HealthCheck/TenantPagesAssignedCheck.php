<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports jurisdictions without optional published information pages.
 *
 * Legal pages may come from operator metadata rather than node:page entities.
 * This editorial reminder does not assess legal content or release readiness.
 *
 * @HealthCheck(
 *   id = "tenant_pages_assigned",
 *   label = @Translation("Tenants without node:page"),
 *   severity = "warning",
 *   description = @Translation("Counts jurisdictions without optional published information pages. This does not assess legal pages."),
 *   fix_hint = @Translation("If additional information pages are wanted, create or assign published node:page content via field_jurisdiction. Verify legal content separately."),
 *   fix_url = "/admin/content?type=page",
 * )
 */
class TenantPagesAssignedCheck extends HealthCheckPluginBase {

  /**
   * Detail rows truncate cap to keep payload bounded.
   */
  private const DETAILS_LIMIT = 25;

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
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
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('group') || !$this->entityTypeManager->hasDefinition('node')) {
      return $this->pass('group or node entity type not available; check skipped.');
    }

    $jurisdictions = $this->loadJurisdictions($context);
    if ($jurisdictions === []) {
      return $this->pass('No jurisdictions present; check skipped.');
    }

    $details = [];
    $count = 0;
    foreach ($jurisdictions as $jur) {
      $jurId = (int) $jur->id();
      $pageCount = $this->countPagesForJurisdiction($jurId);
      if ($pageCount > 0) {
        continue;
      }
      $count++;
      if (count($details) < self::DETAILS_LIMIT) {
        $details[] = [
          'jurisdiction' => $jurId,
          'jur_label' => (string) $jur->label(),
          'page_count' => 0,
        ];
      }
    }

    if ($count === 0) {
      return $this->pass('Every jurisdiction has at least one published information page. Legal content was not assessed.');
    }

    return $this->failWithSeverity(
      'warning',
      $count,
      sprintf(
        '%d jurisdiction(s) without optional published information pages. Legal content was not assessed.',
        $count,
      ),
      $details,
      max(0, $count - count($details)),
      $context['jurisdiction'] ?? NULL,
    );
  }

  /**
   * Loads jurisdictions, scoped to context['jurisdiction'] when present.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Single-element array when context pinned a jurisdiction, otherwise
   *   every jur group.
   */
  protected function loadJurisdictions(array $context): array {
    $storage = $this->entityTypeManager->getStorage('group');
    $contextJur = $context['jurisdiction'] ?? NULL;
    if (is_int($contextJur) && $contextJur > 0) {
      $jur = $storage->load($contextJur);
      return $jur && $jur->bundle() === 'jur' ? [$jur] : [];
    }
    return $storage->loadByProperties(['type' => 'jur']);
  }

  /**
   * Counts published page nodes whose field_jurisdiction matches the gid.
   */
  protected function countPagesForJurisdiction(int $jurisdictionId): int {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return 0;
    }
    $bundles = (array) $this->entityTypeManager->getStorage('node_type')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    if (!in_array('page', $bundles, TRUE)) {
      return 0;
    }
    $fieldDefs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties(['entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'field_jurisdiction']);
    if ($fieldDefs === []) {
      return 0;
    }
    // Draft pages do not satisfy the optional published-content reminder.
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
      ->condition('status', 1)
      ->condition('field_jurisdiction', $jurisdictionId)
      ->count()
      ->execute();
  }

}
