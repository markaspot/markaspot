<?php

namespace Drupal\markaspot_resubmission;

/**
 * Interface ResubmissionServiceInterface provides a service interface.
 */
interface ResubmissionServiceInterface {

  /**
   * Load nodes by status.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of entity objects indexed by their IDs.
   */
  public function load(): array;

}
