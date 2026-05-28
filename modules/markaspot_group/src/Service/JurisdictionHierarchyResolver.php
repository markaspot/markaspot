<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves jurisdiction hierarchy relationships.
 *
 * Single authoritative implementation for traversing the jurisdiction group
 * hierarchy (field_parent_jurisdiction) both upward and downward. Replaces
 * duplicate implementations across markaspot_open311, markaspot_nuxt, and
 * markaspot_stats modules.
 */
class JurisdictionHierarchyResolver implements JurisdictionHierarchyResolverInterface {

  /**
   * Maximum accepted jurisdiction hierarchy depth.
   */
  protected const MAX_HIERARCHY_DEPTH = 50;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a JurisdictionHierarchyResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->logger = $loggerFactory->get('markaspot_group');
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function getRootJurisdictionId(int $groupId): ?int {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      return $groupId;
    }
    if (!$this->isJurisdictionGroup($group)) {
      $this->logWrongBundle('resolve root jurisdiction', $groupId, (string) $group->bundle());
      return $groupId;
    }

    $visited = [];
    while ($group->hasField('field_parent_jurisdiction')
           && !$group->get('field_parent_jurisdiction')->isEmpty()) {
      $currentId = (int) $group->id();

      if (in_array($currentId, $visited, TRUE)) {
        $this->logger->error(
          'Circular parent reference detected at jurisdiction @id.',
          ['@id' => $currentId]
        );
        return NULL;
      }
      $visited[] = $currentId;

      if (count($visited) > self::MAX_HIERARCHY_DEPTH) {
        $this->logger->error(
          'Maximum parent hierarchy depth exceeded at jurisdiction @id.',
          ['@id' => $currentId]
        );
        return NULL;
      }

      $parentId = (int) $group->get('field_parent_jurisdiction')->target_id;
      $parent = $this->entityTypeManager->getStorage('group')->load($parentId);

      if (!$parent) {
        return $currentId;
      }
      if (!$this->isJurisdictionGroup($parent)) {
        $this->logWrongBundle('resolve parent jurisdiction', $parentId, (string) $parent->bundle());
        return $currentId;
      }

      $group = $parent;
    }

