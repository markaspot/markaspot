<?php

namespace Drupal\markaspot_shstweak\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 *  Controller for SHS Tweak module
 */
class SHSTweakController extends ControllerBase {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return \Drupal\markaspot_shstweak\Controller\SHSTweakController|static
   */
  public static function create(ContainerInterface $container) {
    $entityTypeManager = $container->get('entity_type.manager');

    return new static($entityTypeManager);
  }

  /**
   * getting taxonomy term description for given Term
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   * @param int $last_child
   *   if true only terms with no children will return description
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function taxonomyDescription(Term $term, int $last_child = 0) {
    $description = $term->getDescription();
    if ($last_child === 1) {
      $childs = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadTree($term->get('vid')->target_id, $term->id(), 1);
      if (!empty($childs)) {
        $description = '';
      }
    }
    return new JsonResponse([
      'data' => $description,
      'method' => 'GET'
    ]);
  }
}
