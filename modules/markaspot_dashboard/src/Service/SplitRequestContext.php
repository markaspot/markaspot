<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\node\NodeInterface;

/**
 * Identifies the exact child being saved by an internal split operation.
 *
 * This request-local context cannot be supplied by a REST payload. Splitting
 * an existing report is internal processing, not a new citizen submission.
 * It must preserve existing consent values rather than fabricate consent.
 */
final class SplitRequestContext {

  /**
   * Child entities currently inside their initial save.
   *
   * @var \WeakMap<\Drupal\node\NodeInterface, bool>
   */
  private \WeakMap $children;

  /**
   * Constructs the request-local context.
   */
  public function __construct() {
    $this->children = new \WeakMap();
  }

  /**
   * Saves a split child without treating it as a new citizen submission.
   */
  public function saveChild(NodeInterface $child): void {
    $this->children[$child] = TRUE;
    try {
      $child->save();
    }
    finally {
      unset($this->children[$child]);
    }
  }

  /**
   * Checks object identity, not client-controlled entity fields or IDs.
   */
  public function isSavingChild(NodeInterface $node): bool {
    return isset($this->children[$node]);
  }

}
