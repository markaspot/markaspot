<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Field;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\node\NodeInterface;

/**
 * Computes access-filtered dashboard status-note summaries.
 */
final class DashboardStatusNotesItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    $entity = $this->getEntity();
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'service_request') {
      return;
    }

    $items = DashboardReferenceData::statusNotes($entity, \Drupal::currentUser());
    if ($items === []) {
      return;
    }

    $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($json)) {
      $this->list[0] = $this->createItem(0, $json);
    }
  }

}
