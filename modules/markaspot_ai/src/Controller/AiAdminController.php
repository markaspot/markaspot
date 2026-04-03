<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Url;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Admin controller for AI processing status and queue management.
 */
class AiAdminController extends ControllerBase {

  /**
   * Constructs an AiAdminController object.
   *
   * @param \Drupal\markaspot_ai\Service\DuplicateDetectionService $duplicateDetection
   *   The duplicate detection service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queueWorkerManager
   *   The queue worker manager.
   * @param \Drupal\markaspot_ai\Service\EmbeddingService $embeddingService
   *   The embedding service.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrfToken
   *   The CSRF token generator.
   */
  public function __construct(
    protected DuplicateDetectionService $duplicateDetection,
    protected Connection $database,
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $queueWorkerManager,
    protected EmbeddingService $embeddingService,
    protected CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_ai.duplicate_detection'),
      $container->get('database'),
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('markaspot_ai.embedding'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Displays the AI processing status overview page.
   *
   * @return array
   *   A render array with processing status summary cards.
   */
  public function processingStatus(): array {
    // Count total service requests.
    $total = (int) $this->database->select('node_field_data', 'n')
      ->condition('n.type', 'service_request')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count embeddings (verified against existing nodes).
    $embeddingQuery = $this->database->select('markaspot_ai_embeddings', 'e');
    $embeddingQuery->condition('e.entity_type', 'node');
    $embeddingQuery->join('node_field_data', 'n', 'e.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
    $embeddings = (int) $embeddingQuery->countQuery()->execute()->fetchField();

    // Count sentiment records (verified against existing nodes).
    $sentimentQuery = $this->database->select('markaspot_ai_sentiment', 's');
    $sentimentQuery->join('node_field_data', 'n', 's.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
    $sentimentTotal = (int) $sentimentQuery->countQuery()->execute()->fetchField();

    // Sentiment breakdown.
    $sentimentCounts = [];
    foreach (['frustrated', 'neutral', 'positive'] as $type) {
      $breakdownQuery = $this->database->select('markaspot_ai_sentiment', 's');
      $breakdownQuery->join('node_field_data', 'n', 's.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
      $breakdownQuery->condition('s.sentiment', $type);
      $sentimentCounts[$type] = (int) $breakdownQuery->countQuery()->execute()->fetchField();
    }

    // Queue sizes.
    $embeddingQueueSize = $this->queueFactory->get('markaspot_ai_embedding')->numberOfItems();
    $duplicateQueueSize = $this->queueFactory->get('markaspot_ai_duplicate_scan')->numberOfItems();

    // Duplicate match counts.
    $matchCounts = $this->duplicateDetection->getMatchCounts();

    // Calculate percentages.
    $embeddingPct = $total > 0 ? round(($embeddings / $total) * 100) : 0;
    $sentimentPct = $total > 0 ? round(($sentimentTotal / $total) * 100) : 0;

    $build = [];

    $build['status'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-admin-status']],
    ];

    // Summary cards rendered as a details/table layout for Gin compatibility.
    $build['status']['overview'] = [
      '#type' => 'details',
      '#title' => $this->t('Processing Overview'),
      '#open' => TRUE,
    ];

    $build['status']['overview']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Metric'),
        $this->t('Count'),
        $this->t('Percentage'),
      ],
      '#rows' => [
        [
          $this->t('Total service requests'),
          $total,
          '-',
        ],
        [
          $this->t('Embeddings generated'),
          $embeddings,
          $this->t('@pct%', ['@pct' => $embeddingPct]),
        ],
        [
          $this->t('Embeddings missing'),
          $total - $embeddings,
          '-',
        ],
        [
          $this->t('Sentiment analyzed'),
          $sentimentTotal,
          $this->t('@pct%', ['@pct' => $sentimentPct]),
        ],
        [
          $this->t('Sentiment missing'),
          $total - $sentimentTotal,
          '-',
        ],
      ],
    ];

    // Sentiment breakdown.
    $build['status']['sentiment'] = [
      '#type' => 'details',
      '#title' => $this->t('Sentiment Breakdown'),
      '#open' => TRUE,
    ];

    $build['status']['sentiment']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Category'),
        $this->t('Count'),
      ],
      '#rows' => [
        [$this->t('Frustrated'), $sentimentCounts['frustrated']],
        [$this->t('Neutral'), $sentimentCounts['neutral']],
        [$this->t('Positive'), $sentimentCounts['positive']],
      ],
    ];

    // Duplicate matches.
    $build['status']['duplicates'] = [
      '#type' => 'details',
      '#title' => $this->t('Duplicate Matches'),
      '#open' => TRUE,
    ];

    $build['status']['duplicates']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Status'),
        $this->t('Count'),
      ],
      '#rows' => [
        [$this->t('Pending'), $matchCounts['pending']],
        [$this->t('Confirmed'), $matchCounts['confirmed']],
        [$this->t('Rejected'), $matchCounts['rejected']],
        [$this->t('Total'), $matchCounts['total']],
      ],
    ];

