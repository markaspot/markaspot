<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
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

  use JurisdictionIdResolverTrait;

  /**
   * Lock TTL for a single user's membership batch update.
   */
  private const MEMBERSHIP_UPDATE_LOCK_TTL = 120.0;

  /**
   * Maximum membership changes accepted in a single PATCH request.
   */
  private const MAX_MEMBERSHIP_UPDATE_ITEMS = 50;

  /**
   * Lock TTL for email identity mutations.
   */
  private const USER_EMAIL_LOCK_TTL = 120.0;

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
   * Lock backend for per-user membership mutations.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected LockBackendInterface $lock;

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
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    GroupMembershipLoaderInterface $membershipLoader,
    JurisdictionHierarchyResolverInterface $hierarchyResolver,
    AccountInterface $currentUser,
    LockBackendInterface $lock,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->membershipLoader = $membershipLoader;
    $this->hierarchyResolver = $hierarchyResolver;
    $this->currentUser = $currentUser;
    $this->lock = $lock;
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
      $container->get('lock'),
    );
  }

  /**
   * Access check for endpoints reserved to Drupal site administrators.
   *
   * Used by listAdminJurisdictions() so that only Drupal-administrator users
   * (uid 1 or 'administrator' role) can enumerate every jur-group, regardless
   * of membership. Tenant admins are intentionally excluded.
   */
  public function platformAdminAccessCheck(AccountInterface $account): AccessResultInterface {
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }
    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }
    return AccessResult::forbidden('Platform administrator role required.')
      ->addCacheContexts(['user.roles']);
  }

  /**
   * Returns every jurisdiction group for platform admins.
   *
   * The dashboard workspace switcher uses this to merge "all workspaces" into
   * the switcher dropdown for Drupal site administrators, so a platform admin
   * can inspect or unblock a workspace (e.g. a spam workspace) without first
   * being granted explicit group membership. Tenant admins do not see this
   * data — they keep the membership-filtered list from /auth/status.
   */
  public function listAdminJurisdictions(): CacheableJsonResponse {
    $storage = $this->entityTypeManager->getStorage('group');
    $groupType = $this->config('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur';

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $groupType)
      ->sort('label', 'ASC')
      ->execute();

    $jurisdictions = [];
    $cacheability = new CacheableMetadata();
    // Invalidate when ANY group is added/removed/changed (covers visibility
    // toggles, slug renames, new spam workspaces becoming visible). Drupal
    // core's group entity hooks invalidate `group_list` on every CRUD, so
    // per-group `group:N` tags would be redundant here.
    $cacheability->addCacheTags(['group_list']);
    // `user.roles` is the right discriminator: the payload differs between
    // platform admins and everyone else, not per individual uid. Drupal's
    // cache-context optimizer treats `user` as strictly more specific than
    // `user.roles`, so adding both would only inflate CID cardinality.
    $cacheability->addCacheContexts(['user.roles']);

    foreach ($storage->loadMultiple($ids) as $group) {
      if (!$group instanceof GroupInterface || $group->bundle() !== $groupType) {
        continue;
      }
      $jurisdictions[] = [
        'id' => (string) $group->id(),
        'label' => (string) $group->label(),
        'slug' => $group->hasField('field_slug') && !$group->get('field_slug')->isEmpty()
          ? (string) $group->get('field_slug')->value
          : NULL,
        'visibility' => $group->hasField('field_visibility') && !$group->get('field_visibility')->isEmpty()
          ? (string) $group->get('field_visibility')->value
          : 'public',
        'type' => $groupType,
      ];
    }

    $response = new CacheableJsonResponse(['jurisdictions' => $jurisdictions]);
    $response->addCacheableDependency($cacheability);
    // Belt-and-braces against intermediate HTTP caches that might honour
    // group_list invalidation differently.
    $response->headers->set('Cache-Control', 'private, no-cache');
    return $response;
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
    // User 1 (superadmin) always has access — bypasses all checks.
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    // Drupal administrators always have access.
    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }

    // Check if user has tenant-admin membership in a configured jurisdiction.
    $memberships = $this->membershipLoader->loadByUser($account, $this->jurisdictionRoleIds('tenant_admin'));
    foreach ($memberships as $membership) {
      if ($this->isJurisdictionGroup($membership->getGroup())) {
        return AccessResult::allowed()->addCacheContexts(['user']);
      }
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
      $includeInactive = $request->query->has('include_inactive');
      $groups = $this->loadVisibleGroups($groupTypeFilter);
      $availableRolesByType = $this->loadAvailableRoles($groups);
      $isDrupalAdmin = $this->isDrupalAdminAccount($this->currentUser());
      [$users, $totalUsers] = $this->loadUsers($page, $pageSize, $search, $groups, $isDrupalAdmin, $includeInactive);

      // Build group data with hierarchy metadata.
      $groupsData = [];
      foreach ($groups as $group) {
        $actualGroupType = $group->bundle();
        $groupType = $this->isJurisdictionGroup($group) ? 'jur' : $actualGroupType;
        $entry = [
          'id' => (int) $group->id(),
          'label' => $group->label(),
          'type' => $groupType,
          'available_roles' => $availableRolesByType[$actualGroupType] ?? [],
          'parent_id' => NULL,
          'depth' => 0,
          'jurisdiction_id' => NULL,
        ];

        if ($this->isJurisdictionGroup($group)) {
          // Read parent from field_parent_jurisdiction.
          if ($group->hasField('field_parent_jurisdiction')
              && !$group->get('field_parent_jurisdiction')->isEmpty()) {
            $entry['parent_id'] = (int) $group->get('field_parent_jurisdiction')->target_id;
          }
        }
        elseif ($groupType === 'org') {
          // Read jurisdiction reference.
          if ($group->hasField('field_jurisdiction')
              && !$group->get('field_jurisdiction')->isEmpty()) {
            $entry['jurisdiction_id'] = (int) $group->get('field_jurisdiction')->target_id;
          }
        }

        $groupsData[] = $entry;
      }

      $groupsData = $this->addJurisdictionDepths(
        $groupsData,
        $this->loadJurisdictionParentMap($groupsData),
      );

      // Sort jur groups in tree order (depth-first).
      $groupsData = $this->sortGroupsTreeOrder($groupsData);

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

    if (count($content['memberships']) > self::MAX_MEMBERSHIP_UPDATE_ITEMS) {
      return new JsonResponse(['error' => 'Too many membership updates in one request.'], 413);
    }

    $preflight = $this->prepareMembershipUpdateContext($uid);
    if ($preflight instanceof JsonResponse) {
      return $preflight;
    }

    $lockName = $this->buildMembershipUpdateLockName($uid);
    if (!$this->lock->acquire($lockName, self::MEMBERSHIP_UPDATE_LOCK_TTL)) {
      return new JsonResponse(['error' => 'Membership update already in progress for this user.'], 409);
    }

    try {
      return $this->doUpdateMemberships($content, $uid);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Applies a validated membership update batch.
   *
   * @param array $content
   *   Decoded PATCH body with a memberships object.
   * @param int $uid
   *   The user ID to update memberships for.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the update result.
   */
  protected function doUpdateMemberships(array $content, int $uid): JsonResponse {
    $context = $this->prepareMembershipUpdateContext($uid);
    if ($context instanceof JsonResponse) {
      return $context;
    }

    $currentAccount = $context['current_account'];
    $isDrupalAdmin = $context['is_drupal_admin'];
    $targetUser = $context['target_user'];
    $isAllGroupsMember = $context['is_all_groups_member'];
    $groupStorage = $context['group_storage'];
    $adminJurIds = $context['admin_jur_ids'];

    $updated = [];
    $errors = [];

    foreach ($content['memberships'] as $groupId => $update) {
      $groupId = (int) $groupId;
      $action = $update['action'] ?? '';

      /** @var \Drupal\group\Entity\GroupInterface|null $group */
      $group = $groupStorage->load($groupId);
      if (!$group) {
        $errors[] = $this->getMembershipGroupRejectedMessage($groupId);
        continue;
      }

      // Verify requesting user has admin scope over this group.
      if (!$isDrupalAdmin && !$this->isGroupInAdminScopeWith($group, $adminJurIds)) {
        $errors[] = $this->getMembershipGroupRejectedMessage($groupId);
        continue;
      }

      if ($action === 'remove') {
        $result = $this->handleRemoveMembership($group, $targetUser, $isAllGroupsMember, $currentAccount);
      }
      elseif ($action === 'set') {
        $roles = $update['roles'] ?? [];
        $result = $this->handleSetMembership($group, $targetUser, $roles, $currentAccount, $isDrupalAdmin);
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

    $response = $this->buildMembershipUpdateResponse($updated, $errors);

    $this->getLogger('markaspot_group')->notice(
      'User @admin updated memberships for user @target: @updates',
      [
        '@admin' => preg_replace('/[\r\n\t]/', ' ', $currentAccount->getDisplayName()),
        '@target' => preg_replace('/[\r\n\t]/', ' ', $targetUser->getDisplayName()),
        '@updates' => json_encode($updated),
      ]
    );

    return new JsonResponse($response);
  }

  /**
   * Loads the target user and checks caller scope for a membership mutation.
   *
   * This is intentionally run before and after acquiring the per-user lock:
   * before, to avoid lock contention as a UID probe, and after, to
   * prevent stale scope checks from authorizing the mutation.
   *
   * @return array|\Symfony\Component\HttpFoundation\JsonResponse
   *   Context needed by the update loop, or an error response.
   */
  protected function prepareMembershipUpdateContext(int $uid): array|JsonResponse {
    $currentAccount = $this->currentUser();
    $isDrupalAdmin = $this->isDrupalAdminAccount($currentAccount);

    $userStorage = $this->entityTypeManager()->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $targetUser */
    $targetUser = $userStorage->load($uid);
    if (!$targetUser) {
      return new JsonResponse(['error' => 'User not found.'], 404);
    }

    // Non-admin callers cannot modify Drupal administrators.
    if (!$isDrupalAdmin && in_array('administrator', $targetUser->getRoles(), TRUE)) {
      return new JsonResponse(['error' => 'Cannot modify administrator accounts.'], 403);
    }

    // Verify the target user is within the caller's admin scope.
    if (!$isDrupalAdmin && !$this->isUserInAdminScope($targetUser, $currentAccount)) {
      return new JsonResponse(['error' => 'Access denied to this user.'], 403);
    }

    // Check field_all_groups_member flag.
    $isAllGroupsMember = FALSE;
    if ($targetUser->hasField('field_all_groups_member') && !$targetUser->get('field_all_groups_member')->isEmpty()) {
      $isAllGroupsMember = (bool) $targetUser->get('field_all_groups_member')->value;
    }

    $groupStorage = $this->entityTypeManager()->getStorage('group');

    // Pre-compute admin scope once to avoid N+1 per group.
    $adminJurIds = [];
    if (!$isDrupalAdmin) {
      $adminJurIds = $this->getAdminJurisdictionIds($currentAccount);
    }

    return [
      'current_account' => $currentAccount,
      'is_drupal_admin' => $isDrupalAdmin,
      'target_user' => $targetUser,
      'is_all_groups_member' => $isAllGroupsMember,
      'group_storage' => $groupStorage,
      'admin_jur_ids' => $adminJurIds,
    ];
  }

  /**
   * Builds a bounded lock name for membership updates on one user account.
   */
  protected function buildMembershipUpdateLockName(int $uid): string {
    return 'markaspot_group:membership_update:' . $uid;
  }

  /**
   * Builds the membership update response status.
   *
   * @param array $updated
   *   Successful update results keyed by group ID.
   * @param string[] $errors
   *   Error messages for rejected update items.
   *
   * @return array
   *   Response data with status ok, partial, or error.
   */
  protected function buildMembershipUpdateResponse(array $updated, array $errors): array {
    $status = match (TRUE) {
      empty($errors) => 'ok',
      empty($updated) => 'error',
      default => 'partial',
    };

    $response = [
      'status' => $status,
      'updated' => $updated,
    ];

    if (!empty($errors)) {
      $response['errors'] = $errors;
    }

    return $response;
  }

  /**
   * Gets a generic group-level rejection message.
   *
   * Keeps missing-group and out-of-scope-group failures indistinguishable for
   * tenant admins, so direct batch requests cannot probe group ID existence.
   */
  protected function getMembershipGroupRejectedMessage(int $groupId): string {
    return "Membership update rejected for group $groupId.";
  }

  /**
   * Returns detailed information for a single user.
   *
   * @param int $uid
   *   The user ID to get details for.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with detailed user data.
   */
  public function getUserDetail(int $uid): JsonResponse {
    $currentAccount = $this->currentUser();
    $isDrupalAdmin = $this->isDrupalAdminAccount($currentAccount);

    // Non-admin callers cannot view superadmin or Drupal administrator details.
    if (!$isDrupalAdmin && ($uid === 1)) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $userStorage = $this->entityTypeManager()->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $targetUser */
    $targetUser = $userStorage->load($uid);
    if (!$targetUser) {
      return new JsonResponse(['error' => 'User not found.'], 404);
    }

    // Non-admin callers cannot view Drupal administrators.
    if (!$isDrupalAdmin && in_array('administrator', $targetUser->getRoles(), TRUE)) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    // Verify the target user is within the caller's admin scope.
    if (!$isDrupalAdmin && !$this->isUserInAdminScope($targetUser, $currentAccount)) {
      return new JsonResponse(['error' => 'Access denied to this user.'], 403);
    }

    // Build memberships with group labels, scoped to caller's visible groups.
    $visibleGroups = $this->loadVisibleGroups('');
    $visibleGroupIds = array_map(fn($g) => (int) $g->id(), $visibleGroups);

    $memberships = [];
    $allMemberships = $this->membershipLoader->loadByUser($targetUser);
    foreach ($allMemberships as $membership) {
      $group = $membership->getGroup();
      $groupId = (int) $group->id();
      if (!$isDrupalAdmin && !in_array($groupId, $visibleGroupIds, TRUE)) {
        continue;
      }
      $roles = [];
      foreach ($membership->getRoles(FALSE) as $role) {
        $roleId = $role->id();
        if ($this->isJurisdictionGroup($group)) {
          $roleId = $this->canonicalizeJurisdictionRoleId($roleId);
        }
        if ($role->getScope() === PermissionScopeInterface::INDIVIDUAL_ID
          && !MembershipRoleNormalizer::isInternalRoleId($roleId)) {
          $roles[] = $roleId;
        }
      }
      $memberships[(string) $groupId] = [
        'roles' => $roles,
        'group_label' => $group->label(),
      ];
    }

    // Filter out sensitive Drupal roles.
    $exposedRoles = array_values(array_filter(
      $targetUser->getRoles(),
      fn($r) => !in_array($r, ['administrator', 'api_user'], TRUE)
    ));

    return new JsonResponse([
      'uid' => (int) $targetUser->id(),
      'name' => $targetUser->getDisplayName(),
      'email' => $targetUser->getEmail() ?? '',
      'status' => (int) $targetUser->isActive(),
      'created' => (int) $targetUser->getCreatedTime(),
      'last_login' => (int) $targetUser->getLastLoginTime(),
      'drupal_roles' => $exposedRoles,
      'memberships' => $memberships,
    ]);
  }

  /**
   * Updates a user's profile (name, email, status, anonymization).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request containing profile update data.
   * @param int $uid
   *   The user ID to update.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the updated user data or error.
   */
  public function updateUserProfile(Request $request, int $uid): JsonResponse {
    // Cannot modify superadmin.
    if ($uid === 1) {
      return new JsonResponse(['error' => 'Cannot modify the superadmin account.'], 403);
    }

    $content = json_decode($request->getContent(), TRUE);
    if (empty($content) || !is_array($content)) {
      return new JsonResponse(['error' => 'Invalid request body.'], 400);
    }

    $preflight = $this->prepareProfileUpdateContext($uid);
    if ($preflight instanceof JsonResponse) {
      return $preflight;
    }

    $lockName = $this->buildMembershipUpdateLockName($uid);
    if (!$this->lock->acquire($lockName, self::MEMBERSHIP_UPDATE_LOCK_TTL)) {
      return new JsonResponse(['error' => 'Membership update already in progress for this user.'], 409);
    }

    try {
      return $this->doUpdateUserProfile($content, $uid);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Applies a validated profile update while the target user lock is held.
   *
   * @param array $content
   *   Decoded PATCH body.
   * @param int $uid
   *   The user ID to update.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the updated user data or error.
   */
  protected function doUpdateUserProfile(array $content, int $uid): JsonResponse {
    $currentAccount = $this->currentUser();
    $isDrupalAdmin = $this->isDrupalAdminAccount($currentAccount);

    $userStorage = $this->entityTypeManager()->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $targetUser */
    $targetUser = $userStorage->load($uid);
    if (!$targetUser) {
      return new JsonResponse(['error' => 'User not found.'], 404);
    }

    // Non-admin callers cannot modify Drupal administrators.
    if (!$isDrupalAdmin && in_array('administrator', $targetUser->getRoles(), TRUE)) {
      return new JsonResponse(['error' => 'Cannot modify administrator accounts.'], 403);
    }

    // Verify the target user is within the caller's admin scope.
    if (!$isDrupalAdmin && !$this->isUserInAdminScope($targetUser, $currentAccount)) {
      return new JsonResponse(['error' => 'Access denied to this user.'], 403);
    }

    $isSelf = (int) $currentAccount->id() === $uid;
    $changes = [];
    $emailLockName = NULL;
    $emailLockAcquired = FALSE;

    try {
      // Handle anonymization (irreversible, must be processed first).
      if (!empty($content['anonymize'])) {
        if ($isSelf) {
          return new JsonResponse(['error' => 'Cannot anonymize your own account.'], 400);
        }

        if (str_starts_with($targetUser->getAccountName(), 'anonymized_')) {
          return new JsonResponse(['error' => 'User is already anonymized.'], 409);
        }

        $anonymizedName = 'anonymized_' . $uid;
        $anonymizedEmail = 'anonymized_' . $uid . '@deleted.invalid';
        $targetUser->setUsername($anonymizedName);
        if ($targetUser->hasField('field_display_name')) {
          $targetUser->set('field_display_name', $anonymizedName);
        }
        $targetUser->setEmail($anonymizedEmail);
        $targetUser->block();
        $targetUser->save();

        // Remove from all groups.
        $allMemberships = $this->membershipLoader->loadByUser($targetUser);
        foreach ($allMemberships as $membership) {
          $membership->getGroup()->removeMember($targetUser);
        }

        // Invalidate sessions.
        $this->invalidateUserSessions($uid);

        $this->getLogger('markaspot_group')->notice(
          'User @admin anonymized user @target (uid: @uid).',
          [
            '@admin' => preg_replace('/[\r\n\t]/', ' ', $currentAccount->getDisplayName()),
            '@target' => preg_replace('/[\r\n\t]/', ' ', $anonymizedName),
            '@uid' => $uid,
          ]
        );

        return new JsonResponse([
          'uid' => $uid,
          'name' => $anonymizedName,
          'email' => $anonymizedEmail,
          'status' => 0,
          'anonymized' => TRUE,
        ]);
      }

      // Handle name update.
      if (isset($content['name']) && is_string($content['name'])) {
        $newName = trim($content['name']);
        if ($newName !== '') {
          if (mb_strlen($newName) > 60) {
            return new JsonResponse(['error' => 'Name exceeds maximum length of 60 characters.'], 400);
          }
          // Check username uniqueness.
          $existingName = $userStorage->getQuery()
            ->accessCheck(FALSE)
            ->condition('name', $newName)
            ->condition('uid', $uid, '<>')
            ->count()
            ->execute();
          if ((int) $existingName > 0) {
            return new JsonResponse(['error' => 'Username is already in use.'], 409);
          }
          $targetUser->setUsername($newName);
          $changes[] = 'name';
        }
      }

      // Handle email update with uniqueness check.
      if (isset($content['email']) && is_string($content['email'])) {
        $newEmail = trim($content['email']);
        if ($newEmail !== '' && $newEmail !== $targetUser->getEmail()) {
          if (!\Drupal::service('email.validator')->isValid($newEmail)) {
            return new JsonResponse(['error' => 'Invalid email address format.'], 400);
          }
          $emailLockName = $this->buildUserEmailLockName($newEmail);
          if (!$this->lock->acquire($emailLockName, self::USER_EMAIL_LOCK_TTL)) {
            return new JsonResponse(['error' => 'Email update already in progress.'], 409);
          }
          $emailLockAcquired = TRUE;
          // Check email uniqueness.
          $existing = $userStorage->getQuery()
            ->accessCheck(FALSE)
            ->condition('mail', $newEmail)
            ->condition('uid', $uid, '<>')
            ->count()
            ->execute();
          if ((int) $existing > 0) {
            return new JsonResponse(['error' => 'Email address is already in use.'], 409);
          }
          $targetUser->setEmail($newEmail);
          $changes[] = 'email';
        }
      }

      // Handle status update.
      if (isset($content['status']) && in_array($content['status'], [0, 1], TRUE)) {
        $newStatus = (int) $content['status'];
        $currentStatus = (int) $targetUser->isActive();

        if ($newStatus !== $currentStatus) {
          if ($newStatus === 0 && $isSelf) {
            return new JsonResponse(['error' => 'Cannot deactivate your own account.'], 400);
          }

          if ($newStatus === 0) {
            $targetUser->block();
            $this->invalidateUserSessions($uid);
            $changes[] = 'blocked';
          }
          else {
            $targetUser->activate();
            $changes[] = 'activated';
          }
        }
      }

      if (!empty($changes)) {
        $violations = $targetUser->validate();
        if ($violations->count() > 0) {
          $messages = [];
          foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
          }
          return new JsonResponse(['error' => implode('; ', $messages)], 422);
        }
        $targetUser->save();

        $this->getLogger('markaspot_group')->notice(
          'User @admin updated profile for user @target (uid: @uid): @changes.',
          [
            '@admin' => preg_replace('/[\r\n\t]/', ' ', $currentAccount->getDisplayName()),
            '@target' => preg_replace('/[\r\n\t]/', ' ', $targetUser->getDisplayName()),
            '@uid' => $uid,
            '@changes' => implode(', ', $changes),
          ]
        );
      }

      // Filter out sensitive Drupal roles.
      $exposedRoles = array_values(array_filter(
        $targetUser->getRoles(),
        fn($r) => !in_array($r, ['administrator', 'api_user'], TRUE)
      ));

      return new JsonResponse([
        'uid' => (int) $targetUser->id(),
        'name' => $targetUser->getDisplayName(),
        'email' => $targetUser->getEmail() ?? '',
        'status' => (int) $targetUser->isActive(),
        'created' => (int) $targetUser->getCreatedTime(),
        'last_login' => (int) $targetUser->getLastLoginTime(),
        'drupal_roles' => $exposedRoles,
        'changes' => $changes,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_group')->error(
        'Failed to update profile for user @uid: @message',
        ['@uid' => $uid, '@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to update user profile.'], 500);
    }
    finally {
      if ($emailLockAcquired && $emailLockName !== NULL) {
        $this->lock->release($emailLockName);
      }
    }
  }

  /**
   * Loads the target user and checks caller scope for a profile mutation.
   *
   * This is run before locking to avoid lock contention as a UID probe, then
   * repeated inside the lock before applying changes.
   */
  protected function prepareProfileUpdateContext(int $uid): array|JsonResponse {
    $currentAccount = $this->currentUser();
    $isDrupalAdmin = $this->isDrupalAdminAccount($currentAccount);

    $userStorage = $this->entityTypeManager()->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $targetUser */
    $targetUser = $userStorage->load($uid);
    if (!$targetUser) {
      return new JsonResponse(['error' => 'User not found.'], 404);
    }

    // Non-admin callers cannot modify Drupal administrators.
    if (!$isDrupalAdmin && in_array('administrator', $targetUser->getRoles(), TRUE)) {
      return new JsonResponse(['error' => 'Cannot modify administrator accounts.'], 403);
    }

    // Verify the target user is within the caller's admin scope.
    if (!$isDrupalAdmin && !$this->isUserInAdminScope($targetUser, $currentAccount)) {
      return new JsonResponse(['error' => 'Access denied to this user.'], 403);
    }

    return [
      'current_account' => $currentAccount,
      'is_drupal_admin' => $isDrupalAdmin,
      'target_user' => $targetUser,
      'user_storage' => $userStorage,
    ];
  }

  /**
   * Builds a bounded lock name for a user email identity value.
   */
  protected function buildUserEmailLockName(string $email): string {
    return 'markaspot_group:user_email:' . hash('sha256', mb_strtolower(trim($email)));
  }

  /**
   * Invalidates all sessions for a given user.
   *
   * @param int $uid
   *   The user ID whose sessions should be invalidated.
   */
  protected function invalidateUserSessions(int $uid): void {
    $connection = Database::getConnection();
    // Update login timestamp directly via SQL to avoid a second entity save
    // (and its hook invocations) during flows like anonymization.
    $connection->update('users_field_data')
      ->fields(['login' => \Drupal::time()->getRequestTime()])
      ->condition('uid', $uid)
      ->execute();
    // Clear session records as belt-and-suspenders.
    $connection->delete('sessions')
      ->condition('uid', $uid)
      ->execute();
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
    $isDrupalAdmin = $this->isDrupalAdminAccount($currentAccount);

    $jurisdictionType = $this->getJurisdictionGroupType();
    $allowedTypes = [$jurisdictionType, 'org'];
    if ($groupTypeFilter === 'jur' || $groupTypeFilter === $jurisdictionType) {
      $allowedTypes = [$jurisdictionType];
    }
    elseif ($groupTypeFilter === 'org') {
      $allowedTypes = ['org'];
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
    $tenantMemberships = $this->membershipLoader->loadByUser($currentAccount, $this->jurisdictionRoleIds('tenant_admin'));
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
    if (in_array($jurisdictionType, $allowedTypes, TRUE) && !empty($visibleGroupIds)) {
      $jurGroups = $groupStorage->loadMultiple($visibleGroupIds);
      foreach ($jurGroups as $group) {
        if ($this->isJurisdictionGroup($group)) {
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
          if (MembershipRoleNormalizer::isInternalRoleId($role->id())) {
            continue;
          }
          $roleId = $groupType === $this->getJurisdictionGroupType()
            ? $this->canonicalizeJurisdictionRoleId($role->id())
            : $role->id();
          $roles[] = [
            'id' => $roleId,
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
   * For non-admin callers, restricts results to users who are members of
   * at least one of the provided visible groups. This prevents cross-tenant
   * PII exposure.
   *
   * @param int $page
   *   The page number (0-based).
   * @param int $pageSize
   *   Number of users per page.
   * @param string $search
   *   Optional search term for name or email.
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   The visible groups to load memberships for.
   * @param bool $isDrupalAdmin
   *   Whether the requesting user is a Drupal administrator.
   * @param bool $includeInactive
   *   Whether to include blocked/inactive users in the results.
   *
   * @return array
   *   Tuple of [users array, total count].
   */
  protected function loadUsers(int $page, int $pageSize, string $search, array $groups, bool $isDrupalAdmin = FALSE, bool $includeInactive = FALSE): array {
    if (!$isDrupalAdmin && empty($groups)) {
      return [[], 0];
    }

    $userStorage = $this->entityTypeManager()->getStorage('user');

    // Build user query.
    // Exclude anonymous (0) and api_user (2) always.
    $excludeUids = [0, 2];

    // Non-admin callers must not see Drupal administrators (including uid 1).
    // This prevents PII leakage (email, name) of privileged accounts.
    if (!$isDrupalAdmin) {
      $adminUids = $userStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('roles', 'administrator')
        ->execute();
      if (!empty($adminUids)) {
        $excludeUids = array_merge($excludeUids, array_map('intval', $adminUids));
      }
      // Always exclude uid 1 even if they somehow lack the administrator role.
      if (!in_array(1, $excludeUids, TRUE)) {
        $excludeUids[] = 1;
      }
    }

    $query = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $excludeUids, 'NOT IN')
      ->sort('name');

    // Only show active users unless explicitly including inactive.
    if (!$includeInactive) {
      $query->condition('status', 1);
    }

    if ($search !== '') {
      // Escape LIKE metacharacters in user input.
      $escapedSearch = addcslashes($search, '%_\\');
      $orGroup = $query->orConditionGroup()
        ->condition('name', '%' . $escapedSearch . '%', 'LIKE')
        ->condition('mail', '%' . $escapedSearch . '%', 'LIKE');
      $query->condition($orGroup);
    }

    // For non-admin callers, restrict to users who are members of visible
    // groups. This prevents tenant admins from seeing users outside their
    // jurisdiction scope.
    if (!$isDrupalAdmin && !empty($groups)) {
      $groupIds = array_map(fn($g) => (int) $g->id(), $groups);
      $connection = $this->getDatabaseConnection();
      $memberUids = $connection->select('group_relationship_field_data', 'gr')
        ->fields('gr', ['entity_id'])
        ->condition('gid', $groupIds, 'IN')
        ->condition('type', $this->membershipRelationshipTypes(), 'IN')
        ->distinct()
        ->execute()
        ->fetchCol();

      if (empty($memberUids)) {
        return [[], 0];
      }
      $query->condition('uid', array_unique(array_map('intval', $memberUids)), 'IN');
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

    $membershipsByUser = $this->loadUserMembershipsBatch($userEntities, $groupIds);

    $users = [];
    foreach ($userEntities as $user) {
      $isAllGroupsMember = $user->hasField('field_all_groups_member')
        && !$user->get('field_all_groups_member')->isEmpty()
        && (bool) $user->get('field_all_groups_member')->value;

      // Filter out sensitive Drupal roles.
      $exposedRoles = array_values(array_filter(
        $user->getRoles(),
        fn($r) => !in_array($r, ['administrator', 'api_user'], TRUE)
      ));

      $userData = [
        'uid' => (int) $user->id(),
        'name' => $user->getDisplayName(),
        'email' => $user->getEmail() ?? '',
        'status' => (int) $user->isActive(),
        'created' => (int) $user->getCreatedTime(),
        'drupal_roles' => $exposedRoles,
        'memberships' => $membershipsByUser[(int) $user->id()] ?? [],
        'all_groups_member' => $isAllGroupsMember,
      ];
      $users[] = $userData;
    }

    return [$users, $totalUsers];
  }

  /**
   * Checks whether an account has Drupal administrator privileges.
   */
  protected function isDrupalAdminAccount(AccountInterface $account): bool {
    return (int) $account->id() === 1
      || in_array('administrator', $account->getRoles(), TRUE);
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
      $group = $membership->getGroup();
      foreach ($membership->getRoles(FALSE) as $role) {
        $roleId = $role->id();
        if ($this->isJurisdictionGroup($group)) {
          $roleId = $this->canonicalizeJurisdictionRoleId($roleId);
        }
        if ($role->getScope() === PermissionScopeInterface::INDIVIDUAL_ID
          && !MembershipRoleNormalizer::isInternalRoleId($roleId)) {
          $roles[] = $roleId;
        }
      }

      $result[(string) $groupId] = ['roles' => $roles];
    }

    return $result;
  }

  /**
   * Loads visible memberships for many users without per-user loadByUser().
   *
   * @param \Drupal\user\UserInterface[] $users
   *   User entities keyed by any value.
   * @param array $groupIds
   *   Associative array of group ID => group entity for visible groups.
   *
   * @return array
   *   Memberships keyed by user ID, then group ID.
   */
  protected function loadUserMembershipsBatch(array $users, array $groupIds): array {
    if (empty($users) || empty($groupIds)) {
      return [];
    }

    $uids = [];
    foreach ($users as $user) {
      $uids[] = (int) $user->id();
    }
    $uids = array_values(array_unique(array_filter($uids)));
    if (empty($uids)) {
      return [];
    }

    $query = $this->getDatabaseConnection()->select('group_relationship_field_data', 'gr');
    $query->fields('gr', ['id', 'gid', 'entity_id']);
    $query->leftJoin(
      'group_relationship__group_roles',
      'gr_roles',
      'gr_roles.entity_id = gr.id AND gr_roles.deleted = 0 AND gr_roles.langcode = gr.langcode'
    );
    $query->addField('gr_roles', 'group_roles_target_id', 'role_id');
    $query->condition('gr.entity_id', $uids, 'IN')
      ->condition('gr.gid', array_keys($groupIds), 'IN')
      ->condition('gr.type', $this->membershipRelationshipTypes(), 'IN');

    $rows = $query->execute()->fetchAll();
    if (empty($rows)) {
      return [];
    }

    $memberships = [];
    $roleIds = [];
    foreach ($rows as $row) {
      $uid = (int) $row->entity_id;
      $groupId = (int) $row->gid;
      if (!isset($groupIds[$groupId])) {
        continue;
      }

      $groupKey = (string) $groupId;
      $memberships[$uid][$groupKey] ??= ['roles' => []];

      if (!empty($row->role_id)) {
        $roleId = (string) $row->role_id;
        $memberships[$uid][$groupKey]['roles'][] = $roleId;
        $roleIds[$roleId] = $roleId;
      }
    }

    if (empty($roleIds)) {
      return $memberships;
    }

    $individualRoleIds = [];
    $roleStorage = $this->entityTypeManager()->getStorage('group_role');
    foreach ($roleStorage->loadMultiple($roleIds) as $role) {
      if ($role->getScope() === PermissionScopeInterface::INDIVIDUAL_ID
        && !MembershipRoleNormalizer::isInternalRoleId($role->id())) {
        $individualRoleIds[$role->id()] = $this->canonicalizeJurisdictionRoleId($role->id());
      }
    }

    foreach ($memberships as &$groups) {
      foreach ($groups as &$membership) {
        $membership['roles'] = array_values(array_unique(array_filter(array_map(
          fn(string $roleId): ?string => $individualRoleIds[$roleId] ?? NULL,
          $membership['roles']
        ))));
      }
    }
    unset($membership, $groups);

    return $memberships;
  }

  /**
   * Returns the active database connection.
   */
  protected function getDatabaseConnection(): Connection {
    return Database::getConnection();
  }

  /**
   * Returns membership relationship bundles exposed by the matrix APIs.
   *
   * @return string[]
   *   Group relationship bundle IDs.
   */
  protected function membershipRelationshipTypes(): array {
    return array_values(array_unique([
      $this->getJurisdictionGroupType() . '-group_membership',
      'org-group_membership',
    ]));
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

    if ($this->isJurisdictionGroup($group)
      && \_markaspot_group_user_has_org_membership_for_jurisdiction($targetUser, $groupId)) {
      return [
        'success' => FALSE,
        'error' => "Cannot remove user from jurisdiction group $groupId while organisation membership still implies this jurisdiction.",
      ];
    }

    // Cannot self-remove tenant_admin role.
    if ((int) $targetUser->id() === (int) $currentAccount->id()) {
      $member = $group->getMember($targetUser);
      if ($member) {
        foreach ($member->getRoles(FALSE) as $role) {
          if (str_ends_with($role->id(), '-tenant_admin')) {
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
   * @param bool $isDrupalAdmin
   *   Whether the requesting user has Drupal administrator privileges.
   *
   * @return array
   *   Result array with 'success' bool and 'data' or 'error'.
   */
  protected function handleSetMembership(
    GroupInterface $group,
    UserInterface $targetUser,
    array $roleIds,
    AccountInterface $currentAccount,
    bool $isDrupalAdmin = FALSE,
  ): array {
    $groupId = (int) $group->id();
    $groupType = $group->bundle();

    foreach ($roleIds as $roleId) {
      if (!is_string($roleId) || $roleId === '') {
        return [
          'success' => FALSE,
          'error' => 'Invalid role ID.',
        ];
      }
      if (MembershipRoleNormalizer::isInternalRoleId($roleId)) {
        return [
          'success' => FALSE,
          'error' => "Role '$roleId' is managed internally.",
        ];
      }
    }

    if ($this->isJurisdictionGroup($group)) {
      $roleIds = $this->storageJurisdictionRoleIds($roleIds);
    }

    // Prevent tenant_admin from assigning tenant_admin role.
    if (!$isDrupalAdmin) {
      foreach ($roleIds as $roleId) {
        if (str_ends_with($roleId, '-tenant_admin')) {
          return [
            'success' => FALSE,
            'error' => 'Only administrators can assign the tenant_admin role.',
          ];
        }
      }
    }
    $roleIds = MembershipRoleNormalizer::normalize($roleIds, $groupType);

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
      $member = $group->getMember($targetUser);
      if ($member) {
        $hadTenantAdmin = FALSE;
        $tenantAdminRoleId = NULL;
        foreach ($member->getRoles(FALSE) as $existingRole) {
          if (str_ends_with($existingRole->id(), '-tenant_admin')) {
            $hadTenantAdmin = TRUE;
            $tenantAdminRoleId = $existingRole->id();
            break;
          }
        }
        if ($hadTenantAdmin && !in_array($tenantAdminRoleId, $roleIds, TRUE)) {
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
      $existingRoleIds = array_column($relationship->get('group_roles')->getValue(), 'target_id');
      foreach ($existingRoleIds as $existingRoleId) {
        if (is_string($existingRoleId) && MembershipRoleNormalizer::isInternalRoleId($existingRoleId)) {
          $roleIds[] = $existingRoleId;
        }
      }
      $roleIds = array_values(array_unique($roleIds));
      $relationship->set('group_roles', $roleIds);
      $relationship->save();
    }

    $responseRoleIds = array_values(array_filter(
      array_map(
        fn(string $roleId): string => $this->isJurisdictionGroup($group)
          ? $this->canonicalizeJurisdictionRoleId($roleId)
          : $roleId,
        $roleIds
      ),
      static fn(string $roleId): bool => !MembershipRoleNormalizer::isInternalRoleId($roleId)
    ));

    return [
      'success' => TRUE,
      'data' => ['action' => 'set', 'group_id' => $groupId, 'roles' => $responseRoleIds],
    ];
  }

  /**
   * Adds jurisdiction depth values without loading parent entities per group.
   *
   * @param array $groupsData
   *   Flat array of group data entries.
   * @param array<int, int|null> $parentById
   *   Jurisdiction parent IDs keyed by jurisdiction ID.
   *
   * @return array
   *   Group data with map-computed jurisdiction depth values.
   */
  protected function addJurisdictionDepths(array $groupsData, array $parentById): array {
    $depthCache = [];
    foreach ($groupsData as &$entry) {
      if ($entry['type'] === 'jur') {
        $entry['depth'] = $this->calculateJurisdictionDepth((int) $entry['id'], $parentById, $depthCache);
      }
    }
    unset($entry);

    return $groupsData;
  }

  /**
   * Loads jurisdiction parent references in one query for depth calculation.
   *
   * @param array $groupsData
   *   Flat array of group data entries.
   *
   * @return array<int, int|null>
   *   Jurisdiction parent IDs keyed by jurisdiction ID.
   */
  protected function loadJurisdictionParentMap(array $groupsData): array {
    $parentById = [];
    foreach ($groupsData as $entry) {
      if ($entry['type'] === 'jur') {
        $parentById[(int) $entry['id']] = $entry['parent_id'] === NULL
          ? NULL
          : (int) $entry['parent_id'];
      }
    }

    if (empty($parentById)) {
      return [];
    }

    $query = $this->getDatabaseConnection()->select('groups_field_data', 'g');
    $query->leftJoin(
      'group__field_parent_jurisdiction',
      'p',
      'p.entity_id = g.id AND p.deleted = 0'
    );
    $query->fields('g', ['id']);
    $query->addField('p', 'field_parent_jurisdiction_target_id', 'parent_id');
    $query->condition('g.type', $this->getJurisdictionGroupType());

    foreach ($query->execute() as $row) {
      $parentById[(int) $row->id] = $row->parent_id === NULL
        ? NULL
        : (int) $row->parent_id;
    }

    return $parentById;
  }

  /**
   * Calculates a jurisdiction depth from a precomputed parent map.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param array<int, int|null> $parentById
   *   Jurisdiction parent IDs keyed by jurisdiction ID.
   * @param array<int, int> $depthCache
   *   Memoized depth values keyed by jurisdiction ID.
   *
   * @return int
   *   Depth level: 0 for root, 1 for child, 2 for grandchild, etc.
   */
  protected function calculateJurisdictionDepth(int $groupId, array $parentById, array &$depthCache): int {
    if (isset($depthCache[$groupId])) {
      return $depthCache[$groupId];
    }

    $path = [];
    $currentId = $groupId;
    $baseDepth = 0;
    $attachToCachedParent = FALSE;

    while (TRUE) {
      if (isset($depthCache[$currentId])) {
        $baseDepth = $depthCache[$currentId];
        $attachToCachedParent = TRUE;
        break;
      }

      if (isset($path[$currentId])) {
        foreach (array_keys($path) as $pathId) {
          $depthCache[$pathId] = 0;
        }
        return $depthCache[$groupId] ?? 0;
      }

      $path[$currentId] = TRUE;
      $parentId = $parentById[$currentId] ?? NULL;
      if ($parentId === NULL) {
        break;
      }

      if (!array_key_exists($parentId, $parentById)) {
        $path[$parentId] = TRUE;
        break;
      }

      $currentId = $parentId;
    }

    $depth = $baseDepth + ($attachToCachedParent ? 1 : 0);
    foreach (array_reverse(array_keys($path)) as $pathId) {
      $depthCache[$pathId] = $depth;
      $depth++;
    }

    return $depthCache[$groupId] ?? 0;
  }

  /**
   * Sorts groups in tree order: jur groups depth-first, then org groups.
   *
   * @param array $groupsData
   *   Flat array of group data entries.
   *
   * @return array
   *   Groups sorted with jur in tree order followed by org groups.
   */
  protected function sortGroupsTreeOrder(array $groupsData): array {
    $jurGroups = [];
    $orgGroups = [];

    foreach ($groupsData as $entry) {
      if ($entry['type'] === 'jur') {
        $jurGroups[$entry['id']] = $entry;
      }
      else {
        $orgGroups[] = $entry;
      }
    }

    // Build children map for depth-first traversal.
    $childrenMap = [];
    $roots = [];
    foreach ($jurGroups as $id => $entry) {
      $parentId = $entry['parent_id'];
      if ($parentId === NULL || !isset($jurGroups[$parentId])) {
        $roots[] = $id;
      }
      else {
        $childrenMap[$parentId][] = $id;
      }
    }

    // Sort roots and children alphabetically by label.
    usort($roots, fn($a, $b) => strcasecmp($jurGroups[$a]['label'], $jurGroups[$b]['label']));
    foreach ($childrenMap as &$children) {
      usort($children, fn($a, $b) => strcasecmp($jurGroups[$a]['label'], $jurGroups[$b]['label']));
    }

    // Depth-first traversal.
    $sorted = [];
    $stack = array_reverse($roots);
    while (!empty($stack)) {
      $id = array_pop($stack);
      if (isset($jurGroups[$id])) {
        $sorted[] = $jurGroups[$id];
      }
      if (!empty($childrenMap[$id])) {
        foreach (array_reverse($childrenMap[$id]) as $childId) {
          $stack[] = $childId;
        }
      }
    }

    // Org groups follow, sorted alphabetically.
    usort($orgGroups, fn($a, $b) => strcasecmp($a['label'], $b['label']));

    return array_merge($sorted, $orgGroups);
  }

  /**
   * Builds the set of jurisdiction IDs a tenant admin can manage.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return int[]
   *   Array of jurisdiction group IDs (including descendants).
   */
  protected function getAdminJurisdictionIds(AccountInterface $account): array {
    $tenantMemberships = $this->membershipLoader->loadByUser($account, $this->jurisdictionRoleIds('tenant_admin'));
    if (empty($tenantMemberships)) {
      return [];
    }

    $adminJurIds = [];
    foreach ($tenantMemberships as $membership) {
      $group = $membership->getGroup();
      if (!$this->isJurisdictionGroup($group)) {
        continue;
      }
      $jurId = (int) $group->id();
      $adminJurIds[] = $jurId;
      $adminJurIds = array_merge($adminJurIds, $this->hierarchyResolver->getDescendantIds($jurId));
    }
    return array_values(array_unique($adminJurIds));
  }

  /**
   * Checks whether a group falls within a pre-computed admin scope.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to check.
   * @param int[] $adminJurIds
   *   Pre-computed array of administered jurisdiction IDs.
   *
   * @return bool
   *   TRUE if the group is in scope.
   */
  protected function isGroupInAdminScopeWith(GroupInterface $group, array $adminJurIds): bool {
    if (empty($adminJurIds)) {
      return FALSE;
    }

    // Jur group: must be in the administered set.
    if ($this->isJurisdictionGroup($group)) {
      return in_array((int) $group->id(), $adminJurIds, TRUE);
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

  /**
   * Checks whether a group falls within the current user's admin scope.
   *
   * Convenience wrapper that computes the admin scope on the fly.
   * For batch operations, prefer getAdminJurisdictionIds() +
   * isGroupInAdminScopeWith() to avoid N+1.
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
    return $this->isGroupInAdminScopeWith($group, $this->getAdminJurisdictionIds($account));
  }

  /**
   * Checks whether a target user is within the caller's admin scope.
   *
   * A target user is in scope if they hold membership in at least one group
   * that the calling tenant admin can see (via loadVisibleGroups).
   *
   * @param \Drupal\user\UserInterface $targetUser
   *   The user to check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The requesting user account.
   *
   * @return bool
   *   TRUE if the target user is in at least one of the caller's visible
   *   groups.
   */
  protected function isUserInAdminScope(UserInterface $targetUser, AccountInterface $account): bool {
    $visibleGroups = $this->loadVisibleGroups('');
    $visibleGroupIds = array_map(fn($g) => (int) $g->id(), $visibleGroups);

    $targetMemberships = $this->membershipLoader->loadByUser($targetUser);
    foreach ($targetMemberships as $membership) {
      if (in_array((int) $membership->getGroup()->id(), $visibleGroupIds, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
