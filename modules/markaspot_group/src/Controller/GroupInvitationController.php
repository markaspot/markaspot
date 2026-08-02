<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_nuxt\Service\FrontendUrlService;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for group member invitation endpoints.
 *
 * Provides REST endpoints for inviting users to groups via email tokens.
 * Reuses the same admin scope and tenant isolation patterns as
 * GroupMembersController.
 */
class GroupInvitationController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Invitation token expiry: 7 days in seconds.
   */
  protected const TOKEN_EXPIRY_SECONDS = 7 * 86400;

  /**
   * Claim endpoint flood event name.
   */
  protected const CLAIM_FLOOD_EVENT = 'markaspot_group.invitation_claim';

  /**
   * Maximum claim attempts per IP per flood window.
   */
  protected const CLAIM_FLOOD_LIMIT = 5;

  /**
   * Claim flood window in seconds.
   */
  protected const CLAIM_FLOOD_WINDOW = 3600;

  /**
   * Lock TTL for one user's membership mutations.
   */
  protected const MEMBERSHIP_UPDATE_LOCK_TTL = 120.0;

  /**
   * Lock TTL for email identity mutations.
   */
  protected const USER_EMAIL_LOCK_TTL = 120.0;

  /**
   * Constructs a GroupInvitationController.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\group\GroupMembershipLoaderInterface $membershipLoader
   *   The group membership loader.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param object|null $tierConfigService
   *   The tier config service (from markaspot_fastmap), or NULL.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface|null $entityRepository
   *   The entity repository service.
   * @param \Drupal\markaspot_nuxt\Service\FrontendUrlService|null $frontendUrlService
   *   The public frontend URL resolver, or NULL when markaspot_nuxt is absent.
   */
  public function __construct(
    protected readonly Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    protected readonly MailManagerInterface $mailManager,
    ModuleHandlerInterface $moduleHandler,
    protected readonly LoggerInterface $logger,
    protected readonly GroupMembershipLoaderInterface $membershipLoader,
    protected readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    AccountInterface $currentUser,
    protected readonly FloodInterface $flood,
    protected readonly LockBackendInterface $lock,
    protected readonly ?object $tierConfigService = NULL,
    protected readonly ?EntityRepositoryInterface $entityRepository = NULL,
    protected readonly ?FrontendUrlService $frontendUrlService = NULL,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $moduleHandler = $container->get('module_handler');

    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $moduleHandler,
      $container->get('logger.factory')->get('markaspot_group'),
      $container->get('group.membership_loader'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('current_user'),
      $container->get('flood'),
      $container->get('lock'),
      $moduleHandler->moduleExists('markaspot_fastmap')
        ? $container->get('markaspot_fastmap.tier_config')
        : NULL,
      $container->get('entity.repository'),
      $moduleHandler->moduleExists('markaspot_nuxt')
        ? $container->get('markaspot_nuxt.frontend_url')
        : NULL,
    );
  }

  /**
   * Access check for invitation management endpoints.
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
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }

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
   * Sends an invitation to join a group.
   *
   * POST /api/group-members/invite.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with invitation status.
   */
  public function invite(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE);
    if (empty($content)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    $email = trim((string) ($content['email'] ?? ''));
    $groupId = (int) ($content['group_id'] ?? 0);
    $roles = (array) ($content['roles'] ?? []);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse(['error' => 'A valid email address is required.'], 400);
    }
    if ($groupId <= 0) {
      return new JsonResponse(['error' => 'A valid group_id is required.'], 400);
    }

    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $this->entityTypeManager()->getStorage('group')->load($groupId);
    if (!$group) {
      return new JsonResponse(['error' => 'Group not found.'], 404);
    }

    // Verify admin scope.
    $currentAccount = $this->currentUser();
    $isDrupalAdmin = in_array('administrator', $currentAccount->getRoles(), TRUE)
      || (int) $currentAccount->id() === 1;

    if (!$isDrupalAdmin && !$this->isGroupInAdminScope($group, $currentAccount)) {
      return new JsonResponse(['error' => 'Access denied to this group.'], 403);
    }
    foreach ($roles as $role) {
      if (!is_string($role) || $role === '') {
        return new JsonResponse(['error' => 'Invalid role ID.'], 400);
      }
    }
    if ($this->isJurisdictionGroup($group)) {
      $roles = $this->storageJurisdictionRoleIds($roles);
    }
    $roles = MembershipRoleNormalizer::normalize($roles, $group->bundle());
    $roles = $this->ensureBaseMemberRole($roles, $group);

    // Validate roles against permitted set to prevent privilege escalation.
    if (!empty($roles)) {
      $permittedRoles = $this->getPermittedRoles($isDrupalAdmin, $group);
      $invalidRoles = array_diff($roles, $permittedRoles);
      if (!empty($invalidRoles)) {
        return new JsonResponse(['error' => 'One or more requested roles are not permitted.'], 403);
      }
    }

    // Check member limit if markaspot_fastmap is available.
    $limitError = $this->checkMemberLimit($group);
    if ($limitError !== NULL) {
      return new JsonResponse($limitError, 409);
    }

    // Invitations carry a bearer token. Never create one unless a validated
    // public frontend URL is configured for the claim link.
    // 409, not 503: this is a durable configuration gap that retrying never
    // clears. It also keeps the message readable, because the Nuxt proxy
    // replaces every 5xx body with a generic text to avoid leaking internals.
    $frontendBase = $this->resolveInvitationFrontendBase();
    if ($frontendBase === NULL) {
      $this->logger->error('Cannot create group invitation: no public frontend URL is configured for invitation emails.');
      return new JsonResponse([
        'error' => 'Invitation email delivery is temporarily unavailable.',
        'code' => 'invitation_delivery_unconfigured',
      ], 409);
    }

    // Check for duplicate pending invitation.
    $existing = $this->database->select('markaspot_group_invitations', 'i')
      ->fields('i', ['id'])
      ->condition('email', $email)
      ->condition('group_id', $groupId)
      ->condition('expires', time(), '>')
      ->isNull('claimed')
      ->execute()
      ->fetchField();

    if ($existing) {
      return new JsonResponse([
        'error' => 'A pending invitation for this email and group already exists.',
        'code' => 'invitation_pending',
      ], 409);
    }

    // Generate token and insert invitation.
    $token = bin2hex(random_bytes(32));
    $now = time();

    $this->database->insert('markaspot_group_invitations')
      ->fields([
        'token' => $token,
        'email' => $email,
        'group_id' => $groupId,
        'roles' => json_encode($roles),
        'invited_by' => (int) $currentAccount->id(),
        'created' => $now,
        'expires' => $now + self::TOKEN_EXPIRY_SECONDS,
      ])
      ->execute();

    // Send invitation email.
    $langcode = $this->resolveInvitationLangcode($email);
    $this->sendInvitationEmail($email, $token, $group, $langcode, $frontendBase);

    $this->logger->notice('User @admin invited @email to group @group (id=@gid).', [
      '@admin' => $currentAccount->getDisplayName(),
      '@email' => $email,
      '@group' => $group->label(),
      '@gid' => $groupId,
    ]);

    return new JsonResponse([
      'status' => 'invited',
      'email' => $email,
      'group_id' => $groupId,
    ], 202);
  }

  /**
   * Lists pending invitations for a group.
   *
   * GET /api/group-members/invitations?group_id={id}
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with pending invitations.
   */
  public function listInvitations(Request $request): JsonResponse {
    $groupId = (int) $request->query->get('group_id', '0');
    if ($groupId <= 0) {
      return new JsonResponse(['error' => 'group_id query parameter is required.'], 400);
    }

    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $this->entityTypeManager()->getStorage('group')->load($groupId);
    if (!$group) {
      return new JsonResponse(['error' => 'Group not found.'], 404);
    }

    // Verify admin scope.
    $currentAccount = $this->currentUser();
    $isDrupalAdmin = in_array('administrator', $currentAccount->getRoles(), TRUE)
      || (int) $currentAccount->id() === 1;

    if (!$isDrupalAdmin && !$this->isGroupInAdminScope($group, $currentAccount)) {
      return new JsonResponse(['error' => 'Access denied to this group.'], 403);
    }

    $results = $this->database->select('markaspot_group_invitations', 'i')
      ->fields('i', ['id', 'email', 'group_id', 'roles', 'invited_by', 'created', 'expires'])
      ->condition('group_id', $groupId)
      ->condition('expires', time(), '>')
      ->isNull('claimed')
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $invitations = [];
    foreach ($results as $row) {
      $roles = json_decode($row['roles'], TRUE) ?? [];
      if ($this->isJurisdictionGroup($group)) {
        $roles = array_values(array_map(
          fn(string $roleId): string => $this->canonicalizeJurisdictionRoleId($roleId),
          array_filter($roles, 'is_string')
        ));
      }
      $invitations[] = [
        'id' => (int) $row['id'],
        'email' => $row['email'],
        'group_id' => (int) $row['group_id'],
        'roles' => $roles,
        'invited_by' => (int) $row['invited_by'],
        'created' => (int) $row['created'],
        'expires' => (int) $row['expires'],
      ];
    }

    return new JsonResponse(['invitations' => $invitations]);
  }

  /**
   * Revokes a pending invitation.
   *
   * DELETE /api/group-members/invitations/{invitation_id}
   *
   * @param int $invitation_id
   *   The invitation ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response confirming revocation.
   */
  public function revokeInvitation(int $invitation_id): JsonResponse {
    $invitation = $this->database->select('markaspot_group_invitations', 'i')
      ->fields('i', ['id', 'group_id', 'email'])
      ->condition('id', $invitation_id)
      ->condition('expires', time(), '>')
      ->isNull('claimed')
      ->execute()
      ->fetchAssoc();

    if (!$invitation) {
      return new JsonResponse(['error' => 'Invitation not found or already claimed.'], 404);
    }

    // Verify admin scope over the invitation's group.
    $groupId = (int) $invitation['group_id'];
    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $this->entityTypeManager()->getStorage('group')->load($groupId);
    if (!$group) {
      return new JsonResponse(['error' => 'Group not found.'], 404);
    }

    $currentAccount = $this->currentUser();
    $isDrupalAdmin = in_array('administrator', $currentAccount->getRoles(), TRUE)
      || (int) $currentAccount->id() === 1;

    if (!$isDrupalAdmin && !$this->isGroupInAdminScope($group, $currentAccount)) {
      return new JsonResponse(['error' => 'Access denied to this group.'], 403);
    }

    $this->database->delete('markaspot_group_invitations')
      ->condition('id', (int) $invitation['id'])
      ->execute();

    $this->logger->notice('User @admin revoked invitation for @email to group @gid.', [
      '@admin' => $currentAccount->getDisplayName(),
      '@email' => $invitation['email'],
      '@gid' => $groupId,
    ]);

    return new JsonResponse(['status' => 'revoked']);
  }

  /**
   * Claims an invitation token and adds the user to the group.
   *
   * POST /api/group-members/claim/{token}
   *
   * @param string $token
   *   The invitation token.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with claim result.
   */
  public function claimInvitation(string $token, Request $request): JsonResponse {
    $ip = $request->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed(self::CLAIM_FLOOD_EVENT, self::CLAIM_FLOOD_LIMIT, self::CLAIM_FLOOD_WINDOW, $ip)) {
      return new JsonResponse(['error' => 'Too many attempts. Try again later.'], 429);
    }
    $this->flood->register(self::CLAIM_FLOOD_EVENT, self::CLAIM_FLOOD_WINDOW, $ip);

    // Atomically mark as claimed to prevent double-claim.
    $now = time();
    // Tokens stay SQL-compared intentionally: they are 256-bit random values,
    // this path is flood-limited above, and hashing existing tokens would need
    // a disruptive schema migration for a low-risk side channel.
    $affected = $this->database->update('markaspot_group_invitations')
      ->fields(['claimed' => $now])
      ->condition('token', $token)
      ->condition('expires', $now, '>')
      ->isNull('claimed')
      ->execute();

    if ($affected === 0) {
      // Check if the token exists at all to distinguish expired from invalid.
      $row = $this->database->select('markaspot_group_invitations', 'i')
        ->fields('i', ['id', 'expires', 'claimed'])
        ->condition('token', $token)
        ->execute()
        ->fetchAssoc();

      if (!$row) {
        return new JsonResponse(['error' => 'Invalid or already claimed invitation.'], 404);
      }
      if (!empty($row['claimed'])) {
        return new JsonResponse(['error' => 'Invalid or already claimed invitation.'], 404);
      }
      return new JsonResponse(['error' => 'This invitation has expired.'], 410);
    }

    // Re-fetch the full invitation row.
    $invitation = $this->database->select('markaspot_group_invitations', 'i')
      ->fields('i')
      ->condition('token', $token)
      ->execute()
      ->fetchAssoc();

    if (!$invitation) {
      return new JsonResponse(['error' => 'Invitation not found.'], 404);
    }

    // If user is authenticated, verify their email matches the invitation.
    $currentAccount = $this->currentUser();
    if (!$currentAccount->isAnonymous()) {
      $currentEmail = $currentAccount->getEmail();
      if ($currentEmail && strtolower($currentEmail) !== strtolower($invitation['email'])) {
        $this->unclaimInvitation((int) $invitation['id']);
        return new JsonResponse([
          'error' => 'This invitation is for a different email address. Please log in with the correct account.',
        ], 403);
      }
    }

    $groupId = (int) $invitation['group_id'];
    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $this->entityTypeManager()->getStorage('group')->load($groupId);
    if (!$group) {
      $this->unclaimInvitation((int) $invitation['id']);
      return new JsonResponse(['error' => 'The target group no longer exists.'], 404);
    }

    $jurGroup = $this->resolveJurisdictionGroup($group);
    $groupLockName = $this->buildMembershipGroupLockName((int) ($jurGroup?->id() ?? $groupId));
    if (!$this->lock->acquire($groupLockName, self::MEMBERSHIP_UPDATE_LOCK_TTL)) {
      $this->unclaimInvitation((int) $invitation['id']);
      return new JsonResponse(['error' => 'Group membership update already in progress.'], 409);
    }

    try {
      // Re-check member limit while the group mutation lock is held.
      $limitError = $this->checkMemberLimit($group);
      if ($limitError !== NULL) {
        $this->unclaimInvitation((int) $invitation['id']);
        return new JsonResponse($limitError, 409);
      }

      $email = $invitation['email'];
      $roles = json_decode($invitation['roles'], TRUE) ?? [];
      if ($this->isJurisdictionGroup($group)) {
        $roles = $this->storageJurisdictionRoleIds($roles);
      }
      $roles = MembershipRoleNormalizer::normalize($roles, $group->bundle());
      $roles = $this->ensureBaseMemberRole($roles, $group);
      $langcode = $this->languageManager()->getCurrentLanguage()->getId();

      $emailLockName = $this->buildUserEmailLockName($email);
      if (!$this->lock->acquire($emailLockName, self::USER_EMAIL_LOCK_TTL)) {
        $this->unclaimInvitation((int) $invitation['id']);
        return new JsonResponse(['error' => 'Email update already in progress.'], 409);
      }

      try {
        // Find existing user by email or auto-create.
        $user = $this->findOrCreateUser($email, $langcode);
        if (!$user) {
          $this->unclaimInvitation((int) $invitation['id']);
          return new JsonResponse(['error' => 'Failed to create user account.'], 500);
        }

        $lockName = $this->buildMembershipUpdateLockName((int) $user->id());
        if (!$this->lock->acquire($lockName, self::MEMBERSHIP_UPDATE_LOCK_TTL)) {
          $this->unclaimInvitation((int) $invitation['id']);
          return new JsonResponse(['error' => 'Membership update already in progress for this user.'], 409);
        }

        try {
          // Check if user is already a member.
          $existingMember = $group->getMember($user);
          if ($existingMember) {
            return new JsonResponse([
              'status' => 'already_member',
              'redirect' => '/dashboard',
              'group_name' => $group->label(),
              'jurisdiction_slug' => $this->getJurisdictionSlug($group),
            ]);
          }

          // Add user to group with specified roles.
          try {
            $group->addMember($user, ['group_roles' => $roles]);
          }
          catch (\Exception $e) {
            $this->unclaimInvitation((int) $invitation['id']);
            $this->logger->error('Failed to add user @uid to group @gid: @msg', [
              '@uid' => $user->id(),
              '@gid' => $groupId,
              '@msg' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Failed to add member to group.'], 500);
          }

          // Invitation was already marked as claimed atomically at the top.
          $this->logger->notice('Invitation claimed: @email joined group @group (id=@gid).', [
            '@email' => $email,
            '@group' => $group->label(),
            '@gid' => $groupId,
          ]);

          return new JsonResponse([
            'status' => 'claimed',
            'redirect' => '/dashboard',
            'group_name' => $group->label(),
            'jurisdiction_slug' => $this->getJurisdictionSlug($group),
          ]);
        }
        finally {
          $this->lock->release($lockName);
        }
      }
      finally {
        $this->lock->release($emailLockName);
      }
    }
    finally {
      $this->lock->release($groupLockName);
    }
  }

  /**
   * Builds a bounded lock name for membership updates on one user account.
   */
  protected function buildMembershipUpdateLockName(int $uid): string {
    return 'markaspot_group:membership_update:' . $uid;
  }

  /**
   * Builds a bounded lock name for membership mutations in one group.
   */
  protected function buildMembershipGroupLockName(int $groupId): string {
    return 'markaspot_group:membership_group:' . $groupId;
  }

  /**
   * Builds a bounded lock name for a user email identity value.
   */
  protected function buildUserEmailLockName(string $email): string {
    return 'markaspot_group:user_email:' . hash('sha256', mb_strtolower(trim($email)));
  }

  /**
   * Marks a previously claimed invitation as claimable again.
   */
  protected function unclaimInvitation(int $invitationId): void {
    $this->database->update('markaspot_group_invitations')
      ->fields(['claimed' => NULL])
      ->condition('id', $invitationId)
      ->execute();
  }

  /**
   * Resolves the frontend jurisdiction slug for a group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The claimed group.
   *
   * @return string|null
   *   The jurisdiction slug, or NULL when it cannot be resolved.
   */
  protected function getJurisdictionSlug(GroupInterface $group): ?string {
    $jurGroup = $this->resolveJurisdictionGroup($group);
    if (!$jurGroup || !$jurGroup->hasField('field_slug') || $jurGroup->get('field_slug')->isEmpty()) {
      return NULL;
    }

    $slug = trim((string) $jurGroup->get('field_slug')->value);
    if (!preg_match('/^[a-z0-9_-]{1,64}$/', $slug)) {
      return NULL;
    }

    return $slug !== '' ? $slug : NULL;
  }

  /**
   * Ensures every jurisdiction invitation carries the base member role.
   *
   * Jurisdiction memberships require their individual member role to gain the
   * group permissions that the invite flow promises. Organisation groups use
   * their automatic insider role instead, so no role is added to them.
   * Functional roles remain opt-in and are handled by the normalizer above.
   *
   * @param string[] $roles
   *   Normalized, user-selected role IDs.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The target group.
   *
   * @return string[]
   *   The role IDs to persist on the membership.
   */
  protected function ensureBaseMemberRole(array $roles, GroupInterface $group): array {
    if (!$this->isJurisdictionGroup($group)) {
      return $roles;
    }

    $member_role = $group->bundle() . '-member';
    if (in_array($member_role, $roles, TRUE)) {
      return $roles;
    }

    array_unshift($roles, $member_role);
    return $roles;
  }

  /**
   * Checks whether the group has reached its member limit.
   *
   * Skips the check if markaspot_fastmap is not installed or if the group
   * has no tier field.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to check.
   *
   * @return array{error: string, code: string, tier: string, limit: int}|null
   *   Error response data if limit is exceeded, NULL if within limits.
   */
  protected function checkMemberLimit(GroupInterface $group): ?array {
    if (!$this->tierConfigService) {
      return NULL;
    }

    // Resolve to the jurisdiction group (for org groups, find parent).
    $jurGroup = $this->resolveJurisdictionGroup($group);
    if (!$jurGroup || !$jurGroup->hasField('field_tier') || $jurGroup->get('field_tier')->isEmpty()) {
      return NULL;
    }

    $tier = (string) $jurGroup->get('field_tier')->value;
    $limit = $this->tierConfigService->getMemberLimit($tier);

    if ($limit === NULL) {
      // Unknown tier, no limit.
      return NULL;
    }

    // Count active members and pending invitations across all groups in this
    // jurisdiction (jur + all org sub-groups) to prevent limit bypass via
    // multiple orgs.
    $allGroupIds = [(int) $jurGroup->id()];
    $orgGroups = $this->entityTypeManager()->getStorage('group')->loadByProperties([
      'type' => 'org',
      'field_jurisdiction' => $jurGroup->id(),
    ]);
    foreach ($orgGroups as $orgGroup) {
      $allGroupIds[] = (int) $orgGroup->id();
    }
    $allGroupIds = array_values(array_unique($allGroupIds));

    $currentMembers = 0;
    foreach ($allGroupIds as $groupId) {
      $currentMembers += $this->tierConfigService->countMembers((int) $groupId);
    }

    $pendingInvites = (int) $this->database->select('markaspot_group_invitations', 'i')
      ->condition('group_id', $allGroupIds, 'IN')
      ->condition('expires', time(), '>')
      ->isNull('claimed')
      ->countQuery()
      ->execute()
      ->fetchField();

    if (($currentMembers + $pendingInvites) >= $limit) {
      $memberLabel = $limit === 1 ? 'member' : 'members';
      return [
        'error' => "Member limit reached for the '$tier' tier ($limit $memberLabel). Upgrade to add more members.",
        'code' => 'member_limit_reached',
        'tier' => $tier,
        'limit' => $limit,
      ];
    }

    return NULL;
  }

  /**
   * Resolves a group to its jurisdiction group.
   *
   * For jur groups, returns the group itself. For org groups, follows the
   * field_jurisdiction reference.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to resolve.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The jurisdiction group, or NULL if unresolvable.
   */
  protected function resolveJurisdictionGroup(GroupInterface $group): ?GroupInterface {
    if ($this->isJurisdictionGroup($group)) {
      return $group;
    }

    if ($group->bundle() === 'org'
        && $group->hasField('field_jurisdiction')
        && !$group->get('field_jurisdiction')->isEmpty()) {
      $jurId = (int) $group->get('field_jurisdiction')->target_id;
      $jurGroup = $this->entityTypeManager()->getStorage('group')->load($jurId);
      return ($jurGroup instanceof GroupInterface) ? $jurGroup : NULL;
    }

    return NULL;
  }

  /**
   * Finds an existing user by email or creates a new one.
   *
   * Follows the passwordless signup pattern: creates users with the email
   * prefix as username, active status, and the appropriate language.
   *
   * @param string $email
   *   The email address.
   * @param string $langcode
   *   The language code for the new user.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity, or NULL on failure.
   */
  protected function findOrCreateUser(string $email, string $langcode): ?UserInterface {
    $userStorage = $this->entityTypeManager()->getStorage('user');

    // Look up existing user by email.
    $existing = $userStorage->loadByProperties(['mail' => $email]);
    if (!empty($existing)) {
      return reset($existing);
    }

    // Auto-create user with randomized suffix to prevent username enumeration.
    $emailPrefix = strstr($email, '@', TRUE) ?: $email;
    $username = $emailPrefix . '_' . bin2hex(random_bytes(4));

    try {
      /** @var \Drupal\user\UserInterface $user */
      $user = $userStorage->create([
        'name' => $username,
        'mail' => $email,
        'status' => 1,
        'langcode' => $langcode,
      ]);
      $user->save();

      $this->logger->notice('Auto-created user @name (@email) via invitation.', [
        '@name' => $username,
        '@email' => $email,
      ]);

      return $user;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create user for @email: @msg', [
        '@email' => $email,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Sends the invitation email.
   *
   * @param string $email
   *   The recipient email.
   * @param string $token
   *   The invitation token.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group being invited to.
   * @param string $langcode
   *   The language code for the email.
   * @param string $frontendBase
   *   The validated public frontend base URL.
   */
  protected function sendInvitationEmail(
    string $email,
    string $token,
    GroupInterface $group,
    string $langcode,
    string $frontendBase,
  ): void {
    $claimUrl = rtrim($frontendBase, '/') . '/auth/invite?token=' . rawurlencode($token);

    $siteName = $this->config('system.site')->get('name') ?: 'Mark-a-Spot';

    $params = [
      'group_name' => $this->getEntityLabelForLangcode($group, $langcode),
      'claim_url' => $claimUrl,
      'site_name' => $siteName,
      'langcode' => $langcode,
    ];

    $result = $this->mailManager->mail(
      'markaspot_group',
      'member_invitation',
      $email,
      $langcode,
      $params,
      NULL,
      TRUE,
    );

    if (!$result || empty($result['result'])) {
      $this->logger->error('Failed to send invitation email to @email for group @gid.', [
        '@email' => $email,
        '@gid' => $group->id(),
      ]);
    }
  }

  /**
   * Resolves the public frontend base URL for invitation claim links.
   *
   * @return string|null
   *   A validated public URL, or NULL when invitations must fail closed.
   */
  protected function resolveInvitationFrontendBase(): ?string {
    $frontendBase = $this->frontendUrlService?->getNotificationFrontendBaseUrl();
    if (!is_string($frontendBase) || trim($frontendBase) === '') {
      return NULL;
    }

    if (strtolower((string) parse_url($frontendBase, PHP_URL_SCHEME)) !== 'https') {
      return NULL;
    }

    return rtrim($frontendBase, '/');
  }

  /**
   * Resolves the invitation mail language for an email address.
   */
  protected function resolveInvitationLangcode(string $email): string {
    $fallback = $this->languageManager()->getCurrentLanguage()->getId();

    try {
      $users = $this->entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['mail' => $email]);
      $user = reset($users);
      if ($user instanceof UserInterface) {
        $preferred = (string) $user->getPreferredLangcode(FALSE);
        if ($preferred !== '') {
          return $preferred;
        }
      }
    }
    catch (\Exception) {
      // Existing invitation behavior is request-language fallback when the
      // recipient account cannot be loaded.
    }

    return $fallback;
  }

  /**
   * Gets an entity label in a specific language when available.
   */
  protected function getEntityLabelForLangcode(EntityInterface $entity, string $langcode): string {
    if ($entity instanceof ConfigEntityInterface) {
      return $this->getConfigEntityLabelForLangcode($entity, $langcode);
    }

    if ($langcode !== '' && $this->entityRepository !== NULL) {
      try {
        $translated = $this->entityRepository
          ->getTranslationFromContext($entity, $langcode);
        if ($translated instanceof EntityInterface) {
          return (string) $translated->label();
        }
      }
      catch (\Exception) {
        // Fall back to the entity's default label when translation services are
        // unavailable or the entity has no requested translation.
      }
    }

    return (string) $entity->label();
  }

  /**
   * Gets a config entity label in a specific language when available.
   */
  protected function getConfigEntityLabelForLangcode(ConfigEntityInterface $entity, string $langcode): string {
    $languageManager = $this->languageManager();
    if ($langcode !== '' && method_exists($languageManager, 'getLanguageConfigOverride')) {
      try {
        $label = $languageManager
          ->getLanguageConfigOverride($langcode, $entity->getConfigDependencyName())
          ->get('label');
        if (is_string($label) && trim($label) !== '') {
          return $label;
        }
      }
      catch (\Exception) {
        // Fall back to the entity's default config label.
      }
    }

    return (string) $entity->label();
  }

  /**
   * Builds the set of jurisdiction IDs a tenant admin can manage.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
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
   * Returns the set of group roles the inviting user is permitted to assign.
   *
   * Tenant admins can assign member-level and editorial roles.
   * Only Drupal administrators can assign tenant_admin roles.
   *
   * @param bool $isDrupalAdmin
   *   Whether the inviting user is a Drupal administrator.
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The target group, when tier-scoped role limits should be applied.
   *
   * @return string[]
   *   Array of permitted group role IDs.
   */
  protected function getPermittedRoles(bool $isDrupalAdmin, ?GroupInterface $group = NULL): array {
    // Base roles any tenant admin may assign.
    $jurisdictionGroupType = $this->getJurisdictionGroupType();
    $permitted = [
      $jurisdictionGroupType . '-member',
      $jurisdictionGroupType . '-moderator',
      'org-member',
      'org-moderator',
    ];

    // Only Drupal admins may create new tenant admins.
    if ($isDrupalAdmin) {
      $permitted[] = $jurisdictionGroupType . '-tenant_admin';
      $permitted[] = 'org-tenant_admin';
    }

    if ($group && $this->tierConfigService && method_exists($this->tierConfigService, 'getAssignableRoleIds')) {
      $jurGroup = $this->resolveJurisdictionGroup($group);
      if ($jurGroup && $jurGroup->hasField('field_tier')) {
        $tier = $jurGroup->get('field_tier')->isEmpty()
          ? 'free'
          : (string) $jurGroup->get('field_tier')->value;
        $tierRoles = $this->tierConfigService->getAssignableRoleIds($tier);
        if (is_array($tierRoles)) {
          $tierRoles = array_map(
            fn(string $roleId): string => $this->storageJurisdictionRoleId($roleId),
            array_filter($tierRoles, 'is_string')
          );
          $permitted = array_values(array_intersect($permitted, $tierRoles));
        }
      }
    }

    return array_values(array_unique($permitted));
  }

  /**
   * Checks whether a group falls within the current user's admin scope.
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
    $adminJurIds = $this->getAdminJurisdictionIds($account);
    if (empty($adminJurIds)) {
      return FALSE;
    }

    if ($this->isJurisdictionGroup($group)) {
      return in_array((int) $group->id(), $adminJurIds, TRUE);
    }

    if ($group->bundle() === 'org') {
      // Check via jurisdiction reference first.
      if ($group->hasField('field_jurisdiction') && !$group->get('field_jurisdiction')->isEmpty()) {
        $orgJurId = (int) $group->get('field_jurisdiction')->target_id;
        if (in_array($orgJurId, $adminJurIds, TRUE)) {
          return TRUE;
        }
      }

      // Fallback: check direct org-tenant_admin membership.
      $orgMemberships = $this->membershipLoader->loadByUser($account, ['org-tenant_admin']);
      foreach ($orgMemberships as $membership) {
        if ((int) $membership->getGroup()->id() === (int) $group->id()) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
