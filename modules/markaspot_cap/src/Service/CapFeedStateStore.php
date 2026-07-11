<?php

declare(strict_types=1);

namespace Drupal\markaspot_cap\Service;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Stores monotonic CAP feed timestamps under a row-level transaction lock.
 */
final class CapFeedStateStore implements CapFeedStateStoreInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly SerializationInterface $serializer,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function advance(string $key, int $requestTime): int {
    // Materialize the row before locking it. When an entity transaction is
    // active, both this insert and the following row lock remain held until
    // that entity transaction commits or rolls back.
    $this->database->merge('key_value')
      ->insertFields([
        'collection' => 'state',
        'name' => $key,
        'value' => $this->serializer->encode(0),
      ])
      ->condition('collection', 'state')
      ->condition('name', $key)
      ->execute();

    $encoded = $this->database->select('key_value', 'kv')
      ->fields('kv', ['value'])
      ->condition('collection', 'state')
      ->condition('name', $key)
      ->forUpdate()
      ->execute()
      ->fetchField();
    if (!is_string($encoded)) {
      throw new \RuntimeException('CAP feed timestamp State is unreadable.');
    }

    try {
      $previous = $this->serializer->decode($encoded);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException('CAP feed timestamp State is unreadable.', 0, $exception);
    }
    if (!is_int($previous) || $previous < 0) {
      throw new \RuntimeException('CAP feed timestamp State is invalid.');
    }

    $next = max($requestTime, $previous + 1);
    // State::set() writes through the same connection and refreshes Drupal's
    // request cache with the transaction-locked value.
    $this->state->set($key, $next);
    return $next;
  }

}
