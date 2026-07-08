<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Resolves organisation hierarchy relationships.
 *
 * Thin facade over ParentTreeResolver for the fixed org axis.
 *
 * @internal The org axis MUST NEVER be used to scope read access to
 *   service_request data or other PII. Routing, escalation and rollup only;
 *   see OrgHierarchyResolverInterface for the full policy note.
 */
class OrgHierarchyResolver implements OrgHierarchyResolverInterface {

  /**
   * The generic parent-tree resolver.
   *
   * @var \Drupal\markaspot_group\Service\ParentTreeResolver
   */
  protected ParentTreeResolver $treeResolver;

  /**
   * Constructs an OrgHierarchyResolver.
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
    $logger = $loggerFactory->get('markaspot_group');
    $this->treeResolver = new ParentTreeResolver(
      $entityTypeManager,
      $database,
      $logger,
      'field_parent_org',
      static fn(mixed $group): bool => self::isOrgGroup($group),
      'organisation',
      ParentTreeResolver::ROOT_FAIL_CLOSED,
      TRUE,
      static fn(mixed $parent, mixed $child): bool => self::isSameJurisdictionOrgEdge($parent, $child),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRootOrgId(int $groupId): ?int {
    return $this->treeResolver->getRootId(
      $groupId,
      'resolve root organisation',
      'resolve parent organisation',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescendantIds(int $groupId): array {
    return $this->treeResolver->getDescendantIds(
      $groupId,
      'load descendant organisations',
      'load child organisation',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getAncestorIds(int $groupId): array {
    return $this->treeResolver->getAncestorIds(
      $groupId,
      'load ancestor organisations',
      'resolve parent organisation',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getChildIds(int $groupId): array {
    return $this->treeResolver->getChildIds(
      $groupId,
      'load child organisations',
      'load child organisation',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isChildOrg(int $groupId): bool {
    return $this->treeResolver->hasValidParent(
      $groupId,
      'check child organisation',
      'resolve parent organisation',
    );
  }

  /**
   * Checks whether a group uses the org type.
   *
   * @param mixed $group
   *   The candidate group entity.
   *
   * @return bool
   *   TRUE when the group bundle is org.
   */
  protected static function isOrgGroup(mixed $group): bool {
    return is_object($group)
      && method_exists($group, 'bundle')
      && $group->bundle() === 'org';
  }

  /**
   * Checks whether an org parent-child edge stays in one jurisdiction.
   *
   * @param mixed $parent
   *   The candidate parent group entity.
   * @param mixed $child
   *   The candidate child group entity.
   *
   * @return bool
   *   TRUE when parent and child both reference the same jurisdiction.
   */
  protected static function isSameJurisdictionOrgEdge(mixed $parent, mixed $child): bool {
    $parentJurisdictionId = self::getJurisdictionId($parent);
    $childJurisdictionId = self::getJurisdictionId($child);

    return $parentJurisdictionId !== NULL
      && $parentJurisdictionId === $childJurisdictionId;
  }

  /**
   * Gets an org group's jurisdiction ID.
   *
   * @param mixed $group
   *   The candidate group entity.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL when unavailable.
   */
  protected static function getJurisdictionId(mixed $group): ?int {
    if (!is_object($group)
      || !method_exists($group, 'hasField')
      || !method_exists($group, 'get')
      || !$group->hasField('field_jurisdiction')
    ) {
      return NULL;
    }

    $field = $group->get('field_jurisdiction');
    if (!is_object($field) || !method_exists($field, 'isEmpty') || $field->isEmpty()) {
      return NULL;
    }

    if (method_exists($field, '__get')) {
      $targetId = $field->__get('target_id');
      return $targetId === NULL ? NULL : (int) $targetId;
    }

    return isset($field->target_id) ? (int) $field->target_id : NULL;
  }

}
