<?php

declare(strict_types=1);

namespace Drupal\service_request\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\node\Entity\Node;
use Drupal\service_request\RevisionPolicy;

/**
 * Report bundle class that preserves composite field history on every save.
 */
class ServiceRequest extends Node {

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    RevisionPolicy::prepare($this);
    parent::preSave($storage);
  }

}
