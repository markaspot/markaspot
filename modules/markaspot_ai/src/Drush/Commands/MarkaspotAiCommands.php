<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\Connection;
use Drupal\markaspot_ai\Utility\BlurPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\markaspot_ai\Service\SpamRiskScannerService;
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
    protected ?SpamRiskScannerService $spamRiskScanner = NULL,
    protected ?AiClientService $aiClient = NULL,
    protected ?TokenTrackingService $tokenTracking = NULL,
    protected ?ConfigFactoryInterface $configFactory = NULL,
    protected ?ModuleHandlerInterface $moduleHandler = NULL,
    protected ?ImageProcessingService $vision = NULL,
    protected ?LoggerInterface $blurLogger = NULL,
  ) {
    parent::__construct();
  }

  /**
   * Tests provider compatibility using synthetic data only.
   */
  #[CLI\Command(name: 'markaspot:ai:selftest')]
  #[CLI\Option(name: 'provider', description: 'Provider to test; defaults to default_provider.')]
  #[CLI\Option(name: 'skip-vision', description: 'Skip the synthetic vision check.')]
  public function selftest(array $options = ['provider' => NULL, 'skip-vision' => FALSE]): int {
    if (!$this->aiClient || !$this->tokenTracking || !$this->configFactory || !$this->moduleHandler) {
      throw new \RuntimeException('AI self-test services are unavailable.');
    }
    $config = $this->configFactory->get('markaspot_ai.settings');
    $provider = ($options['provider'] ?? NULL) ?: ($config->get('default_provider') ?? 'openai');
    $provider_config = $config->get("providers.{$provider}");
    if (!is_array($provider_config)) {
      $this->output()->writeln('provider: http 0: unknown provider');
      return 1;
    }
    $model = $this->aiClient->resolveChatModel(NULL, $provider);
    $url = getenv('MARKASPOT_AI_API_URL') ?: ($provider_config['api_url'] ?? ($provider === 'anthropic' ? 'https://api.anthropic.com/v1' : 'https://api.openai.com/v1'));
    $this->output()->writeln(sprintf('provider=%s model=%s host=%s', $provider, $model, parse_url($url, PHP_URL_HOST) ?: 'unknown'));
    $blur_mode = $this->vision ? $this->vision->getBlurMode() : BlurPolicy::mode($this->blurLogger ?? new NullLogger());
    $this->output()->writeln('blur mode=' . $blur_mode);
    if ($blur_mode === 'auto-unprotected') {
      $this->output()->writeln('Notice: blur is not enforced; the site switch decides. Set MARKASPOT_BLUR_URL to enforce preprocessing or MARKASPOT_BLUR_REQUIRED=0 to acknowledge unprotected operation.');
    }
    $checks = [
      'chat JSON' => fn() => $this->aiClient->chat([['role' => 'user', 'content' => 'Return JSON: {"ok":true}']], [
        'provider' => $provider,
        'operation' => 'selftest',
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 150,
      ]),
      'chat text' => fn() => $this->aiClient->chat([['role' => 'user', 'content' => 'Reply with OK.']], [
        'provider' => $provider,
        'operation' => 'selftest',
        'max_tokens' => 150,
      ]),
      'embedding' => fn() => $this->aiClient->embed('Synthetic compatibility test.', ['provider' => $provider]),
    ];
    if (empty($options['skip-vision']) && $this->moduleHandler->moduleExists('markaspot_vision') && $this->vision) {
      $vision_config = $this->configFactory->get('markaspot_vision.settings');
      $vision_url = getenv('MARKASPOT_VISION_API_URL') ?: $vision_config->get('api_url');
      if ($vision_url && $vision_config->get('ai_model')) {
        $this->output()->writeln(sprintf('vision model=%s host=%s', $vision_config->get('ai_model'), parse_url($vision_url, PHP_URL_HOST) ?: 'unknown'));
        $checks['vision'] = fn() => $this->vision->selfTest();
      }
    }
    $failed = FALSE;
    foreach ($checks as $name => $check) {
      try {
        $embedding_matches = TRUE;
        if ($name === 'embedding') {
          $configured = $this->aiClient->resolveEmbeddingModel(NULL, $provider);
          $rows = $this->embeddingService->getStoredModelSummary();
          $this->printEmbeddingModels($rows);
          foreach ($rows as $row) {
            if ($row['model'] !== $configured) {
              $embedding_matches = FALSE;
              $failed = TRUE;
              $this->output()->writeln('embedding identity mismatch: configured=' . $configured . '; use markaspot:ai:embeddings-relabel only for an identity-only rename.');
              break;
            }
          }
        }
        $result = $check();
        $response = $name === 'vision' ? $result['response'] : $result;
        $used_model = $response['model'] ?? ($name === 'embedding' ? $this->aiClient->resolveEmbeddingModel(NULL, $provider) : $model);
        $usage = $response['usage'] ?? [];
        $this->tokenTracking->logUsage(
          $name === 'vision' ? 'vision' : $provider,
          $used_model,
          'selftest',
          (int) ($usage['prompt_tokens'] ?? $usage['total_tokens'] ?? 0),
          (int) ($usage['completion_tokens'] ?? 0),
        );
        $content = $response['choices'][0]['message']['content'] ?? '';
        $valid = is_string($content) && trim($content) !== '';
        if ($name === 'chat JSON') {
          $valid = $valid && (json_decode($content, TRUE)['ok'] ?? NULL) === TRUE;
        }
        elseif ($name === 'embedding') {
          $vector = $response['data'][0]['embedding'] ?? [];
          $valid = is_array($vector) && $vector !== [];
          $this->output()->writeln(sprintf('embedding model=%s dimensions=%d', $used_model, count($vector)));
          $valid = $valid && $embedding_matches;
        }
        elseif ($name === 'vision') {
          $valid = $valid && is_array(json_decode($content, TRUE));
          $this->output()->writeln(sprintf('blur processed=%s applied=%s', $result['blur_processed'] ? 'yes' : 'no', $result['blur_applied'] ? 'yes' : 'no'));
          $valid = $valid && (!$this->vision->isBlurRequired() || $result['blur_processed']);
        }
        $this->output()->writeln($name . ': ' . ($valid ? 'ok' : 'empty'));
        $failed = $failed || !$valid;
      }
      catch (\Throwable $e) {
        // Provider bodies can contain secrets. Report only safe reasons.
        $code = 0;
        for ($cause = $e; $cause !== NULL; $cause = $cause->getPrevious()) {
          if ($cause->getCode() >= 400 && $cause->getCode() <= 599) {
            $code = (int) $cause->getCode();
            break;
          }
        }
        $reason = match (TRUE) {
          $code === 400 => 'request rejected',
          $code === 401 || $code === 403 => 'authentication or permission denied',
          $code === 429 => 'rate limited',
          $code >= 500 => 'provider unavailable',
          default => 'request failed',
        };
        $this->output()->writeln($name . ': ' . (str_contains(strtolower($e->getMessage()), 'token budget exhausted') ? 'empty' : "http {$code}: {$reason}"));
        if ($name === 'vision') {
          $this->output()->writeln('blur processed=unconfirmed applied=unconfirmed');
        }
        $failed = TRUE;
      }
    }
    return $failed ? 1 : 0;
  }

  /**
   * Relabels persisted vectors after an operator-confirmed deployment rename.
   */
  #[CLI\Command(name: 'markaspot:ai:embeddings-relabel')]
  #[CLI\Option(name: 'from', description: 'Exact stored identifier to relabel (required).')]
  #[CLI\Option(name: 'to', description: 'Configured embedding identifier (required).')]
  #[CLI\Option(name: 'dry-run', description: 'Report matching rows without changing them.')]
  public function embeddingsRelabel(array $options = ['from' => NULL, 'to' => NULL, 'dry-run' => FALSE]): int {
    $this->printEmbeddingModels($this->embeddingService->getStoredModelSummary());
    try {
      $count = $this->embeddingService->relabelEmbeddings((string) ($options['from'] ?? ''), (string) ($options['to'] ?? ''), !empty($options['dry-run']));
      $this->output()->writeln(sprintf('%s rows: %d', empty($options['dry-run']) ? 'Affected' : 'Would relabel', $count));
      return 0;
    }
    catch (\InvalidArgumentException $e) {
      $this->output()->writeln($e->getMessage());
      return 1;
    }
  }

  /**
   * Prints stored model identities without vectors or provider requests.
   */
  protected function printEmbeddingModels(array $rows): void {
    $this->output()->writeln('Stored embeddings (model, dimensions, count):');
    foreach ($rows as $row) {
      $this->output()->writeln(sprintf('%s, %d, %d', $row['model'], $row['dimensions'], $row['count']));
    }
  }

  /**
   * Scan new workspaces for likely spam activity.
   */
  #[CLI\Command(name: 'markaspot:ai:spam-scan', aliases: ['mas:ai:spam-scan'])]
  #[CLI\Option(name: 'hours', description: 'Look back this many hours for service requests.')]
  #[CLI\Option(name: 'workspace', description: 'Restrict scan to one workspace ID or slug.')]
  #[CLI\Option(name: 'limit', description: 'Maximum recent requests to inspect before bucketing.')]
  #[CLI\Option(name: 'new-workspace-days', description: 'Only scan workspaces created within this many days. Use 0 to disable.')]
  #[CLI\Option(name: 'sample-limit', description: 'Maximum redacted request samples per workspace.')]
  #[CLI\Option(name: 'use-ai', description: 'Use the configured AI provider after deterministic prefiltering.')]
  #[CLI\Option(name: 'provider', description: 'AI provider override for classification, for example anthropic.')]
  #[CLI\Option(name: 'ai-trigger-score', description: 'Minimum deterministic score before sending redacted samples to AI.')]
  #[CLI\Option(name: 'apply', description: 'Apply automatic block on the shared self-service platform when risk reaches the threshold.')]
  #[CLI\Option(name: 'auto-block-threshold', description: 'Risk score required for --apply to set field_visibility=blocked on self-service workspaces.')]
  #[CLI\FieldLabels(labels: [
    'workspace_id' => 'Workspace ID',
    'workspace' => 'Workspace',
    'risk_score' => 'Risk',
    'decision' => 'Decision',
    'action' => 'Action',
    'request_count' => 'Requests',
    'request_ids' => 'Samples',
    'reasons' => 'Reasons',
  ])]
  #[CLI\Usage(name: 'markaspot:ai:spam-scan --hours=24', description: 'Dry-run deterministic scan of new workspaces.')]
  #[CLI\Usage(name: 'markaspot:ai:spam-scan --hours=24 --use-ai --provider=anthropic', description: 'Send only suspicious, redacted first-request samples to Anthropic.')]
  #[CLI\Usage(name: 'markaspot:ai:spam-scan --workspace=test --use-ai --provider=anthropic --apply', description: 'Scan one self-service workspace and block it automatically when the score reaches the threshold.')]
  public function spamScan(
    array $options = [
      'hours' => 24,
      'workspace' => NULL,
      'limit' => 500,
      'new-workspace-days' => 7,
      'sample-limit' => 10,
      'use-ai' => FALSE,
      // Default to the documented Anthropic privacy contract. Operators may
      // pass --provider=openai (etc.) for an explicit override; an unset
      // provider must NEVER fall back to the global default_provider.
      'provider' => 'anthropic',
      'ai-trigger-score' => 50,
      'apply' => FALSE,
      'auto-block-threshold' => 95,
    ],
  ): RowsOfFields {
    $hours = max(1, (int) ($options['hours'] ?? 24));
    $since = time() - ($hours * 3600);

    if (!$this->spamRiskScanner instanceof SpamRiskScannerService) {
      throw new \RuntimeException('Spam risk scanner service is not available. Run drush cr and retry.');
    }

    $rows = $this->spamRiskScanner->scanRecent(
      $since,
      $options['workspace'] !== NULL && $options['workspace'] !== '' ? (string) $options['workspace'] : NULL,
      max(1, (int) ($options['limit'] ?? 500)),
      !empty($options['use-ai']),
      !empty($options['apply']),
      max(1, (int) ($options['auto-block-threshold'] ?? 95)),
      max(0, (int) ($options['new-workspace-days'] ?? 7)),
      max(0, (int) ($options['ai-trigger-score'] ?? 50)),
      max(1, min(10, (int) ($options['sample-limit'] ?? 10))),
      !empty($options['provider']) ? (string) $options['provider'] : NULL,
    );

    if ($rows === []) {
      $this->logger()->notice('No matching new-workspace spam signals found.');
    }

    return new RowsOfFields($rows);
  }

  /**
   * Queue service requests for AI processing (embeddings + sentiment).
   */
  #[CLI\Command(name: 'markaspot:ai:queue', aliases: ['mas:ai:queue', 'maiq'])]
  #[CLI\Argument(name: 'scope', description: 'What to queue among tenants with features.aiProcessing=true: all, missing, sentiment, or a specific node ID')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of nodes to queue (default: 100)')]
  #[CLI\Option(name: 'force', description: 'Force re-processing even if already processed')]
  #[CLI\Usage(name: 'markaspot:ai:queue all', description: 'Queue all AI-enabled service requests')]
  #[CLI\Usage(name: 'markaspot:ai:queue missing', description: 'Queue AI-enabled requests without embeddings')]
  #[CLI\Usage(name: 'markaspot:ai:queue sentiment', description: 'Queue AI-enabled requests with embeddings but missing sentiment')]
  #[CLI\Usage(name: 'markaspot:ai:queue 64', description: 'Queue specific node ID only if its tenant has AI text processing enabled')]
  #[CLI\Usage(name: 'markaspot:ai:queue all --limit=500', description: 'Queue up to 500 AI-enabled requests')]
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
  #[CLI\Command(name: 'markaspot:ai:status', aliases: ['mas:ai:status', 'mais'])]
  #[CLI\Usage(name: 'markaspot:ai:status', description: 'Show AI processing statistics')]
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
      $this->io()->note("{$eligibleMissing} AI-enabled requests need AI processing. Run: drush markaspot:ai:queue missing");
    }
  }

  /**
   * Process the AI queues immediately.
   */
  #[CLI\Command(name: 'markaspot:ai:process', aliases: ['mas:ai:process', 'maip'])]
  #[CLI\Option(name: 'limit', description: 'Maximum items to process per queue (default: 50)')]
  #[CLI\Option(name: 'time-limit', description: 'Maximum time in seconds (default: 60)')]
  #[CLI\Usage(name: 'markaspot:ai:process', description: 'Process up to 50 queued items per queue')]
  #[CLI\Usage(name: 'markaspot:ai:process --limit=200', description: 'Process up to 200 items per queue')]
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
