<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_ai\Service\NodeAnalysisService;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for sentiment analysis API endpoints.
 *
 * Provides REST API endpoints for retrieving sentiment analysis
 * results for service requests.
 */
class SentimentController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * The sentiment service.
   *
   * @var \Drupal\markaspot_ai\Service\SentimentService
   */
  protected SentimentService $sentimentService;

  /**
   * The node analysis service.
   *
   * @var \Drupal\markaspot_ai\Service\NodeAnalysisService
   */
  protected NodeAnalysisService $nodeAnalysisService;

  /**
   * The group membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoaderInterface
   */
  protected GroupMembershipLoaderInterface $membershipLoader;

  /**
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|null
   */
  protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Constructs a SentimentController object.
   *
   * @param \Drupal\markaspot_ai\Service\SentimentService $sentiment_service
   *   The sentiment service.
   * @param \Drupal\markaspot_ai\Service\NodeAnalysisService $node_analysis_service
   *   The node analysis service.
   * @param \Drupal\group\GroupMembershipLoaderInterface $membership_loader
   *   The group membership loader.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|null $hierarchy_resolver
   *   The jurisdiction hierarchy resolver.
   */
  public function __construct(
    SentimentService $sentiment_service,
    NodeAnalysisService $node_analysis_service,
    GroupMembershipLoaderInterface $membership_loader,
    ?JurisdictionHierarchyResolverInterface $hierarchy_resolver = NULL,
  ) {
    $this->sentimentService = $sentiment_service;
    $this->nodeAnalysisService = $node_analysis_service;
    $this->membershipLoader = $membership_loader;
    $this->hierarchyResolver = $hierarchy_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_ai.sentiment'),
      $container->get('markaspot_ai.node_analysis'),
      $container->get('group.membership_loader'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL
    );
  }

  /**
   * Gets sentiment analysis for a service request node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing:
   *   - 'node': Basic info about the node.
   *   - 'sentiment': The sentiment data or null if not analyzed.
   */
  public function getSentiment(NodeInterface $node): JsonResponse {
    // Verify it's a service request.
    if ($node->bundle() !== 'service_request') {
      throw new BadRequestHttpException('Node must be a service_request.');
    }

    if (!$this->isSentimentAnalysisEnabled() || !$this->canAccessNode($node, 'view')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Sentiment data is disabled for this jurisdiction.',
      ], 403);
    }

    $nid = (int) $node->id();
    $sentiment = $this->sentimentService->getSentiment($nid);

    return new JsonResponse([
      'node' => [
        'nid' => $nid,
        'title' => $node->getTitle(),
        'created' => (int) $node->getCreatedTime(),
      ],
      'sentiment' => $sentiment,
      'has_analysis' => $sentiment !== NULL,
    ]);
  }

  /**
   * Triggers sentiment analysis for a specific node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with analysis results.
   */
  public function analyzeNode(NodeInterface $node, Request $request): JsonResponse {
    // Verify it's a service request.
    if ($node->bundle() !== 'service_request') {
      throw new BadRequestHttpException('Node must be a service_request.');
    }

    if (!$this->isSentimentAnalysisEnabled()) {
      return new JsonResponse([
        'success' => FALSE,
        'node' => [
          'nid' => (int) $node->id(),
          'title' => $node->getTitle(),
        ],
        'message' => 'Sentiment analysis is disabled.',
      ], 403);
    }

    if (!$this->canAccessNode($node, 'update')) {
      return new JsonResponse([
        'success' => FALSE,
        'node' => [
          'nid' => (int) $node->id(),
          'title' => $node->getTitle(),
        ],
        'message' => 'AI processing is disabled for this jurisdiction.',
      ], 403);
    }

    // Check for force parameter.
    $content = $request->getContent();
    $data = [];
    if (!empty($content)) {
      $data = json_decode($content, TRUE) ?? [];
    }
    $force = !empty($data['force']);

    $nid = (int) $node->id();

    try {
      $result = $this->nodeAnalysisService->analyzeNode($node, $force);

      if ($result === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'node' => [
            'nid' => $nid,
            'title' => $node->getTitle(),
          ],
          'message' => 'Analysis skipped (already processed or no content). Use force=true to re-analyze.',
        ]);
      }

      return new JsonResponse([
        'success' => TRUE,
        'node' => [
          'nid' => $nid,
          'title' => $node->getTitle(),
        ],
        'sentiment' => $result['sentiment'],
        'hazard' => $result['hazard'],
        'risk_score' => $result['risk_score'],
        'message' => sprintf(
          'Analyzed node %d: sentiment=%s, hazard=%d, risk=%.2f',
          $nid,
          $result['sentiment']['sentiment'],
          $result['hazard']['level'],
          $result['risk_score']
        ),
      ]);

    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'node' => [
          'nid' => $nid,
          'title' => $node->getTitle(),
        ],
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Gets sentiment statistics.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with sentiment statistics.
   */
  public function getStatistics(Request $request): JsonResponse {
    $days = (int) $request->query->get('days', 30);

    // Cap days to prevent abuse.
    $days = min(max($days, 1), 365);

    if (!$this->isSentimentAnalysisEnabled()) {
      return new JsonResponse($this->emptyStatistics($days));
    }

    // Pass jurisdiction filter if provided (supports slugs).
    $jurisdictionId = $this->resolveJurisdictionId($request->query->get('jurisdiction_id'));
    if (!$this->currentUserCanSeeAllJurisdictions() && $jurisdictionId === NULL) {
      return new JsonResponse($this->emptyStatistics($days));
    }
    $nodeIds = $this->getAccessibleAiEnabledNodeIds($jurisdictionId, 'view');
    if (empty($nodeIds)) {
      return new JsonResponse($this->emptyStatistics($days));
    }

    $cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);
    $query = \Drupal::database()->select('markaspot_ai_sentiment', 's')
      ->condition('s.analyzed_at', $cutoff, '>=')
      ->condition('s.entity_id', $nodeIds, 'IN');
    $query->addField('s', 'sentiment');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('s.sentiment');
    $results = $query->execute()->fetchAllKeyed();

    // Calculate percentages.
    $stats = [
      'frustrated' => (int) ($results['frustrated'] ?? 0),
      'neutral' => (int) ($results['neutral'] ?? 0),
      'positive' => (int) ($results['positive'] ?? 0),
    ];
    $total = array_sum($stats);
    $percentages = [];
    if ($total > 0) {
      $percentages = [
        'frustrated' => round(($stats['frustrated'] / $total) * 100, 1),
        'neutral' => round(($stats['neutral'] / $total) * 100, 1),
        'positive' => round(($stats['positive'] / $total) * 100, 1),
      ];
    }

    return new JsonResponse([
      'counts' => [
        'frustrated' => $stats['frustrated'],
        'neutral' => $stats['neutral'],
        'positive' => $stats['positive'],
        'total' => $total,
      ],
      'percentages' => $percentages,
      'period_days' => $days,
    ]);
  }

  /**
   * Gets recent frustrated reports for prioritization.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with frustrated reports.
   */
  public function getFrustrated(Request $request): JsonResponse {
    if (!$this->isSentimentAnalysisEnabled()) {
      return new JsonResponse([
        'reports' => [],
        'count' => 0,
        'period_days' => 0,
      ]);
    }

    $limit = (int) $request->query->get('limit', 20);
    $limit = min(max($limit, 1), 100);

    $days = (int) $request->query->get('days', 7);
    $days = min(max($days, 1), 30);

    $jurisdictionId = $this->resolveJurisdictionId($request->query->get('jurisdiction_id'));
    if (!$this->currentUserCanSeeAllJurisdictions() && $jurisdictionId === NULL) {
      return new JsonResponse([
        'reports' => [],
        'count' => 0,
        'period_days' => $days,
      ]);
    }
    $nodeIds = $this->getAccessibleAiEnabledNodeIds($jurisdictionId, 'view');
    if (empty($nodeIds)) {
      return new JsonResponse([
        'reports' => [],
        'count' => 0,
        'period_days' => $days,
      ]);
    }

    $cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);

    // Query for frustrated reports.
    $query = \Drupal::database()->select('markaspot_ai_sentiment', 's')
      ->fields('s', ['entity_id', 'sentiment', 'score', 'confidence', 'reasoning', 'analyzed_at'])
      ->condition('s.sentiment', SentimentService::SENTIMENT_FRUSTRATED)
      ->condition('s.analyzed_at', $cutoff, '>=')
      ->condition('s.entity_id', $nodeIds, 'IN')
      ->orderBy('s.score', 'ASC')
      ->range(0, $limit);

    $results = $query->execute()->fetchAll();

    // Enrich with node data.
    $nodeStorage = $this->entityTypeManager()->getStorage('node');
    $reports = [];

    foreach ($results as $row) {
      $node = $nodeStorage->load($row->entity_id);
      if (!$node) {
        continue;
      }
      if (!$this->canAccessNode($node, 'view')) {
        continue;
      }

      $report = [
        'nid' => (int) $row->entity_id,
        'title' => $node->getTitle(),
        'score' => (float) $row->score,
        'confidence' => (float) $row->confidence,
        'reasoning' => $row->reasoning,
        'analyzed_at' => (int) $row->analyzed_at,
        'node_created' => (int) $node->getCreatedTime(),
        'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ];

      // Add status if available.
      if ($node->hasField('field_status') && !$node->get('field_status')->isEmpty()) {
        $status = $node->get('field_status')->entity;
        if ($status) {
          $report['request_status'] = [
            'id' => (int) $status->id(),
            'name' => $status->label(),
          ];
        }
      }

      $reports[] = $report;
    }

    return new JsonResponse([
      'reports' => $reports,
      'count' => count($reports),
      'period_days' => $days,
    ]);
  }

  /**
   * Checks the global sentiment analysis task toggle.
   */
  protected function isSentimentAnalysisEnabled(): bool {
    return (bool) $this->config('markaspot_ai.settings')
      ->get('sentiment_analysis.enabled');
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
   * Checks tenant AI processing for a jurisdiction ID.
   */
  protected function isJurisdictionAiProcessingEnabled(int $jurisdiction_id): bool {
    $group = $this->entityTypeManager()
      ->getStorage('group')
      ->load($jurisdiction_id);

    return _markaspot_ai_is_feature_enabled_for_jurisdiction($group, 'aiProcessing');
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
      $node_ids = $this->entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'service_request')
        ->execute();
    }

    if (empty($node_ids)) {
      return [];
    }

    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($node_ids);
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
   * Builds an empty sentiment statistics response.
   */
  protected function emptyStatistics(int $days): array {
    return [
      'counts' => [
        'frustrated' => 0,
        'neutral' => 0,
        'positive' => 0,
        'total' => 0,
      ],
      'percentages' => [],
      'period_days' => $days,
    ];
  }

}
