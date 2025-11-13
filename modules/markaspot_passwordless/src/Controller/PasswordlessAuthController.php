<?php

namespace Drupal\markaspot_passwordless\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
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
   * Constructs a PasswordlessAuthController object.
   *
   * @param \Drupal\markaspot_passwordless\Service\OtpService $otp_service
   *   The OTP service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   */
  public function __construct(OtpService $otp_service, AccountInterface $current_user, FloodInterface $flood) {
    $this->otpService = $otp_service;
    $this->currentUser = $current_user;
    $this->flood = $flood;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('markaspot_passwordless.otp'),
      $container->get('current_user'),
      $container->get('flood')
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

    // Rate limit by email (3 requests per hour).
    if (!$this->flood->isAllowed('passwordless.request_code', 3, 3600, $email)) {
      return new JsonResponse([
        'error' => 'Too many code requests. Please try again in an hour.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    // Rate limit by IP (10 requests per hour).
    $ip = $request->getClientIp();
    if (!$this->flood->isAllowed('passwordless.request_code.ip', 10, 3600, $ip)) {
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

    // Check for hard lockout (5+ failed attempts in 15 minutes).
    if (!$this->flood->isAllowed('passwordless.verify.lockout', 5, 900, $identifier)) {
      return new JsonResponse([
        'error' => 'Too many failed attempts. Account temporarily locked. Please try again in 15 minutes.',
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
      $this->flood->register('passwordless.verify.lockout', 900, $identifier);
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
   * POST /api/auth/logout
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with logout status.
   */
  public function logout(Request $request): JsonResponse {
    if ($this->currentUser->isAuthenticated()) {
      user_logout();

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Logged out successfully',
      ]);
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Not logged in',
    ]);
  }

  /**
   * User status endpoint.
   *
   * GET /api/auth/status
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
      $user = \Drupal::entityTypeManager()
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
    if (!\Drupal::moduleHandler()->moduleExists('group')) {
      return $groups;
    }

    try {
      // Load group membership service.
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
      \Drupal::logger('markaspot_passwordless')->error('Failed to load user groups: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $groups;
  }

}
