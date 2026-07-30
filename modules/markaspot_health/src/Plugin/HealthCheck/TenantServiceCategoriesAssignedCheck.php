<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects jurisdictions with no resolvable service categories.
 *
 * A jur without categories cannot advertise a categorised report form or map
 * an advertised service code to a tenant-owned term. Upstream of
 * `eca_tid_mismatch`: a tenant with no categories cannot have mismatched tids
 * in the first place.
 *
 * Category resolution mirrors the runtime Open311 service catalog:
 * - resolve a child jurisdiction to its root category owner
 * - load service_category terms owned by that root
 * - apply field_service_categories when the requested jurisdiction narrows
 *   the inherited catalog
 *
 * Direct and inherited coverage pass.
 *
 * Severity `error`: misconfigured tenants fail citizen submissions silently
 * from end-user perspective.
 *
 * @HealthCheck(
 *   id = "tenant_service_categories_assigned",
 *   label = @Translation("Tenants without service categories"),
 *   severity = "error",
 *   description = @Translation("Asserts every jurisdiction resolves at least one taxonomy_term:service_category through its root hierarchy and optional field_service_categories restriction."),
 *   fix_hint = @Translation("Open the jurisdiction edit form and assign at least one service_category — or back-fill field_jurisdiction on existing terms via a seed script."),
 * )
 */
class TenantServiceCategoriesAssignedCheck extends HealthCheckPluginBase {

  private const DETAILS_LIMIT = 25;

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver,
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
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('group')
      || $this->hierarchyResolver === NULL) {
      return $this->pass('group entity type not available; check skipped.');
    }

    $jurisdictions = $this->loadJurisdictions($context);
    if ($jurisdictions === []) {
      return $this->pass('No jurisdictions present; check skipped.');
    }

    $errorDetails = [];
    $infoDetails = [];
    $errorCount = 0;
    $infoCount = 0;
    foreach ($jurisdictions as $jur) {
      $jurId = (int) $jur->id();
      $termSide = $this->countTermsByJurisdiction($jurId);
      $groupSide = $this->countGroupSideAssignments($jur);
      $resolvedCount = $this->countResolvedCategories($jurId);
      $detail = [
        'jurisdiction' => $jurId,
        'jur_label' => (string) $jur->label(),
        'term_side_count' => $termSide,
        'group_side_count' => $groupSide,
        'resolved_count' => $resolvedCount,
      ];
      if ($resolvedCount === 0) {
        $errorCount++;
        if (count($errorDetails) < self::DETAILS_LIMIT) {
          $errorDetails[] = $detail;
        }
        continue;
      }

      if ($termSide === 0 && $groupSide === 0) {
        $infoCount++;
        if (count($infoDetails) < self::DETAILS_LIMIT) {
          $infoDetails[] = $detail;
        }
      }
    }

    if ($errorCount === 0 && $infoCount === 0) {
      return $this->pass('Every jurisdiction resolves at least one service_category through direct coverage.');
    }

    if ($errorCount > 0) {
      $details = array_merge(
        array_map(static fn(array $d) => $d + ['severity' => 'error'], $errorDetails),
        array_map(static fn(array $d) => $d + ['severity' => 'info'], $infoDetails),
      );
      $truncated = ($errorCount + $infoCount) - count($details);
      return $this->failWithSeverity(
        'error',
        $errorCount,
        sprintf(
          '%d jurisdiction(s) resolve no service categories (plus %d inherited-only coverage info item(s)).',
          $errorCount,
          $infoCount,
        ),
        $details,
        max(0, $truncated),
        $context['jurisdiction'] ?? NULL,
      );
    }

    return $this->pass(
      sprintf(
        'Every jurisdiction resolves service categories; %d use inheritance.',
        $infoCount,
      ),
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
   * Counts service_category terms whose field_jurisdiction targets this jur.
   *
   * Mirrors GeoreportProcessorService::getTaxonomyTree().
   */
  protected function countTermsByJurisdiction(int $jurisdictionId, ?array $allowedIds = NULL): int {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return 0;
    }
    $fieldDefs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'taxonomy_term',
        'bundle' => 'service_category',
        'field_name' => 'field_jurisdiction',
      ]);
    if ($fieldDefs === []) {
      return 0;
    }
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_category')
      ->condition('field_jurisdiction', $jurisdictionId)
      ->condition('status', 1);
    if ($allowedIds !== NULL) {
      if ($allowedIds === []) {
        return 0;
      }
      $query->condition('tid', $allowedIds, 'IN');
    }
    return (int) $query->count()->execute();
  }

  /**
   * Counts categories exposed by the runtime hierarchy resolver.
   */
  protected function countResolvedCategories(int $jurisdictionId): int {
    if ($this->hierarchyResolver === NULL) {
      return 0;
    }
    $rootId = $this->hierarchyResolver
      ->getRootJurisdictionId($jurisdictionId);
    if ($rootId === NULL) {
      return 0;
    }

    return $this->countTermsByJurisdiction(
      $rootId,
      $this->hierarchyResolver->getAllowedCategoryIds($jurisdictionId),
    );
  }

  /**
   * Counts the jur's own field_service_categories references (forward-looking).
   */
  protected function countGroupSideAssignments($jurisdiction): int {
    if (!$jurisdiction->hasField('field_service_categories')) {
      return 0;
    }
    return $jurisdiction->get('field_service_categories')->count();
  }

}
