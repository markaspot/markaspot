<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

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
    $memberships = GroupMembership::loadByUser($user, self::getTenantAdminRoleIds());

    $jur_ids = [];
    foreach ($memberships as $membership) {
      assert($membership instanceof GroupRelationshipInterface);
      if (self::isJurisdictionGroup($membership->getGroup())) {
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
   * Removes the tenant-admin group role from one jurisdiction membership.
   *
   * The membership itself is retained. Only the elevated jur-tenant_admin role
   * is stripped so manual removal of the Drupal tenant_admin role leaves both
   * authorization layers in sync.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID whose tenant-admin group role should be
   *   removed.
   *
   * @return bool
   *   TRUE when a membership was changed, FALSE otherwise.
   */
  public static function stripGroupRole(AccountInterface $account, int $jurisdiction_id): bool {
    $user = User::load($account->id());
    if (!$user || $jurisdiction_id <= 0) {
      return FALSE;
    }

    $memberships = GroupMembership::loadByUser($user, self::getTenantAdminRoleIds());
    foreach ($memberships as $membership) {
      assert($membership instanceof GroupRelationshipInterface);
      $group = $membership->getGroup();
      if (!self::isJurisdictionGroup($group) || (int) $group->id() !== $jurisdiction_id) {
        continue;
      }

      $role_values = $membership->get('group_roles')->getValue();
      $filtered = self::filterTenantAdminRoleValues($role_values);

      if (count($filtered) === count($role_values)) {
        return FALSE;
      }

      $membership->set('group_roles', $filtered);
      $membership->save();
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Filters tenant-admin role values while preserving all other roles.
   *
   * @param array<int, array<string, mixed>> $role_values
   *   Raw group_roles field values.
   *
   * @return array<int, array<string, mixed>>
   *   Values without the jur-tenant_admin group role.
   */
  public static function filterTenantAdminRoleValues(array $role_values): array {
    $tenant_admin_role_ids = self::getTenantAdminRoleIds();
    return array_values(array_filter(
      $role_values,
      static fn(array $item): bool => !in_array($item['target_id'] ?? NULL, $tenant_admin_role_ids, TRUE)
    ));
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

  /**
   * Removes tenant-admin group roles from all jurisdiction memberships.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return int
   *   The number of memberships changed.
   */
  public static function stripAllGroupRoles(AccountInterface $account): int {
    $changed = 0;
    foreach (self::getUserJurisdictionIds($account) as $jurisdiction_id) {
      if (self::stripGroupRole($account, $jurisdiction_id)) {
        $changed++;
      }
    }
    return $changed;
  }

}
