<?php

declare(strict_types=1);

namespace Drupal\markaspot_passwordless\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Issues one-time recovery codes while Drupal Core maintenance mode is active.
 *
 * This is deliberately separate from the regular passwordless-code table. A
 * recovery code is global, has no jurisdiction scope, and must never be
 * accepted by the normal OTP flow. Only an existing, active account with
 * Drupal Core's maintenance-mode permission can receive and consume one.
 */
final class BreakGlassOtpService implements BreakGlassOtpServiceInterface {

  /**
   * Dedicated expirable key-value collection for recovery codes.
   */
  private const COLLECTION = 'markaspot_passwordless_break_glass_codes';

  /**
   * Pre-computed bcrypt hash for timing-safe dummy verification.
   */
  private const DUMMY_HASH = '$2y$12$Swf5KJU7Onq1XGRI0n8hdeWHWEiR033nChaJ6yHLG7mpvl/xa32aG';

  /**
   * The Core permission that grants a maintenance exemption.
   */
  private const MAINTENANCE_PERMISSION = 'access site in maintenance mode';

  /**
   * Generic response text used for every syntactically valid request.
   */
  private const GENERIC_REQUEST_MESSAGE = 'If the account is eligible, a verification code has been sent.';

  /**
   * Constructs the break-glass OTP service.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly LockBackendInterface $lock,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Reports whether the Core-wide maintenance switch is currently active.
   */
  public function isAvailable(): bool {
    return (bool) $this->state->get('system.maintenance_mode', FALSE);
  }

