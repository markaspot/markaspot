<?php

namespace Drupal\markaspot_boilerplate\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Class AjaxCommand.
 */
class AjaxCommand implements CommandInterface {

  /**
   * Render custom ajax command.
   *
   * @return array
   *   Command function.
   */
  public function render() {
    return [
      'command' => 'getNode',
      'message' => $this->load($nid),
    ];
  }

  /**
   * Render custom ajax command.
   *
   * @return ajax
   *   Command function.
   */
  public function load($nid) {

    // Query for some entities with the entity query service.
    $query = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('nid', $nid);
    $nids = $query->execute();
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nids[0]);
    return $node->body->value;
  }
}
