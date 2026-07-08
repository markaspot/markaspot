<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Field;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Computes the display label for a service request assignee.
 */
final class AssigneeLabelItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    $entity = $this->getEntity();
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'service_request') {
      return;
    }

    if (!$entity->hasField('field_assignee') || $entity->get('field_assignee')->isEmpty()) {
      $this->setTeamLabel($entity);
      return;
    }

    $assignee = $entity->get('field_assignee')->entity;
    if (!$assignee instanceof UserInterface) {
      $this->setTeamLabel($entity);
      return;
    }

    $label = $assignee->getDisplayName();
    if ($label === '') {
      return;
    }

    $this->list[0] = $this->createItem(0, $label);
  }

  /**
   * Sets the assigned team label when present.
   */
  private function setTeamLabel(NodeInterface $entity): void {
    if (!$entity->hasField('field_assigned_team') || $entity->get('field_assigned_team')->isEmpty()) {
      return;
    }

    $team = $entity->get('field_assigned_team')->entity;
    if (!$team instanceof GroupInterface) {
      return;
    }

    $label = (string) $team->label();
    if ($label === '') {
      return;
    }

    $this->list[0] = $this->createItem(0, $label);
  }

}
