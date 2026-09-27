<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\user\UserInterface;

/**
 * Gives editorial staff full rights in every organisation of their tenant.
 *
 * Editorial users (Drupal role editorial_board) are members of all
 * organisations of their jurisdiction and may do everything there. The rights
 * come from the internal individual group role org-editorial, which only this
 * service assigns and only inside the editor's own tenant; an org membership
 * of an editor anywhere else carries no editorial rights. Memberships the
 * service creates also carry the internal marker role org-editorial_member,
 * so revocation deletes exactly those and keeps memberships that existed
 * before (a moderator's own organisations, for example).
 *
 * The scope is the root jurisdiction. It comes from jurisdiction memberships
 * that carry a staff role (admin, editorial, moderator, tenant_admin). Plain,
 * member-only and mirrored memberships are weak: they count only when the
 * site has exactly one root, so the single-tenant customer installs need no
 * setup and a multi-tenant site never widens scope by accident. A membership
 * that cannot be placed in the hierarchy fails closed.
 *
 * Administrators and uid 1 are not editors here: they have everything anyway.
 * They only bypass the scope of the "All groups member" flag.
 */
final class EditorialOrgMembership {

  /**
   * Drupal role whose holders get the editorial org rights.
   */
  public const EDITORIAL_ROLE = 'editorial_board';

  /**
   * Organisation group type.
   */
  private const ORG_GROUP_TYPE = 'org';

  /**
   * Accounts being synchronized.
   *
   * The org-to-jurisdiction membership mirror re-enters through
   * hook_group_relationship_insert().
   *
   * @var array<int, true>
   */
  private static array $syncing = [];

  /**
   * Accounts being pruned; revocation re-enters through the mirror cleanup.
   *
   * @var array<int, true>
   */
  private static array $pruning = [];

  /**
   * Whether this service is writing editorial roles right now.
   *
   * The group_relationship presave guard only lets the editorial roles change
   * while this is set.
   */
  private static bool $writing = FALSE;

  /**
   * Groups being deleted in this request.
   *
   * @var array<int, true>
   */
  private static array $deletingGroups = [];

  /**
   * Scope per account for the current request.
   *
   * @var array<int, int[]>
   */
  private array $scopeCache = [];

  /**
   * Tenant-admin jurisdictions per account for the current request.
   *
   * The Group UI checks every membership row of a list against it.
   *
   * @var array<int, int[]>
   */
  private array $tenantAdminScopeCache = [];

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   The fail-closed jurisdiction hierarchy resolver.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether this service is currently writing editorial roles.
   */
  public static function isWriting(): bool {
    return self::$writing;
  }

  /**
   * Keeps the editorial roles of a membership exactly as they were stored.
   *
   * Only this service assigns org-editorial and org-editorial_member; any
   * other write (Group forms, JSON:API, the member matrix, imports) can
   * neither add them nor drop them.
   */
  public static function guardEditorialRoles(GroupRelationshipInterface $relationship): void {
    if (self::$writing
      || $relationship->getPluginId() !== 'group_membership'
      || $relationship->getGroupTypeId() !== self::ORG_GROUP_TYPE
      || !$relationship->hasField('group_roles')) {
      return;
    }
    $editorialRoles = [
      MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID,
      MembershipRoleNormalizer::EDITORIAL_ORG_MARKER_ROLE_ID,
    ];
    $submitted = array_column($relationship->get('group_roles')->getValue(), 'target_id');
    $original = $relationship->isNew() ? NULL : $relationship->getOriginal();
    $stored = $original instanceof GroupRelationshipInterface && $original->hasField('group_roles')
      ? array_column($original->get('group_roles')->getValue(), 'target_id')
      : [];
    $roles = array_values(array_unique(array_merge(
      array_diff($submitted, $editorialRoles),
      array_intersect($stored, $editorialRoles),
    )));
    if ($roles !== array_values($submitted)) {
      $relationship->set('group_roles', $roles);
    }
  }

  /**
   * Marks a group whose relationships are about to be deleted with it.
   */
  public static function markGroupDeleting(int $groupId): void {
    self::$deletingGroups[$groupId] = TRUE;
  }

  /**
   * Forgets a deletion mark once the group is gone.
   */
  public static function clearGroupDeleting(int $groupId): void {
    unset(self::$deletingGroups[$groupId]);
  }

