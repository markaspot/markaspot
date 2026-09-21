<?php

namespace Drupal\markaspot_boilerplate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\markaspot_boilerplate\Access\BoilerplateAccess;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
    if (!$node instanceof NodeInterface || $node->bundle() !== 'boilerplate') {
      throw new NotFoundHttpException();
    }
    if (!$node->isPublished()) {
      throw new AccessDeniedHttpException();
    }
    $access = BoilerplateAccess::view($node, $this->currentUser())
      ->andIf($node->access('view', $this->currentUser(), TRUE));
    if (!$access->isAllowed()) {
      throw new AccessDeniedHttpException();
    }
    $response = new CacheableJsonResponse($node->get('body')->value);
    $response->addCacheableDependency($access);
    return $response;
  }

}
