<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

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
   * Constructs a JurisdictionHierarchyResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->logger = $loggerFactory->get('markaspot_group');
  }

  /**
   * {@inheritdoc}
   */
  public function getRootJurisdictionId(int $groupId): int {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group || $group->bundle() !== 'jur') {
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
        return $currentId;
      }
      $visited[] = $currentId;

      $parentId = (int) $group->get('field_parent_jurisdiction')->target_id;
      $parent = $this->entityTypeManager->getStorage('group')->load($parentId);

      if (!$parent || $parent->bundle() !== 'jur') {
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
    if (!$group || $group->bundle() !== 'jur') {
      return FALSE;
    }
    return $group->hasField('field_parent_jurisdiction')
      && !$group->get('field_parent_jurisdiction')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescendantIds(int $groupId): array {
    $ids = [$groupId];
    $visited = [$groupId];

    $children = $this->database->select('group__field_parent_jurisdiction', 'p')
      ->fields('p', ['entity_id'])
      ->condition('field_parent_jurisdiction_target_id', $groupId)
      ->execute()
      ->fetchCol();

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
      $ids = array_merge($ids, $this->getDescendantIdsRecursive($childId, $visited));
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
   *
   * @return array
   *   Array of descendant jurisdiction IDs.
   */
  protected function getDescendantIdsRecursive(int $groupId, array &$visited): array {
    $ids = [$groupId];

    $children = $this->database->select('group__field_parent_jurisdiction', 'p')
      ->fields('p', ['entity_id'])
      ->condition('field_parent_jurisdiction_target_id', $groupId)
      ->execute()
      ->fetchCol();

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
      $ids = array_merge($ids, $this->getDescendantIdsRecursive($childId, $visited));
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getTermJurisdictionIds(int $groupId): array {
    $rootId = $this->getRootJurisdictionId($groupId);
    return $this->getDescendantIds($rootId);
  }

  /**
   * {@inheritdoc}
   */
  public function getNodeIdsInJurisdiction(int $groupId): array {
    // Validate that the group is a jurisdiction type.
    // Prevents cross-type data leakage (e.g. org group ID returning org nodes).
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group || $group->bundle() !== 'jur') {
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
    if (!$group || $group->bundle() !== 'jur') {
      return NULL;
    }

    // Root jurisdictions always show all categories.
    if (!$group->hasField('field_parent_jurisdiction')
        || $group->get('field_parent_jurisdiction')->isEmpty()) {
      return NULL;
    }

    // Child jurisdiction without category restrictions inherits all from root.
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

}
