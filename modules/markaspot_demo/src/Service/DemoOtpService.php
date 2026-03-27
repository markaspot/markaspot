<?php

namespace Drupal\markaspot_demo\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Demo OTP service that accepts fixed codes for demo users.
 *
 * This service decorates the original OtpService and allows demo users
 * to authenticate with a fixed code (123456) without email verification.
 *
 * WARNING: This module should NEVER be enabled in production!
 */
class DemoOtpService extends OtpService {

  /**
   * The demo code that works for all demo users.
   */
  const DEMO_CODE = '123456';

  /**
   * The inner OTP service (decorated service).
   *
   * @var \Drupal\markaspot_passwordless\Service\OtpService
   */
  protected $inner;

  /**
   * Constructs a DemoOtpService object.
   *
   * @param \Drupal\markaspot_passwordless\Service\OtpService $inner
   *   The inner OTP service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(
    OtpService $inner,
    Connection $database,
    MailManagerInterface $mail_manager,
    AccountProxyInterface $current_user,
    LoggerInterface $logger,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
  ) {
    parent::__construct(
      $database,
      $mail_manager,
      $current_user,
      $logger,
      $config_factory,
      $entity_type_manager,
      $language_manager
    );
    $this->inner = $inner;
  }

  /**
   * Request an OTP code for email authentication.
   *
   * For demo users, we skip email sending and return success immediately.
   *
   * @param string $email
   *   The email address.
   *
   * @return array
   *   Result array with status and message.
   */
  public function requestCode(string $email, string $langcode = '', int $jurisdiction_id = 0): array {
    // Check if this is a demo user.
    if ($this->isDemoUser($email)) {
      $this->logger->info('Demo mode: Code request for demo user @email. Use code: @code', [
        '@email' => $email,
        '@code' => self::DEMO_CODE,
      ]);

      // Return success without actually sending email.
      return [
        'success' => TRUE,
        'message' => 'Demo mode: Use code ' . self::DEMO_CODE,
        'expiresIn' => 600,
        'demo' => TRUE,
      ];
    }

    // For non-demo users, use the inner service.
    return $this->inner->requestCode($email, $langcode, $jurisdiction_id);
  }

  /**
   * Verify an OTP code.
   *
   * For demo users, accept the fixed demo code.
   *
   * @param string $email
   *   The email address.
   * @param string $code
   *   The 6-digit OTP code.
   *
   * @return array
   *   Result array with status, message, and optional user data.
   */
  public function verifyCode(string $email, string $code): array {
    // Check if this is a demo user with the demo code.
    if ($this->isDemoUser($email) && $code === self::DEMO_CODE) {
      $this->logger->info('Demo mode: Authenticating demo user @email with demo code', [
        '@email' => $email,
      ]);

      // Authenticate the user directly.
      $user = $this->authenticateDemoUser($email);

      if ($user) {
        return [
          'success' => TRUE,
          'message' => 'Demo authentication successful',
          'user' => [
            'uid' => $user->id(),
            'name' => $user->getAccountName(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'groups' => $this->getUserGroups($user),
          ],
        ];
      }

      return [
        'success' => FALSE,
        'error' => 'Demo user not found or inactive',
      ];
    }

    // For non-demo users or wrong code, use the inner service.
    return $this->inner->verifyCode($email, $code);
  }

  /**
   * Check if the email belongs to a demo user.
   *
   * @param string $email
   *   The email address.
   *
   * @return bool
   *   TRUE if this is a demo user.
   */
  protected function isDemoUser(string $email): bool {
    $config = $this->configFactory->get('markaspot_demo.settings');
    $demo_emails = $config->get('demo_emails') ?? [];

    // If no explicit emails configured, auto-detect from roles.
    if (empty($demo_emails)) {
      $demo_emails = $this->getAutoDetectedDemoEmails();
    }

    return in_array(strtolower($email), array_map('strtolower', $demo_emails));
  }

  /**
   * Get auto-detected demo emails from users with specific roles.
   *
   * @return array
   *   Array of email addresses.
   */
  protected function getAutoDetectedDemoEmails(): array {
    $demo_emails = [];
    $demo_roles = ['administrator', 'moderator', 'api_user', 'tenant_admin'];

    try {
      $user_storage = $this->entityTypeManager->getStorage('user');

      foreach ($demo_roles as $role) {
        $users = $user_storage->loadByProperties(['roles' => $role, 'status' => 1]);
        foreach ($users as $user) {
          $mail = $user->getEmail();
          if ($mail) {
            $demo_emails[] = $mail;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load demo users: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return array_unique($demo_emails);
  }

  /**
   * Authenticate a demo user by email.
   *
   * @param string $email
   *   The email address.
   *
   * @return \Drupal\user\Entity\User|null
   *   The user entity or NULL on failure.
   */
  protected function authenticateDemoUser(string $email): ?User {
    try {
      $users = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['mail' => $email, 'status' => 1]);

      if (!empty($users)) {
        /** @var \Drupal\user\Entity\User $user */
        $user = reset($users);

        // Log the user in.
        user_login_finalize($user);

        return $user;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to authenticate demo user: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

}
