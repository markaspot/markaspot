<?php

declare(strict_types=1);

namespace Drupal\service_request\Access;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Identifies trusted staff requests backed by a real Drupal user session.
 */
final class InternalServiceRequestCreatePolicy {

  /**
   * Drupal roles that already identify dashboard staff.
   *
   * @var string[]
   */
  private const STAFF_ROLES = [
    'administrator',
    'moderator',
    'editorial_board',
    'tenant_admin',
  ];

  /**
   * Existing permissions that already identify report-wide staff access.
   *
   * @var string[]
   */
  private const STAFF_PERMISSIONS = [
    'administer nodes',
    'edit any service_request content',
  ];

  /**
   * Whether the current request is a trusted staff browser session.
   */
  public static function allows(AccountInterface $account, Request $request): bool {
    if (!$account->isAuthenticated() || !$request->hasSession()) {
      return FALSE;
    }

    try {
      if ((int) $request->getSession()->get('uid', 0) !== (int) $account->id()) {
        return FALSE;
      }
    }
    catch (\Throwable) {
      return FALSE;
    }

    $roles = $account->getRoles();
    $has_staff_role = array_intersect(self::STAFF_ROLES, $roles) !== [];

    // Contractor-only accounts have a deliberately restricted management
    // boundary. Permissions inherited from another source must not widen it.
    // Mixed staff and contractor accounts retain their existing staff access.
    if (in_array('contractor', $roles, TRUE) && !$has_staff_role) {
      return FALSE;
    }

    if ($has_staff_role) {
      return TRUE;
    }

    foreach (self::STAFF_PERMISSIONS as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
