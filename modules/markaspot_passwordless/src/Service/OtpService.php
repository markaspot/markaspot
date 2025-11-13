<?php

namespace Drupal\markaspot_passwordless\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and validating OTP codes.
 */
class OtpService {

  /**
   * OTP code length (6 digits).
   */
  const CODE_LENGTH = 6;

  /**
   * OTP lifetime in seconds (10 minutes).
   */
  const CODE_LIFETIME = 600;

  /**
   * Maximum verification attempts.
   */
  const MAX_ATTEMPTS = 3;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs an OtpService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    Connection $database,
    MailManagerInterface $mail_manager,
    AccountProxyInterface $current_user,
    LoggerInterface $logger
  ) {
    $this->database = $database;
    $this->mailManager = $mail_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger;
  }

  /**
   * Generate a 6-digit OTP code.
   *
   * Uses random_int for cryptographically secure random number generation.
   * Generates codes from 100000-999999 (excludes leading zeros).
   *
   * @return string
   *   The 6-digit OTP code.
   */
  protected function generateCode(): string {
    // Generate random number between 100000 and 999999
    // This ensures we always get exactly 6 digits (no leading zeros)
    return (string) random_int(100000, 999999);
  }

  /**
   * Request an OTP code for email authentication.
   *
   * Generates a new OTP code and sends it via email.
   *
   * @param string $email
   *   The email address.
   *
   * @return array
   *   Result array with status and message.
   */
  public function requestCode(string $email): array {
    // Clean up expired codes first.
    $this->cleanupExpiredCodes();

    // Invalidate any existing codes for this email.
    $this->database->update('markaspot_passwordless_codes')
      ->fields(['verified' => 2]) // Mark as invalidated
      ->condition('email', $email)
      ->condition('verified', 0)
      ->execute();

    // Generate new code.
    $code = $this->generateCode();
    $now = time();
    $expires = $now + self::CODE_LIFETIME;

    // Store code in database.
    try {
      $this->database->insert('markaspot_passwordless_codes')
        ->fields([
          'email' => $email,
          'code' => $code,
          'attempts' => 0,
          'created' => $now,
          'expires' => $expires,
          'verified' => 0,
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store OTP code: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'error' => 'Failed to generate verification code',
      ];
    }

    // Send email.
    $sent = $this->sendCode($email, $code);

    if ($sent) {
      return [
        'success' => TRUE,
        'message' => 'Verification code sent to your email',
        'expiresIn' => self::CODE_LIFETIME,
      ];
    }

    return [
      'success' => FALSE,
      'error' => 'Failed to send verification email',
    ];
  }

  /**
   * Verify an OTP code.
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
    // Look up the code.
    $record = $this->database->select('markaspot_passwordless_codes', 'c')
      ->fields('c')
      ->condition('email', $email)
      ->condition('code', $code)
      ->condition('verified', 0)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$record) {
      return [
        'success' => FALSE,
        'error' => 'Invalid verification code',
      ];
    }

    // Check if expired.
    if ($record['expires'] < time()) {
      return [
        'success' => FALSE,
        'error' => 'Verification code has expired',
      ];
    }

    // Check attempts.
    if ($record['attempts'] >= self::MAX_ATTEMPTS) {
      return [
        'success' => FALSE,
        'error' => 'Too many attempts. Please request a new code.',
      ];
    }

    // Increment attempts.
    $this->database->update('markaspot_passwordless_codes')
      ->fields(['attempts' => $record['attempts'] + 1])
      ->condition('id', $record['id'])
      ->execute();

    // Code is valid - mark as verified.
    $this->database->update('markaspot_passwordless_codes')
      ->fields(['verified' => 1])
      ->condition('id', $record['id'])
      ->execute();

    // Authenticate the user.
    $user = $this->authenticateUser($email);

    if ($user) {
      return [
        'success' => TRUE,
        'message' => 'Authentication successful',
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
      'error' => 'Failed to authenticate user',
    ];
  }

  /**
   * Authenticate or create user and log them in.
   *
   * @param string $email
   *   The email address.
   *
   * @return \Drupal\user\Entity\User|null
   *   The user entity or NULL on failure.
   */
  protected function authenticateUser(string $email): ?User {
    // Look up user by email.
    $users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    if (!empty($users)) {
      /** @var \Drupal\user\Entity\User $user */
      $user = reset($users);
    }
    else {
      // Auto-create user if they don't exist.
      try {
        $user = User::create([
          'name' => $email, // Use email as username
          'mail' => $email,
          'status' => 1,
          'roles' => ['authenticated'],
        ]);
        $user->save();

        $this->logger->info('Created new user account for @email', [
          '@email' => $email,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to create user: @message', [
          '@message' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    // Log the user in.
    user_login_finalize($user);

    return $user;
  }

  /**
   * Send OTP code via email.
   *
   * @param string $email
   *   The email address.
   * @param string $code
   *   The 6-digit OTP code.
   *
   * @return bool
   *   TRUE if email was sent successfully.
   */
  protected function sendCode(string $email, string $code): bool {
    $params = [
      'code' => $code,
      'email' => $email,
      'expires_in' => self::CODE_LIFETIME / 60, // Convert to minutes
    ];

    try {
      $result = $this->mailManager->mail(
        'markaspot_passwordless',
        'verification_code',
        $email,
        \Drupal::languageManager()->getDefaultLanguage()->getId(),
        $params,
        NULL,
        TRUE
      );

      return $result['result'] ?? FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send OTP email: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Clean up expired OTP codes.
   *
   * Removes codes that have expired more than 1 hour ago.
   */
  protected function cleanupExpiredCodes(): void {
    $this->database->delete('markaspot_passwordless_codes')
      ->condition('expires', time() - 3600, '<')
      ->execute();
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
  protected function getUserGroups(User $user): array {
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
      $this->logger->error('Failed to load user groups: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $groups;
  }

}
