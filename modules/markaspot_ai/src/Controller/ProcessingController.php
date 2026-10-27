<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for AI processing management from dashboard.
 */
class ProcessingController extends ControllerBase {

  use JurisdictionIdResolverTrait;

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
    protected ?FeatureScopeResolver $featureScopeResolver = NULL,
    protected ?MemoryCacheInterface $entityMemoryCache = NULL,
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
        : NULL,
      $container->get('markaspot_nuxt.feature_scope_resolver'),
      $container->get('entity.memory_cache'),
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
    $requested = $request?->query->get('jurisdiction_id');
    $jurisdiction_id = is_int($requested) || is_string($requested) ? $this->resolveJurisdictionId($requested) : NULL;
    $scope = $jurisdiction_id ? $this->buildJurisdictionScope($jurisdiction_id) : NULL;

    // A requested jurisdiction that does not resolve (unknown slug, org group,
    // unpublished) never widens to every tenant.
    if ($this->isRequested($requested) && $scope === NULL) {
      return new JsonResponse($this->buildEmptyStatus());
    }

    // Only published reports are analysed (on create, update and publish).
    // Unpublished ones are reported separately and never counted as missing.
    $countQuery = function (?string $table, int $status) use ($scope): SelectInterface {
      $query = $this->database->select('node_field_data', 'n')
        ->condition('n.type', 'service_request')
        ->condition('n.status', $status);
      if ($scope !== NULL) {
        $query->condition('n.nid', clone $scope, 'IN');
      }
      if ($table === 'markaspot_ai_embeddings') {
        $query->join($table, 'e', "e.entity_id = n.nid AND e.entity_type = 'node'");
      }
      elseif ($table === 'markaspot_ai_sentiment') {
        $query->join($table, 's', 's.entity_id = n.nid');
      }
      $query->addExpression('COUNT(DISTINCT n.nid)', 'nodes');
      return $query;
    };

    $total = (int) $countQuery(NULL, 1)->execute()->fetchField();
    $awaitingPublication = (int) $countQuery(NULL, 0)->execute()->fetchField();
    $embeddings = (int) $countQuery('markaspot_ai_embeddings', 1)->execute()->fetchField();
    $sentiment = (int) $countQuery('markaspot_ai_sentiment', 1)->execute()->fetchField();

    $sentimentCounts = array_fill_keys(['frustrated', 'neutral', 'positive'], 0);
    $breakdownQuery = $countQuery('markaspot_ai_sentiment', 1);
    $breakdownQuery->addField('s', 'sentiment');
    $breakdownQuery->groupBy('s.sentiment');
    foreach ($breakdownQuery->execute()->fetchAll() as $row) {
      if (array_key_exists((string) $row->sentiment, $sentimentCounts)) {
        $sentimentCounts[$row->sentiment] = (int) $row->nodes;
      }
    }

    // Queue status.
    $embeddingQueue = $this->queueFactory->get('markaspot_ai_embedding');
    $duplicateQueue = $this->queueFactory->get('markaspot_ai_duplicate_scan');
    $eligibleMissingEmbeddings = $this->countEligibleMissing(
      fn(int $batch_limit, int $offset): array => $this->embeddingService
        ->findMissingEmbeddings(
          $batch_limit,
          'node',
          'service_request',
          'content',
          $scope,
          $offset
        ),
      $jurisdiction_id,
      $total
    );
    $eligibleMissingSentiment = $this->countEligibleMissing(
      fn(int $batch_limit, int $offset): array => $this->findMissingSentiment(
        $batch_limit,
        $scope,
        $offset
      ),
      $jurisdiction_id,
      $total
    );

