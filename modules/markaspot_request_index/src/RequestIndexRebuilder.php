<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues and processes re-projections of the request index.
 *
 * Queue items:
 * - range: every service request with from < nid <= to, plus removal of
 *   orphan rows in that range (full rebuild, resumable per range).
 * - nids: a list of node IDs, with the number of earlier failed attempts.
 * - organisation, jurisdiction, term: expanded at processing time into nids
 *   items for the requests that reference the group or term.
 */
final class RequestIndexRebuilder {

  /**
   * Queue name, shared with the queue worker plugin ID.
   */
  public const QUEUE = 'markaspot_request_index';

  /**
   * State key of the current or last full rebuild run.
   */
  public const STATE_RUN = 'markaspot_request_index.rebuild';

  /**
   * Default number of requests per queue item.
   */
  public const BATCH_SIZE = 200;

  /**
   * Upper bound of the last range (INT UNSIGNED maximum).
   */
  private const MAX_NID = 4294967295;

  /**
   * Seconds a claimed item stays leased while drush drains the queue.
   *
   * A range of 200 requests takes well under a second; an item claimed by
   * an interrupted run comes back after this lease.
   */
  public const LEASE = 60;

  /**
   * Attempts after which a request that fails to project is dropped.
   */
  public const MAX_ATTEMPTS = 3;

  /**
   * Requests that failed to project since the last drain started.
   */
  private int $failed = 0;

  /**
   * Constructs the rebuilder.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly RequestIndexWriter $writer,
    private readonly RequestIndexProjector $projector,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly LoggerInterface $logger,
    private readonly RequestIndexReader $reader,
    private readonly RequestIndexStaleSet $staleSet,
  ) {}

  /**
   * Replaces the queue with a full rebuild in node ID ranges.
   *
   * Queued incremental items are dropped: the ranges cover every request.
   * Stale marks stay until the range that holds the request projects it.
   *
   * @return array{id: string, started: int, requests: int, items: int, batch_size: int, finished: int|null}
   *   The new run.
   */
  public function startFullRebuild(int $batchSize = self::BATCH_SIZE): array {
    $batchSize = max(1, $batchSize);
    $queue = $this->queue();
    $queue->deleteQueue();
    $queue->createQueue();

    $nids = $this->serviceRequestIds();
    $chunks = $nids === [] ? [[]] : array_chunk($nids, $batchSize);
    $run = [
      'id' => bin2hex(random_bytes(8)),
      'started' => $this->time->getCurrentTime(),
      'requests' => count($nids),
      'items' => count($chunks),
      'batch_size' => $batchSize,
      'finished' => NULL,
    ];
    $from = 0;
    $last = count($chunks) - 1;
    foreach ($chunks as $index => $chunk) {
      $to = $index === $last ? self::MAX_NID : (int) end($chunk);
      $queue->createItem([
        'op' => 'range',
        'from' => $from,
        'to' => $to,
        'run' => $run['id'],
        'final' => $index === $last,
      ]);
      $from = $to;
    }
    $this->state->set(self::STATE_RUN, $run);
    $this->reader->resetParity();
    return $run;
  }

  /**
   * Returns the full rebuild run that still has queued items, if any.
   *
   * @return array<string, mixed>|null
   *   The unfinished run, or NULL.
   */
  public function pendingRun(): ?array {
    $run = $this->state->get(self::STATE_RUN);
    if (!is_array($run) || !empty($run['finished']) || $this->queue()->numberOfItems() === 0) {
      return NULL;
    }
    return $run;
  }

  /**
   * Queues a re-projection of the given requests.
   *
   * @param list<int> $nids
   *   Node IDs.
   * @param int $attempt
   *   Failed attempts so far; 0 for a first try.
   */
  public function enqueueNids(array $nids, int $attempt = 0): void {
    $nids = array_values(array_unique(array_filter(array_map('intval', $nids))));
    foreach (array_chunk($nids, self::BATCH_SIZE) as $chunk) {
      $item = ['op' => 'nids', 'nids' => $chunk];
      if ($attempt > 0) {
        $item['attempt'] = $attempt;
      }
      $this->queue()->createItem($item);
    }
  }

