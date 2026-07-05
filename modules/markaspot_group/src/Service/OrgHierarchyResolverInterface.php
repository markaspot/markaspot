<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

/**
 * Resolves organisation hierarchy relationships for routing and escalation.
 *
 * Provides fail-closed traversal over org groups linked through
 * field_parent_org, scoped to a single jurisdiction (parent/child edges
 * crossing jurisdictions are rejected).
 *
 * @internal The org axis MUST NEVER be used to scope read access to
 *   service_request data or other PII. It exists solely for routing,
 *   escalation, and organisational rollup. Access scoping is the sole
 *   responsibility of JurisdictionHierarchyResolverInterface.
 */
interface OrgHierarchyResolverInterface {

  /**
   * Finds the root organisation by traversing field_parent_org upward.
   *
   * @param int $groupId
   *   An org group ID.
   *
   * @return int|null
   *   The root org group ID, or NULL when the input or traversal is invalid.
   */
  public function getRootOrgId(int $groupId): ?int;

  /**
   * Gets all descendant org IDs, including the given ID.
   *
   * Unlike the identically named jurisdiction-axis method, this result MUST
   * NOT be used as an access filter on service_request or other PII queries.
   * Routing, escalation and rollup only.
   *
   * @param int $groupId
   *   The parent org group ID.
   *
   * @return int[]
   *   The given org ID plus all valid descendant org IDs.
   */
  public function getDescendantIds(int $groupId): array;

  /**
   * Gets ancestor org IDs, nearest parent first.
   *
   * Unlike the jurisdiction axis, ancestor results MUST NOT widen read
   * access; they exist to resolve escalation and routing targets.
   *
   * @param int $groupId
   *   The org group ID.
   *
   * @return int[]
   *   Ancestor org IDs excluding the given ID, or an empty array on failure.
   */
  public function getAncestorIds(int $groupId): array;

  /**
   * Checks whether an org group has a parent org reference.
   *
   * @param int $groupId
   *   The org group ID.
   *
   * @return bool
   *   TRUE when the org group has a parent reference, otherwise FALSE.
   */
  public function isChildOrg(int $groupId): bool;

}
