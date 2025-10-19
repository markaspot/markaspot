<?php

namespace Drupal\markaspot_passwordless\Controller;

use Drupal\Core\Controller\ControllerBase;
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
   * Constructs a PasswordlessAuthController object.
   *
   * @param \Drupal\markaspot_passwordless\Service\OtpService $otp_service
   *   The OTP service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(OtpService $otp_service, AccountInterface $current_user) {
    $this->otpService = $otp_service;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('markaspot_passwordless.otp'),
      $container->get('current_user')
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

    try {
      // Request OTP code.
      $result = $this->otpService->requestCode($email);

      if ($result['success']) {
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

    try {
      // Verify OTP code.
      $result = $this->otpService->verifyCode($email, $code);

      if ($result['success']) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => $result['message'],
          'user' => $result['user'],
        ]);
      }

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
      return new JsonResponse([
        'authenticated' => TRUE,
        'user' => [
          'uid' => $account->id(),
          'name' => $account->getAccountName(),
          'email' => $account->getEmail(),
          'roles' => $account->getRoles(),
        ],
      ]);
    }

    return new JsonResponse([
      'authenticated' => FALSE,
    ]);
  }

}
