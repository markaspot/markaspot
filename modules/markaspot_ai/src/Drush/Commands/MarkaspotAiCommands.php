<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_ai\Service\SentimentService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Mark-a-Spot AI module.
 */
class MarkaspotAiCommands extends DrushCommands {

  /**
   * Constructs a new MarkaspotAiCommands object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $queueWorkerManager,
    protected EmbeddingService $embeddingService,
    protected SentimentService $sentimentService,
    protected Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Queue service requests for AI processing (embeddings + sentiment).
   */
  #[CLI\Command(name: 'mas:ai:queue', aliases: ['maiq'])]
  #[CLI\Argument(name: 'scope', description: 'What to queue among tenants with features.aiProcessing=true: all, missing, sentiment, or a specific node ID')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of nodes to queue (default: 100)')]
  #[CLI\Option(name: 'force', description: 'Force re-processing even if already processed')]
  #[CLI\Usage(name: 'mas:ai:queue all', description: 'Queue all AI-enabled service requests')]
  #[CLI\Usage(name: 'mas:ai:queue missing', description: 'Queue AI-enabled requests without embeddings')]
  #[CLI\Usage(name: 'mas:ai:queue sentiment', description: 'Queue AI-enabled requests with embeddings but missing sentiment')]
  #[CLI\Usage(name: 'mas:ai:queue 64', description: 'Queue specific node ID only if its tenant has AI text processing enabled')]
  #[CLI\Usage(name: 'mas:ai:queue all --limit=500', description: 'Queue up to 500 AI-enabled requests')]
  public function queueRequests(string $scope = 'missing', array $options = ['limit' => 100, 'force' => FALSE]): void {
    $limit = (int) $options['limit'];
    $force = (bool) $options['force'];
    $queue = $this->queueFactory->get('markaspot_ai_embedding');

    // Handle specific node ID.
    if (is_numeric($scope)) {
      $nid = (int) $scope;
      $node = $this->entityTypeManager->getStorage('node')->load($nid);

      if (!$node || $node->bundle() !== 'service_request') {
        $this->logger()->error("Node {$nid} not found or not a service request.");
        return;
      }

      if (!_markaspot_ai_is_ai_enabled_for_node($node)) {
        $this->logger()->warning("Node {$nid} belongs to a tenant without features.aiProcessing=true. Nothing queued.");
        return;
      }

      $queue->createItem([
        'nid' => $nid,
        'is_new' => FALSE,
        'force' => $force,
      ]);

      $this->logger()->success("Queued node {$nid} for AI processing.");
      return;
    }

    $nids = match ($scope) {
      'all' => $this->collectAiEnabledServiceRequestIds(
        fn(int $batch_limit, int $offset): array => $this->getAllServiceRequestIds($batch_limit, $offset),
        $limit
      ),
      'missing' => $this->collectAiEnabledServiceRequestIds(
        fn(int $batch_limit, int $offset): array => $this->getMissingEmbeddingIds($batch_limit, $offset),
        $limit
      ),
      'sentiment' => $this->collectAiEnabledServiceRequestIds(
        fn(int $batch_limit, int $offset): array => $this->getMissingSentimentIds($batch_limit, $offset),
        $limit
      ),
      default => throw new \InvalidArgumentException("Invalid scope: {$scope}. Use 'all', 'missing', 'sentiment', or a node ID."),
    };

    if (empty($nids)) {
      $this->logger()->notice('No AI-enabled service requests to queue.');
      return;
    }

    $count = 0;
    foreach ($nids as $nid) {
      $queue->createItem([
        'nid' => $nid,
        'is_new' => FALSE,
        'force' => $force,
      ]);
      $count++;
    }

    $this->logger()->success("Queued {$count} service requests for AI processing.");
    $this->logger()->notice("Run 'drush queue:run markaspot_ai_embedding' to process.");
  }

