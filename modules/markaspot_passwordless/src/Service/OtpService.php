<?php

namespace Drupal\markaspot_passwordless\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
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
   * Pre-computed bcrypt hash for timing-safe dummy verification.
   *
   * Uses cost factor 12 (PHP 8.x PASSWORD_BCRYPT default) to match
   * the timing of real password_verify() calls and prevent email
   * enumeration via response time differences.
   */
  private const DUMMY_HASH = '$2y$12$Swf5KJU7Onq1XGRI0n8hdeWHWEiR033nChaJ6yHLG7mpvl/xa32aG';

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    Connection $database,
    MailManagerInterface $mail_manager,
    AccountProxyInterface $current_user,
    LoggerInterface $logger,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    ModuleHandlerInterface $module_handler,
  ) {
    $this->database = $database;
    $this->mailManager = $mail_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
    $this->moduleHandler = $module_handler;
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
   * @param string $langcode
   *   The language code for the email.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID for branding.
   *
   * @return array
   *   Result array with status and message.
   */
  public function requestCode(string $email, string $langcode = '', int $jurisdiction_id = 0): array {
    // Clean up expired codes first.
    $this->cleanupExpiredCodes();

    // Get configuration values.
    $config = $this->configFactory->get('markaspot_passwordless.settings');
    $code_lifetime = $config->get('code_lifetime') ?? 600;

    // Invalidate any existing codes for this email.
    $this->database->update('markaspot_passwordless_codes')
    // Mark as invalidated.
      ->fields(['verified' => 2])
      ->condition('email', $email)
      ->condition('verified', 0)
      ->execute();

    // Generate new code.
    $code = $this->generateCode();
    $now = time();
    $expires = $now + $code_lifetime;

    // Hash the code before storing it. This prevents plaintext OTP
    // exposure if the database is compromised.
    $code_hash = password_hash($code, PASSWORD_BCRYPT);

    // Store hashed code in database.
    try {
      $this->database->insert('markaspot_passwordless_codes')
        ->fields([
          'email' => $email,
          'code' => $code_hash,
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
    $sent = $this->sendCode($email, $code, $code_lifetime, $langcode, $jurisdiction_id);

    if ($sent) {
      return [
        'success' => TRUE,
        'message' => 'Verification code sent to your email',
        'expiresIn' => $code_lifetime,
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
   * The entire verification process runs inside a database transaction
   * to prevent race condition brute force attacks where parallel requests
   * could bypass the attempt counter.
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
    // Get configuration values.
    $config = $this->configFactory->get('markaspot_passwordless.settings');
    $max_attempts = $config->get('max_attempts') ?? 3;

    // Wrap the entire verification in a transaction to prevent race
    // conditions where parallel requests could bypass attempt limits.
    $transaction = $this->database->startTransaction('otp_verify');

    try {
      // Look up pending codes for this email. Since codes are hashed,
      // we cannot filter by code in the query. We fetch all pending
      // codes for this email and verify against each hash.
      $records = $this->database->select('markaspot_passwordless_codes', 'c')
        ->fields('c')
        ->condition('email', $email)
        ->condition('verified', 0)
        ->orderBy('created', 'DESC')
        ->execute()
        ->fetchAll();

      if (empty($records)) {
        // Perform a dummy bcrypt verify to equalize timing and prevent
        // email enumeration via response time measurement.
        password_verify('000000', self::DUMMY_HASH);
        return [
          'success' => FALSE,
          'error' => 'Invalid verification code',
        ];
      }

      // Find the record whose hash matches the provided code.
      $matched_record = NULL;
      foreach ($records as $record) {
        if (password_verify($code, $record->code)) {
          $matched_record = $record;
          break;
        }
      }

      if ($matched_record === NULL) {
        // No matching hash found. Use atomic SQL increment to prevent
        // race conditions where parallel requests all read the same
        // attempt count and only increment by 1 instead of N.
        $latest = reset($records);
        $this->database->update('markaspot_passwordless_codes')
          ->expression('attempts', 'attempts + 1')
          ->condition('id', $latest->id)
          ->execute();

        return [
          'success' => FALSE,
          'error' => 'Invalid verification code',
        ];
      }

      // Check if expired.
      if ($matched_record->expires < time()) {
        return [
          'success' => FALSE,
          'error' => 'Verification code has expired',
        ];
      }

      // Check attempts.
      if ($matched_record->attempts >= $max_attempts) {
        return [
          'success' => FALSE,
          'error' => 'Too many attempts. Please request a new code.',
        ];
      }

      // Code is valid. Mark as verified atomically within the transaction.
      // Use atomic increment and condition on verified=0 to prevent
      // double-claim race conditions. Check affected rows to detect
      // concurrent verification attempts.
      $affected = $this->database->update('markaspot_passwordless_codes')
        ->expression('attempts', 'attempts + 1')
        ->fields(['verified' => 1])
        ->condition('id', $matched_record->id)
        ->condition('verified', 0)
        ->execute();

      if ($affected === 0) {
        // Another request already verified this code (race condition).
        $this->logger->warning('Double-claim attempt detected for OTP code id @id, email @email.', [
          '@id' => $matched_record->id,
          '@email' => $email,
        ]);
        return [
          'success' => FALSE,
          'error' => 'Verification code has already been used',
        ];
      }

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
    catch (\Exception $e) {
      // Roll back the transaction on any exception.
      $transaction->rollBack();
      $this->logger->error('OTP verification failed with exception: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
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
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    if (!empty($users)) {
      /** @var \Drupal\user\Entity\User $user */
      $user = reset($users);
    }
    else {
      // Check if auto-registration is enabled.
      $config = $this->configFactory->get('markaspot_passwordless.settings');
      $auto_register = $config->get('auto_register') ?? FALSE;

      if (!$auto_register) {
        $this->logger->notice('Login attempt for non-existent user @email. Auto-registration is disabled.', [
          '@email' => $email,
        ]);
        return NULL;
      }

      // Auto-create user if they don't exist.
      try {
        $user = User::create([
        // Use email as username.
          'name' => $email,
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
   * @param int $code_lifetime
   *   The code lifetime in seconds.
   * @param string $langcode
   *   The language code for the email.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID for branding.
   *
   * @return bool
   *   TRUE if email was sent successfully.
   */
  protected function sendCode(string $email, string $code, int $code_lifetime, string $langcode = '', int $jurisdiction_id = 0): bool {
    // Determine language: explicit > current > default.
    if (empty($langcode)) {
      $langcode = $this->languageManager->getCurrentLanguage()->getId();
    }

    $params = [
      'code' => $code,
      'email' => $email,
      'expires_in' => (int) ($code_lifetime / 60),
      'platform_name' => '',
      'email_footer' => '',
    ];

    // Load jurisdiction group entity for platform name and email footer.
    if ($jurisdiction_id > 0) {
      try {
        $group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
        if ($group) {
          // Use translation if available.
          if ($group->hasTranslation($langcode)) {
            $group = $group->getTranslation($langcode);
          }
          if ($group->hasField('field_platform_name') && !$group->get('field_platform_name')->isEmpty()) {
            $params['platform_name'] = $group->get('field_platform_name')->value;
          }
          if ($group->hasField('field_email_footer') && !$group->get('field_email_footer')->isEmpty()) {
            $params['email_footer'] = $group->get('field_email_footer')->value;
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Failed to load jurisdiction @id for email: @message', [
          '@id' => $jurisdiction_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Fallback platform name to site name.
    if (empty($params['platform_name'])) {
      $params['platform_name'] = $this->configFactory->get('system.site')->get('name') ?: 'Mark-a-Spot';
    }

    try {
      $result = $this->mailManager->mail(
        'markaspot_passwordless',
        'verification_code',
        $email,
        $langcode,
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
   * This is called internally and also via cron (hook_cron).
   */
  public function cleanupExpiredCodes(): void {
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
    if (!$this->moduleHandler->moduleExists('group')) {
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
