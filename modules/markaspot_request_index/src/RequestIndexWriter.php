<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Writes request index records.
 *
 * Called from entity hooks, so every write joins the node save transaction;
 * the writer's own transaction becomes a savepoint there. Never saves a node.
 * A successful write or removal of a request's row takes the request out of
 * the stale set.
 */
final class RequestIndexWriter {

  /**
   * The row table.
   */
  public const TABLE = 'markaspot_request_index';

  /**
   * Child tables keyed by the record property they hold.
   */
  public const CHILD_TABLES = [
    'organisations' => 'markaspot_request_index_org',
    'jurisdictions' => 'markaspot_request_index_jur',
  ];

  /**
   * Constructs the writer.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestIndexProjector $projector,
    private readonly TimeInterface $time,
    private readonly RequestIndexStaleSet $staleSet,
  ) {}

  /**
   * Inserts or replaces the row and the child rows of one record.
   */
  public function upsert(RequestIndexRecord $record): void {
    if ($record->nid <= 0) {
      return;
    }
    $transaction = $this->database->startTransaction();
    try {
      $fields = ['nid' => $record->nid] + $record->row + ['projected' => $this->time->getCurrentTime()];
      $this->database->upsert(self::TABLE)
        ->key('nid')
        ->fields(array_keys($fields))
        ->values($fields)
        ->execute();
      foreach (self::CHILD_TABLES as $property => $table) {
        $this->database->delete($table)->condition('nid', $record->nid)->execute();
        if ($record->{$property} === []) {
          continue;
        }
        $insert = $this->database->insert($table)->fields(['nid', 'gid']);
        foreach ($record->{$property} as $gid) {
          $insert->values(['nid' => $record->nid, 'gid' => $gid]);
        }
        $insert->execute();
      }
    }
    catch (\Throwable $throwable) {
      $transaction->rollBack();
      throw $throwable;
    }
  }

  /**
   * Removes the rows of the given requests from all index tables.
   *
   * @param list<int> $nids
   *   Node IDs.
   */
  public function delete(array $nids): void {
    if ($nids === []) {
      return;
    }
    foreach ([self::TABLE, ...array_values(self::CHILD_TABLES)] as $table) {
      $this->database->delete($table)->condition('nid', $nids, 'IN')->execute();
    }
    $this->staleSet->remove($nids);
  }

  /**
   * Re-projects requests by ID from their stored state.
   *
   * Static entity caches are reset before and after the load, so the record
   * is built from what the database holds, never from an entity object that
   * a hook changed after the write, and a deleted request is never
   * resurrected from a cached instance. Requests that no longer exist or are
   * not service requests lose their rows. A request whose projection fails
   * keeps its previous row and is reported, so one broken request never
   * blocks the others; the caller marks it stale. Projected requests leave
   * the stale set.
   *
   * @param list<int> $nids
   *   Node IDs.
   *
   * @return array{projected: int, failed: array<int, string>}
   *   Number of projected rows, and per node ID that failed a description of
   *   the failure without field values.
   */
  public function reproject(array $nids): array {
    $nids = array_values(array_unique(array_filter(array_map('intval', $nids), static fn(int $nid): bool => $nid > 0)));
    $result = ['projected' => 0, 'failed' => []];
    if ($nids === []) {
      return $result;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache($nids);
    $nodes = $storage->loadMultiple($nids);
    $gone = [];
    $projected = [];
    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if (!$node instanceof NodeInterface || !$this->projector->applies($node)) {
        $gone[] = $nid;
        continue;
      }
      try {
        $this->upsert($this->projector->build($node));
        $projected[] = $nid;
      }
      catch (\Throwable $throwable) {
        $result['failed'][$nid] = self::describeFailure($throwable);
      }
    }
    $result['projected'] = count($projected);
    $this->delete($gone);
    $this->staleSet->remove($projected);
    $storage->resetCache($nids);
    return $result;
  }

  /**
   * Describes a failed write without the values it tried to write.
   *
   * The message of a database exception wrapper carries the query and its
   * arguments: the title and address of the request. Only the exception
   * class, SQLSTATE and driver error code are kept; other throwables are
   * named by class and location.
   */
  public static function describeFailure(\Throwable $throwable): string {
    if ($throwable instanceof DatabaseException) {
      $previous = $throwable->getPrevious();
      $info = $previous instanceof \PDOException ? ($previous->errorInfo ?? []) : [];
      return sprintf(
        '%s (SQLSTATE %s, driver error %s)',
        $throwable::class,
        $info[0] ?? ($previous?->getCode() ?: '-'),
        $info[1] ?? '-',
      );
    }
    return sprintf('%s at %s:%d', $throwable::class, basename($throwable->getFile()), $throwable->getLine());
  }

  /**
   * Removes rows in a node ID range that do not belong to the given IDs.
   *
   * @param int $from
   *   Exclusive lower bound.
   * @param int $to
   *   Inclusive upper bound.
   * @param list<int> $keep
   *   Node IDs of existing service requests in the range.
   *
   * @return int
   *   Number of removed orphan rows.
   */
  public function deleteOrphans(int $from, int $to, array $keep): int {
    $query = $this->database->select(self::TABLE, 'i')
      ->fields('i', ['nid'])
      ->condition('i.nid', $from, '>')
      ->condition('i.nid', $to, '<=');
    if ($keep !== []) {
      $query->condition('i.nid', $keep, 'NOT IN');
    }
    $orphans = array_map('intval', $query->execute()->fetchCol());
    // Child rows can exist without a row after a partial manual cleanup.
    foreach (self::CHILD_TABLES as $table) {
      $child = $this->database->select($table, 'c')
        ->fields('c', ['nid'])
        ->condition('c.nid', $from, '>')
        ->condition('c.nid', $to, '<=');
      if ($keep !== []) {
        $child->condition('c.nid', $keep, 'NOT IN');
      }
      $orphans = array_merge($orphans, array_map('intval', $child->execute()->fetchCol()));
    }
    $orphans = array_values(array_unique($orphans));
    $this->delete($orphans);
    // A request that failed and was deleted before a retry may have left no
    // row behind; in this range it is gone either way.
    $this->staleSet->remove(array_filter(
      $this->staleSet->ids(),
      static fn(int $nid): bool => $nid > $from && $nid <= $to && !in_array($nid, $keep, TRUE),
    ));
    return count($orphans);
  }

}
