<?php

namespace Drupal\markaspot_boilerplate\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    $node = $this->nodeStorage->load($nid);
    if (!$node || $node->bundle() !== 'boilerplate') {
      throw new NotFoundHttpException();
    }

    $access = $node->access('view', NULL, TRUE);
    if (!$access->isAllowed()) {
      throw new NotFoundHttpException();
    }

    $body_field = $node->get('body');
    $field_access = $body_field->access('view', NULL, TRUE);
    if (!$field_access->isAllowed()) {
      throw new NotFoundHttpException();
    }

    $body = $body_field->getValue();
    $response = new CacheableJsonResponse($body[0]['value'] ?? '');
    $response->addCacheableDependency($node);
    $response->addCacheableDependency($access);
    $response->addCacheableDependency($field_access);
    return $response;
  }

}
