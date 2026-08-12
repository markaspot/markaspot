<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines a tenant-scoped facility category.
 *
 * @ContentEntityType(
 *   id = "markaspot_facility_category",
 *   label = @Translation("Facility category"),
 *   label_collection = @Translation("Facility categories"),
 *   base_table = "markaspot_facility_category",
 *   admin_permission = "administer markaspot facility",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "storage_schema" = "Drupal\markaspot_facility\FacilityCategoryStorageSchema",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "collection" = "/admin/mark-a-spot/facility-categories",
 *   },
 * )
 */
final class FacilityCategory extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $jurisdiction_id = (int) $this->get('jurisdiction_id')->target_id;
    $machine_name = trim((string) $this->get('machine_name')->value);
    if ($jurisdiction_id <= 0 || $machine_name === '') {
      return;
    }

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('jurisdiction_id', $jurisdiction_id)
      ->condition('machine_name', $machine_name);
    if (!$this->isNew()) {
      $query->condition('id', (int) $this->id(), '<>');
    }
    if ($query->count()->execute() > 0) {
      throw new EntityStorageException(sprintf(
        'Facility category key "%s" already exists for jurisdiction %d.',
        $machine_name,
        $jurisdiction_id,
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['jurisdiction_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Jurisdiction'))
      ->setDescription(t('The jurisdiction group this facility category belongs to.'))
      ->setSetting('target_type', 'group')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Machine key'))
      ->setDescription(t('Stable tenant-local facility category key.'))
      ->setSetting('max_length', 128)
      ->setRequired(TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('Human-readable facility category label.'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);

    $fields['icon'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Icon'))
      ->setDescription(t('Lucide icon used by facilities in this category.'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('Sort weight in the facility category catalogue.'))
      ->setDefaultValue(0);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
