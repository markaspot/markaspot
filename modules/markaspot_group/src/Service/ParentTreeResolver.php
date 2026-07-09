<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves generic parent-tree relationships for group hierarchy axes.
 *
 * The resolver owns the shared traversal mechanics for self-referencing group
 * trees. Axis-specific facades provide the parent field, accepted bundle
 * predicate, fail mode, and human-readable log noun.
 */
class ParentTreeResolver {

  /**
   * Root walk fail mode that returns the original or current group ID.
   */
  public const ROOT_FAIL_LEGACY_SELF = 'legacy_self';

  /**
   * Root walk fail mode that returns NULL on invalid input or traversal.
   */
  public const ROOT_FAIL_CLOSED = 'closed';

  /**
   * Maximum accepted parent-tree depth.
   */
  public const MAX_HIERARCHY_DEPTH = 50;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The parent entity reference field name.
   *
   * @var string
   */
  protected string $parentFieldName;

  /**
   * The parent field storage table name.
   *
   * @var string
   */
  protected string $parentTableName;

  /**
   * The parent target ID column name.
   *
   * @var string
   */
  protected string $parentColumnName;

  /**
   * Predicate that accepts groups belonging to this tree axis.
   *
   * @var \Closure
   */
  protected \Closure $bundleAccepts;

  /**
   * Human-readable noun for log messages.
   *
   * @var string
   */
  protected string $noun;

  /**
   * Fail mode used by upward root walks.
   *
   * @var string
   */
  protected string $rootFailMode;

  /**
   * Whether missing root input groups are logged as warnings.
   *
   * @var bool
   */
  protected bool $warnMissingGroups;

  /**
   * Predicate that accepts parent-child edges in this tree axis.
   *
   * @var \Closure|null
   */
  protected ?\Closure $edgeAccepts;

  /**
   * Raw child group IDs keyed by parent group ID.
   *
   * @var array<int, int[]>
   */
  private array $childIdsByGroupId = [];

  /**
   * Constructs a ParentTreeResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param string $parentFieldName
   *   The parent entity reference field machine name.
   * @param callable $bundleAccepts
   *   Callback receiving a loaded group entity and returning TRUE when it
   *   belongs to this hierarchy axis.
   * @param string $noun
   *   Human-readable noun used in log messages.
   * @param string $rootFailMode
   *   One of ROOT_FAIL_LEGACY_SELF or ROOT_FAIL_CLOSED.
   * @param bool $warnMissingGroups
   *   TRUE to log missing root input groups as warnings.
   * @param callable|null $edgeAccepts
   *   Optional callback receiving parent and child group entities and returning
   *   TRUE when the edge is valid for this hierarchy axis.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    Connection $database,
    LoggerInterface $logger,
    string $parentFieldName,
    callable $bundleAccepts,
    string $noun,
    string $rootFailMode = self::ROOT_FAIL_CLOSED,
    bool $warnMissingGroups = TRUE,
    ?callable $edgeAccepts = NULL,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->logger = $logger;
    $this->parentFieldName = $parentFieldName;
    $this->parentTableName = 'group__' . $parentFieldName;
    $this->parentColumnName = $parentFieldName . '_target_id';
    $this->bundleAccepts = \Closure::fromCallable($bundleAccepts);
    $this->noun = $noun;
    $this->rootFailMode = $rootFailMode;
    $this->warnMissingGroups = $warnMissingGroups;
    $this->edgeAccepts = $edgeAccepts === NULL ? NULL : \Closure::fromCallable($edgeAccepts);
  }

  /**
   * Finds the tree root by traversing the configured parent field upward.
   *
   * @param int $groupId
   *   The starting group ID.
   * @param string $rootWrongBundleOperation
   *   Log operation for a wrong-bundle starting group.
   * @param string $parentWrongBundleOperation
   *   Log operation for a wrong-bundle parent group.
   *
   * @return int|null
   *   The resolved root ID, legacy fallback ID, or NULL on fail-closed
   *   traversal failures.
   */
  public function getRootId(
    int $groupId,
    string $rootWrongBundleOperation,
    string $parentWrongBundleOperation,
  ): ?int {
    $group = $this->loadGroup($groupId);
    if (!$group) {
      $this->logMissingGroup($rootWrongBundleOperation, $groupId);
      return $this->legacySelfOrNull($groupId);
    }
    if (!$this->isAcceptedBundle($group)) {
      $this->logWrongBundle($rootWrongBundleOperation, $groupId, $this->getBundle($group));
      return $this->legacySelfOrNull($groupId);
    }

    $visited = [];
    while (($parentId = $this->getParentId($group)) !== NULL) {
      $currentId = (int) $group->id();

      if (in_array($currentId, $visited, TRUE)) {
        $this->logger->error(
          sprintf('Circular parent reference detected at %s @id.', $this->noun),
          ['@id' => $currentId]
        );
        return NULL;
      }
      $visited[] = $currentId;

      if (count($visited) > self::MAX_HIERARCHY_DEPTH) {
        $this->logger->error(
          sprintf('Maximum parent hierarchy depth exceeded at %s @id.', $this->noun),
          ['@id' => $currentId]
        );
        return NULL;
      }

      $parent = $this->loadGroup($parentId);
      if (!$parent) {
        $this->logMissingGroup($parentWrongBundleOperation, $parentId);
        return $this->legacySelfOrNull($currentId);
      }
      if (!$this->isAcceptedBundle($parent)) {
        $this->logWrongBundle($parentWrongBundleOperation, $parentId, $this->getBundle($parent));
        return $this->legacySelfOrNull($currentId);
      }
      if (!$this->isAcceptedEdge($parent, $group)) {
        $this->logRejectedEdge($parentWrongBundleOperation, (int) $parent->id(), $currentId);
        return $this->legacySelfOrNull($currentId);
      }

      $group = $parent;
    }

    return (int) $group->id();
  }

