<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for sentiment analysis API endpoints.
 *
 * Provides REST API endpoints for retrieving sentiment analysis
 * results for service requests.
 */
class SentimentController extends ControllerBase {

  /**
   * The sentiment service.
   *
   * @var \Drupal\markaspot_ai\Service\SentimentService
   */
  protected SentimentService $sentimentService;

  /**
   * Constructs a SentimentController object.
   *
   * @param \Drupal\markaspot_ai\Service\SentimentService $sentiment_service
   *   The sentiment service.
   */
  public function __construct(SentimentService $sentiment_service) {
    $this->sentimentService = $sentiment_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_ai.sentiment')
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

    // Check for force parameter.
    $content = $request->getContent();
    $data = [];
    if (!empty($content)) {
      $data = json_decode($content, TRUE) ?? [];
    }
    $force = !empty($data['force']);

    $nid = (int) $node->id();

    try {
      $result = $this->sentimentService->analyzeNode($node, $force);

      if ($result === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'node' => [
            'nid' => $nid,
            'title' => $node->getTitle(),
          ],
          'message' => 'Failed to analyze sentiment. Check logs for details.',
        ], 500);
      }

      return new JsonResponse([
        'success' => TRUE,
        'node' => [
          'nid' => $nid,
          'title' => $node->getTitle(),
        ],
        'sentiment' => $result,
        'message' => sprintf(
          'Analyzed sentiment for node %d: %s (score: %.2f)',
          $nid,
          $result['sentiment'],
          $result['score']
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

    $stats = $this->sentimentService->getStatistics(['days' => $days]);

    // Calculate percentages.
    $total = $stats['total'];
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
      'period_days' => $stats['period_days'],
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
    $limit = (int) $request->query->get('limit', 20);
    $limit = min(max($limit, 1), 100);

    $days = (int) $request->query->get('days', 7);
    $days = min(max($days, 1), 30);

    $cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);

    // Query for frustrated reports.
    $query = \Drupal::database()->select('markaspot_ai_sentiment', 's')
      ->fields('s', ['entity_id', 'sentiment', 'score', 'confidence', 'reasoning', 'analyzed_at'])
      ->condition('s.sentiment', SentimentService::SENTIMENT_FRUSTRATED)
      ->condition('s.analyzed_at', $cutoff, '>=')
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

}
