<?php

namespace Drupal\markaspot_boilerplate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;


/**
 * Class LoadController.
 */
class LoadController extends ControllerBase {

  /**
   * Load.
   *
   * @return string
   *   Return Hello string.
   */
  public function load($nid) {
    // Query for some entities with the entity query service.
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    return new JsonResponse($node->body->value);
  }



}