  /**
   * Queues a re-projection of every request that references a group or term.
   *
   * @param string $kind
   *   One of organisation, jurisdiction or term.
   * @param int $id
   *   The group or term ID.
   */
  public function enqueueReference(string $kind, int $id): void {
    if (!in_array($kind, ['organisation', 'jurisdiction', 'term'], TRUE) || $id <= 0) {
      return;
    }
    $this->queue()->createItem(['op' => $kind, 'id' => $id]);
  }

  /**
   * Processes one queue item.
   *
   * A request whose projection fails does not fail the item: it is queued
   * again on its own and dropped after MAX_ATTEMPTS. A failure of the item
   * itself is rethrown without field values in its message, for drush and
   * for the cron log alike.
   *
   * @param array<string, mixed> $data
   *   The item data.
   *
   * @return int
   *   Number of projected rows; expansion items return 0.
   */
  public function processItem(array $data): int {
    try {
      return $this->doProcessItem($data);
    }
    catch (\Throwable $throwable) {
      throw new \RuntimeException('Request index queue item failed: ' . RequestIndexWriter::describeFailure($throwable), 0, $throwable);
    }
  }

  /**
   * Drains the queue in this process.
   *
   * @param callable(int, int, int): void|null $progress
   *   Called after each item with items done, items left and rows projected.
   * @param int $timeLimit
   *   Seconds after which draining stops; 0 means no limit.
   *
   * @return array{items: int, rows: int, left: int, failed: int}
   *   Processed items, projected rows, items left in the queue and failed
   *   projection attempts (each logged and retried until MAX_ATTEMPTS).
   */
  public function drain(?callable $progress = NULL, int $timeLimit = 0): array {
    $queue = $this->queue();
    $start = microtime(TRUE);
    $items = 0;
    $rows = 0;
    $this->failed = 0;
    while (is_object($item = $queue->claimItem(self::LEASE))) {
      try {
        $rows += $this->processItem((array) ($item->data ?? []));
        $queue->deleteItem($item);
      }
      catch (\Throwable $throwable) {
        $queue->releaseItem($item);
        throw $throwable;
      }
      $items++;
      if ($progress !== NULL) {
        $progress($items, $queue->numberOfItems(), $rows);
      }
      if ($timeLimit > 0 && microtime(TRUE) - $start >= $timeLimit) {
        break;
      }
    }
    $left = $queue->numberOfItems();
    if ($left === 0) {
      $this->markFinished(NULL);
    }
    return ['items' => $items, 'rows' => $rows, 'left' => $left, 'failed' => $this->failed];
  }

  /**
   * Returns live counts for the status report and drush.
   *
   * The index is usable for the dashboard list only while requests and
   * indexed are equal and queued and stale are 0 (RequestIndexReader).
   *
   * @return array{requests: int, indexed: int, queued: int, stale: int, run: array<string, mixed>|null}
   *   Service request count, row count, queued items, requests marked stale
   *   and the last run.
   */
  public function status(): array {
    $run = $this->state->get(self::STATE_RUN);
    return [
      'requests' => $this->countServiceRequests(),
      'indexed' => $this->reader->countRows(),
      'queued' => $this->queue()->numberOfItems(),
      'stale' => count($this->staleSet->ids()),
      'run' => is_array($run) ? $run : NULL,
    ];
  }

  /**
   * Counts service requests.
   */
  public function countServiceRequests(): int {
    return $this->reader->countServiceRequests();
  }