  /**
   * Show AI processing statistics.
   */
  #[CLI\Command(name: 'mas:ai:status', aliases: ['mais'])]
  #[CLI\Usage(name: 'mas:ai:status', description: 'Show AI processing statistics')]
  public function showStatus(): void {
    // Count total service requests.
    $total = $this->database->select('node_field_data', 'n')
      ->condition('n.type', 'service_request')
      ->condition('n.status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count embeddings.
    $embeddings = $this->database->select('markaspot_ai_embeddings', 'e')
      ->condition('e.entity_type', 'node')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count sentiment analyzed.
    $sentiment = $this->database->select('markaspot_ai_sentiment', 's')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count by sentiment type (single query).
    $query = $this->database->select('markaspot_ai_sentiment', 's');
    $query->addField('s', 'sentiment');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('s.sentiment');
    $sentimentResults = $query->execute()->fetchAllKeyed();

    $sentimentCounts = [
      'frustrated' => (int) ($sentimentResults['frustrated'] ?? 0),
      'neutral' => (int) ($sentimentResults['neutral'] ?? 0),
      'positive' => (int) ($sentimentResults['positive'] ?? 0),
    ];

    // Queue status.
    $queue = $this->queueFactory->get('markaspot_ai_embedding');
    $queueCount = $queue->numberOfItems();
    $eligibleMissing = count($this->collectAiEnabledServiceRequestIds(
      fn(int $batch_limit, int $offset): array => $this->getMissingEmbeddingIds(
        $batch_limit,
        $offset
      ),
      max(1, $total)
    ));

    $this->io()->title('Mark-a-Spot AI Status');

    $this->io()->definitionList(
      ['Total Service Requests' => $total],
      ['With Embeddings' => "{$embeddings} (" . round(($embeddings / max($total, 1)) * 100) . "%)"],
      ['AI-eligible Missing Embeddings' => $eligibleMissing],
      ['With Sentiment' => "{$sentiment} (" . round(($sentiment / max($total, 1)) * 100) . "%)"],
    );

    $this->io()->section('Sentiment Breakdown');
    $this->io()->definitionList(
      ['Frustrated' => $sentimentCounts['frustrated'] ?? 0],
      ['Neutral' => $sentimentCounts['neutral'] ?? 0],
      ['Positive' => $sentimentCounts['positive'] ?? 0],
    );

    $this->io()->section('Queue');
    $this->io()->definitionList(
      ['Pending Items' => $queueCount],
    );

    if ($eligibleMissing > 0) {
      $this->io()->note("{$eligibleMissing} AI-enabled requests need AI processing. Run: drush mas:ai:queue missing");
    }
  }

  /**
   * Process the AI queues immediately.
   */
  #[CLI\Command(name: 'mas:ai:process', aliases: ['maip'])]
  #[CLI\Option(name: 'limit', description: 'Maximum items to process per queue (default: 50)')]
  #[CLI\Option(name: 'time-limit', description: 'Maximum time in seconds (default: 60)')]
  #[CLI\Usage(name: 'mas:ai:process', description: 'Process up to 50 queued items per queue')]
  #[CLI\Usage(name: 'mas:ai:process --limit=200', description: 'Process up to 200 items per queue')]
  public function processQueue(array $options = ['limit' => 50, 'time-limit' => 60]): void {
    $limit = (int) $options['limit'];
    $timeLimit = (int) $options['time-limit'];

    $queues = [
      'markaspot_ai_embedding',
      'markaspot_ai_duplicate_scan',
    ];

    $totalProcessed = 0;
    $totalErrors = 0;
    $startTime = time();

    foreach ($queues as $queueName) {
      $queue = $this->queueFactory->get($queueName);
      $queueCount = $queue->numberOfItems();

      if ($queueCount === 0) {
        continue;
      }

      $this->logger()->notice("Processing {$queueName} ({$queueCount} items)...");

      try {
        $worker = $this->queueWorkerManager->createInstance($queueName);
      }
      catch (\Exception $e) {
        $this->logger()->warning("Queue worker not found for {$queueName}, skipping.");
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
          $this->logger()->error("Queue item failed: {$e->getMessage()}");
          $queue->releaseItem($item);
          $errors++;

          if ($errors >= 3) {
            $this->logger()->warning("Stopping {$queueName} due to multiple errors.");
            break;
          }
        }
      }

      $remaining = $queue->numberOfItems();
      $this->logger()->notice("  {$queueName}: {$processed} processed, {$errors} errors, {$remaining} remaining.");
      $totalProcessed += $processed;
      $totalErrors += $errors;
    }

    $this->logger()->success("Total: {$totalProcessed} items processed ({$totalErrors} errors).");
  }

