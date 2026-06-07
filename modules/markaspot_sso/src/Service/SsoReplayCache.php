<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Stores processed SSO response IDs to prevent replay.
 */
final class SsoReplayCache {
  private const DEFAULT_TTL = 600;

  /**
   * Constructs the replay cache.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Stores message/assertion IDs when they have not been seen before.
   */
  public function checkAndStore(string $provider, ?string $message_id, ?string $assertion_id, ?int $expires): bool {
    $this->deleteExpired();
    $message_id = $this->cleanId($message_id);
    $assertion_id = $this->cleanId($assertion_id);
    if ($message_id === NULL || $assertion_id === NULL) {
      return FALSE;
    }

    if (
          ($message_id !== NULL && $this->seen('message_id', $provider, $message_id))
          || ($assertion_id !== NULL && $this->seen('assertion_id', $provider, $assertion_id))
      ) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $safe_expires = $expires !== NULL && $expires > $now
        ? min($expires, $now + 86400)
        : $now + self::DEFAULT_TTL;

    try {
      $this->database->insert('markaspot_sso_replay')
        ->fields([
          'provider' => $provider,
          'message_id' => $message_id,
          'assertion_id' => $assertion_id,
          'expires' => $safe_expires,
          'created' => $now,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Deletes expired replay records.
   */
  public function deleteExpired(): void {
    $this->database->delete('markaspot_sso_replay')
      ->condition('expires', $this->time->getRequestTime(), '<')
      ->execute();
  }

  /**
   * Checks whether an ID was already processed.
   */
  private function seen(string $field, string $provider, string $value): bool {
    return (bool) $this->database->select('markaspot_sso_replay', 'r')
      ->condition('provider', $provider)
      ->condition($field, $value)
      ->range(0, 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Sanitizes SSO message identifiers for storage.
   */
  private function cleanId(?string $value): ?string {
    if ($value === NULL || trim($value) === '') {
      return NULL;
    }

    return mb_substr(trim($value), 0, 255);
  }

}
