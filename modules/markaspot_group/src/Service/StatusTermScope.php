<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Resolves the effective service status terms for a jurisdiction.
 *
 * The field storage is optional across supported install shapes. Keeping the
 * guard here prevents consumers from querying a field table that is absent on
 * older single-tenant sites. On multi-tenant sites status terms are owned by
 * the root jurisdiction while each requested jurisdiction may select a subset.
 */
class StatusTermScope {

  /**
   * Constructs a StatusTermScope object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {}

  /**
   * Loads the effective status set for the requested jurisdiction.
   *
   * @param array<string, mixed> $properties
   *   Properties accepted by taxonomy term storage.
   * @param int|null $jurisdictionId
   *   The resolved jurisdiction group ID, or NULL when no single context is
   *   available.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Matching taxonomy terms.
   */
  public function loadByProperties(array $properties, ?int $jurisdictionId): array {
    if (!$this->canScope($jurisdictionId)) {
      // This call is intentionally byte-equivalent to the legacy lookup.
      return $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties($properties);
    }

    $rootJurisdictionId = $this->hierarchyResolver
      ->getRootJurisdictionId($jurisdictionId);
    if ($rootJurisdictionId === NULL || $rootJurisdictionId <= 0) {
      return [];
    }

    $terms = $this->loadRootTerms($properties, $rootJurisdictionId);
    $selectedIds = $this->getSelectedTermIds($jurisdictionId);
    if ($selectedIds === NULL) {
      return $terms;
    }

    $selected = array_fill_keys($selectedIds, TRUE);
    return array_filter(
      $terms,
      static fn(EntityInterface $term): bool => isset($selected[(int) $term->id()]),
    );
  }

  /**
   * Loads the complete root-owned tree pool, ignoring group selection.
   *
   * @param array<string, mixed> $properties
   *   Properties accepted by taxonomy term storage.
   * @param int|null $jurisdictionId
   *   A jurisdiction in the requested tree.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Matching taxonomy terms.
   */
  public function loadTreePoolByProperties(array $properties, ?int $jurisdictionId): array {
    if (!$this->canScope($jurisdictionId)) {
      return $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties($properties);
    }

    $rootJurisdictionId = $this->hierarchyResolver
      ->getRootJurisdictionId($jurisdictionId);
    if ($rootJurisdictionId === NULL || $rootJurisdictionId <= 0) {
      return [];
    }

    return $this->loadRootTerms($properties, $rootJurisdictionId);
  }

  /**
   * Checks whether a status lookup can be safely jurisdiction-scoped.
   *
   * @param int|null $jurisdictionId
   *   The resolved jurisdiction group ID.
   *
   * @return bool
   *   TRUE when both the context and taxonomy field storage exist.
   */
  public function canScope(?int $jurisdictionId): bool {
    if ($jurisdictionId === NULL || $jurisdictionId <= 0) {
      return FALSE;
    }

    $definitions = $this->entityFieldManager
      ->getFieldStorageDefinitions('taxonomy_term');
    return isset($definitions['field_jurisdiction']);
  }

  /**
   * Loads and defensively validates terms owned by one root jurisdiction.
   *
   * @param array<string, mixed> $properties
   *   Taxonomy term lookup properties.
   * @param int $rootJurisdictionId
   *   Root jurisdiction group ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Root-owned service status terms.
   */
  protected function loadRootTerms(array $properties, int $rootJurisdictionId): array {
    $properties['field_jurisdiction'] = $rootJurisdictionId;
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties($properties);

    return array_filter(
      $terms,
      fn(EntityInterface $term): bool => $this->belongsToRoot($term, $rootJurisdictionId),
    );
  }

  /**
   * Returns an explicit selection or NULL when the jurisdiction inherits.
   *
   * @param int $jurisdictionId
   *   Requested jurisdiction group ID.
   *
   * @return int[]|null
   *   Selected term IDs, or NULL for the full root pool.
   */
  protected function getSelectedTermIds(int $jurisdictionId): ?array {
    $group = $this->entityTypeManager
      ->getStorage('group')
      ->load($jurisdictionId);
    if (!$group instanceof GroupInterface
      || !$group->hasField('field_service_statuses')
      || $group->get('field_service_statuses')->isEmpty()) {
      return NULL;
    }

    $termIds = [];
    foreach ($group->get('field_service_statuses')->getValue() as $item) {
      $termId = (int) ($item['target_id'] ?? 0);
      if ($termId > 0) {
        $termIds[$termId] = $termId;
      }
    }

    return array_values($termIds);
  }

  /**
   * Checks the bundle and ownership stored on a selected status term.
   *
   * @param \Drupal\Core\Entity\EntityInterface $term
   *   Candidate status term.
   * @param int $rootJurisdictionId
   *   Expected root jurisdiction group ID.
   *
   * @return bool
   *   TRUE when the term is a service status owned by the expected root.
   */
  protected function belongsToRoot(EntityInterface $term, int $rootJurisdictionId): bool {
    if (!$term instanceof FieldableEntityInterface
      || $term->bundle() !== 'service_status'
      || !$term->hasField('field_jurisdiction')
      || $term->get('field_jurisdiction')->isEmpty()) {
      return FALSE;
    }

    $jurisdiction = $term->get('field_jurisdiction')->getValue();
    return (int) ($jurisdiction[0]['target_id'] ?? 0) === $rootJurisdictionId;
  }

}
