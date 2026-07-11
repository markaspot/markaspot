<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Builds a small JSON:API response for a previously accepted Lite post.
 */
final class EmergencySubmissionReplayResponder implements EmergencySubmissionReplayResponderInterface {

  /**
   * Constructs the replay responder.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a no-store JSON:API document for one prior service request.
   *
   * The idempotency key itself is the capability for this replay. We do not
   * require a subsequent anonymous entity view grant, because a report may be
   * intentionally unpublished immediately after it was accepted.
   */
  public function buildResponse(Request $request, string $nodeUuid): JsonResponse {
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'uuid' => $nodeUuid,
      'type' => 'service_request',
    ]);
    $node = reset($nodes);
    if (!$node instanceof NodeInterface || $node->isNew()) {
      throw new ConflictHttpException('The prior emergency submission is no longer available for replay.');
    }

    $response = new JsonResponse([
      'jsonapi' => ['version' => '1.0'],
      'links' => [
        'self' => ['href' => $request->getUri()],
      ],
      'data' => [
        'type' => 'node--service_request',
        'id' => strtolower($node->uuid()),
        'attributes' => [
          'drupal_internal__nid' => (int) $node->id(),
        ],
      ],
      'meta' => [
        'markaspot_idempotent_replay' => TRUE,
      ],
    ], 200, [
      'Cache-Control' => 'no-store',
      'X-Markaspot-Emergency-Idempotent-Replay' => 'true',
    ]);
    $response->headers->set('Content-Type', 'application/vnd.api+json');
    return $response;
  }

}
