<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Remembers requests whose index row may be stale.
 *
 * A request lands here whenever its projection or removal failed: in the
 * node hooks (RequestIndexHooks::guard()) and in every failed queue attempt
 * (RequestIndexRebuilder::retry()), including the last one after which the
 * retry is dropped. Only a successful write of the request's row, or its
 * removal, takes it out again (RequestIndexWriter). While the set is not
 * empty, RequestIndexReader::isUsable() is FALSE.
 *
 * One key per node ID in a key/value collection: concurrent failures write
 * different keys, so no request can get lost in a read-modify-write race.
 */
final class RequestIndexStaleSet {

  /**
   * Key/value collection holding one key per stale node ID.
   */
  public const COLLECTION = 'markaspot_request_index.stale';

  /**
   * Constructs the stale set.
   */
  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {}

  /**
   * Returns the stale node IDs in ascending order.
   *
   * @return list<int>
   *   Node IDs.
   */
  public function ids(): array {
    $ids = array_map('intval', array_keys($this->store()->getAll()));
    sort($ids);
    return $ids;
  }

  /**
   * Marks requests as stale.
   *
   * @param array<int|string> $nids
   *   Node IDs.
   */
  public function add(array $nids): void {
    $nids = self::normalize($nids);
    if ($nids !== []) {
      $this->store()->setMultiple(array_fill_keys($nids, TRUE));
    }
  }

  /**
   * Unmarks requests; writes only when one of them is marked.
   *
   * Called on every successful projection, mostly inside the node save
   * transaction. Reading first keeps that common path free of writes.
   *
   * @param array<int|string> $nids
   *   Node IDs.
   */
  public function remove(array $nids): void {
    $nids = self::normalize($nids);
    if ($nids === []) {
      return;
    }
    $marked = array_keys($this->store()->getMultiple(array_map('strval', $nids)));
    if ($marked !== []) {
      $this->store()->deleteMultiple($marked);
    }
  }

  /**
   * Unmarks every request.
   */
  public function clear(): void {
    $this->store()->deleteAll();
  }

  /**
   * Returns distinct positive node IDs.
   *
   * @param array<int|string> $nids
   *   Raw node IDs.
   *
   * @return list<int>
   *   Node IDs.
   */
  private static function normalize(array $nids): array {
    return array_values(array_unique(array_filter(array_map('intval', $nids), static fn(int $nid): bool => $nid > 0)));
  }

  /**
   * Returns the key/value store.
   */
  private function store(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
