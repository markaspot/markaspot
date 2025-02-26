<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for AI processing management from dashboard.
 */
class ProcessingController extends ControllerBase {

  /**
   * Constructs a ProcessingController object.
   */
  public function __construct(
    protected Connection $database,
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $queueWorkerManager,
    protected EmbeddingService $embeddingService,
    protected RequestStack $requestStackService,
    protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('markaspot_ai.embedding'),
      $container->get('request_stack'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL
    );
  }

  /**
   * Get AI processing status.
   *
   * Supports optional ?jurisdiction_id=N to scope counts to a jurisdiction.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status information.
   */
  public function getStatus(): JsonResponse {
    $request = $this->requestStackService->getCurrentRequest();
    $jurisdiction_id = $request?->query->get('jurisdiction_id');
    $node_ids = $jurisdiction_id ? $this->getNodeIdsForJurisdiction((int) $jurisdiction_id) : NULL;

    if ($node_ids !== NULL && empty($node_ids)) {
      // Jurisdiction specified but no nodes in it.
      return new JsonResponse($this->buildEmptyStatus());
    }

    // Count total service requests (including unpublished, as AI processes all).
    $totalQuery = $this->database->select('node_field_data', 'n')
      ->condition('n.type', 'service_request');
    if ($node_ids !== NULL) {
      $totalQuery->condition('n.nid', $node_ids, 'IN');
    }
    $total = (int) $totalQuery->countQuery()->execute()->fetchField();

    // Count embeddings (joined to verify nodes still exist).
    $embeddingQuery = $this->database->select('markaspot_ai_embeddings', 'e');
    $embeddingQuery->condition('e.entity_type', 'node');
    $embeddingQuery->join('node_field_data', 'n', 'e.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
    if ($node_ids !== NULL) {
      $embeddingQuery->condition('n.nid', $node_ids, 'IN');
    }
    $embeddings = (int) $embeddingQuery
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count sentiment analyzed (joined to verify nodes still exist).
    $sentimentQuery = $this->database->select('markaspot_ai_sentiment', 's');
    $sentimentQuery->join('node_field_data', 'n', 's.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
    if ($node_ids !== NULL) {
      $sentimentQuery->condition('n.nid', $node_ids, 'IN');
    }
    $sentiment = (int) $sentimentQuery
      ->countQuery()
      ->execute()
      ->fetchField();

    // Sentiment breakdown.
    $sentimentCounts = [];
    foreach (['frustrated', 'neutral', 'positive'] as $type) {
      $breakdownQuery = $this->database->select('markaspot_ai_sentiment', 's');
      $breakdownQuery->join('node_field_data', 'n', 's.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
      $breakdownQuery->condition('s.sentiment', $type);
      if ($node_ids !== NULL) {
        $breakdownQuery->condition('n.nid', $node_ids, 'IN');
      }
      $sentimentCounts[$type] = (int) $breakdownQuery
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    // Queue status.
    $embeddingQueue = $this->queueFactory->get('markaspot_ai_embedding');
    $duplicateQueue = $this->queueFactory->get('markaspot_ai_duplicate_scan');

    return new JsonResponse([
      'total_requests' => $total,
      'embeddings' => [
        'count' => $embeddings,
        'percentage' => $total > 0 ? round(($embeddings / $total) * 100) : 0,
        'missing' => $total - $embeddings,
      ],
      'sentiment' => [
        'count' => $sentiment,
        'percentage' => $total > 0 ? round(($sentiment / $total) * 100) : 0,
        'breakdown' => $sentimentCounts,
      ],
      'queues' => [
        'embedding' => $embeddingQueue->numberOfItems(),
        'duplicate_scan' => $duplicateQueue->numberOfItems(),
      ],
    ]);
  }

  /**
   * Queue missing items for processing.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with queue result.
   */
  public function queueMissing(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE) ?? [];
    // Validate and clamp limit to reasonable bounds.
    $limit = min(1000, max(1, (int) ($content['limit'] ?? 100)));

    // Optional jurisdiction scoping.
    $jurisdiction_id = $content['jurisdiction_id']
      ?? $request->query->get('jurisdiction_id');
    $jurisdiction_node_ids = $jurisdiction_id
      ? $this->getNodeIdsForJurisdiction((int) $jurisdiction_id)
      : NULL;

    try {
      // Find nodes without embeddings.
      $missing = $this->embeddingService->findMissingEmbeddings(
        $limit,
        'node',
        'service_request',
        'content'
      );

      // Post-filter by jurisdiction if specified.
      if ($jurisdiction_node_ids !== NULL) {
        $missing = array_values(array_intersect($missing, $jurisdiction_node_ids));
      }

      if (empty($missing)) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'No missing items to queue.',
          'queued' => 0,
        ]);
      }

      // Queue them.
      $queue = $this->queueFactory->get('markaspot_ai_embedding');
      $count = 0;

      foreach ($missing as $nid) {
        $queue->createItem([
          'nid' => $nid,
          'is_new' => FALSE,
        ]);
        $count++;
      }

      $this->getLogger('markaspot_ai')->notice('Queued @count items for AI processing via dashboard.', [
        '@count' => $count,
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'message' => "Queued {$count} items for processing.",
        'queued' => $count,
      ]);

    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_ai')->error('Failed to queue items: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to queue items. Check logs for details.',
      ], 500);
    }
  }

  /**
   * Process the AI queues.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with processing result.
   */
  public function processQueue(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE) ?? [];
    // Validate and clamp limits to reasonable bounds.
    $limit = min(100, max(1, (int) ($content['limit'] ?? 10)));
    $timeLimit = min(300, max(5, (int) ($content['time_limit'] ?? 30)));

    $queues = [
      'markaspot_ai_embedding',
      'markaspot_ai_duplicate_scan',
    ];

    $totalProcessed = 0;
    $totalErrors = 0;
    $startTime = time();
    $details = [];

    try {
      foreach ($queues as $queueName) {
        $queue = $this->queueFactory->get($queueName);
        $queueCount = $queue->numberOfItems();

        if ($queueCount === 0) {
          continue;
        }

        try {
          $worker = $this->queueWorkerManager->createInstance($queueName);
        }
        catch (\Exception $e) {
          continue;
        }

        $processed = 0;
        $errors = 0;

        while ($processed < $limit && (time() - $startTime) < $timeLimit) {
          $item = $queue->claimItem(60);

          if (!$item) {
            break;
          }

          try {
            $worker->processItem($item->data);
            $queue->deleteItem($item);
            $processed++;
          }
          catch (\Exception $e) {
            $this->getLogger('markaspot_ai')->error('Queue item processing failed: @message', [
              '@message' => $e->getMessage(),
            ]);
            $queue->releaseItem($item);
            $errors++;

            if ($errors >= 3) {
              break;
            }
          }
        }

        $remaining = $queue->numberOfItems();
        $details[$queueName] = [
          'processed' => $processed,
          'errors' => $errors,
          'remaining' => $remaining,
        ];
        $totalProcessed += $processed;
        $totalErrors += $errors;
      }

      $this->getLogger('markaspot_ai')->notice('Processed @count items via dashboard.', [
        '@count' => $totalProcessed,
      ]);

      $embeddingRemaining = $this->queueFactory->get('markaspot_ai_embedding')->numberOfItems();
      $duplicateRemaining = $this->queueFactory->get('markaspot_ai_duplicate_scan')->numberOfItems();
      $totalRemaining = $embeddingRemaining + $duplicateRemaining;

      return new JsonResponse([
        'success' => TRUE,
        'processed' => $totalProcessed,
        'errors' => $totalErrors,
        'remaining' => $totalRemaining,
        'details' => $details,
        'message' => "Processed {$totalProcessed} items" . ($totalRemaining > 0 ? ", {$totalRemaining} remaining." : "."),
      ]);

    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_ai')->error('Queue processing failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Queue processing failed. Check logs for details.',
      ], 500);
    }
  }

  /**
   * Gets node IDs for a jurisdiction, including child jurisdictions.
   *
   * Uses the hierarchy resolver when available to include nodes from
   * descendant jurisdictions. Falls back to flat single-group query.
   *
   * @param int $group_id
   *   The jurisdiction group ID.
   *
   * @return array<int>
   *   Array of node IDs belonging to the jurisdiction subtree.
   */
  protected function getNodeIdsForJurisdiction(int $group_id): array {
    if (!$this->moduleHandler()->moduleExists('group')) {
      return [];
    }

    // Validate group exists and is a jurisdiction type.
    // Prevents cross-tenant leakage by rejecting org/other group types.
    $group = $this->entityTypeManager()->getStorage('group')->load($group_id);
    if (!$group || $group->bundle() !== 'jur') {
      return [];
    }

    if ($this->hierarchyResolver) {
      return $this->hierarchyResolver->getNodeIdsInJurisdiction($group_id);
    }

    // Fallback: flat single-group query when hierarchy resolver unavailable.
    $relationship_storage = $this->entityTypeManager()->getStorage('group_relationship');
    $relationship_ids = $relationship_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group_id)
      ->condition('plugin_id', 'group_node:service_request')
      ->execute();

    if (empty($relationship_ids)) {
      return [];
    }

    $relationships = $relationship_storage->loadMultiple($relationship_ids);
    $node_ids = [];

    foreach ($relationships as $relationship) {
      $node_ids[] = (int) $relationship->get('entity_id')->target_id;
    }

    return array_values(array_unique($node_ids));
  }

  /**
   * Builds an empty status response structure.
   *
   * @return array
   *   Empty status data.
   */
  protected function buildEmptyStatus(): array {
    return [
      'total_requests' => 0,
      'embeddings' => [
        'count' => 0,
        'percentage' => 0,
        'missing' => 0,
      ],
      'sentiment' => [
        'count' => 0,
        'percentage' => 0,
        'breakdown' => [
          'frustrated' => 0,
          'neutral' => 0,
          'positive' => 0,
        ],
      ],
      'queues' => [
        'embedding' => 0,
        'duplicate_scan' => 0,
      ],
    ];
  }

}
