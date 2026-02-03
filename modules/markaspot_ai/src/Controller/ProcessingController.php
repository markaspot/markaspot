<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('markaspot_ai.embedding')
    );
  }

  /**
   * Get AI processing status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status information.
   */
  public function getStatus(): JsonResponse {
    // Count total service requests.
    $total = (int) $this->database->select('node_field_data', 'n')
      ->condition('n.type', 'service_request')
      ->condition('n.status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count embeddings.
    $embeddings = (int) $this->database->select('markaspot_ai_embeddings', 'e')
      ->condition('e.entity_type', 'node')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count sentiment analyzed.
    $sentiment = (int) $this->database->select('markaspot_ai_sentiment', 's')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Sentiment breakdown.
    $sentimentCounts = [];
    foreach (['frustrated', 'neutral', 'positive'] as $type) {
      $sentimentCounts[$type] = (int) $this->database->select('markaspot_ai_sentiment', 's')
        ->condition('s.sentiment', $type)
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

    try {
      // Find nodes without embeddings.
      $missing = $this->embeddingService->findMissingEmbeddings(
        $limit,
        'node',
        'service_request',
        'content'
      );

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

}
