<?php

namespace Drupal\fa_icon_class\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'fa_icon_class_rgb' field type.
 *
 * @FieldType(
 *   id = "fa_icon_class",
 *   label = @Translation("Icon Class"),
 *   module = "fa_icon_class",
 *   description = @Translation("Adds a icon class field formatter."),
 *   default_widget = "fa_icon_class",
 *   default_formatter = "fa_icon_class"
 * )
 */
class ClassItem extends FieldItemBase {
  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return array(
      'columns' => array(
        'value' => array(
          'type' => 'text',
          'size' => 'tiny',
          'not null' => FALSE,
        ),
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('value')->getValue();
    return $value === NULL || $value === '';
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(t('Class name'));

    return $properties;
  }

}
