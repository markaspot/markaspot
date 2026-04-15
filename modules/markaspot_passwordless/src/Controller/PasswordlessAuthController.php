<?php

namespace Drupal\markaspot_passwordless\Controller;

use Symfony\Component\HttpFoundation\Cookie;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_passwordless\Service\OtpService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides JSON API endpoints for passwordless OTP authentication.
 */
class PasswordlessAuthController extends ControllerBase {

  use JurisdictionIdResolverTrait;

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
   * The feature flag checker.
   *
   * @var \Drupal\markaspot_nuxt\Service\FeatureFlagChecker
   */
  protected FeatureFlagChecker $featureFlagChecker;

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
   * @param \Drupal\markaspot_nuxt\Service\FeatureFlagChecker $feature_flag_checker
   *   The feature flag checker.
   */
  public function __construct(
    OtpService $otp_service,
    AccountInterface $current_user,
    FloodInterface $flood,
    ConfigFactoryInterface $config_factory,
    SessionConfigurationInterface $session_configuration,
    KeyValueExpirableFactoryInterface $key_value_expirable,
    FeatureFlagChecker $feature_flag_checker,
  ) {
    $this->otpService = $otp_service;
    $this->currentUser = $current_user;
    $this->flood = $flood;
    $this->configFactory = $config_factory;
    $this->sessionConfiguration = $session_configuration;
    $this->keyValueExpirable = $key_value_expirable;
    $this->featureFlagChecker = $feature_flag_checker;
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
      $container->get('keyvalue.expirable'),
      $container->get('markaspot_nuxt.feature_flag_checker')
    );
  }

  /**
   * Resolves the jurisdiction_id field from a request body.
   *
   * Enforces a strict contract:
   * - Key absent, NULL, or empty string → returns 0 (single-tenant /
   *   unscoped).
   * - Key present and resolves to a real, loadable jur group → returns
   *   the integer GID.
   * - Key present but of the wrong type, unresolvable, or pointing at a
   *   non-existent GID → returns FALSE (caller should reject with 400).
   *
   * This closes two bypasses from the #324 review cycle:
   * 1. Attacker submits an unresolvable slug and falls through a
   *    null-coalescing fallback onto the unscoped sentinel, matching
   *    legacy / single-tenant OTP rows.
   * 2. Attacker submits a positive numeric GID that does not correspond
   *    to any jurisdiction (phantom tenant), exploiting the trait's
   *    numeric pass-through to issue or consume OTPs against a
   *    fictitious scope.
   *
   * @param array $data
   *   The decoded request body.
   *
   * @return int|false
   *   The resolved jurisdiction group ID, 0 for an omitted field, or
   *   FALSE if the provided value does not resolve to a real group.
   */
  protected function resolveJurisdictionPayload(array $data): int|false {
    if (!array_key_exists('jurisdiction_id', $data)) {
      return 0;
    }
    $raw = $data['jurisdiction_id'];
    if ($raw === NULL || $raw === '') {
      return 0;
    }
    // Reject non-scalar input (array, object) before the trait's
    // string|int|null signature would throw a TypeError.
    if (!is_scalar($raw)) {
      return FALSE;
    }
    $resolved = $this->resolveJurisdictionId($raw);
    if ($resolved === NULL) {
      return FALSE;
    }
    // The trait's numeric branch passes integers through without
    // verifying the group exists. Enforce existence here so phantom
    // GIDs cannot be used to issue or consume OTPs against fictitious
    // tenants.
    if ($resolved > 0) {
      $group = $this->entityTypeManager()->getStorage('group')->load($resolved);
      if (!$group || $group->bundle() !== 'jur') {
        return FALSE;
      }
    }
    return $resolved;
  }

  /**
   * Checks whether passwordless auth is enabled for the request jurisdiction.
   *
   * Reads features.passwordless from field_nuxt_config. Default is FALSE
   * (schema default), matching the frontend useFeatureFlags().passwordlessEnabled
   * and the dashboard writer. Returns a 403 JsonResponse when disabled, a 400
   * when the provided jurisdiction_id cannot be resolved, NULL when the call
   * should proceed.
   *
   * @param array $data
   *   The decoded request body.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   A 400/403 response when the request is rejected, NULL otherwise.
   */
  protected function assertPasswordlessEnabled(array $data): ?JsonResponse {
    // Route through the strict resolver so that an unresolvable slug or
    // a phantom GID gets a 400 (clear client error) rather than a 403
    // ("feature disabled") that would mask the real reason.
    $jurisdictionId = $this->resolveJurisdictionPayload($data);
    if ($jurisdictionId === FALSE) {
      return new JsonResponse([
        'error' => 'Invalid jurisdiction_id',
      ], Response::HTTP_BAD_REQUEST);
    }

    $jurisdiction = $jurisdictionId
      ? $this->entityTypeManager()->getStorage('group')->load($jurisdictionId)
      : NULL;

    if (!$this->featureFlagChecker->isEnabled('features.passwordless', $jurisdiction, FALSE)) {
      return new JsonResponse([
        'error' => $this->t('Passwordless authentication is disabled for this jurisdiction.'),
      ], Response::HTTP_FORBIDDEN);
    }

    return NULL;
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
    if (!is_array($data)) {
      $data = [];
    }

    // Feature flag gate — reject early when the jurisdiction has
    // passwordless auth disabled. This runs before email validation
    // and flood checks so disabled tenants never hit rate limiters.
    if ($denied = $this->assertPasswordlessEnabled($data)) {
      return $denied;
    }

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

    // Optional: language from request body.
    $langcode = !empty($data['langcode']) ? trim($data['langcode']) : '';

    // Scope the request to a jurisdiction. If the caller provided a
    // jurisdiction_id but it cannot be resolved to a real jur group,
    // reject with 400 rather than falling through to the unscoped
    // sentinel — otherwise an attacker could pin the insert at
    // jurisdiction_id=0 and consume it later on any other tenant.
    $jurisdiction_id = $this->resolveJurisdictionPayload($data);
    if ($jurisdiction_id === FALSE) {
      return new JsonResponse([
        'error' => 'Invalid jurisdiction_id',
      ], Response::HTTP_BAD_REQUEST);
    }

    try {
      // Request OTP code.
      $result = $this->otpService->requestCode($email, $jurisdiction_id, $langcode);

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
    if (!is_array($data)) {
      $data = [];
    }

    // Feature flag gate — reject when the jurisdiction has passwordless
    // auth disabled. Belt-and-suspenders with requestCode's gate, in
    // case someone grabs an OTP from a different tenant.
    if ($denied = $this->assertPasswordlessEnabled($data)) {
      return $denied;
    }

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

    // Resolve the jurisdiction context. The OTP must have been issued
    // for this jurisdiction; otherwise the service-layer lookup will
    // not find it. This is the storage-level mirror of the feature-flag
    // gate at the top of this method — and what actually prevents an
    // OTP issued on tenant A from being consumed on tenant B. Reject
    // explicitly-provided-but-unresolvable jurisdiction_id values so
    // an attacker cannot pin the lookup to the unscoped sentinel.
    $jurisdiction_id = $this->resolveJurisdictionPayload($data);
    if ($jurisdiction_id === FALSE) {
      return new JsonResponse([
        'error' => 'Invalid jurisdiction_id',
      ], Response::HTTP_BAD_REQUEST);
    }

    try {
      // Verify OTP code.
      $result = $this->otpService->verifyCode($email, $code, $jurisdiction_id);

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
          'preferred_langcode' => $user->getPreferredLangcode(FALSE),
        ],
      ]);
    }

    return new JsonResponse([
      'authenticated' => FALSE,
    ]);
  }

  /**
   * Update authenticated user preferences.
   *
   * PATCH /api/auth/preferences
   * Body: { "preferred_langcode": "de" }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the updated user, matching the status() shape.
   */
  public function updatePreferences(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    if (!is_array($data) || empty($data['preferred_langcode'])) {
      return new JsonResponse([
        'error' => 'preferred_langcode is required',
      ], Response::HTTP_BAD_REQUEST);
    }

    $langcode = strtolower(trim((string) $data['preferred_langcode']));

    // Validate against user-facing configurable languages only. STATE_ALL
    // would also accept locked sentinels like 'und' (LANGCODE_NOT_SPECIFIED)
    // and 'zxx' (LANGCODE_NOT_APPLICABLE), which must not be writable as a
    // user preference.
    $enabled = $this->languageManager()
      ->getLanguages(LanguageInterface::STATE_CONFIGURABLE);
    if (!isset($enabled[$langcode])) {
      return new JsonResponse([
        'error' => 'Invalid langcode',
      ], Response::HTTP_BAD_REQUEST);
    }

    try {
      /** @var \Drupal\user\UserInterface $user */
      $user = $this->entityTypeManager()
        ->getStorage('user')
        ->load($this->currentUser->id());

      if (!$user) {
        return new JsonResponse([
          'error' => 'User not found',
        ], Response::HTTP_NOT_FOUND);
      }

      $user->set('preferred_langcode', $langcode);
      $user->save();

      return new JsonResponse([
        'authenticated' => TRUE,
        'user' => [
          'uid' => $user->id(),
          'name' => $user->getAccountName(),
          'email' => $user->getEmail(),
          'roles' => $user->getRoles(),
          'groups' => $this->getUserGroups($user),
          'preferred_langcode' => $user->getPreferredLangcode(FALSE),
        ],
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to update preferences: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Failed to update preferences',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
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