  /**
   * Requests a recovery code without exposing account eligibility.
   *
   * @param string $email
   *   A syntactically valid email address supplied by the requester.
   * @param string $langcode
   *   The optional mail language requested by the client.
   *
   * @return array{success: bool, message: string, expiresIn: int}
   *   The same generic result for all requests, including noneligible users.
   */
  public function requestCode(string $email, string $langcode = ''): array {
    $lifetime = $this->getCodeLifetime();
    $result = [
      'success' => TRUE,
      'message' => self::GENERIC_REQUEST_MESSAGE,
      'expiresIn' => $lifetime,
    ];

    if (!$this->isAvailable()) {
      $this->performDummyRequestWork();
      return $result;
    }

    $user = $this->loadEligibleUserByEmail($email);
    if ($user === NULL) {
      $this->performDummyRequestWork();
      return $result;
    }

    $key = $this->getKey($email);
    $lock_name = self::COLLECTION . ':' . $key;
    if (!$this->lock->acquire($lock_name, 10.0)) {
      $this->performDummyRequestWork();
      return $result;
    }

    try {
      // Check again after acquiring the lock so a concurrent state or role
      // change cannot turn this into a recovery path for a stale account.
      if (!$this->isAvailable() || !$this->isEligible($user)) {
        $this->performDummyRequestWork();
        return $result;
      }

      $code = (string) random_int(100000, 999999);
      $record = [
        'uid' => (int) $user->id(),
        'email' => $this->canonicalizeEmail($user->getEmail()),
        'code' => password_hash($code, PASSWORD_BCRYPT),
        'attempts' => 0,
        'expires' => time() + $lifetime,
      ];
      $this->getStore()->setWithExpire($key, $record, $lifetime);

      try {
        $mail_result = $this->mailManager->mail(
          'markaspot_passwordless',
          'verification_code',
          $user->getEmail(),
          $langcode,
          [
            'code' => $code,
            'expires_in' => (int) ceil($lifetime / 60),
            'platform_name' => $this->configFactory->get('system.site')->get('name') ?: 'Mark-a-Spot',
            'email_footer' => '',
            'jurisdiction_id' => NULL,
          ],
          NULL,
          TRUE,
        );
        if (empty($mail_result['result'])) {
          $this->logger->error('Break-glass OTP mail transport reported failure.');
        }
      }
      catch (\Throwable $exception) {
        // Do not turn a mail transport failure into an eligibility oracle.
        $this->logger->error('Break-glass OTP mail could not be sent: @message', [
          '@message' => $exception->getMessage(),
        ]);
      }
    }
    catch (\Throwable $exception) {
      // The public response stays generic. Operators retain the detailed log.
      $this->logger->error('Break-glass OTP request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
    finally {
      $this->lock->release($lock_name);
    }

    return $result;
  }

  /**
   * Validates a recovery code and returns the eligible account, if any.
   *
   * Caller must finish the Drupal login only after this method returns a user.
   * The permission and active-account checks intentionally run again here:
   * receiving a code must not retain access after the account is blocked or
   * its Core maintenance permission is revoked.
   */
  public function verifyCode(string $email, string $code): ?UserInterface {
    if (!$this->isAvailable()) {
      $this->performDummyVerifyWork();
      return NULL;
    }

    $key = $this->getKey($email);
    $lock_name = self::COLLECTION . ':' . $key;
    if (!$this->lock->acquire($lock_name, 10.0)) {
      $this->performDummyVerifyWork();
      return NULL;
    }

    try {
      $record = $this->getStore()->get($key);
      if (!is_array($record)
        || !isset($record['uid'], $record['email'], $record['code'], $record['attempts'], $record['expires'])
        || !is_int($record['uid'])
        || !is_string($record['email'])
        || !is_string($record['code'])
        || !is_int($record['attempts'])
        || !is_int($record['expires'])) {
        $this->performDummyVerifyWork();
        return NULL;
      }

      if ($record['expires'] < time()) {
        $this->getStore()->delete($key);
        $this->performDummyVerifyWork();
        return NULL;
      }

      if (!password_verify($code, $record['code'])) {
        $this->recordFailedAttempt($key, $record);
        return NULL;
      }

      // Delete before returning the user so a valid code is single-use even
      // if the later session login fails.
      $this->getStore()->delete($key);
      $user = $this->entityTypeManager->getStorage('user')->load($record['uid']);
      if (!$user instanceof UserInterface
        || !hash_equals($record['email'], $this->canonicalizeEmail($user->getEmail()))
        || !$this->isAvailable()
        || !$this->isEligible($user)) {
        return NULL;
      }

      return $user;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Break-glass OTP verification failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Finds an existing, active account eligible for maintenance access.
   */
  private function loadEligibleUserByEmail(string $email): ?UserInterface {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);
    $user = reset($users);

    return $user instanceof UserInterface && $this->isEligible($user) ? $user : NULL;
  }

  /**
   * Checks the exact account conditions for recovery access.
   */
  private function isEligible(UserInterface $user): bool {
    return $user->isActive()
      && $user->hasPermission(self::MAINTENANCE_PERMISSION);
  }

  /**
   * Stores a bounded failed-attempt count without extending code lifetime.
   *
   * @param string $key
   *   The recovery-code store key.
   * @param array{uid: int, email: string, code: string, attempts: int, expires: int} $record
   *   The current recovery-code record.
   */
  private function recordFailedAttempt(string $key, array $record): void {
    $record['attempts']++;
    $max_attempts = max(1, (int) ($this->configFactory->get('markaspot_passwordless.settings')->get('max_attempts') ?? 3));
    $remaining = $record['expires'] - time();

    if ($record['attempts'] >= $max_attempts || $remaining < 1) {
      $this->getStore()->delete($key);
      return;
    }

    $this->getStore()->setWithExpire($key, $record, $remaining);
  }

  /**
   * Gets the configured OTP lifetime with a safe lower bound.
   */
  private function getCodeLifetime(): int {
    return max(60, (int) ($this->configFactory->get('markaspot_passwordless.settings')->get('code_lifetime') ?? 600));
  }

  /**
   * Gets the isolated expirable store.
   */
  private function getStore(): KeyValueStoreExpirableInterface {
    return $this->keyValueExpirable->get(self::COLLECTION);
  }

  /**
   * Builds a non-reversible storage key from the submitted email address.
   */
  private function getKey(string $email): string {
    return hash('sha256', $this->canonicalizeEmail($email));
  }

  /**
   * Normalizes email casing for store lookups and record binding.
   */
  private function canonicalizeEmail(string $email): string {
    return mb_strtolower(trim($email));
  }

  /**
   * Equalizes noneligible request work against bcrypt code generation.
   */
  private function performDummyRequestWork(): void {
    password_hash('000000', PASSWORD_BCRYPT);
  }

  /**
   * Equalizes missing or noneligible verification against a real code check.
   */
  private function performDummyVerifyWork(): void {
    password_verify('000000', self::DUMMY_HASH);
  }

}
