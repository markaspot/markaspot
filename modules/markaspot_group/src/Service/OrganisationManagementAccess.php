<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;

/**
 * Resolves jurisdiction-scoped organisation management access.
 */
class OrganisationManagementAccess {

  /**
   * Constructs the organisation management access checker.
   */
  public function __construct(
    protected readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Checks whether an account has an organisation administrator bypass.
   */
  public function hasBypass(AccountInterface $account): bool {
    return (int) $account->id() === 1
      || in_array('administrator', $account->getRoles(), TRUE);
  }

  /**
   * Checks whether an account manages a jurisdiction in the same root tree.
   *
   * Child administrators are included when checking a stored root because POST
   * accepts their child workspace and then normalizes the organisation to root.
   */
  public function canManageJurisdiction(AccountInterface $account, int $jurisdictionId): bool {
    if ($jurisdictionId <= 0) {
      return FALSE;
    }

    $rootId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
    if ($rootId === NULL) {
      return FALSE;
    }

    $managedIds = [$jurisdictionId, $rootId];
    if ($jurisdictionId === $rootId) {
      // Organisations are normalized to their root. A manager on a child must
      // still manage that root-owned organisation after creation.
      $managedIds = array_merge(
        $managedIds,
        $this->hierarchyResolver->getDescendantIds($rootId),
      );
    }
    $managedIds = array_values(array_unique(array_map('intval', $managedIds)));
    foreach ($this->managedJurisdictionIds($account) as $managedId) {
      if (in_array($managedId, $managedIds, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets jurisdiction IDs where the account has a management group role.
   *
   * @return int[]
   *   Managed jurisdiction group IDs.
   */
  public function managedJurisdictionIds(AccountInterface $account): array {
    $managedIds = [];
    foreach ($this->loadManagementMemberships($account) as $membership) {
      $group = $membership->getGroup();
      if ($this->isJurisdictionGroup($group)) {
        $managedIds[] = (int) $group->id();
      }
    }

    return array_values(array_unique(array_filter($managedIds)));
  }

  /**
   * Checks whether the account manages at least one jurisdiction.
   */
  public function managesAnyJurisdiction(AccountInterface $account): bool {
    return $this->managedJurisdictionIds($account) !== [];
  }

  /**
   * Gets jurisdiction IDs used by organisation storage for managed trees.
   *
   * @return int[]
   *   Directly managed jurisdictions and their resolvable roots.
   */
  public function managedOrganisationJurisdictionIds(AccountInterface $account): array {
    $jurisdictionIds = $this->managedJurisdictionIds($account);
    foreach ($jurisdictionIds as $jurisdictionId) {
      $rootId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      if ($rootId !== NULL) {
        $jurisdictionIds[] = $rootId;
      }
    }

    return array_values(array_unique($jurisdictionIds));
  }

  /**
   * Loads management memberships through Group's non-deprecated API.
   *
   * @return \Drupal\group\Entity\GroupMembershipInterface[]
   *   Management membership entities.
   */
  protected function loadManagementMemberships(AccountInterface $account): array {
    return GroupMembership::loadByUser($account, $this->managementRoleIds());
  }

  /**
   * Gets the jurisdiction group roles that may manage organisations.
   *
   * @return string[]
   *   Group role IDs for the configured and canonical jurisdiction bundles.
   */
  protected function managementRoleIds(): array {
    $groupType = $this->jurisdictionGroupType();

    return array_values(array_unique([
      $groupType . '-tenant_admin',
      $groupType . '-admin',
      'jur-tenant_admin',
      'jur-admin',
    ]));
  }

  /**
   * Checks whether a group is a jurisdiction group.
   */
  protected function isJurisdictionGroup(mixed $group): bool {
    return $group instanceof GroupInterface
      && $group->bundle() === $this->jurisdictionGroupType();
  }

  /**
   * Gets the configured jurisdiction group type.
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
