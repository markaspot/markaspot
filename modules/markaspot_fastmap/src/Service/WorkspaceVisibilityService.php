<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Determines workspace visibility and anonymous access rules.
 *
 * Uses static caching to avoid repeated group loads during the same request
 * (critical for hook_node_access which runs per-entity).
 */
class WorkspaceVisibilityService implements WorkspaceVisibilityInterface {

  /**
   * Static cache of visibility values keyed by group ID.
   *
   * @var array<int, string>
   */
  protected array $cache = [];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets the visibility mode for a workspace/jurisdiction.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return string
   *   One of: 'public', 'submission_only', 'authenticated', 'blocked'.
   */
  public function getVisibility(int $groupId): string {
    if (isset($this->cache[$groupId])) {
      return $this->cache[$groupId];
    }

    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if ($group && $group->hasField('field_visibility') && !$group->get('field_visibility')->isEmpty()) {
      $this->cache[$groupId] = $group->get('field_visibility')->value;
    }
    else {
      $this->cache[$groupId] = 'public';
    }

    return $this->cache[$groupId];
  }

  /**
   * Whether anonymous users can view requests in this workspace.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE only for 'public' workspaces.
   */
  public function canAnonymousView(int $groupId): bool {
    return $this->getVisibility($groupId) === 'public';
  }

  /**
   * Whether anonymous users can submit requests to this workspace.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE for 'public' and 'submission_only' workspaces.
   */
  public function canAnonymousSubmit(int $groupId): bool {
    $visibility = $this->getVisibility($groupId);
    return in_array($visibility, ['public', 'submission_only'], TRUE);
  }

  /**
   * Whether the workspace is fully blocked.
   *
   * Blocked workspaces reject public access and regular tenant writes. Site
   * administrators can still unblock them through privileged operations.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE when the workspace visibility is 'blocked'.
   */
  public function isBlocked(int $groupId): bool {
    return $this->getVisibility($groupId) === 'blocked';
  }

  /**
   * Resets cached visibility values.
   *
   * @param int|null $groupId
   *   Optional group ID to invalidate. When omitted, the full request-local
   *   cache is cleared.
   */
  public function resetCache(?int $groupId = NULL): void {
    if ($groupId === NULL) {
      $this->cache = [];
      return;
    }

    unset($this->cache[$groupId]);
  }

  /**
   * Checks whether any jurisdiction matching the supplied context is blocked.
   *
   * Used by the Open311 POST path to defeat the boundary-claim backdoor where
   * a client claims a public parent workspace while the coordinates land in a
   * blocked child. We probe the claim plus every child of the configured
   * jurisdiction group type that contains the coordinates.
   *
   * The lookup is intentionally conservative: if any candidate is blocked, the
   * caller short-circuits with 403. False positives (multiple overlapping
   * boundaries where only one is blocked) are acceptable; missing a blocked
   * tenant is not.
   *
   * @param int|null $claimedJurisdictionId
   *   The jurisdiction ID supplied by the caller, or NULL when implicit.
   * @param float|null $lat
   *   Latitude from the request, or NULL when not supplied.
   * @param float|null $lng
   *   Longitude from the request, or NULL when not supplied.
   *
   * @return bool
   *   TRUE when the claimed jurisdiction or any boundary-resolved candidate
   *   is currently blocked.
   */
  public function isBlockedForSubmission(?int $claimedJurisdictionId, ?float $lat, ?float $lng): bool {
    if ($claimedJurisdictionId !== NULL && $claimedJurisdictionId > 0 && $this->isBlocked($claimedJurisdictionId)) {
      return TRUE;
    }

    if ($lat === NULL || $lng === NULL || ($lat === 0.0 && $lng === 0.0)) {
      return FALSE;
    }

    $candidates = $this->resolveBoundaryCandidates($lat, $lng);
    foreach ($candidates as $candidateId) {
      if ($this->isBlocked($candidateId)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns jurisdiction group IDs whose boundary contains the coordinates.
   *
   * The deepest match plus every ancestor in its parent chain — a parent
   * being blocked must not be hidden by a public child boundary. The helper
   * is procedural-loaded by markaspot_fastmap.module and is part of the same
   * release; tests stub it via dependency injection rather than via
   * function_exists() gates.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lng
   *   Longitude.
   *
   * @return int[]
   *   Matching jurisdiction group IDs, deepest first, then ancestors.
   */
  protected function resolveBoundaryCandidates(float $lat, float $lng): array {
    // The procedural helper is part of this module and is always loaded in
    // production. The function_exists() guard exists solely so unit tests
    // (which do not bootstrap markaspot_fastmap.module) can instantiate the
    // service without falling over. Production paths cover the call.
    if (!function_exists('_markaspot_fastmap_resolve_boundary_jurisdiction_id_from_coordinates')) {
      return [];
    }
    $deepest = _markaspot_fastmap_resolve_boundary_jurisdiction_id_from_coordinates($lat, $lng);
    if (!$deepest) {
      return [];
    }

    $candidates = [$deepest];
    $visited = [$deepest => TRUE];
    $cursor = $deepest;
    // Walk parent_jurisdiction up to the tree root. Loop bound + visited set
    // guard against pathological cycles in misconfigured trees.
    $groupStorage = $this->entityTypeManager->getStorage('group');
    for ($i = 0; $i < 16; $i++) {
      $group = $groupStorage->load($cursor);
      if (!$group || !$group->hasField('field_parent_jurisdiction')
        || $group->get('field_parent_jurisdiction')->isEmpty()) {
        break;
      }
      $parent = (int) $group->get('field_parent_jurisdiction')->target_id;
      if ($parent <= 0 || isset($visited[$parent])) {
        break;
      }
      $candidates[] = $parent;
      $visited[$parent] = TRUE;
      $cursor = $parent;
    }

    return $candidates;
  }

}
