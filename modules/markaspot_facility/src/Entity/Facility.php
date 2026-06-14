<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines a jurisdiction facility catalogue entry.
 *
 * Facilities are the normalized catalogue rows behind the public
 * `facilities.items[]` payload. Service requests continue to store the stable
 * machine key in `node.field_facility`, so report data does not depend on the
 * numeric entity id.
 *
 * @ContentEntityType(
 *   id = "markaspot_facility",
 *   label = @Translation("Facility"),
 *   label_collection = @Translation("Facilities"),
 *   base_table = "markaspot_facility",
 *   admin_permission = "administer markaspot facility",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "collection" = "/admin/mark-a-spot/facilities",
 *   },
 * )
 */
class Facility extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['jurisdiction_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Jurisdiction'))
      ->setDescription(t('The jurisdiction group this facility belongs to.'))
      ->setSetting('target_type', 'group')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Machine key'))
      ->setDescription(t('Stable facility key stored on service_request.field_facility.'))
      ->setSetting('max_length', 128)
      ->setRequired(TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('Human-readable facility label.'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);

    $fields['lat'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Latitude'))
      ->setDescription(t('Facility latitude in WGS84.'))
      ->setRequired(TRUE);

    $fields['lng'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Longitude'))
      ->setDescription(t('Facility longitude in WGS84.'))
      ->setRequired(TRUE);

    $fields['address'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Address'))
      ->setDescription(t('Normalized facility address, JSON encoded.'));

    $fields['organisation_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Organisation id'))
      ->setDescription(t('Optional default organisation id for future routing decisions.'))
      ->setSetting('max_length', 255);

    $fields['active'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Active'))
      ->setDescription(t('Whether the facility is exposed in public settings.'))
      ->setDefaultValue(TRUE);

    $fields['icon'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Icon'))
      ->setDescription(t('Optional icon name for dashboard and public display.'))
      ->setSetting('max_length', 255);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('Optional plain-text description for the facility.'));

    $fields['url'] = BaseFieldDefinition::create('string')
      ->setLabel(t('URL'))
      ->setDescription(t('Optional http(s) URL for more information about the facility.'))
      ->setSetting('max_length', 512);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('Sort weight in the facility catalogue.'))
      ->setDefaultValue(0);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
