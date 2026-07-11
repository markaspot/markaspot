<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Service;

/**
 * Storage boundary for transactional emergency submission idempotency.
 */
interface EmergencySubmissionIdempotencyLedgerInterface {

  /**
   * Finds a committed matching result or rejects conflicting key reuse.
   */
  public function findReplay(
    int $rootId,
    int $revision,
    string $idempotencyKey,
    string $requestHash,
  ): ?string;

  /**
   * Reserves a key in the surrounding node-save transaction.
   */
  public function reserve(
    int $rootId,
    int $revision,
    string $idempotencyKey,
    string $requestHash,
    string $nodeUuid,
  ): ?string;

  /**
   * Purges records outside the supported offline retry retention window.
   */
  public function purgeExpired(): int;

}
