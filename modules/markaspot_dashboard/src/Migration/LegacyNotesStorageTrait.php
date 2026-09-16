<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Migration;

use Drupal\Core\Entity\ContentEntityBase;

/**
 * Offline migration storage; deliberately NOT an entity storage handler.
 *
 * Ordinary save() runs business hooks, ECA, field callbacks and node grants.
 * This narrowly scoped writer uses Core's SQL mapping and revision allocation
 * only. The caller owns transactions, exclusive access, invariants and caches.
 * Do not register this class in the container or use it for online edits.
 *
 * @internal
 */
trait LegacyNotesStorageTrait {

  /**
   * Writes a prepared new remark or a new default service request revision.
   */
  public function writeSnapshot(ContentEntityBase $entity): void {
    $supported = ($entity->getEntityTypeId() === 'node'
        && $entity->bundle() === 'service_request' && !$entity->isNew())
      || ($entity->getEntityTypeId() === 'paragraph'
        && $entity->bundle() === 'internal_remark' && $entity->isNew());
    if (!$supported || !$entity->isNewRevision() || !$entity->isDefaultRevision()) {
      throw new \LogicException('Unsupported legacy-notes migration snapshot.');
    }
    $entity->updateOriginalValues();
    $this->doSave($entity->id(), $entity);
    $entity->enforceIsNew(FALSE);
    $entity->updateLoadedRevisionId();
    $entity->setNewRevision(FALSE);
  }

}
