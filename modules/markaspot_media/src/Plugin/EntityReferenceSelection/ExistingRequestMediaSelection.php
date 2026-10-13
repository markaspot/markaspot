<?php

namespace Drupal\markaspot_media\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\Plugin\EntityReferenceSelection\MediaSelection;
use Drupal\node\NodeInterface;

/**
 * Retains authorized, existing unpublished images during request editing.
 *
 * Core's default media selector filters unpublished images even if entity view
 * is allowed. Keep its autocomplete and new-reference rules intact; only accept
 * an existing reference on its persisted parent when staff may still edit it.
 */
#[EntityReferenceSelection(
  id: 'existing_request_media:media',
  label: new TranslatableMarkup('Existing request media'),
  entity_types: ['media'],
  group: 'existing_request_media',
  weight: 0,
)]
class ExistingRequestMediaSelection extends MediaSelection {

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    $valid = parent::validateReferenceableEntities($ids);
    $configuration = $this->getConfiguration();
    $entity = $configuration['entity'] ?? NULL;
    $bundles = $configuration['target_bundles'];
    if (is_array($bundles) && !in_array('request_image', $bundles, TRUE)) {
      return $valid;
    }
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'service_request' || $entity->isNew() || $this->currentUser->isAnonymous()) {
      return $valid;
    }
    $parent = $this->entityTypeManager->getStorage('node')->loadUnchanged($entity->id());
    if (!$parent instanceof NodeInterface || !$parent->hasField('field_request_media') || !$parent->access('view', $this->currentUser) || !$parent->access('update', $this->currentUser) || !$parent->get('field_request_media')->access('view', $this->currentUser)) {
      return $valid;
    }
    $existing = array_column($parent->get('field_request_media')->getValue(), 'target_id');
    $candidates = array_intersect(array_diff($ids, $valid), $existing);
    foreach ($this->entityTypeManager->getStorage('media')->loadMultiple($candidates) as $media) {
      if ($media->bundle() === 'request_image' && !$media->isPublished() && $media->access('view', $this->currentUser)) {
        $valid[] = $media->id();
      }
    }
    return $valid;
  }

}
