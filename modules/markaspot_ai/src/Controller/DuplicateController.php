<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for duplicate detection API endpoints.
 *
 * Provides REST API endpoints for retrieving and reviewing
 * potential duplicate service requests.
 */
class DuplicateController extends ControllerBase {

  /**
   * The duplicate detection service.
   *
   * @var \Drupal\markaspot_ai\Service\DuplicateDetectionService
   */
  protected DuplicateDetectionService $duplicateDetectionService;

  /**
   * Constructs a DuplicateController object.
   *
   * @param \Drupal\markaspot_ai\Service\DuplicateDetectionService $duplicate_detection_service
   *   The duplicate detection service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    DuplicateDetectionService $duplicate_detection_service,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->duplicateDetectionService = $duplicate_detection_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_ai.duplicate_detection'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Gets duplicate matches for a service request node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing:
   *   - 'node': Basic info about the source node.
   *   - 'duplicates': Array of duplicate matches.
   *   - 'count': Total number of matches.
   */
  public function getDuplicates(NodeInterface $node): JsonResponse {
    // Verify it's a service request.
    if ($node->bundle() !== 'service_request') {
      throw new BadRequestHttpException('Node must be a service_request.');
    }

    // Get stored matches from database.
    $matches = $this->duplicateDetectionService->getDuplicateMatches(
      (int) $node->id()
    );

    // Enrich match data with node information.
    $duplicates = [];
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($matches as $match) {
      $otherNid = $match['other_nid'];
      $otherNode = $nodeStorage->load($otherNid);

      if (!$otherNode) {
        continue;
      }

      $duplicate = [
        'match_id' => (int) $match['id'],
        'nid' => $otherNid,
        'title' => $otherNode->getTitle(),
        'similarity_score' => (float) $match['similarity_score'],
        'distance_meters' => $match['distance_meters'] ? (float) $match['distance_meters'] : NULL,
        'status' => $match['status'],
        'created' => (int) $match['created'],
        'node_created' => (int) $otherNode->getCreatedTime(),
        'url' => $otherNode->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ];

      // Add address if available.
      if ($otherNode->hasField('field_address') && !$otherNode->get('field_address')->isEmpty()) {
        $duplicate['address'] = $otherNode->get('field_address')->value;
      }

      // Add geolocation if available.
      if ($otherNode->hasField('field_geolocation') && !$otherNode->get('field_geolocation')->isEmpty()) {
        $duplicate['location'] = [
          'lat' => (float) $otherNode->get('field_geolocation')->lat,
          'lng' => (float) $otherNode->get('field_geolocation')->lng,
        ];
      }

      // Add category if available.
      if ($otherNode->hasField('field_category') && !$otherNode->get('field_category')->isEmpty()) {
        $category = $otherNode->get('field_category')->entity;
        if ($category) {
          $duplicate['category'] = [
            'id' => (int) $category->id(),
            'name' => $category->label(),
          ];
        }
      }

      // Add status if available.
      if ($otherNode->hasField('field_status') && !$otherNode->get('field_status')->isEmpty()) {
        $status = $otherNode->get('field_status')->entity;
        if ($status) {
          $duplicate['request_status'] = [
            'id' => (int) $status->id(),
            'name' => $status->label(),
          ];
        }
      }

      // Add review info if reviewed.
      if (!empty($match['reviewed_by'])) {
        $reviewer = $this->entityTypeManager->getStorage('user')
          ->load($match['reviewed_by']);
        $duplicate['reviewed'] = [
          'by' => $reviewer ? $reviewer->getDisplayName() : 'Unknown',
          'at' => (int) $match['reviewed_at'],
        ];
      }

      $duplicates[] = $duplicate;
    }

    return new JsonResponse([
      'node' => [
        'nid' => (int) $node->id(),
        'title' => $node->getTitle(),
        'created' => (int) $node->getCreatedTime(),
      ],
      'duplicates' => $duplicates,
      'count' => count($duplicates),
    ]);
  }

  /**
   * Reviews (confirms or rejects) a duplicate match.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param int $match
   *   The match record ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success or failure.
   */
  public function reviewMatch(Request $request, int $match): JsonResponse {
    // Get the match record first.
    $matchRecord = $this->duplicateDetectionService->getMatch($match);

    if (!$matchRecord) {
      throw new NotFoundHttpException('Match not found.');
    }

    // Parse request body.
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new BadRequestHttpException('Invalid JSON in request body.');
    }

    // Validate status with type checking.
    $status = $data['status'] ?? NULL;
    if (!is_string($status) || !in_array($status, ['confirmed', 'rejected'], TRUE)) {
      throw new BadRequestHttpException('Status must be "confirmed" or "rejected".');
    }

    // Get current user (permission already checked by route access).
    $currentUser = $this->currentUser();

    // Perform the review.
    $success = $this->duplicateDetectionService->reviewMatch(
      $match,
      $status,
      (int) $currentUser->id()
    );

    if (!$success) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to update match status.',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'match_id' => $match,
      'status' => $status,
      'reviewed_by' => $currentUser->getDisplayName(),
      'message' => sprintf('Match %s as %s.', $match, $status),
    ]);
  }

  /**
   * Gets pending duplicate matches for review.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with pending matches.
   */
  public function getPendingMatches(Request $request): JsonResponse {
    $limit = (int) $request->query->get('limit', 50);
    $offset = (int) $request->query->get('offset', 0);

    // Cap limit to prevent abuse.
    $limit = min($limit, 100);

    $matches = $this->duplicateDetectionService->getPendingMatches($limit, $offset);
    $counts = $this->duplicateDetectionService->getMatchCounts();

    return new JsonResponse([
      'matches' => $matches,
      'count' => count($matches),
      'offset' => $offset,
      'limit' => $limit,
      'total_counts' => $counts,
    ]);
  }

  /**
   * Triggers a duplicate scan for a specific node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with scan results.
   */
  public function scanNode(NodeInterface $node): JsonResponse {
    // Verify it's a service request.
    if ($node->bundle() !== 'service_request') {
      throw new BadRequestHttpException('Node must be a service_request.');
    }

    // Perform live scan (not via queue).
    $duplicates = $this->duplicateDetectionService->findDuplicates($node);

    // Store matches.
    foreach ($duplicates as $match) {
      $this->duplicateDetectionService->storeDuplicateMatch(
        (int) $node->id(),
        $match['nid'],
        $match['similarity'],
        $match['distance_meters']
      );
    }

    return new JsonResponse([
      'node' => [
        'nid' => (int) $node->id(),
        'title' => $node->getTitle(),
      ],
      'duplicates_found' => count($duplicates),
      'matches' => $duplicates,
      'message' => sprintf('Scanned node %d. Found %d potential duplicates.', $node->id(), count($duplicates)),
    ]);
  }

}
