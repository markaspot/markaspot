<?php

namespace Drupal\markaspot_passwordless\Controller;

use Symfony\Component\HttpFoundation\Cookie;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_nuxt\Service\FrontendUrlService;
use Drupal\markaspot_passwordless\Service\BreakGlassOtpServiceInterface;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides JSON API endpoints for passwordless OTP authentication.
 *
 * @phpstan-consistent-constructor
 */
class PasswordlessAuthController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  protected const SESSION_HANDOFF_TOKEN_STORE = 'markaspot_session_handoff_tokens';

  protected const SESSION_HANDOFF_TOKEN_TTL = 60;

  protected const SESSION_HANDOFF_CLAIM_LIMIT = 30;

  protected const SESSION_HANDOFF_CLAIM_WINDOW = 300;

  /**
   * Drupal permissions exposed as narrow frontend dashboard capability keys.
   */
  protected const FRONTEND_PERMISSION_MAP = [
    'administer site configuration' => 'administer site configuration',
    'triage inbound mail' => 'triage inbound mail',
    'delete requests' => 'delete any service_request content',
    'split service requests' => 'split service requests',
    'administer markaspot mail texts' => 'administer markaspot mail texts',
    'assign service requests' => 'assign service requests',
    'switch users' => 'switch users',
  ];

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
   * @var \Drupal\markaspot_nuxt\Service\FeatureScopeResolver
   */
  protected FeatureScopeResolver $featureScopeResolver;

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
   * @param \Drupal\markaspot_nuxt\Service\FeatureScopeResolver $feature_scope_resolver
   *   The effective feature scope resolver.
   * @param \Drupal\Core\State\StateInterface $state
   *   The Core state service containing the global maintenance switch.
   * @param \Drupal\markaspot_passwordless\Service\BreakGlassOtpServiceInterface $breakGlassOtp
   *   The recovery-only OTP service used while Core maintenance is active.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface|null $entityRepository
   *   The entity repository service.
   * @param \Drupal\markaspot_nuxt\Service\FrontendUrlService|null $frontendUrlService
   *   The frontend URL service.
   */
  public function __construct(
    OtpService $otp_service,
    AccountInterface $current_user,
    FloodInterface $flood,
    ConfigFactoryInterface $config_factory,
    SessionConfigurationInterface $session_configuration,
    KeyValueExpirableFactoryInterface $key_value_expirable,
    FeatureScopeResolver $feature_scope_resolver,
    private readonly StateInterface $state,
    private readonly BreakGlassOtpServiceInterface $breakGlassOtp,
    protected ?EntityRepositoryInterface $entityRepository = NULL,
    protected ?FrontendUrlService $frontendUrlService = NULL,
  ) {
    $this->otpService = $otp_service;
    $this->currentUser = $current_user;
    $this->flood = $flood;
    $this->configFactory = $config_factory;
    $this->sessionConfiguration = $session_configuration;
    $this->keyValueExpirable = $key_value_expirable;
    $this->featureScopeResolver = $feature_scope_resolver;
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
      $container->get('markaspot_nuxt.feature_scope_resolver'),
      $container->get('state'),
      $container->get('markaspot_passwordless.break_glass_otp'),
      $container->get('entity.repository'),
      $container->get('markaspot_nuxt.frontend_url'),
    );
  }

  /**
   * Resolves the jurisdiction_id field from a request body.
   *
   * Enforces a strict contract:
   * - Key absent, NULL, or empty string → returns 0 (single-tenant /
   *   unscoped).
   * - Key present and resolving to a real jurisdiction group: returns
   *   the integer GID.
   * - Key present but of the wrong type, unresolvable, or pointing at a
   *   non-existent GID → returns FALSE (caller should reject with 400).
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
    if (is_int($raw)) {
      if ($raw === 0) {
        return 0;
      }
    }
    elseif (is_string($raw)) {
      $raw = trim($raw);
      if ($raw === '') {
        return 0;
      }
      if (ctype_digit($raw) && (int) $raw === 0) {
        return 0;
      }
      if (is_numeric($raw) && !ctype_digit($raw)) {
        return FALSE;
      }
    }
    else {
      return FALSE;
    }

    $resolved = $this->resolveJurisdictionId($raw);
    if ($resolved === NULL) {
      return FALSE;
    }
    return $resolved;
  }

  /**
   * Checks whether passwordless auth is enabled on this installation.
   *
   * Passwordless login is a platform-scope flag: it is read from the central
   * platform_features configuration regardless of the requested jurisdiction.
   * An unset (NULL) value derives from the operating mode (saas => enabled).
   *
   * @param int $jurisdictionId
   *   The resolved jurisdiction group ID, or 0 for unscoped requests. Kept
   *   for signature stability; the platform flag ignores it.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   A 403 response when the request is rejected, NULL otherwise.
   */
  protected function assertPasswordlessEnabled(int $jurisdictionId): ?JsonResponse {
    if (!$this->featureScopeResolver->isPlatformFeatureEnabled('passwordless')) {
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

    // Core maintenance turns normal OTP into a narrow recovery path. This
    // branch intentionally runs before tenant resolution and the passwordless
    // feature flag: recovery is installation-wide and must work even if a
    // tenant has normal passwordless login disabled.
    if ($this->isCoreMaintenanceModeActive()) {
      return $this->requestBreakGlassCode($data, $request);
    }

    $jurisdiction_id = $this->resolveJurisdictionPayload($data);
    if ($jurisdiction_id === FALSE) {
      return new JsonResponse([
        'error' => 'Invalid jurisdiction_id',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Feature flag gate — reject early when the jurisdiction has
    // passwordless auth disabled. This runs before email validation
    // and flood checks so disabled tenants never hit rate limiters.
    if ($denied = $this->assertPasswordlessEnabled($jurisdiction_id)) {
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
    $email_identifier = $email . ':' . $jurisdiction_id;
    if (!$this->flood->isAllowed('passwordless.request_code', $request_limit_per_email, 3600, $email_identifier)) {
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

    try {
      // Request OTP code.
      $result = $this->otpService->requestCode($email, $jurisdiction_id, $langcode);

      if ($result['success']) {
        // Register the successful request for rate limiting.
        $this->flood->register('passwordless.request_code', 3600, $email_identifier);
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

    // A recovery code is deliberately not a normal passwordless code. It is
    // accepted only while Core maintenance is still active and the dedicated
    // service repeats the current account eligibility check before login.
    if ($this->isCoreMaintenanceModeActive()) {
      return $this->verifyBreakGlassCode($data, $request);
    }

    $jurisdiction_id = $this->resolveJurisdictionPayload($data);
    if ($jurisdiction_id === FALSE) {
      return new JsonResponse([
        'error' => 'Invalid jurisdiction_id',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Feature flag gate — reject when the jurisdiction has passwordless
    // auth disabled. Belt-and-suspenders with requestCode's gate, in
    // case someone grabs an OTP from a different tenant.
    if ($denied = $this->assertPasswordlessEnabled($jurisdiction_id)) {
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
    $identifier = $email . ':' . $ip . ':' . $jurisdiction_id;

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
   * Handles the public recovery-code request while Core is in maintenance.
   *
   * The result is intentionally identical for eligible, blocked, missing, and
   * nonexempt accounts. Only the dedicated service decides whether mail is
   * actually sent, which prevents the endpoint from becoming an account or
   * permission oracle.
   *
   * @param array<string, mixed> $data
   *   The decoded request payload.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   */
  protected function requestBreakGlassCode(array $data, Request $request): JsonResponse {
    if (empty($data['email'])) {
      return new JsonResponse([
        'error' => 'Email is required',
      ], Response::HTTP_BAD_REQUEST);
    }

    $email = trim((string) $data['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse([
        'error' => 'Invalid email format',
      ], Response::HTTP_BAD_REQUEST);
    }

    $config = $this->configFactory->get('markaspot_passwordless.settings');
    $request_limit_per_email = $config->get('request_limit_per_email') ?? 3;
    $request_limit_per_ip = $config->get('request_limit_per_ip') ?? 10;
    $flood_email = $this->canonicalizeBreakGlassFloodEmail($email);
    $email_identifier = 'break_glass:' . $flood_email;
    $ip = $request->getClientIp() ?: 'unknown';

    if (!$this->flood->isAllowed('passwordless.break_glass.request', $request_limit_per_email, 3600, $email_identifier)) {
      return new JsonResponse([
        'error' => 'Too many code requests. Please try again in an hour.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }
    if (!$this->flood->isAllowed('passwordless.break_glass.request.ip', $request_limit_per_ip, 3600, $ip)) {
      return new JsonResponse([
        'error' => 'Too many requests from your location. Please try again later.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    $langcode = !empty($data['langcode']) ? trim((string) $data['langcode']) : '';
    $result = $this->breakGlassOtp->requestCode($email, $langcode);
    $this->flood->register('passwordless.break_glass.request', 3600, $email_identifier);
    $this->flood->register('passwordless.break_glass.request.ip', 3600, $ip);

    return new JsonResponse($result);
  }

  /**
   * Handles recovery-code verification while Core is in maintenance.
   *
   * @param array<string, mixed> $data
   *   The decoded request payload.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   */
  protected function verifyBreakGlassCode(array $data, Request $request): JsonResponse {
    if (empty($data['email']) || empty($data['code'])) {
      return new JsonResponse([
        'error' => 'Email and code are required',
      ], Response::HTTP_BAD_REQUEST);
    }

    $email = trim((string) $data['email']);
    $code = trim((string) $data['code']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse([
        'error' => 'Invalid email format',
      ], Response::HTTP_BAD_REQUEST);
    }
    if (!preg_match('/^\d{6}$/', $code)) {
      return new JsonResponse([
        'error' => 'Invalid code format',
      ], Response::HTTP_BAD_REQUEST);
    }

    $config = $this->configFactory->get('markaspot_passwordless.settings');
    $verify_lockout_attempts = $config->get('verify_lockout_attempts') ?? 5;
    $verify_lockout_duration = $config->get('verify_lockout_duration') ?? 900;
    $ip = $request->getClientIp() ?: 'unknown';
    $flood_email = $this->canonicalizeBreakGlassFloodEmail($email);
    $identifier = 'break_glass:' . $flood_email . ':' . $ip;

    if (!$this->flood->isAllowed('passwordless.break_glass.verify.lockout', $verify_lockout_attempts, $verify_lockout_duration, $identifier)) {
      $minutes = ceil($verify_lockout_duration / 60);
      return new JsonResponse([
        'error' => "Too many failed attempts. Account temporarily locked. Please try again in {$minutes} minutes.",
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }
    if (!$this->flood->isAllowed('passwordless.break_glass.verify.backoff', 3, 60, $identifier)) {
      return new JsonResponse([
        'error' => 'Too many attempts. Please wait a moment before trying again.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    $user = $this->breakGlassOtp->verifyCode($email, $code);
    if ($user === NULL) {
      $this->flood->register('passwordless.break_glass.verify.lockout', $verify_lockout_duration, $identifier);
      $this->flood->register('passwordless.break_glass.verify.backoff', 60, $identifier);
      return new JsonResponse([
        'error' => 'Invalid verification code',
      ], Response::HTTP_UNAUTHORIZED);
    }

    $this->flood->clear('passwordless.break_glass.verify.lockout', $identifier);
    $this->flood->clear('passwordless.break_glass.verify.backoff', $identifier);
    $this->completeBreakGlassLogin($user);

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Authentication successful',
      'user' => $this->buildAuthUserPayload($user, $user),
    ]);
  }

  /**
   * Starts the normal Drupal session after recovery authorization succeeded.
   */
  protected function completeBreakGlassLogin(UserInterface $user): void {
    user_login_finalize($user);
  }

  /**
   * Returns whether Drupal Core's global maintenance switch is currently on.
   */
  protected function isCoreMaintenanceModeActive(): bool {
    return (bool) $this->state->get('system.maintenance_mode', FALSE);
  }

  /**
   * Canonicalizes the email portion of maintenance-only Flood identifiers.
   *
   * This remains deliberately separate from the normal OTP flow. Core
   * maintenance is a global recovery path, so differently cased variants of
   * the same address must not create separate per-email Flood buckets.
   */
  private function canonicalizeBreakGlassFloodEmail(string $email): string {
    return mb_strtolower(trim($email));
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
    // HttpOnly cookies cannot be deleted by JavaScript.
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
        // Keep the frontend aligned with Drupal Core's exact exemption
        // semantics. It is intentionally a capability, not a role check:
        // installations may grant the Core permission to operational staff
        // such as editorial_board without allowing them to toggle maintenance.
        'maintenance_access' => $account->hasPermission('access site in maintenance mode'),
        'user' => $this->buildAuthUserPayload($account, $user),
      ]);
    }

    return new JsonResponse([
      'authenticated' => FALSE,
      'maintenance_access' => FALSE,
    ]);
  }

  /**
   * Starts a Drupal-to-Nuxt session handoff for the current user.
   *
   * GET /api/auth/session-handoff/start?redirect=/amsterdam/dashboard.
   *
   * If the caller has no Drupal session yet, redirect them to Drupal's login
   * form and preserve this endpoint as the login destination. Once logged in,
   * a short-lived one-time token is minted and the browser is sent to Nuxt's
   * claim page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Redirect response, or JSON error when no frontend base URL is configured.
   */
  public function startSessionHandoff(Request $request): Response {
    if (!$this->currentUser->isAuthenticated()) {
      return new RedirectResponse('/user/login?destination=' . rawurlencode($request->getRequestUri()));
    }

    $redirect = $this->normalizeInternalRedirect($request->query->get('redirect'));
    $frontend_base = $this->resolveFrontendBaseUrl($request);
    if ($frontend_base === NULL) {
      return new JsonResponse([
        'error' => 'Frontend base URL is not configured for session handoff.',
      ], Response::HTTP_CONFLICT);
    }

    try {
      $token = bin2hex(random_bytes(32));
      $store = $this->keyValueExpirable->get(static::SESSION_HANDOFF_TOKEN_STORE);
      $store->setWithExpire($token, [
        'uid' => (int) $this->currentUser->id(),
        'redirect' => $redirect,
      ], static::SESSION_HANDOFF_TOKEN_TTL);

      $claim_path = $this->buildSessionHandoffClaimPath($token, $redirect);
      return new TrustedRedirectResponse(rtrim($frontend_base, '/') . $claim_path);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to start session handoff: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Failed to start session handoff.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Claims a Drupal-to-Nuxt session handoff token.
   *
   * POST /api/auth/session-handoff/claim
   * Body: { "token": "abc123..." }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with authenticated user state and the stored redirect path.
   */
  public function claimSessionHandoff(Request $request): JsonResponse {
    if ($denied = $this->assertSessionHandoffClaimAllowed($request)) {
      return $denied;
    }

    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['token'])) {
      return new JsonResponse([
        'error' => 'Token is required.',
      ], Response::HTTP_BAD_REQUEST);
    }

    $token = trim((string) $data['token']);
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
      return new JsonResponse([
        'error' => 'Invalid token format.',
      ], Response::HTTP_BAD_REQUEST);
    }

    try {
      $store = $this->keyValueExpirable->get(static::SESSION_HANDOFF_TOKEN_STORE);
      $token_data = $store->get($token);

      if (!$token_data || !is_array($token_data) || empty($token_data['uid'])) {
        return new JsonResponse([
          'error' => 'Invalid or expired token.',
        ], Response::HTTP_UNAUTHORIZED);
      }

      // Delete before login finalization so the token stays single-use even if
      // a later hook fails.
      $store->delete($token);

      $target_user = $this->entityTypeManager()
        ->getStorage('user')
        ->load((int) $token_data['uid']);

      if (!$target_user || $target_user->isBlocked()) {
        return new JsonResponse([
          'error' => 'Target user not available.',
        ], Response::HTTP_FORBIDDEN);
      }

      if ($this->currentUser->isAuthenticated()) {
        $this->moduleHandler()->invokeAll('user_logout', [$this->currentUser]);
      }

      user_login_finalize($target_user);

      return new JsonResponse([
        'success' => TRUE,
        'authenticated' => TRUE,
        'redirect' => $this->normalizeInternalRedirect($token_data['redirect'] ?? NULL),
        'user' => $this->buildAuthUserPayload($target_user, $target_user),
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_passwordless')->error('Failed to claim session handoff: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'An error occurred during session handoff.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Rate-limits public session handoff token claim attempts.
   */
  protected function assertSessionHandoffClaimAllowed(Request $request): ?JsonResponse {
    $ip = $request->getClientIp() ?: 'unknown';
    $event = 'passwordless.session_handoff_claim';

    if (!$this->flood->isAllowed($event, static::SESSION_HANDOFF_CLAIM_LIMIT, static::SESSION_HANDOFF_CLAIM_WINDOW, $ip)) {
      return new JsonResponse([
        'error' => 'Too many session handoff attempts. Please try again later.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    $this->flood->register($event, static::SESSION_HANDOFF_CLAIM_WINDOW, $ip);
    return NULL;
  }

  /**
   * Builds the authenticated user payload returned by auth endpoints.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account used for stable identity fields.
   * @param object|null $user
   *   The loaded user entity when available.
   *
   * @return array
   *   The frontend auth user payload.
   */
  protected function buildAuthUserPayload(AccountInterface $account, $user): array {
    $preferred_langcode = $user && method_exists($user, 'getPreferredLangcode')
      ? (string) $user->getPreferredLangcode(FALSE)
      : '';

    return [
      'uid' => $account->id(),
      'uuid' => $user && method_exists($user, 'uuid') ? (string) $user->uuid() : '',
      'name' => $account->getAccountName(),
      'email' => $account->getEmail(),
      'roles' => $account->getRoles(),
      'permissions' => $this->getFrontendPermissions($account),
      'groups' => $user ? $this->getUserGroups($user) : [],
      'preferred_langcode' => $preferred_langcode,
    ] + $this->getTosAcceptancePayload($user);
  }

  /**
   * Returns the small permission key set the Nuxt dashboard understands.
   */
  protected function getFrontendPermissions(AccountInterface $account): array {
    $permissions = [];
    foreach (static::FRONTEND_PERMISSION_MAP as $frontend_permission => $drupal_permission) {
      if ($account->hasPermission($drupal_permission)) {
        $permissions[] = $frontend_permission;
      }
    }
    return $permissions;
  }

  /**
   * Normalizes a client redirect to a safe internal frontend path.
   */
  protected function normalizeInternalRedirect(mixed $raw): string {
    if (!is_string($raw)) {
      return '/dashboard';
    }
    if (str_contains($raw, '\\')) {
      return '/dashboard';
    }

    $value = trim(str_replace(["\r", "\n", "\0"], '', $raw));
    if ($value === ''
      || strlen($value) > 1024
      || !str_starts_with($value, '/')
      || str_starts_with($value, '//')
    ) {
      return '/dashboard';
    }

    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
      return '/dashboard';
    }

    $segments = explode('/', trim($path, '/'));
    if (in_array('auth', $segments, TRUE)) {
      return '/dashboard';
    }

    return $value;
  }

  /**
   * Resolves the Nuxt frontend base URL for the browser redirect.
   */
  protected function resolveFrontendBaseUrl(Request $request): ?string {
    $configured = $this->frontendUrlService?->getFrontendBaseUrl();
    if (is_string($configured) && trim($configured) !== '') {
      return $this->normalizeFrontendBaseUrl($configured);
    }

    foreach (['NUXT_PUBLIC_SITE_URL', 'NUXT_SITE_URL', 'FRONTEND_BASE_URL'] as $env_key) {
      $env_value = getenv($env_key);
      if (is_string($env_value) && trim($env_value) !== '') {
        return $this->normalizeFrontendBaseUrl($env_value);
      }
    }

    $host = $request->getHost();
    if ($host === '' || str_contains($host, ':')) {
      return NULL;
    }

    if ($host === 'localhost'
      || $host === '127.0.0.1'
      || str_ends_with($host, '.localhost')
      || str_ends_with($host, '.ddev.site')
    ) {
      $scheme = $request->isSecure() ? 'https' : $request->getScheme();
      return $scheme . '://' . $host . ':3001';
    }

    return NULL;
  }

  /**
   * Normalizes configured frontend base URLs to safe http(s) origins.
   */
  protected function normalizeFrontendBaseUrl(string $raw): ?string {
    $value = rtrim(trim(str_replace(["\r", "\n", "\0"], '', $raw)), '/');
    if ($value === '') {
      return NULL;
    }

    $parts = parse_url($value);
    if (!is_array($parts)
      || empty($parts['scheme'])
      || empty($parts['host'])
      || !in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE)
      || isset($parts['user'])
      || isset($parts['pass'])
      || isset($parts['query'])
      || isset($parts['fragment'])
    ) {
      return NULL;
    }

    return $value;
  }

  /**
   * Builds the Nuxt claim path while preserving a jurisdiction URL prefix.
   */
  protected function buildSessionHandoffClaimPath(string $token, string $redirect): string {
    $path = parse_url($redirect, PHP_URL_PATH);
    $segments = is_string($path) ? explode('/', trim($path, '/')) : [];
    $first_segment = $segments[0] ?? '';

    $reserved = [
      'admin',
      'api',
      'auth',
      'dashboard',
      'embed',
      'impressum',
      'legal',
      'lite',
      'privacy',
      'report',
      'requests',
      'start',
      'terms',
      'user',
    ];

    $prefix = '';
    if ($first_segment !== ''
      && preg_match('/^[a-z0-9_-]{1,64}$/', $first_segment)
      && !ctype_digit($first_segment)
      && !in_array($first_segment, $reserved, TRUE)
    ) {
      $prefix = '/' . $first_segment;
    }

    return $prefix . '/auth/claim?type=drupal-session#token=' . $token;
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
        'user' => $this->buildAuthUserPayload($user, $user),
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
        'user' => $this->buildAuthUserPayload($target_user, $target_user),
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

      // Entity Query applies inequalities row-by-row on multi-value fields.
      // Build an anti-set so mixed-role accounts are excluded while users
      // without any explicit role remain eligible.
      $api_user_uids = $user_storage->getQuery()
        ->condition('roles', 'api_user')
        ->accessCheck(FALSE)
        ->execute();

      // Load active users, exclude anonymous (uid=0) and super admin (uid=1).
      $query = $user_storage->getQuery()
        ->condition('uid', 1, '>')
        ->condition('status', 1)
        ->sort('uid');
      if ($api_user_uids !== []) {
        $query->condition('uid', array_values($api_user_uids), 'NOT IN');
      }
      $uids = $query
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
        'user' => $this->buildAuthUserPayload($target_user, $target_user),
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
      $memberships = $this->loadUserGroupMemberships($user);

      foreach ($memberships as $membership) {
        $group = $membership->getGroup();
        $group_roles = [];
        $is_jurisdiction_group = $this->isJurisdictionGroup($group);

        // Get group roles for this membership.
        foreach ($membership->getRoles() as $role) {
          $role_id = $role->id();
          if ($is_jurisdiction_group) {
            $role_id = $this->canonicalizeJurisdictionRoleId($role_id);
          }
          if (MembershipRoleNormalizer::isInternalRoleId($role_id)) {
            continue;
          }
          $group_roles[] = [
            'id' => $role_id,
            'label' => $this->getEntityLabelForUserLanguage($role, $user),
          ];
        }

        $slug = NULL;
        if ($is_jurisdiction_group && $group->hasField('field_slug') && !$group->get('field_slug')->isEmpty()) {
          $candidate = trim((string) $group->get('field_slug')->value);
          // Reject digit-only slugs to keep the client-side scope guard
          // (markaspot-ui#438) free of slug/numeric-id collisions, where a
          // membership in slug "42" would otherwise grant scope access to the
          // unrelated numeric group 42.
          if ($candidate !== ''
            && preg_match('/^[a-z0-9_-]{1,64}$/', $candidate)
            && !ctype_digit($candidate)
          ) {
            $slug = $candidate;
          }
        }

        $groups[] = [
          'id' => $group->id(),
          'uuid' => $group->uuid(),
          'label' => $this->getEntityLabelForUserLanguage($group, $user),
          'type' => $is_jurisdiction_group ? 'jur' : $group->bundle(),
          'slug' => $slug,
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

  /**
   * Loads group memberships for the given user.
   */
  protected function loadUserGroupMemberships(AccountInterface $user): array {
    return GroupMembership::loadByUser($user);
  }

  /**
   * Gets Terms of Service acceptance state from the user entity.
   *
   * The field is provided by markaspot_fastmap, so passwordless auth treats it
   * as optional and exposes a stable false/null shape when it is unavailable.
   *
   * @param object|null $user
   *   The user entity.
   *
   * @return array{tos_accepted: bool, tos_accepted_at: int|null}
   *   ToS acceptance payload for frontend auth state.
   */
  protected function getTosAcceptancePayload($user): array {
    if (
      !$user ||
      !method_exists($user, 'hasField') ||
      !$user->hasField('field_tos_accepted_at') ||
      !method_exists($user, 'get')
    ) {
      return [
        'tos_accepted' => FALSE,
        'tos_accepted_at' => NULL,
      ];
    }

    $field = $user->get('field_tos_accepted_at');
    $value = $field->value ?? NULL;
    if (($value === NULL || $value === '') && method_exists($field, 'getString')) {
      $value = $field->getString();
    }

    $accepted_at = ($value !== NULL && $value !== '') ? (int) $value : NULL;

    return [
      'tos_accepted' => $accepted_at !== NULL,
      'tos_accepted_at' => $accepted_at,
    ];
  }

  /**
   * Gets an entity label in the user's preferred language when available.
   */
  protected function getEntityLabelForUserLanguage(EntityInterface $entity, $user): string {
    $langcode = method_exists($user, 'getPreferredLangcode')
      ? (string) $user->getPreferredLangcode(FALSE)
      : '';

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
        // Fall back to the entity's default label when translation lookup is
        // unavailable, e.g. in minimal test containers.
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

}
