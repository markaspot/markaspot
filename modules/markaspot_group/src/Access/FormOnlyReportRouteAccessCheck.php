<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\node\NodeInterface;

/**
 * Prevents identifiers from granting access to form-only follow-up routes.
 */
final class FormOnlyReportRouteAccessCheck implements AccessInterface {

  /**
   * Constructs the additional report access check.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceVisibilityInterface $visibility,
  ) {}

  /**
   * Requires actual report access only for the form-only operating mode.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $node = $route_match->getParameter('node');
    if (!$node instanceof NodeInterface) {
      $uuid = $route_match->getParameter('uuid');
      $nodes = is_string($uuid) ? $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]) : [];
      $node = reset($nodes);
    }
    // Preserve the endpoint's existing missing-node and other-bundle handling.
    $result = AccessResult::allowed()->addCacheTags(['group_list']);
    if (!$node instanceof NodeInterface) {
      return $result->setCacheMaxAge(0);
    }
    $result->addCacheableDependency($node);
    if ($node->bundle() !== 'service_request') {
      return $result;
    }
    $organisation_ids = $node->hasField('field_organisation')
      ? array_map('intval', array_column($node->get('field_organisation')->getValue(), 'target_id'))
      : [];
    foreach (_markaspot_group_workspace_visibility_jurisdiction_ids($node) as $jurisdiction_id) {
      if ($this->visibility->getVisibility($jurisdiction_id) !== 'form_only') {
        continue;
      }
      // Evaluate request-sensitive policy before the entity access cache: the
      // same staff UID with a public API key must remain anonymous-equivalent.
      if (!$this->visibility->allowsReportReadFor($account, $jurisdiction_id, $organisation_ids)) {
        return AccessResult::forbidden('Form-only reports require scoped staff access.')
          ->addCacheableDependency($result)->cachePerUser()->setCacheMaxAge(0);
      }
      $node_access = $node->access('view', $account, TRUE);
      $result = $result->andIf(AccessResult::allowedIf($node_access->isAllowed())
        ->addCacheableDependency($node_access))->cachePerUser()->setCacheMaxAge(0);
    }
    return $result;
  }

}
