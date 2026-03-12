<?php

declare(strict_types=1);

namespace Drupal\markaspot_media\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Exception\UnsupportedEntityTypeDefinitionException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\Plugin\EntityReferenceSelection\MediaSelection;

/**
 * Entity reference selection for request media that allows unpublished items.
 *
 * In the Mark-a-Spot workflow, media entities of type 'request_image' are
 * created as unpublished by design (privacy by design, fail-closed). AI
 * screening must complete before media is published. This selection handler
 * allows entity references to unpublished 'request_image' media during node
 * creation, which would otherwise be blocked by core's MediaSelection that
 * requires status=1 for non-admin users.
 *
 * The actual access control for viewing media remains unchanged: unpublished
 * media is not displayed to users without the appropriate permission.
 *
 * @see _markaspot_vision_publish_safe_media()
 */
#[EntityReferenceSelection(
  id: 'request_media:media',
  label: new TranslatableMarkup('Request Media selection (allows unpublished)'),
  entity_types: ['media'],
  group: 'request_media',
  weight: 0,
)]
class RequestMediaSelection extends MediaSelection {

  /**
   * {@inheritdoc}
   *
   * Builds the entity query without the published-only restriction.
   *
   * Core's MediaSelection adds condition('status', 1) for non-admin users,
   * which blocks references to unpublished media pending AI screening.
   * This override reproduces DefaultSelection::buildEntityQuery() without
   * that restriction, since we intentionally allow referencing unpublished
   * request_image media in the moderation workflow.
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $configuration = $this->getConfiguration();
    $target_type = $configuration['target_type'];
    $entity_type = $this->entityTypeManager->getDefinition($target_type);

    $query = $this->entityTypeManager->getStorage($target_type)->getQuery();
    // Keep access check enabled for general entity-level access control.
    $query->accessCheck(TRUE);

    // Bundle filtering (from DefaultSelection).
    if (is_array($configuration['target_bundles'])) {
      if ($configuration['target_bundles'] === []) {
        $query->condition($entity_type->getKey('id'), NULL, '=');
        return $query;
      }
      elseif ($entity_type->hasKey('bundle')) {
        $query->condition($entity_type->getKey('bundle'), $configuration['target_bundles'], 'IN');
      }
      else {
        throw new UnsupportedEntityTypeDefinitionException(\sprintf(
          "Trying to use non-empty 'target_bundle' configuration on entity type '%s' without bundle support.",
          $entity_type->id(),
        ));
      }
    }

    if (isset($match) && $label_key = $entity_type->getKey('label')) {
      $query->condition($label_key, $match, $match_operator);
    }

    // Add entity-access tag.
    $query->addTag($target_type . '_access');

    // Add the Selection handler for system_query_entity_reference_alter().
    $query->addTag('entity_reference');
    $query->addMetaData('entity_reference_selection_handler', $this);

    // Add the sort option.
    if ($configuration['sort']['field'] !== '_none') {
      $query->sort($configuration['sort']['field'], $configuration['sort']['direction']);
    }

    // Intentionally NOT adding condition('status', 1). Unpublished
    // request_image media must be referenceable during the AI screening
    // workflow. Display-level access control prevents unauthorized viewing.
    return $query;
  }

  /**
   * {@inheritdoc}
   *
   * Allows unpublished new entities to be referenced.
   */
  public function validateReferenceableNewEntities(array $entities) {
    // Only check bundle validity, not published status.
    return array_filter($entities, function ($entity) {
      $target_bundles = $this->getConfiguration()['target_bundles'];
      if (isset($target_bundles)) {
        return in_array($entity->bundle(), $target_bundles);
      }
      return TRUE;
    });
  }

}
