<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the group members matrix API.
 *
 * Provides REST endpoints for viewing and managing user-group memberships
 * in a matrix/table format. Supports both Drupal administrators and
 * tenant administrators (users with jur-tenant_admin group role).
 */
class GroupMembersController extends ControllerBase {

  /**
   * The membership loader service.
   *
   * @var \Drupal\group\GroupMembershipLoaderInterface
   */
  protected GroupMembershipLoaderInterface $membershipLoader;

  /**
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Constructs a GroupMembersController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\group\GroupMembershipLoaderInterface $membershipLoader
   *   The group membership loader.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    GroupMembershipLoaderInterface $membershipLoader,
    JurisdictionHierarchyResolverInterface $hierarchyResolver,
    AccountInterface $currentUser,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->membershipLoader = $membershipLoader;
    $this->hierarchyResolver = $hierarchyResolver;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('group.membership_loader'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('current_user'),
    );
  }

  /**
   * Access check for group members matrix endpoints.
   *
   * Grants access to Drupal administrators and users who hold the
   * jur-tenant_admin group role in any jurisdiction group.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessCheck(AccountInterface $account): AccessResultInterface {
    // Drupal administrators always have access.
    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }

    // Check if user has jur-tenant_admin role in any jur group.
    $memberships = $this->membershipLoader->loadByUser($account, ['jur-tenant_admin']);
    if (!empty($memberships)) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    return AccessResult::forbidden('User is not an administrator or tenant admin.')
      ->addCacheContexts(['user']);
  }

  /**
   * Returns the group members matrix data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with groups, users, and membership data.
   */
  public function getMatrix(Request $request): JsonResponse {
    $page = max(0, (int) $request->query->get('page', '0'));
    $pageSize = min(100, max(1, (int) $request->query->get('page_size', '50')));
    $search = trim((string) $request->query->get('search', ''));
    $groupTypeFilter = (string) $request->query->get('group_type', '');

    try {
      $groups = $this->loadVisibleGroups($groupTypeFilter);
      $availableRolesByType = $this->loadAvailableRoles($groups);
      [$users, $totalUsers] = $this->loadUsers($page, $pageSize, $search, $groups);

      $groupsData = [];
      foreach ($groups as $group) {
        $groupType = $group->bundle();
        $groupsData[] = [
          'id' => (int) $group->id(),
          'label' => $group->label(),
          'type' => $groupType,
          'available_roles' => $availableRolesByType[$groupType] ?? [],
        ];
      }

      return new JsonResponse([
        'groups' => $groupsData,
        'users' => $users,
        'total_users' => $totalUsers,
        'page' => $page,
        'page_size' => $pageSize,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_group')->error('Failed to load members matrix: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Failed to load members matrix.'], 500);
    }
  }

  /**
   * Updates a user's group memberships.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request containing membership updates.
   * @param int $uid
   *   The user ID to update memberships for.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the update result.
   */
  public function updateMemberships(Request $request, int $uid): JsonResponse {
    // Cannot modify superadmin.
    if ($uid === 1) {
      return new JsonResponse(['error' => 'Cannot modify the superadmin account.'], 403);
    }

    $content = json_decode($request->getContent(), TRUE);
    if (empty($content['memberships']) || !is_array($content['memberships'])) {
      return new JsonResponse(['error' => 'Invalid request body. Expected "memberships" object.'], 400);
    }

    $userStorage = $this->entityTypeManager()->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $targetUser */
    $targetUser = $userStorage->load($uid);
    if (!$targetUser) {
      return new JsonResponse(['error' => 'User not found.'], 404);
    }

    // Check field_all_groups_member flag.
    $isAllGroupsMember = FALSE;
    if ($targetUser->hasField('field_all_groups_member') && !$targetUser->get('field_all_groups_member')->isEmpty()) {
      $isAllGroupsMember = (bool) $targetUser->get('field_all_groups_member')->value;
    }

    $groupStorage = $this->entityTypeManager()->getStorage('group');
    $currentAccount = $this->currentUser();
    $isDrupalAdmin = in_array('administrator', $currentAccount->getRoles(), TRUE);

    $updated = [];
    $errors = [];

    foreach ($content['memberships'] as $groupId => $update) {
      $groupId = (int) $groupId;
      $action = $update['action'] ?? '';

      /** @var \Drupal\group\Entity\GroupInterface|null $group */
      $group = $groupStorage->load($groupId);
      if (!$group) {
        $errors[] = "Group $groupId not found.";
        continue;
      }

      // Verify requesting user has admin scope over this group.
      if (!$isDrupalAdmin && !$this->isGroupInAdminScope($group, $currentAccount)) {
        $errors[] = "No access to group $groupId.";
        continue;
      }

      if ($action === 'remove') {
        $result = $this->handleRemoveMembership($group, $targetUser, $isAllGroupsMember, $currentAccount);
      }
      elseif ($action === 'set') {
        $roles = $update['roles'] ?? [];
        $result = $this->handleSetMembership($group, $targetUser, $roles, $currentAccount);
      }
      else {
        $errors[] = "Invalid action '$action' for group $groupId.";
        continue;
      }

      if ($result['success']) {
        $updated[$groupId] = $result['data'];
      }
      else {
        $errors[] = $result['error'];
      }
    }

    $response = ['status' => 'ok', 'updated' => $updated];
    if (!empty($errors)) {
      $response['errors'] = $errors;
    }

    $this->getLogger('markaspot_group')->notice(
      'User @admin updated memberships for user @target: @updates',
      [
        '@admin' => $currentAccount->getDisplayName(),
        '@target' => $targetUser->getDisplayName(),
        '@updates' => json_encode($updated),
      ]
    );

    return new JsonResponse($response);
  }

  /**
   * Loads groups visible to the current user.
   *
   * Drupal administrators see all groups. Tenant admins see groups within
   * their jurisdiction hierarchy plus associated org groups.
   *
   * @param string $groupTypeFilter
   *   Optional group type filter ('jur' or 'org').
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Array of visible group entities.
   */
  protected function loadVisibleGroups(string $groupTypeFilter): array {
    $groupStorage = $this->entityTypeManager()->getStorage('group');
    $currentAccount = $this->currentUser();
    $isDrupalAdmin = in_array('administrator', $currentAccount->getRoles(), TRUE);

    $allowedTypes = ['jur', 'org'];
    if ($groupTypeFilter && in_array($groupTypeFilter, $allowedTypes, TRUE)) {
      $allowedTypes = [$groupTypeFilter];
    }

    if ($isDrupalAdmin) {
      // Admins see all groups of the allowed types.
      $query = $groupStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $allowedTypes, 'IN')
        ->sort('label');
      $ids = $query->execute();
      return $ids ? $groupStorage->loadMultiple($ids) : [];
    }

    // Tenant admin: scope to their jurisdiction hierarchy.
    $tenantMemberships = $this->membershipLoader->loadByUser($currentAccount, ['jur-tenant_admin']);
    if (empty($tenantMemberships)) {
      return [];
    }

    $visibleGroupIds = [];
    foreach ($tenantMemberships as $membership) {
      $jurId = (int) $membership->getGroup()->id();
      // Get all descendant jurisdictions.
      $jurIds = $this->hierarchyResolver->getDescendantIds($jurId);
      $visibleGroupIds = array_merge($visibleGroupIds, $jurIds);
    }
    $visibleGroupIds = array_unique($visibleGroupIds);

    $groups = [];

    // Load jur groups if type filter allows.
    if (in_array('jur', $allowedTypes, TRUE) && !empty($visibleGroupIds)) {
      $jurGroups = $groupStorage->loadMultiple($visibleGroupIds);
      foreach ($jurGroups as $group) {
        if ($group->bundle() === 'jur') {
          $groups[] = $group;
        }
      }
    }

    // Load org groups that reference these jurisdictions.
    if (in_array('org', $allowedTypes, TRUE) && !empty($visibleGroupIds)) {
      $orgQuery = $groupStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'org')
        ->condition('field_jurisdiction', $visibleGroupIds, 'IN')
        ->sort('label');
      $orgIds = $orgQuery->execute();
      if ($orgIds) {
        $orgGroups = $groupStorage->loadMultiple($orgIds);
        foreach ($orgGroups as $group) {
          $groups[] = $group;
        }
      }
    }

    return $groups;
  }

