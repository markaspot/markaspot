<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
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

  /**
   * Invitation token expiry: 7 days in seconds.
   */
  protected const TOKEN_EXPIRY_SECONDS = 7 * 86400;

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
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('module_handler'),
      $container->get('logger.factory')->get('markaspot_group'),
      $container->get('group.membership_loader'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('current_user'),
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

    $memberships = $this->membershipLoader->loadByUser($account, ['jur-tenant_admin']);
    if (!empty($memberships)) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    return AccessResult::forbidden('User is not an administrator or tenant admin.')
      ->addCacheContexts(['user']);
  }

  /**
   * Sends an invitation to join a group.
   *
   * POST /api/group-members/invite
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

    // Check member limit if markaspot_fastmap is available.
    $limitError = $this->checkMemberLimit($group);
    if ($limitError !== NULL) {
      return new JsonResponse(['error' => $limitError], 409);
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
      return new JsonResponse(['error' => 'A pending invitation for this email and group already exists.'], 409);
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
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();
    $this->sendInvitationEmail($email, $token, $group, $langcode, $request);

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
      ->fields('i', ['id', 'token', 'email', 'group_id', 'roles', 'invited_by', 'created', 'expires'])
      ->condition('group_id', $groupId)
      ->condition('expires', time(), '>')
      ->isNull('claimed')
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $invitations = [];
    foreach ($results as $row) {
      $invitations[] = [
        'id' => (int) $row['id'],
        'token' => $row['token'],
        'email' => $row['email'],
        'group_id' => (int) $row['group_id'],
        'roles' => json_decode($row['roles'], TRUE) ?? [],
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
   * DELETE /api/group-members/invitations/{token}
   *
   * @param string $token
   *   The invitation token.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response confirming revocation.
   */
  public function revokeInvitation(string $token): JsonResponse {
    $invitation = $this->database->select('markaspot_group_invitations', 'i')
      ->fields('i', ['id', 'group_id', 'email'])
      ->condition('token', $token)
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
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with claim result.
   */
  public function claimInvitation(string $token): JsonResponse {
    $invitation = $this->database->select('markaspot_group_invitations', 'i')
      ->fields('i')
      ->condition('token', $token)
      ->isNull('claimed')
      ->execute()
      ->fetchAssoc();

    if (!$invitation) {
      return new JsonResponse(['error' => 'Invalid or already claimed invitation.'], 404);
    }

    if ((int) $invitation['expires'] < time()) {
      return new JsonResponse(['error' => 'This invitation has expired.'], 410);
    }

    $groupId = (int) $invitation['group_id'];
    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $this->entityTypeManager()->getStorage('group')->load($groupId);
    if (!$group) {
      return new JsonResponse(['error' => 'The target group no longer exists.'], 404);
    }

    // Re-check member limit (race condition guard).
    $limitError = $this->checkMemberLimit($group);
    if ($limitError !== NULL) {
      return new JsonResponse(['error' => $limitError], 409);
    }

    $email = $invitation['email'];
    $roles = json_decode($invitation['roles'], TRUE) ?? [];
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();

    // Find existing user by email or auto-create.
    $user = $this->findOrCreateUser($email, $langcode);
    if (!$user) {
      return new JsonResponse(['error' => 'Failed to create user account.'], 500);
    }

    // Check if user is already a member.
    $existingMember = $group->getMember($user);
    if ($existingMember) {
      // Mark invitation as claimed even if already a member.
      $this->database->update('markaspot_group_invitations')
        ->fields(['claimed' => time()])
        ->condition('id', (int) $invitation['id'])
        ->execute();

      return new JsonResponse([
        'status' => 'already_member',
        'redirect' => '/dashboard',
      ]);
    }

    // Add user to group with specified roles.
    try {
      $group->addMember($user, ['group_roles' => $roles]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to add user @uid to group @gid: @msg', [
        '@uid' => $user->id(),
        '@gid' => $groupId,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Failed to add member to group.'], 500);
    }

    // Mark invitation as claimed.
    $this->database->update('markaspot_group_invitations')
      ->fields(['claimed' => time()])
      ->condition('id', (int) $invitation['id'])
      ->execute();

    $this->logger->notice('Invitation claimed: @email joined group @group (id=@gid).', [
      '@email' => $email,
      '@group' => $group->label(),
      '@gid' => $groupId,
    ]);

    return new JsonResponse([
      'status' => 'claimed',
      'redirect' => '/dashboard',
    ]);
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
   * @return string|null
   *   Error message if limit is exceeded, NULL if within limits.
   */
  protected function checkMemberLimit(GroupInterface $group): ?string {
    if (!$this->moduleHandler()->moduleExists('markaspot_fastmap')) {
      return NULL;
    }

    // Resolve to the jurisdiction group (for org groups, look up the parent jur).
    $jurGroup = $this->resolveJurisdictionGroup($group);
    if (!$jurGroup || !$jurGroup->hasField('field_tier') || $jurGroup->get('field_tier')->isEmpty()) {
      return NULL;
    }

    $tier = $jurGroup->get('field_tier')->value;

    /** @var \Drupal\markaspot_fastmap\Service\TierConfigService $tierService */
    $tierService = \Drupal::service('markaspot_fastmap.tier_config');
    $limit = $tierService->getMemberLimit($tier);

    if ($limit === NULL) {
      // Unknown tier, no limit.
      return NULL;
    }

    $currentMembers = $tierService->countMembers((int) $jurGroup->id());

    // Also count pending (unclaimed, not expired) invitations for this group.
    $pendingInvites = (int) $this->database->select('markaspot_group_invitations', 'i')
      ->condition('group_id', (int) $group->id())
      ->condition('expires', time(), '>')
      ->isNull('claimed')
      ->countQuery()
      ->execute()
      ->fetchField();

    if (($currentMembers + $pendingInvites) >= $limit) {
      return "Member limit reached for the '$tier' tier ($limit members). Upgrade to add more members.";
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
    if ($group->bundle() === 'jur') {
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
  protected function findOrCreateUser(string $email, string $langcode): ?\Drupal\user\UserInterface {
    $userStorage = $this->entityTypeManager()->getStorage('user');

    // Look up existing user by email.
    $existing = $userStorage->loadByProperties(['mail' => $email]);
    if (!empty($existing)) {
      return reset($existing);
    }

    // Auto-create user.
    $emailPrefix = strstr($email, '@', TRUE) ?: $email;
    // Ensure unique username.
    $username = $emailPrefix;
    $suffix = 0;
    while (!empty($userStorage->loadByProperties(['name' => $username]))) {
      $suffix++;
      $username = $emailPrefix . '_' . $suffix;
    }

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
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request (used to determine frontend base URL).
   */
  protected function sendInvitationEmail(
    string $email,
    string $token,
    GroupInterface $group,
    string $langcode,
    Request $request,
  ): void {
    // Build the claim URL.
    // Prefer frontend_base_url from the request body (set by Nuxt proxy),
    // then fall back to the request origin.
    $content = json_decode($request->getContent(), TRUE) ?? [];
    $frontendBase = $content['frontend_base_url'] ?? '';

    if (!$frontendBase || !preg_match('#^https?://#', $frontendBase)) {
      $frontendBase = $request->getSchemeAndHttpHost();
    }

    $claimUrl = rtrim($frontendBase, '/') . '/auth/invite?token=' . $token;

    $siteName = $this->config('system.site')->get('name') ?: 'Mark-a-Spot';

    $params = [
      'group_name' => $group->label(),
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
   * Builds the set of jurisdiction IDs a tenant admin can manage.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return int[]
   *   Array of jurisdiction group IDs (including descendants).
   */
  protected function getAdminJurisdictionIds(AccountInterface $account): array {
    $tenantMemberships = $this->membershipLoader->loadByUser($account, ['jur-tenant_admin']);
    if (empty($tenantMemberships)) {
      return [];
    }

    $adminJurIds = [];
    foreach ($tenantMemberships as $membership) {
      $jurId = (int) $membership->getGroup()->id();
      $adminJurIds[] = $jurId;
      $adminJurIds = array_merge($adminJurIds, $this->hierarchyResolver->getDescendantIds($jurId));
    }
    return array_values(array_unique($adminJurIds));
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

    if ($group->bundle() === 'jur') {
      return in_array((int) $group->id(), $adminJurIds, TRUE);
    }

    if ($group->bundle() === 'org') {
      if ($group->hasField('field_jurisdiction') && !$group->get('field_jurisdiction')->isEmpty()) {
        $orgJurId = (int) $group->get('field_jurisdiction')->target_id;
        return in_array($orgJurId, $adminJurIds, TRUE);
      }
    }

    return FALSE;
  }

}
