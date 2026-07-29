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
   * The generic parent-tree resolver.
   *
   * @var \Drupal\markaspot_group\Service\ParentTreeResolver|null
   */
  protected ?ParentTreeResolver $treeResolver = NULL;

  /**
   * Failure mode used by the underlying parent-tree resolver.
   */
  protected string $rootFailMode;

  /**
   * Whether missing hierarchy groups should be logged.
   */
  protected bool $warnMissingGroups;

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
   * @param string $rootFailMode
   *   Failure mode for unresolved hierarchy roots.
   * @param bool $warnMissingGroups
   *   Whether missing hierarchy groups should be logged.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
    ConfigFactoryInterface $configFactory,
    string $rootFailMode = ParentTreeResolver::ROOT_FAIL_LEGACY_SELF,
    bool $warnMissingGroups = FALSE,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->logger = $loggerFactory->get('markaspot_group');
    $this->configFactory = $configFactory;
    $this->rootFailMode = $rootFailMode;
    $this->warnMissingGroups = $warnMissingGroups;
  }

  /**
   * {@inheritdoc}
   */
  public function getRootJurisdictionId(int $groupId): ?int {
    return $this->getTreeResolver()->getRootId(
      $groupId,
      'resolve root jurisdiction',
      'resolve parent jurisdiction',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getAncestorIds(int $groupId): array {
    return $this->getTreeResolver()->getAncestorIds(
      $groupId,
      'load ancestor jurisdictions',
      'resolve parent jurisdiction',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isChildJurisdiction(int $groupId): bool {
    return $this->getTreeResolver()->hasParent(
      $groupId,
      'check child jurisdiction',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getAllRootJurisdictionIds(): array {
    return $this->getTreeResolver()
      ->getAllRootIds($this->getJurisdictionGroupType());
  }

  /**
   * {@inheritdoc}
   */
  public function getDescendantIds(int $groupId): array {
    return $this->getTreeResolver()->getDescendantIds(
      $groupId,
      'load descendant jurisdictions',
      'load child jurisdiction',
    );
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
  public function getScopeJurisdictionIds(int $groupId): array {
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

    return array_map('intval', $this->getDescendantIds($groupId));
  }

  /**
   * {@inheritdoc}
   */
  public function getNodeIdsInJurisdiction(int $groupId): array {
    $jurisdictionIds = $this->getScopeJurisdictionIds($groupId);
    if ($jurisdictionIds === []) {
      return [];
    }

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
   * Gets the generic parent-tree resolver for the jurisdiction axis.
   *
   * @return \Drupal\markaspot_group\Service\ParentTreeResolver
   *   The configured parent-tree resolver.
   */
  protected function getTreeResolver(): ParentTreeResolver {
    if ($this->treeResolver === NULL) {
      $this->treeResolver = new ParentTreeResolver(
        $this->entityTypeManager,
        $this->database,
        $this->logger,
        'field_parent_jurisdiction',
        fn(mixed $group): bool => $this->isJurisdictionGroup($group),
        'jurisdiction',
        $this->rootFailMode,
        $this->warnMissingGroups,
      );
    }

    return $this->treeResolver;
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