  /**
   * Loads available (individual scope) roles for each group type.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   The groups to load roles for.
   *
   * @return array
   *   Keyed by group type, each containing an array of role data.
   */
  protected function loadAvailableRoles(array $groups): array {
    $roleStorage = $this->entityTypeManager()->getStorage('group_role');
    $groupTypes = [];
    foreach ($groups as $group) {
      $groupTypes[$group->bundle()] = TRUE;
    }

    $result = [];
    foreach (array_keys($groupTypes) as $groupType) {
      $roleQuery = $roleStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('group_type', $groupType)
        ->condition('scope', PermissionScopeInterface::INDIVIDUAL_ID);
      $roleIds = $roleQuery->execute();

      $roles = [];
      if ($roleIds) {
        /** @var \Drupal\group\Entity\GroupRoleInterface[] $roleEntities */
        $roleEntities = $roleStorage->loadMultiple($roleIds);
        foreach ($roleEntities as $role) {
          $roles[] = [
            'id' => $role->id(),
            'label' => $role->label(),
          ];
        }
      }
      $result[$groupType] = $roles;
    }

    return $result;
  }

  /**
   * Loads users with their group memberships.
   *
   * @param int $page
   *   The page number (0-based).
   * @param int $pageSize
   *   Number of users per page.
   * @param string $search
   *   Optional search term for name or email.
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   The visible groups to load memberships for.
   *
   * @return array
   *   Tuple of [users array, total count].
   */
  protected function loadUsers(int $page, int $pageSize, string $search, array $groups): array {
    $userStorage = $this->entityTypeManager()->getStorage('user');

    // Build user query.
    $query = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', [0, 2], 'NOT IN')
      ->condition('status', 1)
      ->sort('name');

    if ($search !== '') {
      // Escape LIKE metacharacters in user input.
      $escapedSearch = addcslashes($search, '%_\\');
      $orGroup = $query->orConditionGroup()
        ->condition('name', '%' . $escapedSearch . '%', 'LIKE')
        ->condition('mail', '%' . $escapedSearch . '%', 'LIKE');
      $query->condition($orGroup);
    }

    // Count query (before pagination).
    $countQuery = clone $query;
    $totalUsers = (int) $countQuery->count()->execute();

    // Apply pagination.
    $query->range($page * $pageSize, $pageSize);
    $uids = $query->execute();

    if (empty($uids)) {
      return [[], $totalUsers];
    }

    /** @var \Drupal\user\UserInterface[] $userEntities */
    $userEntities = $userStorage->loadMultiple($uids);

    // Build group ID set for quick lookup.
    $groupIds = [];
    foreach ($groups as $group) {
      $groupIds[(int) $group->id()] = $group;
    }

    $users = [];
    foreach ($userEntities as $user) {
      $isAllGroupsMember = $user->hasField('field_all_groups_member')
        && !$user->get('field_all_groups_member')->isEmpty()
        && (bool) $user->get('field_all_groups_member')->value;

      $userData = [
        'uid' => (int) $user->id(),
        'name' => $user->getDisplayName(),
        'email' => $user->getEmail() ?? '',
        'drupal_roles' => array_values($user->getRoles()),
        'memberships' => $this->loadUserMemberships($user, $groupIds),
        'all_groups_member' => $isAllGroupsMember,
      ];
      $users[] = $userData;
    }

    return [$users, $totalUsers];
  }

