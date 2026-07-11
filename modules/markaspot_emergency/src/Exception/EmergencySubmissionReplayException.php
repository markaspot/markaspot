<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Exception;

/**
 * Stops a duplicate JSON:API create after resolving its prior result.
 *
 * The exception deliberately aborts the second entity save. Its ledger row
 * belongs to the first save transaction, so the request subscriber can turn
 * the wrapped storage exception into a successful replay response.
 */
final class EmergencySubmissionReplayException extends \RuntimeException {

  /**
   * Constructs a replay signal for one persisted service request UUID.
   */
  public function __construct(
    private readonly string $nodeUuid,
  ) {
    parent::__construct('The emergency submission was already accepted.');
  }

  /**
   * Returns the persisted service request UUID to replay.
   */
  public function getNodeUuid(): string {
    return $this->nodeUuid;
  }

}
