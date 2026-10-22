<?php

declare(strict_types=1);

namespace Drupal\service_request\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\node\NodeInterface;
use Drupal\service_request\RevisionLog;

/**
 * Captures the final domain state with explicit Drupal 11 hook ordering.
 */
final class RevisionHooks {

  /**
   * Builds action metadata after the other domain presave hooks.
   */
  #[Hook('entity_presave', order: Order::Last)]
  public function entityPresave(EntityInterface $entity): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'service_request') {
      return;
    }
    $entity->setRevisionLogMessage(RevisionLog::build($entity, [
      'field_organisation' => 'responsibility', 'field_assignee' => 'person',
      'field_assigned_team' => 'team', 'field_status' => 'status',
    ]));
  }

}
