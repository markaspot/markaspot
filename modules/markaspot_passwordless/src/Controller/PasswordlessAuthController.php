<?php

namespace Drupal\markaspot_passwordless\Controller;

use Symfony\Component\HttpFoundation\Cookie;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\markaspot_passwordless\Service\OtpService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides JSON API endpoints for passwordless OTP authentication.
 */
class PasswordlessAuthController extends ControllerBase {

  /**
   * The OTP service.
   *
   * @var \Drupal\markaspot_passwordless\Service\OtpService
   */
  protected $otpService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The session configuration.
   *
   * @var \Drupal\Core\Session\SessionConfigurationInterface
   */
  protected SessionConfigurationInterface $sessionConfiguration;

  /**
   * The expirable key-value store factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected KeyValueExpirableFactoryInterface $keyValueExpirable;

  /**
   * Constructs a PasswordlessAuthController object.
   *
   * @param \Drupal\markaspot_passwordless\Service\OtpService $otp_service
   *   The OTP service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\SessionConfigurationInterface $session_configuration
   *   The session configuration.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable
   *   The expirable key-value store factory.
   */
  public function __construct(
    OtpService $otp_service,
    AccountInterface $current_user,
    FloodInterface $flood,
    ConfigFactoryInterface $config_factory,
    SessionConfigurationInterface $session_configuration,
    KeyValueExpirableFactoryInterface $key_value_expirable,
  ) {
    $this->otpService = $otp_service;
    $this->currentUser = $current_user;
    $this->flood = $flood;
    $this->configFactory = $config_factory;
    $this->sessionConfiguration = $session_configuration;
    $this->keyValueExpirable = $key_value_expirable;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('markaspot_passwordless.otp'),
      $container->get('current_user'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('session_configuration'),
      $container->get('keyvalue.expirable')
    );
  }