  /**
   * Loads a user's memberships for the visible groups.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   * @param array $groupIds
   *   Associative array of group ID => group entity for visible groups.
   *
   * @return array
   *   Memberships keyed by group ID, each containing a 'roles' array.
   */
  protected function loadUserMemberships(UserInterface $user, array $groupIds): array {
    $memberships = $this->membershipLoader->loadByUser($user);
    $result = [];

    foreach ($memberships as $membership) {
      $groupId = (int) $membership->getGroup()->id();
      if (!isset($groupIds[$groupId])) {
        continue;
      }

      // Only include individual-scope roles.
      $roles = [];
      foreach ($membership->getRoles(FALSE) as $role) {
        if ($role->getScope() === PermissionScopeInterface::INDIVIDUAL_ID) {
          $roles[] = $role->id();
        }
      }

      $result[(string) $groupId] = ['roles' => $roles];
    }

    return $result;
  }

  /**
   * Handles removing a user from a group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to remove the user from.
   * @param \Drupal\user\UserInterface $targetUser
   *   The user to remove.
   * @param bool $isAllGroupsMember
   *   Whether the user has the all-groups-member flag set.
   * @param \Drupal\Core\Session\AccountInterface $currentAccount
   *   The requesting user account.
   *
   * @return array
   *   Result array with 'success' bool and 'data' or 'error'.
   */
  protected function handleRemoveMembership(
    GroupInterface $group,
    UserInterface $targetUser,
    bool $isAllGroupsMember,
    AccountInterface $currentAccount,
  ): array {
    $groupId = (int) $group->id();

    if ($isAllGroupsMember) {
      return [
        'success' => FALSE,
        'error' => "Cannot remove user from group $groupId: user has all-groups-member flag set.",
      ];
    }

    // Cannot self-remove tenant_admin role.
    if ((int) $targetUser->id() === (int) $currentAccount->id()) {
      $member = $group->getMember($currentAccount);
      if ($member) {
        foreach ($member->getRoles(FALSE) as $role) {
          if ($role->id() === 'jur-tenant_admin') {
            return [
              'success' => FALSE,
              'error' => "Cannot remove yourself from group $groupId while holding tenant_admin role.",
            ];
          }
        }
      }
    }

    $group->removeMember($targetUser);

    return [
      'success' => TRUE,
      'data' => ['action' => 'removed', 'group_id' => $groupId],
    ];
  }

