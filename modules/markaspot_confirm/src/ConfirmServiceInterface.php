<?php

namespace Drupal\markaspot_confirm;

/**
 * Interface ConfirmServiceInterface.
 */
interface ConfirmServiceInterface {

  /**
   * Load entities by UUID.
   *
   * @param string $uuid
   *   The UUID to search for.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Array of entities.
   */
  public function load($uuid);

  /**
   * Generate a confirmation URL for a given UUID.
   *
   * @param string $uuid
   *   The UUID of the service request.
   *
   * @return string
   *   The confirmation URL.
   */
  public function generateConfirmationUrl($uuid);

}