  /**
   * Request OTP code endpoint.
   *
   * POST /api/auth/request-code
   * Body: { "email": "user@example.com" }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function requestCode(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    // Validate input.
    if (empty($data['email'])) {
      return new JsonResponse([
        'error' => 'Email is required',
      ], Response::HTTP_BAD_REQUEST);
    }

    $email = trim($data['email']);

    // Validate email format.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse([
        'error' => 'Invalid email format',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Get configuration values.
    $config = $this->configFactory->get('markaspot_passwordless.settings');
    $request_limit_per_email = $config->get('request_limit_per_email') ?? 3;
    $request_limit_per_ip = $config->get('request_limit_per_ip') ?? 10;

    // Rate limit by email.
    if (!$this->flood->isAllowed('passwordless.request_code', $request_limit_per_email, 3600, $email)) {
      return new JsonResponse([
        'error' => 'Too many code requests. Please try again in an hour.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    // Rate limit by IP.
    $ip = $request->getClientIp();
    if (!$this->flood->isAllowed('passwordless.request_code.ip', $request_limit_per_ip, 3600, $ip)) {
      return new JsonResponse([
        'error' => 'Too many requests from your location. Please try again later.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    try {
      // Request OTP code.
      $result = $this->otpService->requestCode($email);

      if ($result['success']) {
        // Register the successful request for rate limiting.
        $this->flood->register('passwordless.request_code', 3600, $email);
        $this->flood->register('passwordless.request_code.ip', 3600, $ip);

        return new JsonResponse([
          'success' => TRUE,
          'message' => $result['message'],
          'expiresIn' => $result['expiresIn'],
        ]);
      }

      return new JsonResponse([
        'error' => $result['error'] ?? 'Failed to send verification code',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to send OTP: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'An error occurred while sending verification code',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Verify OTP code endpoint.
   *
   * POST /api/auth/verify-code
   * Body: { "email": "user@example.com", "code": "123456" }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with authentication status.
   */
  public function verifyCode(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    // Validate input.
    if (empty($data['email']) || empty($data['code'])) {
      return new JsonResponse([
        'error' => 'Email and code are required',
      ], Response::HTTP_BAD_REQUEST);
    }

    $email = trim($data['email']);
    $code = trim($data['code']);

    // Validate email format.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse([
        'error' => 'Invalid email format',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Validate code format (6 digits).
    if (!preg_match('/^\d{6}$/', $code)) {
      return new JsonResponse([
        'error' => 'Invalid code format',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Combine email and IP for verification rate limiting.
    $ip = $request->getClientIp();
    $identifier = $email . ':' . $ip;

    // Get configuration values.
    $config = $this->configFactory->get('markaspot_passwordless.settings');
    $verify_lockout_attempts = $config->get('verify_lockout_attempts') ?? 5;
    $verify_lockout_duration = $config->get('verify_lockout_duration') ?? 900;

    // Check for hard lockout.
    if (!$this->flood->isAllowed('passwordless.verify.lockout', $verify_lockout_attempts, $verify_lockout_duration, $identifier)) {
      $minutes = ceil($verify_lockout_duration / 60);
      return new JsonResponse([
        'error' => "Too many failed attempts. Account temporarily locked. Please try again in {$minutes} minutes.",
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    // Check exponential backoff (3+ attempts in 1 minute).
    if (!$this->flood->isAllowed('passwordless.verify.backoff', 3, 60, $identifier)) {
      return new JsonResponse([
        'error' => 'Too many attempts. Please wait a moment before trying again.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    try {
      // Verify OTP code.
      $result = $this->otpService->verifyCode($email, $code);

      if ($result['success']) {
        // Clear all failed attempt records on successful verification.
        $this->flood->clear('passwordless.verify.lockout', $identifier);
        $this->flood->clear('passwordless.verify.backoff', $identifier);

        return new JsonResponse([
          'success' => TRUE,
          'message' => $result['message'],
          'user' => $result['user'],
        ]);
      }

      // Register failed verification attempt.
      $this->flood->register('passwordless.verify.lockout', $verify_lockout_duration, $identifier);
      $this->flood->register('passwordless.verify.backoff', 60, $identifier);

      return new JsonResponse([
        'error' => $result['error'] ?? 'Invalid or expired code',
      ], Response::HTTP_UNAUTHORIZED);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to verify OTP: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'An error occurred during authentication',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Logout endpoint.
   *
   * POST /api/auth/logout.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with logout status.
   */
  public function logout(Request $request): JsonResponse {
    // Get session cookie name before logout destroys the session.
    $session_config = $this->sessionConfiguration;
    $session_options = $session_config->getOptions($request);
    $session_name = $session_options['name'] ?? session_name();

    if ($this->currentUser->isAuthenticated()) {
      user_logout();
    }

    // Build response with explicit cookie expiration.
    $response = new JsonResponse([
      'success' => TRUE,
      'message' => 'Logged out successfully',
    ]);

    // Explicitly expire the session cookie to ensure browser deletes it.
    // This is necessary because HttpOnly cookies can't be deleted by JavaScript.
    $cookie_domain = $session_options['cookie_domain'] ?? '';
    $response->headers->setCookie(
      new Cookie(
        $session_name,
        '',
    // Expire in the past.
        1,
        '/',
        $cookie_domain,
    // Secure.
        TRUE,
    // HttpOnly.
        TRUE,
    // Raw.
        FALSE,
    // SameSite.
        'None'
      )
    );

    return $response;
  }

  /**
   * User status endpoint.
   *
   * GET /api/auth/status.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with user status.
   */
  public function status(Request $request): JsonResponse {
    $account = $this->currentUser;

    if ($account->isAuthenticated()) {
      // Load full user entity to get groups.
      $user = $this->entityTypeManager()
        ->getStorage('user')
        ->load($account->id());

      return new JsonResponse([
        'authenticated' => TRUE,
        'user' => [
          'uid' => $account->id(),
          'name' => $account->getAccountName(),
          'email' => $account->getEmail(),
          'roles' => $account->getRoles(),
          'groups' => $this->getUserGroups($user),
        ],
      ]);
    }

    return new JsonResponse([
      'authenticated' => FALSE,
    ]);
  }

  /**
   * Switch to another user (impersonation for development).
   *
   * POST /api/auth/switch-user
   * Body: { "name": "username" }
   *
   * Requires 'switch users' permission (from Devel module) and
   * the Devel module to be enabled as a safety check.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the new user's status.
   */
  public function switchUser(Request $request): JsonResponse {
    // Safety check: only works when Devel module is enabled.
    if (!$this->moduleHandler()->moduleExists('devel')) {
      return new JsonResponse([
        'error' => 'User switching is only available in development environments.',
      ], Response::HTTP_FORBIDDEN);
    }

    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['name'])) {
      return new JsonResponse([
        'error' => 'Username is required.',
      ], Response::HTTP_BAD_REQUEST);
    }

    $name = trim($data['name']);

    // Load target user by username.
    $target_users = $this->entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $name]);

    if (empty($target_users)) {
      return new JsonResponse([
        'error' => 'User not found.',
      ], Response::HTTP_NOT_FOUND);
    }

    $target_user = reset($target_users);

    // Prevent switching to uid 1 (super admin).
    if ((int) $target_user->id() === 1) {
      return new JsonResponse([
        'error' => 'Cannot switch to the super admin account.',
      ], Response::HTTP_FORBIDDEN);
    }

    // Prevent switching to blocked users.
    if ($target_user->isBlocked()) {
      return new JsonResponse([
        'error' => 'Cannot switch to a blocked user.',
      ], Response::HTTP_FORBIDDEN);
    }

    try {
      $original_uid = $this->currentUser->id();

      // Invoke logout hooks for the current user.
      $this->moduleHandler()->invokeAll('user_logout', [$this->currentUser]);

      // Regenerate the session to prevent session fixation.
      $session = $request->getSession();
      $session->migrate(TRUE);

      // Switch the account on the current session.
      $this->currentUser->setAccount($target_user);
      $session->set('uid', $target_user->id());

      // Invoke login hooks for the new user.
      $this->moduleHandler()->invokeAll('user_login', [$target_user]);

      $this->getLogger('markaspot_passwordless')->notice('User @original switched to @target via headless impersonation.', [
        '@original' => $original_uid,
        '@target' => $target_user->getAccountName(),
      ]);

      // Return the same structure as status().
      return new JsonResponse([
        'success' => TRUE,
        'authenticated' => TRUE,
        'user' => [
          'uid' => $target_user->id(),
          'name' => $target_user->getAccountName(),
          'email' => $target_user->getEmail(),
          'roles' => $target_user->getRoles(),
          'groups' => $this->getUserGroups($target_user),
        ],
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to switch user: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'An error occurred during user switch.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * List users available for switching.
   *
   * GET /api/auth/switch-users.
   *
   * Requires 'switch users' permission and Devel module enabled.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with user list.
   */
  public function listSwitchUsers(): JsonResponse {
    // Safety check: only works when Devel module is enabled.
    if (!$this->moduleHandler()->moduleExists('devel')) {
      return new JsonResponse([
        'error' => 'User switching is only available in development environments.',
      ], Response::HTTP_FORBIDDEN);
    }

    try {
      $user_storage = $this->entityTypeManager()->getStorage('user');

      // Load active users, exclude anonymous (uid=0) and super admin (uid=1).
      $uids = $user_storage->getQuery()
        ->condition('uid', 1, '>')
        ->condition('status', 1)
        ->sort('uid')
        ->range(0, 20)
        ->accessCheck(FALSE)
        ->execute();

      $users = $user_storage->loadMultiple($uids);
      $result = [];

      foreach ($users as $account) {
        $result[] = [
          'uid' => $account->id(),
          'name' => $account->getAccountName(),
          'roles' => array_values(array_diff($account->getRoles(), ['authenticated'])),
        ];
      }

      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to list switch users: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Failed to load user list.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Generate a short-lived, single-use token for session handoff.
   *
   * POST /api/auth/switch-token
   * Body: { "name": "username" }
   *
   * The token can be claimed in a new browser window (no existing session
   * required) to create a fresh session as the target user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with token and claim URL.
   */
  public function generateSwitchToken(Request $request): JsonResponse {
    // Safety check: only works when Devel module is enabled.
    if (!$this->moduleHandler()->moduleExists('devel')) {
      return new JsonResponse([
        'error' => 'User switching is only available in development environments.',
      ], Response::HTTP_FORBIDDEN);
    }

    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['name'])) {
      return new JsonResponse([
        'error' => 'Username is required.',
      ], Response::HTTP_BAD_REQUEST);
    }

    $name = trim($data['name']);

    // Load target user by username.
    $target_users = $this->entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $name]);

    if (empty($target_users)) {
      return new JsonResponse([
        'error' => 'User not found.',
      ], Response::HTTP_NOT_FOUND);
    }

    $target_user = reset($target_users);

    // Prevent switching to uid 1 (super admin).
    if ((int) $target_user->id() === 1) {
      return new JsonResponse([
        'error' => 'Cannot switch to the super admin account.',
      ], Response::HTTP_FORBIDDEN);
    }

    // Prevent switching to blocked users.
    if ($target_user->isBlocked()) {
      return new JsonResponse([
        'error' => 'Cannot switch to a blocked user.',
      ], Response::HTTP_FORBIDDEN);
    }

    try {
      // Generate a secure random token (64 hex chars = 256 bits entropy).
      $token = bin2hex(random_bytes(32));

      // Store token in keyvalue.expirable with 60s TTL.
      $store = $this->keyValueExpirable->get('markaspot_switch_tokens');
      $store->setWithExpire($token, [
        'uid' => (int) $target_user->id(),
        'generated_by' => (int) $this->currentUser->id(),
      ], 60);

      $this->getLogger('markaspot_passwordless')->notice('Switch token generated for @target by uid @generator.', [
        '@target' => $target_user->getAccountName(),
        '@generator' => $this->currentUser->id(),
      ]);

      return new JsonResponse([
        'token' => $token,
        'url' => '/auth/claim?token=' . $token,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to generate switch token: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Failed to generate switch token.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Claim a switch token and create a session for the target user.
   *
   * POST /api/auth/claim-token
   * Body: { "token": "abc123..." }
   *
   * This endpoint is publicly accessible (no session required) since it is
   * designed to be called from a new browser window without any existing
   * authentication. Security is provided by the token itself: 256 bits of
   * entropy, single-use, and 60-second TTL.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the authenticated user's status.
   */
  public function claimSwitchToken(Request $request): JsonResponse {
    // Safety check: only works when Devel module is enabled.
    if (!$this->moduleHandler()->moduleExists('devel')) {
      return new JsonResponse([
        'error' => 'Token claiming is only available in development environments.',
      ], Response::HTTP_FORBIDDEN);
    }

    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['token'])) {
      return new JsonResponse([
        'error' => 'Token is required.',
      ], Response::HTTP_BAD_REQUEST);
    }

    $token = trim($data['token']);

    // Reject obviously invalid tokens before hitting the database.
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
      return new JsonResponse([
        'error' => 'Invalid token format.',
      ], Response::HTTP_BAD_REQUEST);
    }

    try {
      // Look up token in keyvalue.expirable (TTL handles expiry automatically).
      $store = $this->keyValueExpirable->get('markaspot_switch_tokens');
      $token_data = $store->get($token);

      if (!$token_data) {
        return new JsonResponse([
          'error' => 'Invalid or expired token.',
        ], Response::HTTP_UNAUTHORIZED);
      }

      // Delete token immediately (single-use).
      $store->delete($token);

      // Load the target user.
      $target_user = $this->entityTypeManager()
        ->getStorage('user')
        ->load($token_data['uid']);

      if (!$target_user || $target_user->isBlocked()) {
        return new JsonResponse([
          'error' => 'Target user not available.',
        ], Response::HTTP_FORBIDDEN);
      }

      // If the claimer is already authenticated (e.g. same browser window),
      // fire logout hooks before establishing the new session.
      if ($this->currentUser->isAuthenticated()) {
        $this->moduleHandler()->invokeAll('user_logout', [$this->currentUser]);
      }

      // Use Drupal's canonical login finalization: handles session migration,
      // login hooks, and login timestamp in one call.
      user_login_finalize($target_user);

      $this->getLogger('markaspot_passwordless')->notice('Switch token claimed: uid @target (generated by uid @generator).', [
        '@target' => $target_user->getAccountName(),
        '@generator' => $token_data['generated_by'],
      ]);

      // Return the same structure as status().
      return new JsonResponse([
        'success' => TRUE,
        'authenticated' => TRUE,
        'user' => [
          'uid' => $target_user->id(),
          'name' => $target_user->getAccountName(),
          'email' => $target_user->getEmail(),
          'roles' => $target_user->getRoles(),
          'groups' => $this->getUserGroups($target_user),
        ],
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to claim switch token: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'An error occurred during token claim.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Get user's group memberships.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   Array of group information with id, label, and roles.
   */
  protected function getUserGroups($user): array {
    $groups = [];

    // Check if Group module is available.
    if (!$this->moduleHandler()->moduleExists('group')) {
      return $groups;
    }

    try {
      // Dynamic lookup: group.membership_loader only exists when the Group
      // module is enabled, so it cannot be constructor-injected.
      $membership_loader = \Drupal::service('group.membership_loader');
      $memberships = $membership_loader->loadByUser($user);

      foreach ($memberships as $membership) {
        $group = $membership->getGroup();
        $group_roles = [];

        // Get group roles for this membership.
        foreach ($membership->getRoles() as $role) {
          $group_roles[] = [
            'id' => $role->id(),
            'label' => $role->label(),
          ];
        }

        $groups[] = [
          'id' => $group->id(),
          'uuid' => $group->uuid(),
          'label' => $group->label(),
          'type' => $group->bundle(),
          'roles' => $group_roles,
        ];
      }
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to load user groups: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $groups;
  }

}
