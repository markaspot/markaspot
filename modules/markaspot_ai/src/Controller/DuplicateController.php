<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for duplicate detection API endpoints.
 *
 * Provides REST API endpoints for retrieving and reviewing
 * potential duplicate service requests.
 */
class DuplicateController extends ControllerBase {

  use JurisdictionIdResolverTrait;

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
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\group\GroupMembershipLoaderInterface $membershipLoader
   *   The group membership loader.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|null $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   */
  public function __construct(
    DuplicateDetectionService $duplicate_detection_service,
    EntityTypeManagerInterface $entity_type_manager,
    protected Connection $database,
    protected GroupMembershipLoaderInterface $membershipLoader,
    protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
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
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('group.membership_loader'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL
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

    if (!$this->isDuplicateDetectionEnabled() || !$this->canAccessNode($node, 'view')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Duplicate data is disabled for this jurisdiction.',
      ], 403);
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
      if (!$this->canAccessNode($otherNode, 'view')) {
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

    if (!$this->isDuplicateDetectionEnabled() || !$this->canAccessMatchNodes($matchRecord, 'update')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
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

    if (!$this->isDuplicateDetectionEnabled()) {
      return new JsonResponse([
        'matches' => [],
        'count' => 0,
        'offset' => $offset,
        'limit' => $limit,
        'total_counts' => $this->emptyMatchCounts(),
        'jurisdiction_id' => NULL,
      ]);
    }

    // Jurisdiction filter: admins see all, others are scoped.
    $jurisdictionId = $this->resolveJurisdictionFilter($request);
    $nodeIds = $this->getAccessibleAiEnabledNodeIds($jurisdictionId, 'view');
    if (empty($nodeIds)) {
      return new JsonResponse([
        'matches' => [],
        'count' => 0,
        'offset' => $offset,
        'limit' => $limit,
        'total_counts' => $this->emptyMatchCounts(),
        'jurisdiction_id' => $jurisdictionId,
      ]);
    }

    $counts = $this->getDuplicateMatchCounts($nodeIds);
    $matches = $this->getPendingDuplicateMatches($nodeIds, $limit, $offset);

    return new JsonResponse([
      'matches' => $matches,
      'count' => count($matches),
      'offset' => $offset,
      'limit' => $limit,
      'total_counts' => $counts,
      'jurisdiction_id' => $jurisdictionId,
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

    if (!$this->isDuplicateDetectionEnabled()) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Duplicate detection is disabled.',
      ], 403);
    }

    if (!$this->canAccessNode($node, 'update')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'AI processing is disabled for this jurisdiction.',
      ], 403);
    }

    // Resolve jurisdiction for scoped scanning.
    $jurisdictionId = _markaspot_ai_get_jurisdiction_id_for_node($node);
    $options = [];
    if ($jurisdictionId !== NULL) {
      $options['jurisdiction_id'] = $jurisdictionId;
    }

    // Perform live scan (not via queue), scoped to jurisdiction.
    $duplicates = $this->duplicateDetectionService->findDuplicates($node, $options);
    $duplicates = array_values(array_filter(
      $duplicates,
      fn(array $match): bool => $this->canAccessNodeId((int) $match['nid'], 'view')
    ));

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

  /**
   * Resolves jurisdiction filter from request.
   *
   * Admins (uid=1 or 'administer nodes') see all duplicates.
   * Other users are filtered by the jurisdiction_id query parameter.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return int|null
   *   The jurisdiction ID to filter by, or NULL for all (admin).
   */
  protected function resolveJurisdictionFilter(Request $request): ?int {
    // Supports both numeric IDs and slugs (e.g. "amsterdam").
    $resolved = $this->resolveJurisdictionId($request->query->get('jurisdiction_id'));

    // Admins can see all by omitting the parameter, or filter by choice.
    $currentUser = $this->currentUser();
    if ($this->currentUserCanSeeAllJurisdictions()) {
      return $resolved;
    }

    if ($resolved === NULL) {
      return -1;
    }

    return $this->currentUserCanAccessJurisdiction($resolved) ? $resolved : -1;
  }

  /**
   * Checks the global duplicate detection task toggle.
   */
  protected function isDuplicateDetectionEnabled(): bool {
    return (bool) $this->config('markaspot_ai.settings')
      ->get('duplicate_detection.enabled');
  }

  /**
   * Checks if the current user can see all jurisdictions.
   */
  protected function currentUserCanSeeAllJurisdictions(): bool {
    $currentUser = $this->currentUser();
    return (int) $currentUser->id() === 1
      || $currentUser->hasPermission('administer nodes');
  }

  /**
   * Checks access and tenant opt-in for both nodes in a duplicate match.
   */
  protected function canAccessMatchNodes(array $match, string $operation): bool {
    return $this->canAccessNodeId((int) $match['source_nid'], $operation)
      && $this->canAccessNodeId((int) $match['match_nid'], $operation);
  }

  /**
   * Checks access and tenant opt-in for a service request node ID.
   */
  protected function canAccessNodeId(int $nid, string $operation): bool {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== 'service_request') {
      return FALSE;
    }