  /**
   * Returns all service request node IDs in ascending order.
   *
   * @return list<int>
   *   Node IDs.
   */
  public function serviceRequestIds(int $from = 0, int $to = self::MAX_NID): array {
    $query = $this->database->select($this->nodeBaseTable(), 'n')
      ->fields('n', ['nid'])
      ->condition('n.type', RequestIndexProjector::BUNDLE)
      ->orderBy('n.nid');
    if ($from > 0) {
      $query->condition('n.nid', $from, '>');
    }
    if ($to < self::MAX_NID) {
      $query->condition('n.nid', $to, '<=');
    }
    return array_values(array_map('intval', $query->execute()->fetchCol()));
  }

  /**
   * Processes one queue item; see processItem().
   *
   * @param array<string, mixed> $data
   *   The item data.
   */
  private function doProcessItem(array $data): int {
    $op = (string) ($data['op'] ?? '');
    if ($op === 'range') {
      return $this->processRange((int) $data['from'], (int) $data['to'], !empty($data['final']), (string) ($data['run'] ?? ''));
    }
    if ($op === 'nids') {
      $nids = array_values(array_map('intval', (array) ($data['nids'] ?? [])));
      $result = $this->inTransaction(fn(): array => $this->writer->reproject($nids));
      $this->retry($result['failed'], (int) ($data['attempt'] ?? 0));
      return $result['projected'];
    }
    if (in_array($op, ['organisation', 'jurisdiction', 'term'], TRUE)) {
      $nids = $this->referencingIds($op, (int) ($data['id'] ?? 0));
      $this->enqueueNids($nids);
      return 0;
    }
    $this->logger->warning('Dropped request index queue item with unknown operation @op.', ['@op' => $op]);
    return 0;
  }

  /**
   * Projects one node ID range and removes orphan rows in it.
   */
  private function processRange(int $from, int $to, bool $final, string $runId): int {
    $nids = $this->serviceRequestIds($from, $to);
    $result = $this->inTransaction(function () use ($from, $to, $nids): array {
      $result = $this->writer->reproject($nids);
      $this->writer->deleteOrphans($from, $to, $nids);
      return $result;
    });
    $this->retry($result['failed'], 0);
    if ($final) {
      $this->markFinished($runId);
    }
    return $result['projected'];
  }

  /**
   * Marks failed projections stale and queues them again until MAX_ATTEMPTS.
   *
   * A request stays marked after the last attempt, so the dashboard list does
   * not read the index until a later save or rebuild projects it.
   *
   * @param array<int, string> $failed
   *   Failure descriptions keyed by node ID.
   * @param int $attempt
   *   Failed attempts before this one.
   */
  private function retry(array $failed, int $attempt): void {
    if ($failed === []) {
      return;
    }
    $this->failed += count($failed);
    $this->staleSet->add(array_keys($failed));
    $attempt++;
    foreach ($failed as $nid => $failure) {
      $this->logger->error('Request index projection of node @nid failed (attempt @attempt of @max): @failure', [
        '@nid' => $nid,
        '@attempt' => $attempt,
        '@max' => self::MAX_ATTEMPTS,
        '@failure' => $failure,
      ]);
    }
    if ($attempt >= self::MAX_ATTEMPTS) {
      $this->logger->error('Gave up projecting nodes @nids after @max attempts; they stay marked stale and the dashboard list does not read the index until the next save or drush mas:request-index:rebuild projects them. drush mas:request-index:verify lists them.', [
        '@nids' => implode(', ', array_keys($failed)),
        '@max' => self::MAX_ATTEMPTS,
      ]);
      return;
    }
    $this->enqueueNids(array_keys($failed), $attempt);
  }

