<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Changes a saved service request in memory after the storage write.
 *
 * Runs after markaspot_group, whose insert hook saves the request again, so
 * nothing persists the change; the request index hooks run after this one.
 */
final class RequestIndexTestHooks {

  /**
   * State key: "insert" or "update" names the hook that changes the request.
   */
  public const STATE_KEY = 'markaspot_request_index_test.mutate_after_write';

  /**
   * The facility value that only ever exists in memory.
   */
  public const FACILITY = 'memory-only-facility';

  /**
   * The title that only ever exists in memory.
   */
  public const TITLE = 'Memory only title';

  /**
   * Constructs the test hooks.
   */
  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Changes a new request after its insert.
   */
  #[Hook('node_insert', order: new OrderAfter(modules: ['markaspot_group']))]
  public function nodeInsert(NodeInterface $node): void {
    $this->mutate($node, 'insert');
  }

  /**
   * Changes an updated request after its update.
   */
  #[Hook('node_update', order: new OrderAfter(modules: ['markaspot_group']))]
  public function nodeUpdate(NodeInterface $node): void {
    $this->mutate($node, 'update');
  }

  /**
   * Sets projected values on the entity object without saving it.
   */
  private function mutate(NodeInterface $node, string $operation): void {
    if ($node->bundle() !== 'service_request' || $this->state->get(self::STATE_KEY) !== $operation) {
      return;
    }
    $node->set('field_facility', self::FACILITY);
    $node->setTitle(self::TITLE);
  }

}