  /**
   * Re-grants an editorial membership that was deleted by hand.
   *
   * Editorial memberships are managed by role; deleting one through the
   * Group UI or JSON:API must not remove an editor from an organisation of
   * their tenant. Deletions by this service, of the whole organisation and of
   * deleted or demoted users are left alone.
   */
  public function restoreDeletedMembership(GroupRelationshipInterface $relationship): void {
    if (self::$writing
      || $relationship->getPluginId() !== 'group_membership'
      || $relationship->getGroupTypeId() !== self::ORG_GROUP_TYPE
      || isset(self::$deletingGroups[(int) $relationship->getGroupId()])
      || !$relationship->hasField('group_roles')) {
      return;
    }
    $roleIds = array_column($relationship->get('group_roles')->getValue(), 'target_id');
    if (!in_array(MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID, $roleIds, TRUE)) {
      return;
    }
    $uid = (int) $relationship->get('entity_id')->target_id;
    $this->forgetCachedUser($uid);
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user instanceof UserInterface && $this->isEditor($user)) {
      $this->syncEditor($user);
    }
  }

  /**
   * Refuses to move an existing membership to another user.
   *
   * The roles of a membership belong to its user; re-targeting entity_id
   * (possible through JSON:API) would hand them to someone else.
   */
  public static function guardMembershipIdentity(GroupRelationshipInterface $relationship): void {
    if ($relationship->isNew() || $relationship->getPluginId() !== 'group_membership') {
      return;
    }
    $original = $relationship->getOriginal();
    if ($original instanceof GroupRelationshipInterface
      && (int) $original->get('entity_id')->target_id !== (int) $relationship->get('entity_id')->target_id) {
      throw new EntityStorageException('The user of an existing group membership cannot be changed.');
    }
  }

  /**
   * Jurisdictions an account administers as tenant admin, with descendants.
   *
   * The same scope as GroupMembersController::getTenantAdminJurisdictionIds();
   * the editorial scope is deliberately not part of it.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  public function tenantAdminJurisdictionIds(AccountInterface $account): array {
    return $this->tenantAdminScopeCache[(int) $account->id()] ??= $this->computeTenantAdminJurisdictionIds($account);
  }

  /**
   * Computes the tenant-admin jurisdictions of an account.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  private function computeTenantAdminJurisdictionIds(AccountInterface $account): array {
    $rows = $this->entityTypeManager->getStorage('group_relationship')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('group_type', $this->jurisdictionGroupType())
      ->condition('entity_id', (int) $account->id())
      ->condition('group_roles', TenantAdminHelper::getTenantAdminRoleIds(), 'IN')
      ->groupBy('gid')
      ->execute();
    $jurisdictionIds = [];
    foreach (array_column($rows, 'gid') as $groupId) {
      $jurisdictionIds[] = (int) $groupId;
      foreach ($this->hierarchyResolver->getDescendantIds((int) $groupId) as $descendantId) {
        $jurisdictionIds[] = (int) $descendantId;
      }
    }
    return array_values(array_unique($jurisdictionIds));
  }

  /**
   * Whether an account is an editor, tenant admin or administrator.
   *
   * Editors manage plain members and moderators; these accounts are their
   * peers or supervisors and stay with tenant administrators. The same rule
   * as GroupMembersController::isProtectedPeer().
   */
  public function isProtectedPeer(UserInterface $user): bool {
    if (array_intersect(['administrator', self::EDITORIAL_ROLE, 'tenant_admin'], $user->getRoles())) {
      return TRUE;
    }
    return (bool) $this->entityTypeManager->getStorage('group_relationship')->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('group_type', $this->jurisdictionGroupType())
      ->condition('entity_id', (int) $user->id())
      ->condition('group_roles', TenantAdminHelper::getTenantAdminRoleIds(), 'IN')
      ->range(0, 1)
      ->count()
      ->execute();
  }

  /**
   * Whether a change to an organisation membership stays with tenant admins.
   *
   * Applies the member matrix rule to the Group UI: outside their
   * tenant-admin scope, editors change neither their own roles nor the
   * memberships of editors, tenant admins and administrators. Leaving an
   * organisation stays possible.
   *
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   The acting account.
   * @param \Drupal\group\Entity\GroupInterface $organisation
   *   The organisation of the membership.
   * @param \Drupal\user\UserInterface $target
   *   The member.
   * @param string $operation
   *   The entity operation, 'update' or 'delete'.
   */
  public function isReservedForTenantAdmins(AccountInterface $actor, GroupInterface $organisation, UserInterface $target, string $operation): bool {
    if (!$this->isEditor($actor) || $this->isInTenantAdminScope($actor, $organisation)) {
      return FALSE;
    }
    if ((int) $target->id() === (int) $actor->id()) {
      return $operation === 'update';
    }
    return $this->isProtectedPeer($target);
  }

  /**
   * Whether an editor may add an account to an organisation.
   *
   * Outside their tenant-admin scope, editors add only accounts that already
   * belong to their tenant and are no peers, as in the member matrix.
   */
  public function mayAddMember(AccountInterface $actor, GroupInterface $organisation, UserInterface $target): bool {
    if (!$this->isEditor($actor) || $this->isInTenantAdminScope($actor, $organisation)) {
      return TRUE;
    }
    if ($this->isProtectedPeer($target)) {
      return FALSE;
    }
    $roots = $this->rootJurisdictionIds($actor);
    if (!$roots) {
      return FALSE;
    }
    $groupIds = $this->entityTypeManager->getStorage('group_relationship')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('entity_id', (int) $target->id())
      ->groupBy('gid')
      ->execute();
    $groups = $this->entityTypeManager->getStorage('group')
      ->loadMultiple(array_map('intval', array_column($groupIds, 'gid')));
    foreach ($groups as $group) {
      $root = $group->bundle() === self::ORG_GROUP_TYPE
        ? $this->organisationRootId($group)
        : $this->hierarchyResolver->getRootJurisdictionId((int) $group->id());
      if ($root !== NULL && in_array($root, $roots, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether an organisation lies in the actor's tenant-admin scope.
   */
  private function isInTenantAdminScope(AccountInterface $actor, GroupInterface $organisation): bool {
    $untranslated = $organisation->getUntranslated();
    if (!$untranslated->hasField('field_jurisdiction') || $untranslated->get('field_jurisdiction')->isEmpty()) {
      return FALSE;
    }
    return in_array(
      (int) $untranslated->get('field_jurisdiction')->target_id,
      $this->tenantAdminJurisdictionIds($actor),
      TRUE,
    );
  }

  /**
   * Whether an account is uid 1 or a Drupal administrator.
   */
  public function hasBypass(AccountInterface $account): bool {
    return (int) $account->id() === 1
      || in_array('administrator', $account->getRoles(), TRUE);
  }

  /**
   * Whether an account gets the editorial org rights.
   */
  public function isEditor(AccountInterface $account): bool {
    if ($account instanceof UserInterface && !$account->isActive()) {
      return FALSE;
    }
    return in_array(self::EDITORIAL_ROLE, $account->getRoles(), TRUE)
      && !$this->hasBypass($account);
  }

  /**
   * Root jurisdiction IDs an account is scoped to.
   *
   * @return int[]
   *   Root jurisdiction group IDs; empty when the account has no scope.
   */
  public function rootJurisdictionIds(AccountInterface $account): array {
    $uid = (int) $account->id();
    return $this->scopeCache[$uid] ??= $this->computeRootJurisdictionIds($account);
  }

  /**
   * Forgets cached scopes after membership changes.
   */
  public function resetScope(?int $uid = NULL): void {
    if ($uid === NULL) {
      $this->scopeCache = [];
      $this->tenantAdminScopeCache = [];
      return;
    }
    unset($this->scopeCache[$uid], $this->tenantAdminScopeCache[$uid]);
  }

  /**
   * Whether an account may be added to an organisation automatically.
   *
   * Used for editorial memberships and for the "All groups member" flag.
   */
  public function mayJoinOrganisation(AccountInterface $account, GroupInterface $organisation): bool {
    if ($this->hasBypass($account)) {
      return TRUE;
    }
    $root = $this->organisationRootId($organisation);
    return $root !== NULL && in_array($root, $this->rootJurisdictionIds($account), TRUE);
  }

  /**
   * Grants an editor the editorial role in every organisation of their scope.
   *
   * @return int
   *   Number of memberships created or granted the editorial role.
   */
  public function syncEditor(UserInterface $account): int {
    $uid = (int) $account->id();
    if (!$this->isEditor($account) || isset(self::$syncing[$uid])) {
      return 0;
    }
    $this->forgetCachedUser($uid);
    $roots = $this->rootJurisdictionIds($account);
    if (!$roots) {
      $this->loggerFactory->get('markaspot_group')->notice(
        'Editorial user @uid has no jurisdiction scope; no organisation rights granted.',
        ['@uid' => $uid],
      );
      return 0;
    }

    self::$syncing[$uid] = TRUE;
    try {
      $granted = 0;
      foreach ($this->organisationsInRoots($roots) as $organisation) {
        // The query matches any translation; the default translation decides.
        if (!isset(self::$deletingGroups[(int) $organisation->id()])
          && in_array($this->organisationRootId($organisation), $roots, TRUE)
          && $this->grant($organisation, $account)) {
          $granted++;
        }
      }
      return $granted;
    }
    finally {
      unset(self::$syncing[$uid]);
      $this->resetScope($uid);
    }
  }

  /**
   * Revokes editorial rights the account should no longer hold.
   *
   * Removes the editorial role from every organisation outside the account's
   * scope, or from all of them once the account is no longer an editor, and
   * deletes the memberships this service created.
   *
   * @return int
   *   Number of memberships revoked.
   */
  public function pruneEditor(UserInterface $account): int {
    $uid = (int) $account->id();
    if (isset(self::$syncing[$uid]) || isset(self::$pruning[$uid])) {
      return 0;
    }
    $this->resetScope($uid);
    $this->forgetCachedUser($uid);
    self::$pruning[$uid] = TRUE;
    try {
      $revoked = 0;
      foreach ($this->editorialMemberships(['entity_id' => $uid]) as $relationship) {
        $organisation = $relationship->getGroup();
        if (!$organisation instanceof GroupInterface
          || !$this->isEditor($account)
          || !$this->mayJoinOrganisation($account, $organisation)) {
          $this->revoke($relationship);
          $revoked++;
        }
      }
      return $revoked;
    }
    finally {
      unset(self::$pruning[$uid]);
    }
  }

  /**
   * Grants every in-scope editor the editorial role in one organisation.
   *
   * @return int
   *   Number of memberships created or granted the editorial role.
   */
  public function syncOrganisation(GroupInterface $organisation): int {
    if ($organisation->bundle() !== self::ORG_GROUP_TYPE
      || isset(self::$deletingGroups[(int) $organisation->id()])
      || $this->organisationRootId($organisation) === NULL) {
      return 0;
    }
    $granted = 0;
    foreach ($this->editors() as $editor) {
      if (!isset(self::$syncing[(int) $editor->id()])
        && $this->mayJoinOrganisation($editor, $organisation)
        && $this->grant($organisation, $editor)) {
        $granted++;
      }
    }
    return $granted;
  }

  /**
   * Revokes editorial rights that no longer match an organisation's tenant.
   *
   * Runs when an organisation moves to another jurisdiction, so editorial
   * rights never follow an organisation into a foreign tenant.
   *
   * @return int
   *   Number of memberships revoked.
   */
  public function pruneOrganisation(GroupInterface $organisation): int {
    if ($organisation->bundle() !== self::ORG_GROUP_TYPE) {
      return 0;
    }
    $revoked = 0;
    foreach ($this->editorialMemberships(['gid' => $organisation->id()]) as $relationship) {
      $user = $relationship->getEntity();
      if (!$user instanceof UserInterface
        || !$this->isEditor($user)
        || !$this->mayJoinOrganisation($user, $organisation)) {
        $this->revoke($relationship);
        $revoked++;
      }
    }
    return $revoked;
  }

  /**
   * Re-applies the editorial rule to all active editors.
   *
   * @return array{editors: int, granted: int, revoked: int, without_scope: int, roots: int}
   *   Counts for update and drush reports.
   */
  public function syncAllEditors(): array {
    $counts = [
      'editors' => 0,
      'granted' => 0,
      'revoked' => 0,
      'without_scope' => 0,
      'roots' => 0,
    ];
    $editors = $this->editors();
    $strayHolders = $this->nonEditorHolders(array_map('intval', array_keys($editors)));
    if (!$editors && !$strayHolders) {
      return $counts;
    }
    // Hand-assigned before the guard was active, or left behind by a
    // demotion that bypassed the hooks.
    foreach ($strayHolders as $holder) {
      $counts['revoked'] += $this->pruneEditor($holder);
    }
    $this->hierarchyResolver->resetCache();
    $this->resetScope();
    $counts['roots'] = count($this->hierarchyResolver->getAllRootJurisdictionIds());
    foreach ($editors as $editor) {
      $counts['editors']++;
      $counts['revoked'] += $this->pruneEditor($editor);
      if (!$this->rootJurisdictionIds($editor)) {
        $counts['without_scope']++;
        continue;
      }
      $counts['granted'] += $this->syncEditor($editor);
    }
    return $counts;
  }

  /**
   * Resolves the root jurisdiction of an organisation's default translation.
   */
  public function organisationRootId(GroupInterface $organisation): ?int {
    $organisation = $organisation->isDefaultTranslation() ? $organisation : $organisation->getUntranslated();
    if (!$organisation->hasField('field_jurisdiction') || $organisation->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $jurisdictionId = (int) $organisation->get('field_jurisdiction')->target_id;
    return $jurisdictionId > 0 ? $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId) : NULL;
  }

  /**
   * Drops a user from the entity caches before memberships change.
   *
   * Group re-saves the member user whenever a membership is added or deleted.
   * Inside a user save the entity cache still holds the pre-save account, so
   * that re-save would write the old roles back (and re-grant a revoked
   * editor). The account row is already written when the user hooks run, so
   * a fresh load is correct.
   */
  private function forgetCachedUser(int $uid): void {
    $this->entityTypeManager->getStorage('user')->resetCache([$uid]);
  }

  /**
   * Computes the root jurisdiction scope of an account.
   *
   * @return int[]
   *   Root jurisdiction group IDs.
   */
  private function computeRootJurisdictionIds(AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $relationshipIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('entity_id', $account->id())
      ->condition('group_type', $this->jurisdictionGroupType())
      ->execute();

    $staffRoles = $this->staffRoleIds();
    $explicitRoots = [];
    $weakRoots = [];
    foreach ($storage->loadMultiple($relationshipIds) as $relationship) {
      $root = $this->hierarchyResolver->getRootJurisdictionId((int) $relationship->getGroupId());
      if ($root === NULL) {
        // Fail closed: a membership we cannot place must not fall back to
        // the single-root default.
        return [];
      }
      $roleIds = array_column($relationship->get('group_roles')->getValue(), 'target_id');
      if (array_intersect($roleIds, $staffRoles)) {
        $explicitRoots[$root] = $root;
      }
      else {
        $weakRoots[$root] = $root;
      }
    }
    if ($explicitRoots) {
      return array_values($explicitRoots);
    }

    $singleRoot = $this->singleRootId();
    if ($singleRoot === NULL || array_diff(array_values($weakRoots), [$singleRoot]) !== []) {
      return [];
    }
    return [$singleRoot];
  }

  /**
   * The root of a single-jurisdiction site, or NULL.
   *
   * Every jurisdiction must resolve, and all to the same root; a broken
   * hierarchy disables the fallback instead of guessing.
   */
  private function singleRootId(): ?int {
    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->jurisdictionGroupType())
      ->execute();
    $roots = [];
    foreach ($ids as $id) {
      $root = $this->hierarchyResolver->getRootJurisdictionId((int) $id);
      if ($root === NULL) {
        return NULL;
      }
      $roots[$root] = $root;
      if (count($roots) > 1) {
        return NULL;
      }
    }
    return count($roots) === 1 ? (int) reset($roots) : NULL;
  }

  /**
   * Jurisdiction staff role IDs that establish an editor's scope.
   *
   * @return string[]
   *   Role IDs.
   */
  private function staffRoleIds(): array {
    $groupType = $this->jurisdictionGroupType();
    $roleIds = [];
    foreach (WorkspaceVisibilityService::ELEVATED_JURISDICTION_ROLE_SUFFIXES as $suffix) {
      $roleIds[] = $groupType . '-' . $suffix;
      $roleIds[] = 'jur-' . $suffix;
    }
    return array_values(array_unique($roleIds));
  }

  /**
   * Makes an account an editorial member of an organisation.
   *
   * @return bool
   *   TRUE when a membership was created or the role was added.
   */
  private function grant(GroupInterface $organisation, UserInterface $account): bool {
    if (!$this->rolesAvailable()) {
      return FALSE;
    }
    $relationship = GroupMembership::loadSingle($organisation, $account);
    $roleIds = [];
    if ($relationship) {
      $roleIds = array_column($relationship->get('group_roles')->getValue(), 'target_id');
      if (in_array(MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID, $roleIds, TRUE)) {
        return FALSE;
      }
      $roleIds[] = MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID;
    }
    $this->writeEditorialRoles(function () use ($organisation, $account, $relationship, $roleIds): void {
      if (!$relationship) {
        $organisation->addMember($account, [
          'group_roles' => [
            MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID,
            MembershipRoleNormalizer::EDITORIAL_ORG_MARKER_ROLE_ID,
          ],
        ]);
        return;
      }
      $relationship->set('group_roles', $roleIds)->save();
    });
    return TRUE;
  }

  /**
   * Whether both editorial org roles exist.
   *
   * They ship as optional config and are created by update 11954. Until then
   * (and on sites without organisations) there is nothing to grant, and
   * saving users or groups must not fail.
   */
  private function rolesAvailable(): bool {
    $roles = $this->entityTypeManager->getStorage('group_role')->loadMultiple([
      MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID,
      MembershipRoleNormalizer::EDITORIAL_ORG_MARKER_ROLE_ID,
    ]);
    return count($roles) === 2;
  }

  /**
   * Runs a write that may change the editorial roles.
   */
  private function writeEditorialRoles(callable $write): void {
    $previous = self::$writing;
    self::$writing = TRUE;
    try {
      $write();
    }
    finally {
      self::$writing = $previous;
    }
  }

  /**
   * Removes the editorial role; deletes memberships this service created.
   */
  private function revoke(GroupRelationshipInterface $relationship): void {
    $roleIds = array_column($relationship->get('group_roles')->getValue(), 'target_id');
    $created = in_array(MembershipRoleNormalizer::EDITORIAL_ORG_MARKER_ROLE_ID, $roleIds, TRUE);
    $remaining = array_values(array_diff($roleIds, [
      MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID,
      MembershipRoleNormalizer::EDITORIAL_ORG_MARKER_ROLE_ID,
    ]));
    $this->writeEditorialRoles(function () use ($relationship, $created, $remaining): void {
      if ($created && $remaining === []) {
        $relationship->delete();
        return;
      }
      $relationship->set('group_roles', $remaining)->save();
    });
  }

  /**
   * Loads org memberships that carry the editorial role.
   *
   * @param array $conditions
   *   Extra property conditions (gid or entity_id).
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface[]
   *   Membership relationships.
   */
  private function editorialMemberships(array $conditions): array {
    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('group_type', self::ORG_GROUP_TYPE)
      ->condition('group_roles', MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    $ids = $query->execute();
    if (!$ids) {
      return [];
    }
    // Relationships created in this request still embed the account object
    // they were created with; Group re-saves that object when a membership is
    // deleted, which would write stale roles back. Load them fresh.
    $storage->resetCache($ids);
    return $storage->loadMultiple($ids);
  }

  /**
   * Accounts holding an editorial org role without being editors.
   *
   * @param int[] $editorIds
   *   User IDs of the current editors.
   *
   * @return \Drupal\user\UserInterface[]
   *   The holders, keyed by user ID.
   */
  private function nonEditorHolders(array $editorIds): array {
    $query = $this->entityTypeManager->getStorage('group_relationship')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('group_type', self::ORG_GROUP_TYPE)
      ->condition('group_roles', MembershipRoleNormalizer::EDITORIAL_ORG_ROLE_ID)
      ->groupBy('entity_id');
    if ($editorIds) {
      $query->condition('entity_id', $editorIds, 'NOT IN');
    }
    $uids = array_map('intval', array_column($query->execute(), 'entity_id'));
    return $uids ? $this->entityTypeManager->getStorage('user')->loadMultiple($uids) : [];
  }

  /**
   * Loads the active editorial users.
   *
   * @return \Drupal\user\UserInterface[]
   *   Active editorial users that are not administrators.
   */
  private function editors(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('roles', self::EDITORIAL_ROLE)
      ->condition('status', 1)
      ->execute();
    return array_filter(
      $uids ? $storage->loadMultiple($uids) : [],
      fn(UserInterface $user) => $this->isEditor($user),
    );
  }

  /**
   * Loads candidate organisations whose jurisdiction lies under the roots.
   *
   * @param int[] $roots
   *   Root jurisdiction group IDs.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Organisation groups; callers re-check the default translation.
   */
  private function organisationsInRoots(array $roots): array {
    $jurisdictionIds = [];
    foreach ($roots as $root) {
      $jurisdictionIds[] = $root;
      foreach ($this->hierarchyResolver->getDescendantIds($root) as $descendant) {
        $jurisdictionIds[] = (int) $descendant;
      }
    }
    $storage = $this->entityTypeManager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ORG_GROUP_TYPE)
      ->condition('field_jurisdiction', array_values(array_unique($jurisdictionIds)), 'IN')
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Gets the configured jurisdiction group type.
   */
  private function jurisdictionGroupType(): string {
    return $this->configFactory->get('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur';
  }

}
