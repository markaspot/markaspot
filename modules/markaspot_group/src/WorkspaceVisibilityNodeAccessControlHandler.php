<?php

declare(strict_types=1);

namespace Drupal\markaspot_group;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\node\NodeAccessControlHandler;
use Drupal\node\NodeGrantDatabaseStorageInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Enforces workspace reads before core's node-access bypass.
 */
final class WorkspaceVisibilityNodeAccessControlHandler extends NodeAccessControlHandler {

  /**
   * Constructs the workspace visibility node access control handler.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entityType
   *   The node entity type.
   * @param \Drupal\node\NodeGrantDatabaseStorageInterface $grantStorage
   *   The node grant storage.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\markaspot_group\Service\WorkspaceVisibilityInterface $workspaceVisibility
   *   The workspace visibility service.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   */
  public function __construct(
    EntityTypeInterface $entityType,
    NodeGrantDatabaseStorageInterface $grantStorage,
    EntityTypeManagerInterface $entityTypeManager,
    protected readonly WorkspaceVisibilityInterface $workspaceVisibility,
    protected readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {
    parent::__construct($entityType, $grantStorage, $entityTypeManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('node.grant_storage'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_group.workspace_visibility'),
      $container->get('markaspot_group.hierarchy_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(EntityInterface $entity, $operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $this->prepareUser($account);
    $workspace_access = AccessResult::neutral();

    if ($entity instanceof NodeInterface) {
      $jurisdiction_ids = $this->jurisdictionIds($entity);
      $workspace_access = AccessResult::neutral()
        ->cachePerUser()
        ->addCacheTags($this->visibilityCacheTags(
          $jurisdiction_ids,
          $account,
        ));

      if (in_array($operation, [
        'view',
        'view all revisions',
        'view revision',
      ], TRUE) && $entity->bundle() === 'page') {
        $workspace_access = $this->pageViewAccess(
          $jurisdiction_ids,
          $account,
        );
      }
      elseif ($account->hasPermission('bypass node access')
        && $operation === 'view'
        && $entity->bundle() === 'service_request') {
        $workspace_access = $this->serviceRequestViewAccess(
          $jurisdiction_ids,
          $account,
        );
      }
      elseif ($account->hasPermission('bypass node access')
        && in_array($operation, [
          'view',
          'update',
          'delete',
          'revert revision',
          'delete revision',
        ], TRUE)
        && in_array($entity->bundle(), ['service_request', 'page'], TRUE)) {
        $workspace_access = $this->blockedWorkspaceAccess(
          $jurisdiction_ids,
          $account,
        );
      }
    }

    if ($workspace_access->isForbidden()) {
      return $return_as_object ? $workspace_access : FALSE;
    }

    /** @var \Drupal\Core\Access\AccessResultInterface $result */
    $result = parent::access($entity, $operation, $account, TRUE);
    $result->addCacheableDependency($workspace_access);
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Applies the service-request read visibility matrix.
   *
   * @param int[] $jurisdictionIds
   *   Jurisdiction group IDs.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Forbidden when any workspace is unreadable, otherwise neutral.
   */
  private function serviceRequestViewAccess(array $jurisdictionIds, AccountInterface $account): AccessResult {
    foreach ($jurisdictionIds as $jurisdiction_id) {
      if (!$this->workspaceVisibility->allowsReadFor(
        $account,
        $jurisdiction_id,
      )) {
        return AccessResult::forbidden(
          'Workspace visibility prevents viewing this content.',
        )
          ->cachePerUser()
          ->addCacheTags($this->visibilityCacheTags(
            $jurisdictionIds,
            $account,
          ));
      }
    }

    return AccessResult::neutral()
      ->cachePerUser()
      ->addCacheTags($this->visibilityCacheTags(
        $jurisdictionIds,
        $account,
      ));
  }

  /**
   * Applies the page read visibility matrix before node-access bypass.
   *
   * @param int[] $jurisdictionIds
   *   Jurisdiction group IDs.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Forbidden when any workspace is unreadable, otherwise neutral.
   */
  private function pageViewAccess(array $jurisdictionIds, AccountInterface $account): AccessResult {
    foreach ($jurisdictionIds as $jurisdiction_id) {
      if (!$this->workspaceVisibility->allowsPageReadFor(
        $account,
        $jurisdiction_id,
      )) {
        return AccessResult::forbidden(
          'Workspace visibility prevents viewing this content.',
        )
          ->cachePerUser()
          ->addCacheTags($this->visibilityCacheTags(
            $jurisdictionIds,
            $account,
          ));
      }
    }

    return AccessResult::neutral()
      ->cachePerUser()
      ->addCacheTags($this->visibilityCacheTags(
        $jurisdictionIds,
        $account,
      ));
  }

  /**
   * Applies blocked-workspace operations before core's node-access bypass.
   *
   * @param int[] $jurisdictionIds
   *   Jurisdiction group IDs.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Forbidden for blocked workspaces, otherwise neutral.
   */
  private function blockedWorkspaceAccess(array $jurisdictionIds, AccountInterface $account): AccessResult {
    if ((int) $account->id() !== 1
      && !in_array('administrator', $account->getRoles(), TRUE)) {
      foreach ($jurisdictionIds as $jurisdiction_id) {
        if ($this->workspaceVisibility->isBlocked($jurisdiction_id)) {
          return AccessResult::forbidden(
            'Workspace visibility prevents this operation.',
          )
            ->cachePerUser()
            ->addCacheTags($this->visibilityCacheTags(
              $jurisdictionIds,
              $account,
            ));
        }
      }
    }

    return AccessResult::neutral()
      ->cachePerUser()
      ->addCacheTags($this->visibilityCacheTags(
        $jurisdictionIds,
        $account,
      ));
  }

  /**
   * Gets the node's jurisdiction target IDs.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  private function jurisdictionIds(NodeInterface $node): array {
    if ($node->hasField('field_jurisdiction')
      && !$node->get('field_jurisdiction')->isEmpty()) {
      return array_values(array_unique(array_filter(array_map(
        'intval',
        array_column($node->get('field_jurisdiction')->getValue(), 'target_id'),
      ))));
    }

    if (!$node->hasField('field_category')
      || $node->get('field_category')->isEmpty()) {
      return [];
    }

    $category = $node->get('field_category')->entity;
    if (!$category instanceof TermInterface
      || !$category->hasField('field_jurisdiction')
      || $category->get('field_jurisdiction')->isEmpty()) {
      return [];
    }

    $jurisdiction_id = (int) $category->get('field_jurisdiction')->target_id;
    $root_id = $jurisdiction_id > 0
      ? $this->hierarchyResolver->getRootJurisdictionId($jurisdiction_id)
      : NULL;

    return $root_id === NULL ? [] : [$root_id];
  }

  /**
   * Builds cache tags for a membership-dependent visibility decision.
   *
   * @param int[] $jurisdictionIds
   *   Jurisdiction group IDs.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   *
   * @return string[]
   *   Group and membership-list cache tags.
   */
  private function visibilityCacheTags(array $jurisdictionIds, AccountInterface $account): array {
    $tags = array_map(
      static fn(int $id): string => 'group:' . $id,
      $jurisdictionIds,
    );
    $account_id = (int) $account->id();
    if ($account_id > 0) {
      $tags[] = 'group_relationship_list:plugin:group_membership:entity:'
        . $account_id;
    }
    foreach ($jurisdictionIds as $jurisdiction_id) {
      $tags[] = 'group_relationship_list:plugin:group_membership:group:'
        . $jurisdiction_id;
    }

    return array_values(array_unique($tags));
  }

}