  /**
   * Finds the requests that reference a group or a term.
   *
   * Reads the source field tables and the index, so requests that stopped
   * referencing the group are re-projected as well.
   *
   * @return list<int>
   *   Node IDs.
   */
  private function referencingIds(string $kind, int $id): array {
    if ($id <= 0) {
      return [];
    }
    $nids = [];
    if ($kind === 'organisation') {
      $nids = $this->fieldReferenceIds('field_organisation', [$id]);
      $nids = array_merge($nids, $this->childIds('markaspot_request_index_org', [$id]));
    }
    elseif ($kind === 'jurisdiction') {
      $this->hierarchyResolver->resetCache();
      $subtree = array_values(array_map('intval', $this->hierarchyResolver->getDescendantIds($id)));
      $subtree = $subtree === [] ? [$id] : $subtree;
      $nids = $this->fieldReferenceIds('field_jurisdiction', $subtree);
      $nids = array_merge($nids, $this->childIds('markaspot_request_index_jur', $subtree));
      $root = $this->database->select(RequestIndexWriter::TABLE, 'i')
        ->fields('i', ['nid'])
        ->condition('i.root_gid', $subtree, 'IN');
      $nids = array_merge($nids, array_map('intval', $root->execute()->fetchCol()));
    }
    elseif ($kind === 'term') {
      $this->projector->reset();
      $query = $this->database->select(RequestIndexWriter::TABLE, 'i')->fields('i', ['nid']);
      $query->condition($query->orConditionGroup()
        ->condition('i.status_tid', $id)
        ->condition('i.category_tid', $id));
      $nids = array_map('intval', $query->execute()->fetchCol());
    }
    $nids = array_values(array_unique($nids));
    sort($nids);
    return $nids;
  }

  /**
   * Returns service request IDs whose reference field targets the given IDs.
   *
   * @param string $field
   *   Entity reference field on service_request.
   * @param list<int> $targetIds
   *   Target IDs.
   *
   * @return list<int>
   *   Node IDs.
   */
  private function fieldReferenceIds(string $field, array $targetIds): array {
    if ($targetIds === [] || !$this->projector->hasSourceField($field)) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', RequestIndexProjector::BUNDLE)
      ->condition($field, $targetIds, 'IN')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Returns node IDs of child table rows for the given group IDs.
   *
   * @param string $table
   *   Child table.
   * @param list<int> $gids
   *   Group IDs.
   *
   * @return list<int>
   *   Node IDs.
   */
  private function childIds(string $table, array $gids): array {
    return array_values(array_map('intval', $this->database->select($table, 'c')
      ->fields('c', ['nid'])
      ->condition('c.gid', $gids, 'IN')
      ->execute()
      ->fetchCol()));
  }

  /**
   * Marks the current run finished.
   *
   * @param string|null $runId
   *   Only this run, or the current run when NULL.
   */
  private function markFinished(?string $runId): void {
    $run = $this->state->get(self::STATE_RUN);
    if (!is_array($run) || !empty($run['finished'])) {
      return;
    }
    if ($runId !== NULL && ($run['id'] ?? NULL) !== $runId) {
      return;
    }
    $run['finished'] = $this->time->getCurrentTime();
    $this->state->set(self::STATE_RUN, $run);
    $this->reader->resetParity();
  }

  /**
   * Runs a write callback in one transaction.
   *
   * @param callable(): array{projected: int, failed: array<int, string>} $callback
   *   The callback.
   *
   * @return array{projected: int, failed: array<int, string>}
   *   The callback's result.
   */
  private function inTransaction(callable $callback): array {
    $transaction = $this->database->startTransaction();
    try {
      return $callback();
    }
    catch (\Throwable $throwable) {
      $transaction->rollBack();
      throw $throwable;
    }
  }

  /**
   * Returns the reliable queue.
   */
  private function queue(): QueueInterface {
    return $this->queueFactory->get(self::QUEUE, TRUE);
  }

  /**
   * Returns the node base table name.
   */
  private function nodeBaseTable(): string {
    $storage = $this->entityTypeManager->getStorage('node');
    if ($storage instanceof SqlEntityStorageInterface) {
      return (string) $storage->getTableMapping()->getBaseTable();
    }
    return 'node';
  }

}
