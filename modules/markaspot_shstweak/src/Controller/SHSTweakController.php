<?php

namespace Drupal\markaspot_shstweak\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for SHS Tweak module.
 */
class SHSTweakController extends ControllerBase {

  /**
   * The Entity Type manager variable.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Entity Type manager service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Sets a new global container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container interface.
   *
   * @return \Drupal\markaspot_shstweak\Controller\SHSTweakController|static
   *   Get the term description.
   */
  public static function create(ContainerInterface $container) {
    $entityTypeManager = $container->get('entity_type.manager');

    return new static($entityTypeManager);
  }

  /**
   * Getting taxonomy term description for given term.
   *
   * @param string $term
   *   The term parameter from the route (UUID or ID).
   * @param int $last_child
   *   If true only terms with no children will return description.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Returns a JsonResponse.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function taxonomyDescription($term, int $last_child = 0) {
    // If we already have a Term entity, use it directly.
    if ($term instanceof Term) {
      $termEntity = $term;
    }
    else {
      $termEntity = NULL;

      // Check if this is a UUID (contains dashes)
      if (is_string($term) && strpos($term, '-') !== FALSE) {
        // Try to load the term by UUID.
        $terms = $this->entityTypeManager
          ->getStorage('taxonomy_term')
          ->loadByProperties(['uuid' => $term]);

        if (!empty($terms)) {
          $termEntity = reset($terms);
        }
      }

      // If not found by UUID, try numeric ID.
      if (!$termEntity && is_numeric($term)) {
        $termEntity = $this->entityTypeManager
          ->getStorage('taxonomy_term')
          ->load($term);
      }

      // If term is still not found, return error.
      if (!$termEntity) {
        return new JsonResponse([
          'data' => '',
          'error' => 'Term not found',
          'method' => 'GET',
        ], 404);
      }
    }

    // Get the description.
    $description = $termEntity->getDescription();

    // Handle the last_child parameter.
    if ($last_child === 1) {
      $childs = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadTree($termEntity->get('vid')->target_id, $termEntity->id(), 1);
      if (!empty($childs)) {
        $description = '';
      }
    }

    // Check if this term has the disable_form field and if it's true.
    $disableForm = FALSE;
    if ($termEntity->hasField('field_disable_form') && !$termEntity->get('field_disable_form')->isEmpty()) {
      $disableForm = (bool) $termEntity->get('field_disable_form')->value;
    }

    // For the API endpoint, return a JSON object with description and options.
    if (strpos(\Drupal::request()->getPathInfo(), '/api/markaspotshstweak/') === 0) {
      return new JsonResponse([
        'description' => $description,
        'options' => [
          'disableForm' => $disableForm,
        ],
      ]);
    }

    // For the original endpoint, keep the existing response format.
    return new JsonResponse([
      'data' => $description,
      'options' => [
        'disableForm' => $disableForm,
      ],
      'method' => 'GET',
    ]);
  }

}