    return new JsonResponse([
      'total_requests' => $total,
      'awaiting_publication' => $awaitingPublication,
      'embeddings' => [
        'count' => $embeddings,
        'percentage' => $total > 0 ? round(($embeddings / $total) * 100) : 0,
        'missing' => $total - $embeddings,
        'eligible_missing' => $eligibleMissingEmbeddings,
      ],
      'sentiment' => [
        'count' => $sentiment,
        'percentage' => $total > 0 ? round(($sentiment / $total) * 100) : 0,
        'missing' => $total - $sentiment,
        'eligible_missing' => $eligibleMissingSentiment,
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

    // Optional jurisdiction scoping (supports slugs).
    $requested = $content['jurisdiction_id'] ?? $request->query->get('jurisdiction_id');
    $jurisdiction_id = is_int($requested) || is_string($requested) ? $this->resolveJurisdictionId($requested) : NULL;
    $scope = $jurisdiction_id ? $this->buildJurisdictionScope($jurisdiction_id) : NULL;
    if ($this->isRequested($requested) && $scope === NULL) {
      // Never fall back to every tenant for an unknown jurisdiction.
      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Nothing queued: the jurisdiction is unknown.',
        'queued' => 0,
      ]);
    }

    try {
      $missing = $this->collectAiEnabledNodeIds(
        fn(int $batch_limit, int $offset): array => $this->embeddingService
          ->findMissingEmbeddings(
            $batch_limit,
            'node',
            'service_request',
            'content',
            $scope,
            $offset
          ),
        $limit
      );

      // Also find nodes that have embeddings but no sentiment analysis.
      // The embedding queue worker handles this idempotently: it skips
      // embedding generation (hash match) but still runs analyzeSentiment().
      $missingSentiment = $this->collectAiEnabledNodeIds(
        fn(int $batch_limit, int $offset): array => $this->findMissingSentiment(
          $batch_limit,
          $scope,
          $offset
        ),
        $limit
      );
      // Exclude nodes already in the embedding-missing list to avoid duplicates.
      $missingSentiment = array_diff($missingSentiment, $missing);

      $allMissing = array_slice(
        array_values(array_unique(array_merge($missing, $missingSentiment))),
        0,
        $limit
      );

      if (empty($allMissing)) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'Nothing queued: no eligible missing items exist, or the matching tenants are not opted in to AI text processing.',
          'queued' => 0,
        ]);
      }

      // Queue them.
      $queue = $this->queueFactory->get('markaspot_ai_embedding');
      $count = 0;

      foreach ($allMissing as $nid) {
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
   * Whether the caller asked for a jurisdiction at all.
   *
   * @param mixed $value
   *   The raw jurisdiction_id parameter.
   *
   * @return bool
   *   TRUE when a non-empty value was passed.
   */
  protected function isRequested(mixed $value): bool {
    return $value !== NULL && $value !== '';
  }

  /**
   * Builds a subquery selecting the report IDs of a jurisdiction subtree.
   *
   * A subquery instead of an ID list: large tenants hold over 150,000
   * reports, and passing them to every count query cost minutes.
   *
   * @param int $group_id
   *   The jurisdiction group ID.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface|null
   *   The subquery, or NULL when the group is not a jurisdiction.
   */
  protected function buildJurisdictionScope(int $group_id): ?SelectInterface {
    if (!$this->moduleHandler()->moduleExists('group')) {
      return NULL;
    }

    // Validate group exists and is a jurisdiction type.
    // Prevents cross-tenant leakage by rejecting org/other group types.
    $group = $this->entityTypeManager()->getStorage('group')->load($group_id);
    if (!$this->isJurisdictionGroup($group)) {
      return NULL;
    }

    // Child jurisdictions belong to the scope when the resolver is available.
    $group_ids = $this->hierarchyResolver
      ? $this->hierarchyResolver->getScopeJurisdictionIds($group_id)
      : [$group_id];
    if ($group_ids === []) {
      return NULL;
    }

    return $this->database->select('group_relationship_field_data', 'gr')
      ->fields('gr', ['entity_id'])
      ->condition('gr.gid', $group_ids, 'IN')
      ->condition('gr.plugin_id', 'group_node:service_request');
  }

  /**
   * Finds nodes that have embeddings but no sentiment analysis.
   *
   * @param int $limit
   *   Maximum number of results.
   * @param \Drupal\Core\Database\Query\SelectInterface|null $scope
   *   Optional subquery selecting the jurisdiction's report IDs.
   * @param int $offset
   *   Result offset for paged scans.
   *
   * @return array<int>
   *   Array of published node IDs missing sentiment.
   */
  protected function findMissingSentiment(int $limit, ?SelectInterface $scope = NULL, int $offset = 0): array {
    $query = $this->database->select('markaspot_ai_embeddings', 'e');
    $query->addField('e', 'entity_id');
    $query->distinct();
    $query->condition('e.entity_type', 'node');
    $query->join('node_field_data', 'n', 'e.entity_id = n.nid AND n.type = :type AND n.status = 1', [':type' => 'service_request']);
    $query->leftJoin('markaspot_ai_sentiment', 's', 'e.entity_id = s.entity_id');
    $query->isNull('s.id');

    if ($scope !== NULL) {
      $query->condition('n.nid', clone $scope, 'IN');
    }

    $query->range($offset, $limit);
    $query->orderBy('n.created', 'DESC');

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Filters node IDs to tenants with explicit AI processing opt-in.
   *
   * @param array $node_ids
   *   Candidate node IDs.
   *
   * @return array<int>
   *   Node IDs whose jurisdiction has features.aiProcessing=true.
   */
  protected function filterAiEnabledNodeIds(array $node_ids): array {
    if (empty($node_ids)) {
      return [];
    }

    $nodes = $this->entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($node_ids);
    $enabled = [];
    foreach ($nodes as $node) {
      if ($node->bundle() !== 'service_request') {
        continue;
      }
      if (_markaspot_ai_is_ai_enabled_for_node($node)) {
        $enabled[] = (int) $node->id();
      }
    }

    // Release the batch from the static entity cache only: callers page
    // through every missing report, and resetCache() would also evict the
    // persistent entity cache.
    $this->entityMemoryCache?->deleteMultiple(array_map(
      static fn($id): string => 'values:node:' . $id,
      array_values($node_ids),
    ));

    return $enabled;
  }

  /**
   * Counts missing items that AI processing may still handle.
   *
   * AI processing is a tenant-scoped switch, so every report of a
   * jurisdiction shares one decision: for a scoped status it is checked once
   * and the missing IDs are only counted, never loaded. Loading every missing
   * report to check it one by one exhausted the PHP memory limit on tenants
   * with tens of thousands of reports.
   *
   * @param callable $candidate_loader
   *   Callable with signature fn(int $limit, int $offset): array of node IDs.
   * @param int|null $jurisdiction_id
   *   The jurisdiction the status is scoped to, NULL for platform-wide.
   * @param int $total
   *   Total number of reports in scope.
   *
   * @return int
   *   Number of eligible missing items.
   */
  protected function countEligibleMissing(callable $candidate_loader, ?int $jurisdiction_id, int $total): int {
    if ($jurisdiction_id === NULL) {
      return count($this->collectAiEnabledNodeIds($candidate_loader, max(1, $total)));
    }

    $group = $this->entityTypeManager()->getStorage('group')->load($jurisdiction_id);
    if (!$group instanceof GroupInterface
      || $this->featureScopeResolver === NULL
      || !$this->featureScopeResolver->isEnabledEffective('aiProcessing', $group, FALSE)) {
      return 0;
    }

    // One query for all missing IDs: they are only counted, and paging with
    // OFFSET over an IN() list of the whole tenant cost minutes on large
    // tenants. The ID list itself stays small (integers only).
    return count($candidate_loader(max(1, $total) + 1, 0));
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
  protected function collectAiEnabledNodeIds(callable $candidate_loader, int $limit): array {
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

      foreach ($this->filterAiEnabledNodeIds($candidates) as $nid) {
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

  /**
   * Builds an empty status response structure.
   *
   * @return array
   *   Empty status data.
   */
  protected function buildEmptyStatus(): array {
    return [
      'total_requests' => 0,
      'awaiting_publication' => 0,
      'embeddings' => [
        'count' => 0,
        'percentage' => 0,
        'missing' => 0,
      ],
      'sentiment' => [
        'count' => 0,
        'percentage' => 0,
        'missing' => 0,
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