    return (int) $group->id();
  }

  /**
   * {@inheritdoc}
   */
  public function isChildJurisdiction(int $groupId): bool {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      return FALSE;
    }
    if (!$this->isJurisdictionGroup($group)) {
      $this->logWrongBundle('check child jurisdiction', $groupId, (string) $group->bundle());
      return FALSE;
    }
    return $group->hasField('field_parent_jurisdiction')
      && !$group->get('field_parent_jurisdiction')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function getAllRootJurisdictionIds(): array {
    $ids = $this->database->select('groups_field_data', 'g')
      ->distinct()
      ->fields('g', ['id'])
      ->condition('g.type', $this->getJurisdictionGroupType())
      ->notExists(
        $this->database->select('group__field_parent_jurisdiction', 'p')
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
   * {@inheritdoc}
   */
  public function getDescendantIds(int $groupId): array {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      return [];
    }
    if (!$this->isJurisdictionGroup($group)) {
      $this->logWrongBundle('load descendant jurisdictions', $groupId, (string) $group->bundle());
      return [];
    }

    $ids = [$groupId];
    $visited = [$groupId];

    $children = $this->database->select('group__field_parent_jurisdiction', 'p')
      ->fields('p', ['entity_id'])
      ->condition('field_parent_jurisdiction_target_id', $groupId)
      ->execute()
      ->fetchCol();

    $children = $this->filterJurisdictionChildIds($children);
    foreach ($children as $childId) {
      $childId = (int) $childId;
      if (in_array($childId, $visited, TRUE)) {
        $this->logger->error(
          'Circular child reference detected at jurisdiction @id.',
          ['@id' => $childId]
        );
        continue;
      }
      $visited[] = $childId;
      $ids = array_merge($ids, $this->getDescendantIdsRecursive($childId, $visited, 1));
    }

    return $ids;
  }

  /**
   * Recursively collects descendant IDs with cycle guard.
   *
   * @param int $groupId
   *   The parent group ID.
   * @param array &$visited
   *   Reference to visited IDs for cycle detection.
   * @param int $depth
   *   The current traversal depth below the original parent.
   *
   * @return array
   *   Array of descendant jurisdiction IDs.
   */
  protected function getDescendantIdsRecursive(int $groupId, array &$visited, int $depth): array {
    if ($depth > self::MAX_HIERARCHY_DEPTH) {
      $this->logger->error(
        'Maximum child hierarchy depth exceeded at jurisdiction @id.',
        ['@id' => $groupId]
      );
      return [];
    }

    $ids = [$groupId];

    $children = $this->database->select('group__field_parent_jurisdiction', 'p')
      ->fields('p', ['entity_id'])
      ->condition('field_parent_jurisdiction_target_id', $groupId)
      ->execute()
      ->fetchCol();

    $children = $this->filterJurisdictionChildIds($children);
    foreach ($children as $childId) {
      $childId = (int) $childId;
      if (in_array($childId, $visited, TRUE)) {
        $this->logger->error(
          'Circular child reference detected at jurisdiction @id.',
          ['@id' => $childId]
        );
        continue;
      }
      $visited[] = $childId;
      $ids = array_merge($ids, $this->getDescendantIdsRecursive($childId, $visited, $depth + 1));
    }

    return $ids;
  }

  /**
   * Removes child IDs that do not resolve to jurisdiction groups.
   *
   * @param array $childIds
   *   Raw child group IDs from group__field_parent_jurisdiction.
   *
   * @return int[]
   *   Child jurisdiction IDs only.
   */
  protected function filterJurisdictionChildIds(array $childIds): array {
    $childIds = array_values(array_unique(array_map('intval', $childIds)));
    if ($childIds === []) {
      return [];
    }

    $children = $this->entityTypeManager->getStorage('group')->loadMultiple($childIds);
    $jurisdictionIds = [];
    foreach ($childIds as $childId) {
      $child = $children[$childId] ?? NULL;
      if (!$child) {
        $this->logger->warning(
          'Jurisdiction hierarchy references missing child group @id.',
          ['@id' => $childId]
        );
        continue;
      }

      if (!$this->isJurisdictionGroup($child)) {
        $this->logWrongBundle('load child jurisdiction', $childId, (string) $child->bundle());
        continue;
      }

      $jurisdictionIds[] = $childId;
    }

    return $jurisdictionIds;
  }

  /**
   * {@inheritdoc}
   */
  public function getTermJurisdictionIds(int $groupId): array {
    $rootId = $this->getRootJurisdictionId($groupId);
    if ($rootId === NULL) {
      return [];
    }
    return $this->getDescendantIds($rootId);
  }

  /**
   * {@inheritdoc}
   */
  public function getNodeIdsInJurisdiction(int $groupId): array {
    // Validate that the group is a jurisdiction type.
    // Prevents cross-type data leakage (e.g. org group ID returning org nodes).
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      return [];
    }
    if (!$this->isJurisdictionGroup($group)) {
      $this->logWrongBundle('load jurisdiction nodes', $groupId, (string) $group->bundle());
      return [];
    }

    if ($this->getRootJurisdictionId($groupId) === NULL) {
      return [];
    }

    $jurisdictionIds = $this->getDescendantIds($groupId);

    $result = $this->database->select('group_relationship_field_data', 'gr')
      ->fields('gr', ['entity_id'])
      ->condition('gid', $jurisdictionIds, 'IN')
      ->condition('plugin_id', 'group_node:service_request')
      ->execute()
      ->fetchCol();

    return array_map('intval', $result);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedCategoryIds(int $groupId): ?array {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      return NULL;
    }
    if (!$this->isJurisdictionGroup($group)) {
      $this->logWrongBundle('load allowed categories', $groupId, (string) $group->bundle());
      return NULL;
    }

    // Any jurisdiction can narrow the exposed service categories explicitly.
    if (!$group->hasField('field_service_categories')
        || $group->get('field_service_categories')->isEmpty()) {
      return NULL;
    }

    // Return the explicitly referenced category term IDs.
    $ids = [];
    foreach ($group->get('field_service_categories') as $item) {
      $ids[] = (int) $item->target_id;
    }

    return $ids;
  }

  /**
   * Logs when hierarchy code receives a non-jurisdiction group.
   */
  protected function logWrongBundle(string $operation, int $groupId, string $bundle): void {
    $this->logger->warning(
      'Expected jurisdiction group while trying to @operation, got @bundle group @id.',
      [
        '@operation' => $operation,
        '@bundle' => $bundle,
        '@id' => $groupId,
      ]
    );
  }

  /**
   * Gets the configured jurisdiction group type.
   *
   * @return string
   *   The configured jurisdiction group type machine name.
   */
  protected function getJurisdictionGroupType(): string {
    return $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type') ?: 'jur';
  }

  /**
   * Checks whether a group uses the configured jurisdiction type.
   *
   * @param mixed $group
   *   The candidate group entity.
   *
   * @return bool
   *   TRUE when the group bundle is the configured jurisdiction type.
   */
  protected function isJurisdictionGroup(mixed $group): bool {
    return is_object($group)
      && method_exists($group, 'bundle')
      && $group->bundle() === $this->getJurisdictionGroupType();
  }

}
