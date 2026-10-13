<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Privacy;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\node\NodeInterface;

/**
 * Publication hold for reports whose personal-name screening failed.
 *
 * When name detection is enabled but the screening service fails, the report
 * is saved unpublished and recorded here. Staff review the description and
 * publish it themselves; publishing releases the hold. Automatic publication
 * (markaspot_publisher) skips held reports, so an outage can never make an
 * unscreened text public by itself.
 *
 * Entries are keyed by node UUID because the hold is placed in presave,
 * before a new node has an ID; hook_node_insert() adds the ID.
 */
final class PiiScreeningHold {

  /**
   * Key/value collection holding the active holds.
   */
  public const COLLECTION = 'markaspot_ai.pii_screening_hold';

  /**
   * Records a hold for a report that is about to be saved.
   */
  public static function place(NodeInterface $node, string $reason): void {
    self::store()->set($node->uuid(), [
      'nid' => $node->isNew() ? NULL : (int) $node->id(),
      'reason' => $reason,
      'created' => \Drupal::time()->getRequestTime(),
    ]);
  }

  /**
   * Adds the node ID to a hold placed before the first save.
   */
  public static function bindId(NodeInterface $node): void {
    $hold = self::store()->get($node->uuid());
    if (is_array($hold) && empty($hold['nid'])) {
      $hold['nid'] = (int) $node->id();
      self::store()->set($node->uuid(), $hold);
    }
  }

  /**
   * Whether a report is held.
   */
  public static function isHeld(NodeInterface $node): bool {
    return self::store()->has($node->uuid());
  }

  /**
   * Returns the hold record of a report, if any.
   *
   * @return array{nid: int|null, reason: string, created: int}|null
   *   The hold record.
   */
  public static function get(NodeInterface $node): ?array {
    $hold = self::store()->get($node->uuid());
    return is_array($hold) ? $hold : NULL;
  }

  /**
   * Removes the hold of a report.
   */
  public static function release(NodeInterface $node): void {
    self::store()->delete($node->uuid());
  }

  /**
   * IDs of all held reports.
   *
   * @return int[]
   *   Node IDs.
   */
  public static function heldIds(): array {
    $ids = [];
    foreach (self::store()->getAll() as $hold) {
      if (is_array($hold) && !empty($hold['nid'])) {
        $ids[] = (int) $hold['nid'];
      }
    }
    return $ids;
  }

  /**
   * The key/value store of active holds.
   */
  private static function store(): KeyValueStoreInterface {
    return \Drupal::keyValue(self::COLLECTION);
  }

}
