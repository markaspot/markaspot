<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\node\NodeInterface;

/**
 * Restricts the service request version history on the shared platform.
 *
 * Product decision (2026-10-20): on enterprise stacks the jurisdiction's
 * tenant admins and moderators see the edit history of a report. On the
 * shared self-service platform it stays with the platform operator (uid 1 or
 * the administrator role, as in ReportStatsController and the frontend admin
 * bypass), whatever role a workspace member holds; moderators there carry
 * "edit any service_request content", which alone would open the route.
 * The enterprise showcase stack behaves like an enterprise stack.
 *
 * Core revision routes and JSON:API resourceVersion use accessNode() too.
 * A snapshot requires private scope on both the current report and revision;
 * unrelated core revision grants cannot override a scope or platform denial.
 *
 * Combined with the route's _permission and _entity_access requirements by
 * the access manager (AND): this check never grants access on its own.
 */
final class VersionHistoryAccessCheck implements AccessInterface {

  /**
   * Permissions that open the edit history of a report.
   */
  public const REVISION_PERMISSIONS = [
    'view all revisions',
    'view service_request revisions',
  ];

  /**
   * Access reason on the self-service platform for non-operators.
   */
  private const OPERATOR_ONLY_REASON = 'The edit history is reserved for the '
    . 'platform operator on the self-service platform.';

  public function __construct(
    private readonly FeatureScopeResolver $featureScopeResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Applies the history contract to core JSON:API historical snapshots too.
   */
  public function accessNode(NodeInterface $revision, AccountInterface $account, bool $check_current_view = TRUE): AccessResultInterface {
    $result = AccessResult::allowedIf($account->isAuthenticated()
      && ($account->hasPermission('view all revisions')
        || $account->hasPermission('view service_request revisions')
        || $account->hasPermission('edit any service_request content')))
      ->cachePerUser()->cachePerPermissions()->setCacheMaxAge(0);
    $current = $this->entityTypeManager->getStorage('node')->loadUnchanged($revision->id());
    if (!$current instanceof NodeInterface
      || !$current->hasField('field_assignee') || !$revision->hasField('field_assignee')) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    $access = $result->andIf($this->check($account));
    if ($check_current_view) {
      $access = $access->andIf($current->access('view', $account, TRUE));
    }
    $access = $access
      ->andIf($current->get('field_assignee')->access('view', $account, TRUE))
      ->andIf($revision->get('field_assignee')->access('view', $account, TRUE));
    return $access->isAllowed() ? $access
      : AccessResult::forbidden('Revision snapshots require the same scope as the report history.')
        ->addCacheableDependency($access)->setCacheMaxAge(0);
  }

  /**
   * Checks platform-level access to the version history route.
   */
  public function check(AccountInterface $account): AccessResultInterface {
    if ($this->featureScopeResolver->allowsEnterpriseFeatures()) {
      return AccessResult::allowed()->addCacheTags(['config:markaspot_nuxt.settings']);
    }

    $is_operator = (int) $account->id() === 1
      || in_array('administrator', $account->getRoles(), TRUE);
    $result = $is_operator
      ? AccessResult::allowed()
      : AccessResult::neutral(self::OPERATOR_ONLY_REASON);
    return $result
      ->addCacheTags(['config:markaspot_nuxt.settings'])
      ->cachePerUser();
  }

}
