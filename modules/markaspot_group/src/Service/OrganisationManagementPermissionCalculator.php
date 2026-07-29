<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flexible_permissions\CalculatedPermissionsItem;
use Drupal\flexible_permissions\PermissionCalculatorBase;
use Drupal\flexible_permissions\RefinableCalculatedPermissions;
use Drupal\group\PermissionScopeInterface;

/**
 * Adds unpublished organisation view grants for jurisdiction managers.
 */
final class OrganisationManagementPermissionCalculator extends PermissionCalculatorBase {

  /**
   * Constructs the organisation management permission calculator.
   */
  public function __construct(
    private readonly OrganisationManagementAccess $managementAccess,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, $scope) {
    $calculatedPermissions = parent::calculatePermissions($account, $scope);
    assert($calculatedPermissions instanceof RefinableCalculatedPermissions);

    if ($scope !== PermissionScopeInterface::INDIVIDUAL_ID
      || $this->managementAccess->hasBypass($account)) {
      return $calculatedPermissions;
    }

    $managedJurisdictionIds = $this->managementAccess
      ->managedOrganisationJurisdictionIds($account);
    if ($managedJurisdictionIds === []) {
      return $calculatedPermissions;
    }

    $calculatedPermissions
      ->addCacheContexts(['user'])
      ->addCacheTags([
        'group_list',
        'group_relationship_list:plugin:group_membership:entity:' . $account->id(),
      ]);

    $organisationIds = $this->entityTypeManager
      ->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('status', FALSE)
      ->condition('field_jurisdiction', $managedJurisdictionIds, 'IN')
      ->execute();

    foreach ($organisationIds as $organisationId) {
      $calculatedPermissions->addItem(new CalculatedPermissionsItem(
        PermissionScopeInterface::INDIVIDUAL_ID,
        (int) $organisationId,
        ['view any unpublished group'],
        FALSE,
      ));
    }

    return $calculatedPermissions;
  }

  /**
   * {@inheritdoc}
   */
  public function getPersistentCacheContexts($scope) {
    return $scope === PermissionScopeInterface::INDIVIDUAL_ID ? ['user'] : [];
  }

}