  /**
   * Checks whether a group has a configured parent reference.
   *
   * @param int $groupId
   *   The group ID.
   * @param string $wrongBundleOperation
   *   Log operation for a wrong-bundle group.
   *
   * @return bool
   *   TRUE when the group exists, belongs to the axis, and has a parent field
   *   value.
   */
  public function hasParent(int $groupId, string $wrongBundleOperation): bool {
    $group = $this->loadGroup($groupId);
    if (!$group) {
      $this->logMissingGroup($wrongBundleOperation, $groupId);
      return FALSE;
    }
    if (!$this->isAcceptedBundle($group)) {
      $this->logWrongBundle($wrongBundleOperation, $groupId, $this->getBundle($group));
      return FALSE;
    }

    return $this->getParentId($group) !== NULL;
  }

  /**
   * Checks whether a group has a valid configured parent reference.
   *
   * @param int $groupId
   *   The group ID.
   * @param string $wrongBundleOperation
   *   Log operation for a wrong-bundle group.
   * @param string $parentWrongBundleOperation
   *   Log operation for invalid parent groups.
   *
   * @return bool
   *   TRUE when the parent exists, belongs to the axis, and passes the edge
   *   policy.
   */
  public function hasValidParent(
    int $groupId,
    string $wrongBundleOperation,
    string $parentWrongBundleOperation,
  ): bool {
    $group = $this->loadGroup($groupId);
    if (!$group) {
      $this->logMissingGroup($wrongBundleOperation, $groupId);
      return FALSE;
    }
    if (!$this->isAcceptedBundle($group)) {
      $this->logWrongBundle($wrongBundleOperation, $groupId, $this->getBundle($group));
      return FALSE;
    }

    $parentId = $this->getParentId($group);
    if ($parentId === NULL) {
      return FALSE;
    }

    $parent = $this->loadGroup($parentId);
    if (!$parent) {
      $this->logMissingGroup($parentWrongBundleOperation, $parentId);
      return FALSE;
    }
    if (!$this->isAcceptedBundle($parent)) {
      $this->logWrongBundle($parentWrongBundleOperation, $parentId, $this->getBundle($parent));
      return FALSE;
    }
    if (!$this->isAcceptedEdge($parent, $group)) {
      $this->logRejectedEdge($parentWrongBundleOperation, (int) $parent->id(), (int) $group->id());
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Returns IDs of all root groups for the given bundle.
   *
   * @param string $bundle
   *   The group bundle machine name.
   *
   * @return int[]
   *   Root group IDs sorted ascending.
   */
  public function getAllRootIds(string $bundle): array {
    $ids = $this->database->select('groups_field_data', 'g')
      ->distinct()
      ->fields('g', ['id'])
      ->condition('g.type', $bundle)
      ->notExists(
        $this->database->select($this->parentTableName, 'p')
          ->fields('p', ['entity_id'])
          ->where('p.entity_id = g.id')
          ->condition('p.deleted', 0)
      )
      ->orderBy('g.id', 'ASC')
      ->execute()
      ->fetchCol();

    return array_map('intval', $ids);
  }

  /**
   * Gets all descendant IDs, including the starting group ID.
   *
   * @param int $groupId
   *   The parent group ID.
   * @param string $rootWrongBundleOperation
   *   Log operation for a wrong-bundle starting group.
   * @param string $childWrongBundleOperation
   *   Log operation for wrong-bundle child groups.
   *
   * @return int[]
   *   IDs in depth-first traversal order.
   */
  public function getDescendantIds(
    int $groupId,
    string $rootWrongBundleOperation,
    string $childWrongBundleOperation,
  ): array {
    $group = $this->loadGroup($groupId);
    if (!$group) {
      $this->logMissingGroup($rootWrongBundleOperation, $groupId);
      return [];
    }
    if (!$this->isAcceptedBundle($group)) {
      $this->logWrongBundle($rootWrongBundleOperation, $groupId, $this->getBundle($group));
      return [];
    }

    $ids = [$groupId];
    $visited = [$groupId];

    $children = $this->loadAcceptedChildGroups(
      $this->loadChildIds($groupId),
      $childWrongBundleOperation,
      $group,
    );
    foreach ($children as $childId => $child) {
      if (in_array($childId, $visited, TRUE)) {
        $this->logger->error(
          sprintf('Circular child reference detected at %s @id.', $this->noun),
          ['@id' => $childId]
        );
        continue;
      }
      $visited[] = $childId;
      $ids = array_merge($ids, $this->getDescendantIdsRecursive($childId, $child, $visited, $childWrongBundleOperation, 1));
    }

    return $ids;
  }

  /**
   * Gets ancestor IDs, nearest parent first, excluding the starting group.
   *
   * @param int $groupId
   *   The starting group ID.
   * @param string $rootWrongBundleOperation
   *   Log operation for a wrong-bundle starting group.
   * @param string $parentWrongBundleOperation
   *   Log operation for wrong-bundle parent groups.
   *
   * @return int[]
   *   Ancestor IDs, or an empty array on any invalid traversal.
   */
  public function getAncestorIds(
    int $groupId,
    string $rootWrongBundleOperation,
    string $parentWrongBundleOperation,
  ): array {
    $group = $this->loadGroup($groupId);
    if (!$group) {
      $this->logMissingGroup($rootWrongBundleOperation, $groupId);
      return [];
    }
    if (!$this->isAcceptedBundle($group)) {
      $this->logWrongBundle($rootWrongBundleOperation, $groupId, $this->getBundle($group));
      return [];
    }

    $ancestors = [];
    $visited = [];
    while (($parentId = $this->getParentId($group)) !== NULL) {
      $currentId = (int) $group->id();

      if (in_array($currentId, $visited, TRUE)) {
        $this->logger->error(
          sprintf('Circular parent reference detected at %s @id.', $this->noun),
          ['@id' => $currentId]
        );
        return [];
      }
      $visited[] = $currentId;

      if (count($visited) > self::MAX_HIERARCHY_DEPTH) {
        $this->logger->error(
          sprintf('Maximum parent hierarchy depth exceeded at %s @id.', $this->noun),
          ['@id' => $currentId]
        );
        return [];
      }

      $parent = $this->loadGroup($parentId);
      if (!$parent) {
        $this->logMissingGroup($parentWrongBundleOperation, $parentId);
        return [];
      }
      if (!$this->isAcceptedBundle($parent)) {
        $this->logWrongBundle($parentWrongBundleOperation, $parentId, $this->getBundle($parent));
        return [];
      }
      if (!$this->isAcceptedEdge($parent, $group)) {
        $this->logRejectedEdge($parentWrongBundleOperation, (int) $parent->id(), $currentId);
        return [];
      }

      $ancestors[] = $parentId;
      $group = $parent;
    }

    return $ancestors;
  }

  /**
   * Gets direct child IDs for the configured parent field.
   *
   * @param int $groupId
   *   The parent group ID.
   * @param string $rootWrongBundleOperation
   *   Log operation for a wrong-bundle starting group.
   * @param string $childWrongBundleOperation
   *   Log operation for wrong-bundle child groups.
   *
   * @return int[]
   *   Direct child IDs sorted by the field table order.
   */
  public function getChildIds(
    int $groupId,
    string $rootWrongBundleOperation,
    string $childWrongBundleOperation,
  ): array {
    $group = $this->loadGroup($groupId);
    if (!$group) {
      $this->logMissingGroup($rootWrongBundleOperation, $groupId);
      return [];
    }
    if (!$this->isAcceptedBundle($group)) {
      $this->logWrongBundle($rootWrongBundleOperation, $groupId, $this->getBundle($group));
      return [];
    }

    return array_keys($this->loadAcceptedChildGroups(
      $this->loadChildIds($groupId),
      $childWrongBundleOperation,
      $group,
    ));
  }

  /**
   * Recursively collects descendant IDs with cycle and depth guards.
   *
   * @param int $groupId
   *   The parent group ID.
   * @param mixed $group
   *   The loaded parent group entity.
   * @param int[] $visited
   *   Reference to visited IDs for cycle detection.
   * @param string $childWrongBundleOperation
   *   Log operation for wrong-bundle child groups.
   * @param int $depth
   *   The current traversal depth below the original parent.
   *
   * @return int[]
   *   Descendant IDs below the current group.
   */
  protected function getDescendantIdsRecursive(
    int $groupId,
    mixed $group,
    array &$visited,
    string $childWrongBundleOperation,
    int $depth,
  ): array {
    if ($depth > self::MAX_HIERARCHY_DEPTH) {
      $this->logger->error(
        sprintf('Maximum child hierarchy depth exceeded at %s @id.', $this->noun),
        ['@id' => $groupId]
      );
      return [];
    }

    $ids = [$groupId];

    $children = $this->loadAcceptedChildGroups(
      $this->loadChildIds($groupId),
      $childWrongBundleOperation,
      $group,
    );
    foreach ($children as $childId => $child) {
      if (in_array($childId, $visited, TRUE)) {
        $this->logger->error(
          sprintf('Circular child reference detected at %s @id.', $this->noun),
          ['@id' => $childId]
        );
        continue;
      }
      $visited[] = $childId;
      $ids = array_merge($ids, $this->getDescendantIdsRecursive($childId, $child, $visited, $childWrongBundleOperation, $depth + 1));
    }

    return $ids;
  }

  /**
   * Loads raw child IDs from the configured parent field table.
   *
   * @param int $groupId
   *   The parent group ID.
   *
   * @return int[]
   *   Raw child group IDs.
   */
  protected function loadChildIds(int $groupId): array {
    if (array_key_exists($groupId, $this->childIdsByGroupId)) {
      return $this->childIdsByGroupId[$groupId];
    }

    $children = $this->database->select($this->parentTableName, 'p')
      ->fields('p', ['entity_id'])
      ->condition($this->parentColumnName, $groupId)
      ->execute()
      ->fetchCol();

    return $this->childIdsByGroupId[$groupId] = array_map('intval', $children);
  }

  /**
   * Loads child groups that resolve to accepted child groups.
   *
   * @param int[] $childIds
   *   Raw child group IDs from the parent field table.
   * @param string $wrongBundleOperation
   *   Log operation for wrong-bundle child groups.
   * @param mixed $parent
   *   The parent group entity.
   *
   * @return array<int, mixed>
   *   Accepted child groups keyed by ID.
   */
  protected function loadAcceptedChildGroups(
    array $childIds,
    string $wrongBundleOperation,
    mixed $parent,
  ): array {
    $childIds = array_values(array_unique(array_map('intval', $childIds)));
    if ($childIds === []) {
      return [];
    }

    $children = $this->entityTypeManager->getStorage('group')->loadMultiple($childIds);
    $accepted = [];
    foreach ($childIds as $childId) {
      $child = $children[$childId] ?? NULL;
      if (!$child) {
        $this->logger->warning(
          sprintf('%s hierarchy references missing child group @id.', ucfirst($this->noun)),
          ['@id' => $childId]
        );
        continue;
      }

      if (!$this->isAcceptedBundle($child)) {
        $this->logWrongBundle($wrongBundleOperation, $childId, $this->getBundle($child));
        continue;
      }
      if (!$this->isAcceptedEdge($parent, $child)) {
        $this->logRejectedEdge($wrongBundleOperation, (int) $parent->id(), $childId);
        continue;
      }

      $accepted[$childId] = $child;
    }

    return $accepted;
  }

  /**
   * Loads a group entity by ID.
   *
   * @param int $groupId
   *   The group ID.
   *
   * @return mixed
   *   The loaded group entity or NULL.
   */
  protected function loadGroup(int $groupId): mixed {
    return $this->entityTypeManager->getStorage('group')->load($groupId);
  }

  /**
   * Reads the configured parent ID from a group.
   *
   * @param mixed $group
   *   The group entity.
   *
   * @return int|null
   *   The parent group ID, or NULL when no parent is set.
   */
  protected function getParentId(mixed $group): ?int {
    if (!is_object($group)
      || !method_exists($group, 'hasField')
      || !method_exists($group, 'get')
      || !$group->hasField($this->parentFieldName)
    ) {
      return NULL;
    }

    $field = $group->get($this->parentFieldName);
    if (!is_object($field) || !method_exists($field, 'isEmpty') || $field->isEmpty()) {
      return NULL;
    }

    if (method_exists($field, '__get')) {
      return (int) $field->__get('target_id');
    }

    return (int) $field->target_id;
  }

  /**
   * Checks whether the group belongs to this hierarchy axis.
   *
   * @param mixed $group
   *   The candidate group entity.
   *
   * @return bool
   *   TRUE when the bundle predicate accepts the group.
   */
  protected function isAcceptedBundle(mixed $group): bool {
    return (bool) ($this->bundleAccepts)($group);
  }

  /**
   * Checks whether a parent-child edge belongs to this hierarchy axis.
   *
   * @param mixed $parent
   *   The candidate parent group entity.
   * @param mixed $child
   *   The candidate child group entity.
   *
   * @return bool
   *   TRUE when no edge predicate is configured, or the predicate accepts the
   *   parent-child edge.
   */
  protected function isAcceptedEdge(mixed $parent, mixed $child): bool {
    if ($this->edgeAccepts === NULL) {
      return TRUE;
    }

    return (bool) ($this->edgeAccepts)($parent, $child);
  }

  /**
   * Logs when traversal receives a non-axis group.
   *
   * @param string $operation
   *   The operation being attempted.
   * @param int $groupId
   *   The group ID.
   * @param string $bundle
   *   The actual group bundle.
   */
  protected function logWrongBundle(string $operation, int $groupId, string $bundle): void {
    $this->logger->warning(
      sprintf('Expected %s group while trying to @operation, got @bundle group @id.', $this->noun),
      [
        '@operation' => $operation,
        '@bundle' => $bundle,
        '@id' => $groupId,
      ]
    );
  }

  /**
   * Logs when fail-closed axes receive missing groups.
   *
   * @param string $operation
   *   The operation being attempted.
   * @param int $groupId
   *   The missing group ID.
   */
  protected function logMissingGroup(string $operation, int $groupId): void {
    if (!$this->warnMissingGroups) {
      return;
    }

    $this->logger->warning(
      sprintf('Unable to @operation because %s group @id does not exist.', $this->noun),
      [
        '@operation' => $operation,
        '@id' => $groupId,
      ]
    );
  }

  /**
   * Logs when traversal rejects a parent-child edge.
   *
   * @param string $operation
   *   The operation being attempted.
   * @param int $parentId
   *   The rejected parent group ID.
   * @param int $childId
   *   The rejected child group ID.
   */
  protected function logRejectedEdge(string $operation, int $parentId, int $childId): void {
    $this->logger->warning(
      sprintf('Rejected %s hierarchy edge while trying to @operation: parent @parent_id, child @child_id.', $this->noun),
      [
        '@operation' => $operation,
        '@parent_id' => $parentId,
        '@child_id' => $childId,
      ]
    );
  }

  /**
   * Returns the appropriate root-walk failure value.
   *
   * @param int $fallbackId
   *   The ID returned by legacy-self fail mode.
   *
   * @return int|null
   *   The fallback ID or NULL.
   */
  protected function legacySelfOrNull(int $fallbackId): ?int {
    return $this->rootFailMode === self::ROOT_FAIL_LEGACY_SELF ? $fallbackId : NULL;
  }

  /**
   * Gets a group bundle string for logging.
   *
   * @param mixed $group
   *   The group entity.
   *
   * @return string
   *   The group bundle, or an empty string when unavailable.
   */
  protected function getBundle(mixed $group): string {
    if (is_object($group) && method_exists($group, 'bundle')) {
      return (string) $group->bundle();
    }

    return '';
  }

}
