<?php

namespace Drupal\markaspot_group\Service;

/**
 * Resolves jurisdiction hierarchy relationships.
 *
 * Provides methods for traversing the jurisdiction group hierarchy
 * (field_parent_jurisdiction) both upward (child -> root) and downward
 * (root -> descendants). All traversals include cycle guards.
 */
interface JurisdictionHierarchyResolverInterface {

  /**
   * Finds the root jurisdiction by traversing field_parent_jurisdiction upward.
   *
   * @param int $groupId
   *   A jurisdiction group ID (may be child or root).
   *
   * @return int
   *   The group ID of the root jurisdiction (unchanged if already root).
   */
  public function getRootJurisdictionId(int $groupId): int;

  /**
   * Checks if a jurisdiction is a child (has a parent jurisdiction).
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE if the jurisdiction has a parent, FALSE if it's a root.
   */
  public function isChildJurisdiction(int $groupId): bool;

  /**
   * Returns the IDs of all root jurisdictions.
   *
   * A root is a group of bundle 'jur' whose field_parent_jurisdiction is
   * empty. Uses a single raw SQL query for performance, avoiding full entity
   * loads, to stay safe during post_update/install hooks where the entity
   * query API may not be fully wired.
   *
   * @return int[]
   *   Array of root group IDs sorted ascending. Empty array if no jur groups
   *   exist yet (e.g. during the migrate-to-cloud window before
   *   jurisdiction/setup.sh has run).
   */
  public function getAllRootJurisdictionIds(): array;

  /**
   * Gets all descendant jurisdiction IDs (including the given ID).
   *
   * Traverses field_parent_jurisdiction downward via raw SQL for performance.
   *
   * @param int $groupId
   *   The parent group ID.
   *
   * @return array
   *   Array of jurisdiction IDs (the given ID plus all descendants).
   */
  public function getDescendantIds(int $groupId): array;

  /**
   * Gets jurisdiction IDs for taxonomy term filtering.
   *
   * Resolves child -> root first, then returns the full tree (root + all
   * descendants). This ensures child jurisdictions find terms owned by
   * their root parent.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return array
   *   Array of jurisdiction IDs covering the entire tree.
   */
  public function getTermJurisdictionIds(int $groupId): array;

  /**
   * Gets node IDs belonging to a jurisdiction and its descendants.
   *
   * Validates that the group is a 'jur' bundle before querying. Returns empty
   * array for non-jurisdiction groups to prevent cross-type data leakage.
   * Queries group_relationship_field_data for group_node:service_request
   * relationships across the jurisdiction subtree.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return array<int>
   *   Array of integer node IDs, or empty array if none found or invalid group.
   */
  public function getNodeIdsInJurisdiction(int $groupId): array;

  /**
   * Gets allowed service category term IDs for a jurisdiction.
   *
   * Returns the term IDs from field_service_categories if the jurisdiction
   * is a child with explicit category restrictions. Root jurisdictions and
   * jurisdictions without restrictions return NULL (meaning "show all").
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return int[]|null
   *   Array of taxonomy term IDs if restricted, or NULL if unrestricted
   *   (root jurisdiction, missing field, or empty field).
   */
  public function getAllowedCategoryIds(int $groupId): ?array;

}