    return $this->canAccessNode($node, $operation);
  }

  /**
   * Checks node access, tenant membership and tenant AI opt-in.
   */
  protected function canAccessNode(NodeInterface $node, string $operation): bool {
    if (!$node->access($operation)) {
      return FALSE;
    }

    $jurisdiction_id = _markaspot_ai_get_jurisdiction_id_for_node($node);
    return $this->currentUserCanAccessJurisdiction($jurisdiction_id)
      && _markaspot_ai_is_ai_enabled_for_node($node);
  }

  /**
   * Checks if the current user administers the requested jurisdiction scope.
   */
  protected function currentUserCanAccessJurisdiction(?int $jurisdiction_id): bool {
    if ($jurisdiction_id === NULL) {
      return FALSE;
    }
    if ($this->currentUserCanSeeAllJurisdictions()) {
      return TRUE;
    }

    $account = $this->currentUser();
    if (!in_array('tenant_admin', $account->getRoles(), TRUE)) {
      return FALSE;
    }

    $memberships = $this->membershipLoader->loadByUser($account, array_values(array_unique([
      $this->getJurisdictionGroupType() . '-tenant_admin',
      'jur-tenant_admin',
    ])));
    foreach ($memberships as $membership) {
      $managed_group = $membership->getGroup();
      if (!$this->isJurisdictionGroup($managed_group)) {
        continue;
      }
      $managed_id = (int) $managed_group->id();
      $scope_ids = $this->hierarchyResolver
        ? $this->hierarchyResolver->getDescendantIds($managed_id)
        : [$managed_id];
      if (in_array($jurisdiction_id, $scope_ids, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets service request IDs accessible to the current user and AI-enabled.
   */
  protected function getAccessibleAiEnabledNodeIds(?int $jurisdiction_id, string $operation): array {
    if ($jurisdiction_id !== NULL && !$this->currentUserCanAccessJurisdiction($jurisdiction_id)) {
      return [];
    }

    if ($jurisdiction_id !== NULL && $this->hierarchyResolver) {
      $node_ids = $this->hierarchyResolver->getNodeIdsInJurisdiction($jurisdiction_id);
    }
    else {
      if (!$this->currentUserCanSeeAllJurisdictions()) {
        return [];
      }
      $node_ids = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'service_request')
        ->execute();
    }

    if (empty($node_ids)) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
    $enabled = [];
    foreach ($nodes as $node) {
      if ($node->bundle() !== 'service_request') {
        continue;
      }
      if ($this->canAccessNode($node, $operation)) {
        $enabled[] = (int) $node->id();
      }
    }

    return $enabled;
  }

  /**
   * Gets duplicate match counts for an already scoped node set.
   */
  protected function getDuplicateMatchCounts(array $node_ids): array {
    if (empty($node_ids)) {
      return $this->emptyMatchCounts();
    }

    $query = $this->database->select('markaspot_ai_duplicate_matches', 'd')
      ->fields('d', ['status'])
      ->condition('d.source_nid', $node_ids, 'IN')
      ->condition('d.match_nid', $node_ids, 'IN');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('d.status');
    $results = $query->execute()->fetchAllKeyed();

    return [
      'pending' => (int) ($results['pending'] ?? 0),
      'confirmed' => (int) ($results['confirmed'] ?? 0),
      'rejected' => (int) ($results['rejected'] ?? 0),
      'total' => array_sum(array_map('intval', $results)),
    ];
  }

  /**
   * Gets pending duplicate matches for an already scoped node set.
   */
  protected function getPendingDuplicateMatches(array $node_ids, int $limit, int $offset): array {
    if (empty($node_ids)) {
      return [];
    }

    $query = $this->database->select('markaspot_ai_duplicate_matches', 'd')
      ->fields('d')
      ->condition('d.status', 'pending')
      ->condition('d.source_nid', $node_ids, 'IN')
      ->condition('d.match_nid', $node_ids, 'IN')
      ->orderBy('d.similarity_score', 'DESC')
      ->orderBy('d.created', 'DESC')
      ->range($offset, $limit);
    $query->leftJoin('node_field_data', 'ns', 'd.source_nid = ns.nid');
    $query->leftJoin('node_field_data', 'nm', 'd.match_nid = nm.nid');
    $query->addField('ns', 'title', 'source_title');
    $query->addField('nm', 'title', 'match_title');

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Builds duplicate count totals from already access-filtered matches.
   */
  protected function countsFromMatches(array $matches): array {
    $counts = $this->emptyMatchCounts();
    foreach ($matches as $match) {
      $status = (string) ($match['status'] ?? '');
      if (isset($counts[$status])) {
        $counts[$status]++;
        $counts['total']++;
      }
    }
    return $counts;
  }

  /**
   * Returns the empty duplicate count shape.
   */
  protected function emptyMatchCounts(): array {
    return [
      'pending' => 0,
      'confirmed' => 0,
      'rejected' => 0,
      'total' => 0,
    ];
  }

}