  /**
   * Get all published service request node IDs.
   */
  protected function getAllServiceRequestIds(int $limit, int $offset = 0): array {
    return $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('status', 1)
      ->range($offset, $limit)
      ->sort('nid', 'DESC')
      ->execute();
  }

  /**
   * Get service request IDs that are missing embeddings.
   */
  protected function getMissingEmbeddingIds(int $limit, int $offset = 0): array {
    return $this->embeddingService->findMissingEmbeddings(
      $limit,
      'node',
      'service_request',
      'content',
      NULL,
      $offset
    );
  }

  /**
   * Get service request IDs that have embeddings but missing sentiment.
   */
  protected function getMissingSentimentIds(int $limit, int $offset = 0): array {
    // Find nodes that have embeddings but no sentiment record.
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.type', 'service_request');
    $query->condition('n.status', 1);

    // Must have an embedding.
    $query->innerJoin('markaspot_ai_embeddings', 'e', 'e.entity_id = n.nid AND e.entity_type = :type', [':type' => 'node']);

    // Must NOT have a sentiment record.
    $query->leftJoin('markaspot_ai_sentiment', 's', 's.entity_id = n.nid AND s.entity_type = :stype', [':stype' => 'node']);
    $query->isNull('s.id');

    $query->range($offset, $limit);
    $query->orderBy('n.nid', 'DESC');

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Filters node IDs to service requests whose tenant explicitly opted in.
   *
   * @param array $nids
   *   Candidate node IDs.
   *
   * @return array<int>
   *   AI-enabled service request node IDs.
   */
  protected function filterAiEnabledServiceRequestIds(array $nids): array {
    if (empty($nids)) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $enabled = [];
    foreach ($nodes as $node) {
      if ($node->bundle() !== 'service_request') {
        continue;
      }
      if (_markaspot_ai_is_ai_enabled_for_node($node)) {
        $enabled[] = (int) $node->id();
      }
    }

    return $enabled;
  }

  /**
   * Collects up to the requested limit after tenant opt-in filtering.
   *
   * @param callable $candidate_loader
   *   Callable with signature fn(int $limit, int $offset): array.
   * @param int $limit
   *   Maximum number of enabled node IDs to return.
   *
   * @return array<int>
   *   AI-enabled service request node IDs.
   */
  protected function collectAiEnabledServiceRequestIds(callable $candidate_loader, int $limit): array {
    $enabled = [];
    $seen = [];
    $offset = 0;
    $batch_size = min(500, max(50, $limit));

    while (count($enabled) < $limit) {
      $candidates = $candidate_loader($batch_size, $offset);
      if (empty($candidates)) {
        break;
      }
      $offset += count($candidates);

      foreach ($this->filterAiEnabledServiceRequestIds($candidates) as $nid) {
        if (isset($seen[$nid])) {
          continue;
        }
        $seen[$nid] = TRUE;
        $enabled[] = $nid;
        if (count($enabled) >= $limit) {
          break 2;
        }
      }

      if (count($candidates) < $batch_size) {
        break;
      }
    }

    return $enabled;
  }

}