    // Queue status.
    $build['status']['queues'] = [
      '#type' => 'details',
      '#title' => $this->t('Queue Status'),
      '#open' => TRUE,
    ];

    $build['status']['queues']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Queue'),
        $this->t('Items'),
      ],
      '#rows' => [
        [$this->t('Embedding'), $embeddingQueueSize],
        [$this->t('Duplicate scan'), $duplicateQueueSize],
      ],
    ];

    // Action buttons.
    $build['status']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-admin-actions']],
    ];

    $queueUrl = Url::fromRoute('markaspot_ai.admin_queue_missing');
    $queueUrl->setOption('query', [
      'token' => $this->csrfToken->get($queueUrl->getInternalPath()),
    ]);
    $build['status']['actions']['queue_missing'] = [
      '#type' => 'link',
      '#title' => $this->t('Queue missing items'),
      '#url' => $queueUrl,
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    $processUrl = Url::fromRoute('markaspot_ai.admin_process_queue');
    $processUrl->setOption('query', [
      'token' => $this->csrfToken->get($processUrl->getInternalPath()),
    ]);
    $build['status']['actions']['process_queue'] = [
      '#type' => 'link',
      '#title' => $this->t('Process queue (batch of 10)'),
      '#url' => $processUrl,
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    $build['#cache'] = [
      'max-age' => 0,
    ];

    return $build;
  }

  /**
   * Queues missing items for AI processing and redirects back.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the processing status page.
   */
  public function queueMissing(): RedirectResponse {
    $limit = 100;

    try {
      $missing = $this->embeddingService->findMissingEmbeddings(
        $limit,
        'node',
        'service_request',
        'content'
      );

      if (empty($missing)) {
        $this->messenger()->addStatus($this->t('No missing items to queue.'));
      }
      else {
        $queue = $this->queueFactory->get('markaspot_ai_embedding');
        $count = 0;

        foreach ($missing as $nid) {
          $queue->createItem([
            'nid' => $nid,
            'is_new' => FALSE,
          ]);
          $count++;
        }

        $this->messenger()->addStatus($this->t('Queued @count items for AI processing.', [
          '@count' => $count,
        ]));

        $this->getLogger('markaspot_ai')->notice('Queued @count items for AI processing via admin UI.', [
          '@count' => $count,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to queue items. Check the logs for details.'));
      $this->getLogger('markaspot_ai')->error('Admin queue missing failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return new RedirectResponse(
      Url::fromRoute('markaspot_ai.admin_processing_status')->toString()
    );
  }

  /**
   * Processes a batch of queued items and redirects back.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the processing status page.
   */
  public function processQueue(): RedirectResponse {
    $limit = 10;
    $timeLimit = 30;
    $queues = [
      'markaspot_ai_embedding',
      'markaspot_ai_duplicate_scan',
    ];

    $totalProcessed = 0;
    $totalErrors = 0;
    $startTime = time();

    try {
      foreach ($queues as $queueName) {
        $queue = $this->queueFactory->get($queueName);

        if ($queue->numberOfItems() === 0) {
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

        $totalProcessed += $processed;
        $totalErrors += $errors;
      }

      if ($totalProcessed > 0) {
        $this->messenger()->addStatus($this->t('Processed @count items.', [
          '@count' => $totalProcessed,
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('No items to process.'));
      }

      if ($totalErrors > 0) {
        $this->messenger()->addWarning($this->t('@count errors occurred during processing. Check the logs for details.', [
          '@count' => $totalErrors,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Queue processing failed. Check the logs for details.'));
      $this->getLogger('markaspot_ai')->error('Admin queue processing failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return new RedirectResponse(
      Url::fromRoute('markaspot_ai.admin_processing_status')->toString()
    );
  }

}
