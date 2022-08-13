<?php

namespace Drupal\markaspot_boilerplate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class LoadController.
 *
 *  Loads Json Response to reflect body into the textarea.
 */
class LoadController extends ControllerBase {

  /**
   * The storage handler class for nodes.
   *
   * @var \Drupal\node\NodeStorage
   */
  private $nodeStorage;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity
   *   The Entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity) {
    $this->nodeStorage = $entity->getStorage('node');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Load.
   *
   * @return object
   *   Return body.
   */
  public function load($nid) {
    // Query for some entities with the entity query service.
    $node = $this->nodeStorage->load($nid);
    // $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    return new JsonResponse($node->body->value);
  }

}
