<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Transactional, time-bounded idempotency records for Lite submissions.
 *
 * A record is inserted from entity_presave, after Entity SQL storage has
 * opened the service request transaction. Therefore a failed node save rolls
 * the reservation back with the node and does not consume its client key.
 */
final class EmergencySubmissionIdempotencyLedger implements EmergencySubmissionIdempotencyLedgerInterface {

  /**
   * Table holding bounded emergency submission replay records.
   */
  public const TABLE = 'markaspot_emergency_submission_idempotency';

  /**
   * Keep retry records long enough for an intermittently connected device.
   */
  public const RETENTION_SECONDS = 30 * 24 * 60 * 60;

  /**
   * Constructs the idempotency ledger.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Finds one committed replay result or rejects conflicting key reuse.
   *
   * The idempotency UUID is globally unique in the ledger. In addition to
   * protecting a root/revision pair, that prevents an offline client from
   * accidentally reusing a stale key after switching tenants or revisions.
   *
   * @return string|null
   *   The persisted node UUID when a matching result exists, otherwise NULL.
   *
   * @throws \InvalidArgumentException
   *   When a caller bypasses header validation with malformed values.
   * @throws \LogicException
   *   When a key is reused for another scope or document.
   * @throws \RuntimeException
   *   When a stored record is malformed.
   */
  public function findReplay(
    int $rootId,
    int $revision,
    string $idempotencyKey,
    string $requestHash,
  ): ?string {
    $this->assertInput($rootId, $revision, $idempotencyKey, $requestHash);
    $record = $this->fetchByKey(strtolower($idempotencyKey));
    if ($record === NULL) {
      return NULL;
    }

    return $this->assertMatchingRecord(
      $record,
      $rootId,
      $revision,
      $requestHash,
    );
  }

  /**
   * Reserves a key inside the current node-save transaction.
   *
   * @return string|null
   *   NULL for this new reservation, or the already persisted node UUID when
   *   a concurrent request committed an identical submission first.
   *
   * @throws \InvalidArgumentException
   *   When the caller supplies an invalid reservation.
   * @throws \LogicException
   *   When a key is reused for another scope or document.
   * @throws \RuntimeException
   *   When a concurrent ledger row cannot safely be read.
   */
  public function reserve(
    int $rootId,
    int $revision,
    string $idempotencyKey,
    string $requestHash,
    string $nodeUuid,
  ): ?string {
    $this->assertInput($rootId, $revision, $idempotencyKey, $requestHash);
    if (!$this->isUuid($nodeUuid)) {
      throw new \InvalidArgumentException('The emergency submission result is invalid.');
    }

    $key = strtolower($idempotencyKey);
    try {
      $this->database->insert(self::TABLE)
        ->fields([
          'idempotency_key' => $key,
          'root_id' => $rootId,
          'revision' => $revision,
          'request_hash' => $requestHash,
          'node_uuid' => strtolower($nodeUuid),
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      return NULL;
    }
    catch (IntegrityConstraintViolationException) {
      // A conflicting transaction has committed before this insert returned.
      // Read its row under the surrounding node-save transaction before
      // deciding whether this is a safe replay or a conflicting reuse.
    }

    $record = $this->fetchByKey($key, TRUE);
    if ($record === NULL) {
      throw new \RuntimeException('The emergency idempotency reservation could not be verified.');
    }

    return $this->assertMatchingRecord(
      $record,
      $rootId,
      $revision,
      $requestHash,
    );
  }

  /**
   * Removes expired records so disconnected retry support cannot grow forever.
   */
  public function purgeExpired(): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('created', $this->time->getRequestTime() - self::RETENTION_SECONDS, '<')
      ->execute();
  }

  /**
   * Reads one ledger row, optionally with the node-save transaction lock.
   *
   * @return array<string, mixed>|null
   *   The raw database record, or NULL when this key is new.
   */
  private function fetchByKey(string $idempotencyKey, bool $forUpdate = FALSE): ?array {
    $query = $this->database->select(self::TABLE, 'i')
      ->fields('i', [
        'idempotency_key',
        'root_id',
        'revision',
        'request_hash',
        'node_uuid',
      ])
      ->condition('idempotency_key', $idempotencyKey);
    if ($forUpdate) {
      $query->forUpdate();
    }
    $record = $query->execute()->fetchAssoc();
    return is_array($record) ? $record : NULL;
  }

  /**
   * Validates a stored row against one exact idempotency request.
   */
  private function assertMatchingRecord(
    array $record,
    int $rootId,
    int $revision,
    string $requestHash,
  ): string {
    $storedRootId = $record['root_id'] ?? NULL;
    $storedRevision = $record['revision'] ?? NULL;
    $storedHash = $record['request_hash'] ?? NULL;
    $nodeUuid = $record['node_uuid'] ?? NULL;
    if (!is_numeric($storedRootId)
      || !is_numeric($storedRevision)
      || !is_string($storedHash)
      || !is_string($nodeUuid)
      || !$this->isUuid($nodeUuid)) {
      throw new \RuntimeException('The emergency idempotency record is invalid.');
    }
    if ((int) $storedRootId !== $rootId
      || (int) $storedRevision !== $revision
      || !hash_equals($storedHash, $requestHash)) {
      throw new \LogicException('The emergency idempotency key conflicts with a different submission.');
    }
    return strtolower($nodeUuid);
  }

  /**
   * Validates input before any storage operation.
   */
  private function assertInput(
    int $rootId,
    int $revision,
    string $idempotencyKey,
    string $requestHash,
  ): void {
    if ($rootId < 0 || $revision <= 0 || !$this->isUuid($idempotencyKey)) {
      throw new \InvalidArgumentException('The emergency idempotency key is invalid.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
      throw new \InvalidArgumentException('The emergency submission fingerprint is invalid.');
    }
  }

  /**
   * Validates a canonical UUID identifier.
   */
  private function isUuid(string $value): bool {
    return (bool) preg_match(
      '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
      $value,
    );
  }

}
