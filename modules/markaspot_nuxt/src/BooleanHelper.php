<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\json_form_widget\FieldTypeRouter;

/**
 * JSON form widget boolean helper service.
 *
 * Provides boolean field support missing from json_form_widget contrib module.
 */
class BooleanHelper {
  use DependencySerializationTrait;

  /**
   * Field type router.
   *
   * @var \Drupal\json_form_widget\FieldTypeRouter
   */
  protected FieldTypeRouter $builder;

  /**
   * Set the field type router.
   *
   * @param \Drupal\json_form_widget\FieldTypeRouter $builder
   *   Field type router.
   */
  public function setBuilder(FieldTypeRouter $builder): void {
    $this->builder = $builder;
  }

  /**
   * Handle form element for a boolean.
   *
   * @param array $definition
   *   Field definition containing 'name' and 'schema'.
   * @param mixed $data
   *   Current field value (may be bool, int, string, or null).
   * @param object|null $element_schema
   *   Parent field element schema.
   *
   * @return array
   *   Boolean field render array as checkbox.
   */
  public function handleBooleanElement(array $definition, mixed $data, ?object $element_schema = NULL): array {
    $field_name = $definition['name'];
    $field_schema = $definition['schema'];
    $element_schema ??= $this->builder->getSchema();

    return [
      '#type' => 'checkbox',
      '#title' => $field_schema->title ?? '',
      '#description' => $field_schema->description ?? '',
      '#description_display' => 'before',
      '#default_value' => $this->getDefaultValue($data, $field_schema),
      '#required' => $this->checkIfRequired($field_name, $element_schema),
    ];
  }

  /**
   * Get default value for element.
   *
   * @param mixed $data
   *   Current field value.
   * @param object|null $field_schema
   *   Field schema.
   *
   * @return bool
   *   Default field value.
   */
  public function getDefaultValue(mixed $data, ?object $field_schema): bool {
    if ($data !== NULL) {
      return (bool) $data;
    }
    return (bool) ($field_schema->default ?? FALSE);
  }

  /**
   * Check if field is required based on its schema.
   *
   * @param string $field_name
   *   Field name.
   * @param object|null $element_schema
   *   Parent field element schema.
   *
   * @return bool
   *   TRUE if the field is required.
   */
  public function checkIfRequired(string $field_name, ?object $element_schema): bool {
    return in_array($field_name, $element_schema->required ?? []);
  }

}
