<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_admin;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\user\Entity\User;

/**
 * Helper for resolving tenant admin jurisdiction memberships.
 */
class TenantAdminHelper {

  /**
   * The group role ID that triggers tenant admin Drupal role syncing.
   */
  const GROUP_ROLE_ID = 'jur-tenant_admin';

  /**
   * Gets jurisdiction group IDs where the user has the tenant admin group role.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return int[]
   *   Array of group entity IDs.
   */
  public static function getUserJurisdictionIds(AccountInterface $account): array {
    $user = User::load($account->id());
    if (!$user) {
      return [];
    }

    // loadByUser with roles filter queries group_roles field directly.
    $memberships = GroupMembership::loadByUser($user, [self::GROUP_ROLE_ID]);

    $jur_ids = [];
    foreach ($memberships as $membership) {
      assert($membership instanceof GroupRelationshipInterface);
      if ($membership->getGroup()->bundle() === 'jur') {
        $jur_ids[] = (int) $membership->getGroupId();
      }
    }

    return $jur_ids;
  }

  /**
   * Checks if a user has the tenant admin group role in any jur group.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param int|null $exclude_relationship_id
   *   A relationship ID to exclude (e.g., the one being deleted).
   *
   * @return bool
   *   TRUE if the user has the role in at least one jur group.
   */
  public static function userHasJurAdminInAnyGroup(AccountInterface $account, ?int $exclude_relationship_id = NULL): bool {
    $user = User::load($account->id());
    if (!$user) {
      return FALSE;
    }

    $memberships = GroupMembership::loadByUser($user, [self::GROUP_ROLE_ID]);

    foreach ($memberships as $membership) {
      assert($membership instanceof GroupRelationshipInterface);
      if ($exclude_relationship_id && (int) $membership->id() === $exclude_relationship_id) {
        continue;
      }
      if ($membership->getGroup()->bundle() === 'jur') {
        return TRUE;
      }
    }

    return FALSE;
  }

}
