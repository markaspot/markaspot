<?php

declare(strict_types=1);

namespace Drupal\markaspot_cap\Service;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Marks CAP feeds affected by a content entity mutation.
 */
interface CapFeedMutationTrackerInterface {

  /**
   * Records the current and optional original entity feed scopes as changed.
   */
  public function markEntityChanged(
    ContentEntityInterface $entity,
    ?ContentEntityInterface $original = NULL,
  ): void;

}
