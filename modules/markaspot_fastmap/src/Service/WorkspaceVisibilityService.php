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
class WorkspaceVisibilityService {

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
   *   One of: 'public', 'submission_only', 'authenticated'.
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
   * Resets the static cache (useful in tests or after entity saves).
   */
  public function resetCache(): void {
    $this->cache = [];
  }

}
