<?php

namespace Drupal\markaspot_publisher;

/**
 * Interface for the Publisher service.
 */
interface PublisherServiceInterface {

  /**
   * Returns nodes eligible for publishing.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Returns an array of nodes that should be published.
   */
  public function load();

}
