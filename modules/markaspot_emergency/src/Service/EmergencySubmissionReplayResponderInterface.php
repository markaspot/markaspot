<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the successful JSON:API response for an idempotent replay.
 */
interface EmergencySubmissionReplayResponderInterface {

  /**
   * Returns the prior successful result for a matching idempotency key.
   */
  public function buildResponse(Request $request, string $nodeUuid): JsonResponse;

}
