<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;

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
 * Known limit: core's revision routes and JSON:API resourceVersion also admit
 * "administer nodes". On the platform only the operator-side editorial_board
 * role holds it; markaspot_nuxt_user_role_presave() strips the revision
 * permissions from every other non-admin role.
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
  ) {}

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
