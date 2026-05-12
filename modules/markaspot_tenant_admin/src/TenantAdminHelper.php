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
    $cache = &drupal_static(__METHOD__, []);
    $uid = (int) $account->id();
    if (isset($cache[$uid])) {
      return $cache[$uid];
    }

    $user = User::load($uid);
    if (!$user) {
      return $cache[$uid] = [];
    }

    // loadByUser with roles filter queries group_roles field directly.
    $memberships = GroupMembership::loadByUser($user, self::getTenantAdminRoleIds());

    $jur_ids = [];
    foreach ($memberships as $membership) {
      assert($membership instanceof GroupRelationshipInterface);
      if (self::isJurisdictionGroup($membership->getGroup())) {
        $jur_ids[] = (int) $membership->getGroupId();
      }
    }

    return $cache[$uid] = $jur_ids;
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

    $memberships = GroupMembership::loadByUser($user, self::getTenantAdminRoleIds());

    foreach ($memberships as $membership) {
      assert($membership instanceof GroupRelationshipInterface);
      if ($exclude_relationship_id && (int) $membership->id() === $exclude_relationship_id) {
        continue;
      }
      if (self::isJurisdictionGroup($membership->getGroup())) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets tenant-admin role IDs for the configured and legacy jur bundles.
   *
   * @return string[]
   *   Unique group role IDs that represent tenant administration.
   */
  public static function getTenantAdminRoleIds(): array {
    return array_values(array_unique([
      self::getJurisdictionGroupType() . '-tenant_admin',
      self::GROUP_ROLE_ID,
    ]));
  }

  /**
   * Gets jurisdiction group role IDs allowed to manage node published status.
   *
   * Returns the group roles whose members may toggle the `status`
   * (published/unpublished) field on node entities scoped to their
   * jurisdiction. Used by the entity_field_access hook to keep publish
   * rights local to a tenant rather than granting them globally via the
   * core `administer node published status` permission.
   *
   * @return string[]
   *   Unique group role IDs.
   */
  public static function getJurisdictionPublishGroupRoleIds(): array {
    $type = self::getJurisdictionGroupType();
    return array_values(array_unique([
      $type . '-tenant_admin',
      $type . '-moderator',
      self::GROUP_ROLE_ID,
    ]));
  }

  /**
   * Gets jurisdiction IDs where the user has a publish-capable group role.
   *
   * Wider than getUserJurisdictionIds(): includes group members with the
   * `jur-moderator` role (daily triage staff) alongside `jur-tenant_admin`.
   * Used to scope status-field access for service_request + page bundles.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return int[]
   *   Array of jurisdiction group entity IDs.
   */
  public static function getUserPublishCapableJurisdictionIds(AccountInterface $account): array {
    $cache = &drupal_static(__METHOD__, []);
    $uid = (int) $account->id();
    if (isset($cache[$uid])) {
      return $cache[$uid];
    }

    $user = User::load($uid);
    if (!$user) {
      return $cache[$uid] = [];
    }

    $memberships = GroupMembership::loadByUser($user, self::getJurisdictionPublishGroupRoleIds());

    $jur_ids = [];
    foreach ($memberships as $membership) {
      assert($membership instanceof GroupRelationshipInterface);
      if (self::isJurisdictionGroup($membership->getGroup())) {
        $jur_ids[] = (int) $membership->getGroupId();
      }
    }

    return $cache[$uid] = $jur_ids;
  }

  /**
   * Checks whether a group uses the configured jurisdiction group type.
   *
   * @param mixed $group
   *   The candidate group.
   *
   * @return bool
   *   TRUE when the group bundle is the configured jurisdiction type.
   */
  protected static function isJurisdictionGroup(mixed $group): bool {
    return is_object($group)
      && method_exists($group, 'bundle')
      && $group->bundle() === self::getJurisdictionGroupType();
  }

  /**
   * Gets the configured jurisdiction group type.
   *
   * @return string
   *   The configured jurisdiction group type machine name.
   */
  protected static function getJurisdictionGroupType(): string {
    try {
      if (\Drupal::hasService('config.factory')) {
        $configured = \Drupal::config('markaspot_open311.settings')
          ->get('jurisdiction_group_type');
        return is_string($configured) && $configured !== '' ? $configured : 'jur';
      }
    }
    catch (\Throwable) {
      // Unit tests may provide a reduced container without config services.
    }

    return 'jur';
  }

}