  /**
   * Handles setting/updating a user's roles in a group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to update membership in.
   * @param \Drupal\user\UserInterface $targetUser
   *   The user to update.
   * @param array $roleIds
   *   Array of group role IDs to set.
   * @param \Drupal\Core\Session\AccountInterface $currentAccount
   *   The requesting user account.
   *
   * @return array
   *   Result array with 'success' bool and 'data' or 'error'.
   */
  protected function handleSetMembership(
    GroupInterface $group,
    UserInterface $targetUser,
    array $roleIds,
    AccountInterface $currentAccount,
  ): array {
    $groupId = (int) $group->id();
    $groupType = $group->bundle();

    // Validate that all roles are individual-scope roles for this group type.
    $roleStorage = $this->entityTypeManager()->getStorage('group_role');
    foreach ($roleIds as $roleId) {
      /** @var \Drupal\group\Entity\GroupRoleInterface|null $role */
      $role = $roleStorage->load($roleId);
      if (!$role) {
        return [
          'success' => FALSE,
          'error' => "Role '$roleId' not found.",
        ];
      }
      if ($role->getGroupTypeId() !== $groupType) {
        return [
          'success' => FALSE,
          'error' => "Role '$roleId' does not belong to group type '$groupType'.",
        ];
      }
      if ($role->getScope() !== PermissionScopeInterface::INDIVIDUAL_ID) {
        return [
          'success' => FALSE,
          'error' => "Role '$roleId' is not an individually assignable role.",
        ];
      }
    }

    // Self-protection: cannot remove own tenant_admin role.
    if ((int) $targetUser->id() === (int) $currentAccount->id()) {
      $member = $group->getMember($currentAccount);
      if ($member) {
        $hadTenantAdmin = FALSE;
        foreach ($member->getRoles(FALSE) as $existingRole) {
          if ($existingRole->id() === 'jur-tenant_admin') {
            $hadTenantAdmin = TRUE;
            break;
          }
        }
        if ($hadTenantAdmin && !in_array('jur-tenant_admin', $roleIds, TRUE)) {
          return [
            'success' => FALSE,
            'error' => "Cannot remove your own tenant_admin role in group $groupId.",
          ];
        }
      }
    }

    $existingMember = $group->getMember($targetUser);

    if (!$existingMember) {
      // Add new member with roles.
      $group->addMember($targetUser, ['group_roles' => $roleIds]);
    }
    else {
      // Update existing membership roles.
      $relationship = $existingMember->getGroupRelationship();
      $relationship->set('group_roles', $roleIds);
      $relationship->save();
    }

    return [
      'success' => TRUE,
      'data' => ['action' => 'set', 'group_id' => $groupId, 'roles' => $roleIds],
    ];
  }

  /**
   * Checks whether a group falls within the current user's admin scope.
   *
   * A tenant admin can manage groups within their jurisdiction hierarchy:
   * - Jur groups they administer or their descendants.
   * - Org groups whose field_jurisdiction references an administered jur.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The requesting user account.
   *
   * @return bool
   *   TRUE if the user has admin scope over this group.
   */
  protected function isGroupInAdminScope(GroupInterface $group, AccountInterface $account): bool {
    $tenantMemberships = $this->membershipLoader->loadByUser($account, ['jur-tenant_admin']);
    if (empty($tenantMemberships)) {
      return FALSE;
    }

    // Build the set of jur IDs this user administers (including descendants).
    $adminJurIds = [];
    foreach ($tenantMemberships as $membership) {
      $jurId = (int) $membership->getGroup()->id();
      $adminJurIds[] = $jurId;
      $adminJurIds = array_merge($adminJurIds, $this->hierarchyResolver->getDescendantIds($jurId));
    }
    $adminJurIds = array_unique($adminJurIds);

    $groupId = (int) $group->id();

    // Jur group: must be in the administered set.
    if ($group->bundle() === 'jur') {
      return in_array($groupId, $adminJurIds, TRUE);
    }

    // Org group: its field_jurisdiction must reference an administered jur.
    if ($group->bundle() === 'org') {
      if ($group->hasField('field_jurisdiction') && !$group->get('field_jurisdiction')->isEmpty()) {
        $orgJurId = (int) $group->get('field_jurisdiction')->target_id;
        return in_array($orgJurId, $adminJurIds, TRUE);
      }
    }

    return FALSE;
  }

}
