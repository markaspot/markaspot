<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Tells readers of the request index whether they may rely on it.
 *
 * The staff dashboard list (markaspot_nuxt) resolves this service optionally
 * and takes its candidates from the index only while isUsable() is TRUE;
 * otherwise it uses the slower entity query path.
 *
 * Every path in this module that can leave a row missing or stale keeps the
 * index unusable until a later write repairs it:
 * - a failed write in a node hook marks the request stale and queues it;
 * - every failed queue attempt marks the request stale, also the last one
 *   after which the retry is dropped;
 * - a full rebuild, a term change and a hierarchy change queue items, and an
 *   item stays in the queue until it was processed.
 * Those checks run live on every call. Row parity guards against writes
 * outside the entity API, such as manual SQL, and against translated service
 * requests: it requires one index row per service request and no translation
 * row of a service request, because the index holds the default translation
 * while the dashboard's access-checked query orders by the aggregate over
 * every translation row. Data rows without a node row (left behind by old
 * partial deletes) do not count: the entity query never reaches them.
 * On a tenant with 152k requests (MariaDB, warm) the statement took about
 * 230 ms: about 60 ms for the index count, 75 ms for the node count and
 * 85 ms for the translation count, which scans every data row whichever way
 * it is written (a node-driven join measured the same). The verdict is cached
 * for PARITY_TTL seconds and dropped whenever a full rebuild starts or
 * finishes. A failed read is cached as a FALSE verdict as well, so a broken
 * index logs once per PARITY_TTL instead of on every dashboard request.
 *
 * Not covered: a queue worker that loaded a request before a concurrent node
 * save committed writes its older projection after the hook's newer one
 * (last writer wins). Nothing marks that row; the next save of the request
 * or drush mas:request-index:verify repairs it.
 */
final class RequestIndexReader {

  /**
   * Cache ID of the row parity verdict.
   */
  public const PARITY_CID = 'markaspot_request_index:parity';

  /**
   * Seconds a row parity verdict stays cached.
   */
  public const PARITY_TTL = 60;

  /**
   * Constructs the reader.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly RequestIndexStaleSet $staleSet,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether the index holds one current row per service request.
   *
   * FALSE while a request is marked stale, while re-projections are queued
   * (through the queue API, so any queue backend counts), while row and
   * request counts differ or a request has a translation, or for PARITY_TTL
   * seconds after a failed
   * read. A database failure, such as a missing table, is logged without
   * values and makes the index unusable.
   */
  public function isUsable(): bool {
    try {
      if ($this->staleSet->ids() !== []) {
        return FALSE;
      }
      if ($this->queueFactory->get(RequestIndexRebuilder::QUEUE, TRUE)->numberOfItems() > 0) {
        return FALSE;
      }
      return $this->rowParity();
    }
    catch (\Throwable $throwable) {
      $this->reportReadFailure($throwable);
      return FALSE;
    }
  }

  /**
   * Counts service requests.
   */
  public function countServiceRequests(): int {
    return (int) $this->database->select($this->nodeBaseTable(), 'n')
      ->condition('n.type', RequestIndexProjector::BUNDLE)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts index rows.
   */
  public function countRows(): int {
    return (int) $this->database->select(RequestIndexWriter::TABLE, 'i')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Forgets the cached row parity verdict.
   */
  public function resetParity(): void {
    $this->cache->delete(self::PARITY_CID);
  }

  /**
   * Logs a failed index read without the values of the query.
   *
   * The caller falls back to the entity query path. The failure is cached as
   * a FALSE parity verdict, so the index stays unusable and silent for
   * PARITY_TTL seconds instead of failing and logging on every request.
   */
  public function reportReadFailure(\Throwable $throwable): void {
    $this->logger->error('Reading the request index failed; the dashboard list fell back to the entity query: @failure', [
      '@failure' => RequestIndexWriter::describeFailure($throwable),
    ]);
    try {
      $this->cache->set(self::PARITY_CID, FALSE, $this->time->getRequestTime() + self::PARITY_TTL);
    }
    catch (\Throwable) {
      // The cache backend failed too: the next call reads and logs again.
    }
  }

  /**
   * Compares row and request counts and counts translations, cached.
   *
   * One statement counts from one snapshot: a request saved between two
   * separate counts would look like a missing row. A translation is a data
   * row that is not the default translation of an existing node.
   */
  private function rowParity(): bool {
    $cached = $this->cache->get(self::PARITY_CID);
    if ($cached !== FALSE) {
      return (bool) $cached->data;
    }
    $counts = $this->database->query(
      'SELECT (SELECT COUNT(*) FROM {' . RequestIndexWriter::TABLE . '}) AS indexed, (SELECT COUNT(*) FROM {' . $this->nodeBaseTable() . '} WHERE [type] = :bundle) AS requests, (SELECT COUNT(*) FROM {' . $this->nodeDataTable() . '} d WHERE [d].[type] = :data_bundle AND [d].[default_langcode] = 0 AND EXISTS (SELECT 1 FROM {' . $this->nodeBaseTable() . '} n WHERE [n].[nid] = [d].[nid])) AS translations',
      [
        ':bundle' => RequestIndexProjector::BUNDLE,
        ':data_bundle' => RequestIndexProjector::BUNDLE,
      ],
    )->fetchAssoc();
    $parity = is_array($counts)
      && (int) $counts['indexed'] === (int) $counts['requests']
      && (int) $counts['translations'] === 0;
    $this->cache->set(self::PARITY_CID, $parity, $this->time->getRequestTime() + self::PARITY_TTL);
    return $parity;
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

  /**
   * Returns the node data table name: one row per translation.
   */
  private function nodeDataTable(): string {
    $storage = $this->entityTypeManager->getStorage('node');
    $mapping = $storage instanceof SqlEntityStorageInterface ? $storage->getTableMapping() : NULL;
    if ($mapping instanceof DefaultTableMapping) {
      return (string) ($mapping->getDataTable() ?? $mapping->getBaseTable());
    }
    return 'node_field_data';
  }

}
