<?php

namespace Drupal\markaspot_passwordless\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and validating OTP codes.
 */
class OtpService {

  use JurisdictionIdResolverTrait;

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
   * Drupal permissions exposed as narrow frontend dashboard capability keys.
   */
  private const FRONTEND_PERMISSION_MAP = [
    'administer site configuration' => 'administer site configuration',
    'triage inbound mail' => 'triage inbound mail',
    'delete requests' => 'delete any service_request content',
  ];

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
   * @param \Drupal\Core\Entity\EntityRepositoryInterface|null $entityRepository
   *   The entity repository service.
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
    protected ?EntityRepositoryInterface $entityRepository = NULL,
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
   * Generates a new OTP code and sends it via email. The code is bound
   * to the issuing jurisdiction so that verifyCode() cannot consume it
   * on a different tenant.
   *
   * @param string $email
   *   The email address.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID this code belongs to. 0 means
   *   single-tenant / unscoped; callers MUST pass the real jurisdiction
   *   ID in multi-tenant deployments to prevent cross-tenant reuse.
   * @param string $langcode
   *   The language code for the email.
   *
   * @return array
   *   Result array with status and message.
   */
  public function requestCode(string $email, int $jurisdiction_id, string $langcode = ''): array {
    // Clean up expired codes first.
    $this->cleanupExpiredCodes();

    // Get configuration values.
    $config = $this->configFactory->get('markaspot_passwordless.settings');
    $code_lifetime = $config->get('code_lifetime') ?? 600;

    // Block-state guard runs BEFORE any DB mutation so a blocked user
    // cannot cause in-flight legitimate codes for active accounts on
    // other jurisdictions to be wiped, and so attackers cannot observe
    // a DB-mutation timing differential.
    $existingUser = $this->loadUserByEmail($email);
    if ($existingUser && $existingUser->isBlocked()) {
      $this->logger->warning('Blocked user @email requested passwordless login code.', [
        '@email' => $email,
      ]);
      // Dummy bcrypt to equalize timing against the legitimate path that
      // calls password_hash() at line ~212. Same pattern as the empty-
      // records branch of verifyCode().
      password_verify('000000', self::DUMMY_HASH);
      return [
        'success' => TRUE,
        'message' => 'Verification code sent to your email',
        'expiresIn' => $code_lifetime,
      ];
    }

    // Invalidate existing codes for this email *within the same
    // jurisdiction*. A request on tenant A must not wipe an in-flight
    // code the same address holds on tenant B.
    $this->database->update('markaspot_passwordless_codes')
    // Mark as invalidated.
      ->fields(['verified' => 2])
      ->condition('email', $email)
      ->condition('jurisdiction_id', $jurisdiction_id)
      ->condition('verified', 0)
      ->execute();

    // Generate new code.
    $code = $this->generateCode();
    $now = time();
    $expires = $now + $code_lifetime;

    // Hash the code before storing it. This prevents plaintext OTP
    // exposure if the database is compromised.
    $code_hash = password_hash($code, PASSWORD_BCRYPT);

    // Store hashed code in database, bound to the issuing jurisdiction
    // so that verifyCode() cannot consume it on a different tenant.
    try {
      $this->database->insert('markaspot_passwordless_codes')
        ->fields([
          'email' => $email,
          'code' => $code_hash,
          'attempts' => 0,
          'created' => $now,
          'expires' => $expires,
          'verified' => 0,
          'jurisdiction_id' => $jurisdiction_id,
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
   * @param int $jurisdiction_id
   *   The jurisdiction group ID the verification attempt is scoped to.
   *   Codes issued for a different jurisdiction will not match, closing
   *   the cross-tenant OTP reuse vector. 0 means single-tenant / unscoped;
   *   callers MUST pass the real jurisdiction ID in multi-tenant
   *   deployments. No default is intentionally provided to force the
   *   decision at every call site.
   *
   * @return array
   *   Result array with status, message, and optional user data.
   */
  public function verifyCode(string $email, string $code, int $jurisdiction_id): array {
    // Get configuration values.
    $config = $this->configFactory->get('markaspot_passwordless.settings');
    $max_attempts = $config->get('max_attempts') ?? 3;

    // Wrap the entire verification in a transaction to prevent race
    // conditions where parallel requests could bypass attempt limits.
    $transaction = $this->database->startTransaction('otp_verify');

    try {
      // Look up pending codes for this email *within the caller's
      // jurisdiction*. Since codes are hashed, we cannot filter by code
      // in the query. We fetch all pending codes for this email+
      // jurisdiction and verify against each hash. Scoping the lookup
      // by jurisdiction_id makes cross-tenant OTP consumption
      // structurally impossible rather than relying on a post-hoc check.
      $records = $this->database->select('markaspot_passwordless_codes', 'c')
        ->fields('c')
        ->condition('email', $email)
        ->condition('jurisdiction_id', $jurisdiction_id)
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
            'permissions' => $this->getFrontendPermissions($user),
            'groups' => $this->getUserGroups($user),
            'preferred_langcode' => $user->getPreferredLangcode(FALSE),
          ] + $this->getTosAcceptancePayload($user),
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
    $user = $this->loadUserByEmail($email);

    if ($user) {
      if ($user->isBlocked()) {
        $this->logger->warning('Blocked user @email attempted passwordless login.', [
          '@email' => $email,
        ]);
        return NULL;
      }
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

      // Auto-create user. Seed preferred_langcode from the current Drupal
      // request language so the dashboard hydrates with a sensible default;
      // users can change this later via /api/auth/preferences.
      try {
        $current_langcode = $this->languageManager->getCurrentLanguage()->getId();

        $user = User::create([
        // Use email as username.
          'name' => $email,
          'mail' => $email,
          'status' => 1,
          'roles' => ['authenticated'],
          'preferred_langcode' => $current_langcode,
        ]);
        $user->save();

        $this->logger->info('Created new user account for @email with langcode @langcode', [
          '@email' => $email,
          '@langcode' => $current_langcode,
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
   * Loads an existing user by email.
   *
   * @param string $email
   *   The email address.
   *
   * @return \Drupal\user\Entity\User|null
   *   The user entity, or NULL when no matching user exists.
   */
  protected function loadUserByEmail(string $email): ?User {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    if (empty($users)) {
      return NULL;
    }

    /** @var \Drupal\user\Entity\User $user */
    $user = reset($users);
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
      // Expose the jurisdiction id for markaspot_mail's PasswordlessOtpBuilder
      // so branded OTP mails can render with jurisdiction Zone-1 + jurisdiction
      // logo. The legacy hook_mail ignores this param; only markaspot_mail's
      // hook_mail_alter reads it.
      'jurisdiction_id' => $jurisdiction_id > 0 ? $jurisdiction_id : NULL,
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

        $groups[] = [
          'id' => $group->id(),
          'uuid' => $group->uuid(),
          'label' => $this->getEntityLabelForUserLanguage($group, $user),
          'type' => $is_jurisdiction_group ? 'jur' : $group->bundle(),
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

  /**
   * Loads group memberships for the given user.
   */
  protected function loadUserGroupMemberships(AccountInterface $user): array {
    return GroupMembership::loadByUser($user);
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
   * Gets Terms of Service acceptance state from the user entity.
   *
   * The field is provided by markaspot_fastmap, so passwordless auth treats it
   * as optional and exposes a stable false/null shape when it is unavailable.
   *
   * @param \Drupal\user\Entity\User|null $user
   *   The user entity.
   *
   * @return array{tos_accepted: bool, tos_accepted_at: int|null}
   *   ToS acceptance payload for frontend auth state.
   */
  protected function getTosAcceptancePayload(?User $user): array {
    if (
      !$user ||
      !$user->hasField('field_tos_accepted_at')
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
  protected function getEntityLabelForUserLanguage(EntityInterface $entity, User $user): string {
    $langcode = (string) $user->getPreferredLangcode(FALSE);

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
        // Minimal containers may not expose entity.repository. In that case,
        // keep the existing default-label behavior.
      }
    }

    return (string) $entity->label();
  }

  /**
   * Gets a config entity label in a specific language when available.
   */
  protected function getConfigEntityLabelForLangcode(ConfigEntityInterface $entity, string $langcode): string {
    if ($langcode !== '' && method_exists($this->languageManager, 'getLanguageConfigOverride')) {
      try {
        $label = $this->languageManager
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
