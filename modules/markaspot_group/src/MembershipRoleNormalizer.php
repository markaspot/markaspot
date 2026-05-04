<?php

declare(strict_types=1);

namespace Drupal\markaspot_group;

/**
 * Normalizes individual group roles before saving membership relationships.
 */
final class MembershipRoleNormalizer {

  /**
   * Internal individual role marking jur membership derived from an org.
   */
  public const ORG_DERIVED_JUR_ROLE_ID = 'jur-org_member';

  /**
   * Checks whether a role is managed internally rather than user-assignable.
   */
  public static function isInternalRoleId(string $roleId): bool {
    return $roleId === self::ORG_DERIVED_JUR_ROLE_ID
      || str_ends_with($roleId, '-org_member');
  }

  /**
   * Returns the derived org-member role ID for a jurisdiction group type.
   */
  public static function orgDerivedJurRoleId(string $groupType = 'jur'): string {
    return $groupType . '-org_member';
  }

  /**
   * Ensures elevated membership roles keep their base member role.
   *
   * @param array $roleIds
   *   Raw group role IDs.
   * @param string $groupType
   *   Group bundle ID.
   *
   * @return array
   *   Normalized role IDs.
   */
  public static function normalize(array $roleIds, string $groupType): array {
    $roles = array_values(array_unique(array_filter(
      $roleIds,
      static fn($roleId): bool => is_string($roleId) && $roleId !== '',
    )));

    $baseRoles = $groupType === 'org' ? [] : [
      $groupType . '-tenant_admin' => $groupType . '-member',
    ];

    $required = [];
    foreach ($baseRoles as $elevatedRole => $baseRole) {
      if (in_array($elevatedRole, $roles, TRUE)) {
        $required[] = $baseRole;
      }
    }

    return array_values(array_unique([...$required, ...$roles]));
  }

}
