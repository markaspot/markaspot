<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt;

use Drupal\json_form_widget\ValueHandler;

/**
 * Extended value handler with boolean and additionalProperties support.
 *
 * Extends json_form_widget's ValueHandler to add support for boolean
 * fields and dynamic key-value pairs from additionalProperties.
 */
class ExtendedValueHandler extends ValueHandler {

  /**
   * {@inheritdoc}
   */
  public function flattenValues($formValues, $property, $schema) {
    // Handle boolean type which is not supported by parent.
    if ($schema->type === 'boolean') {
      return $this->handleBooleanValues($formValues, $property);
    }

    // Delegate all other types to parent.
    return parent::flattenValues($formValues, $property, $schema);
  }

  /**
   * {@inheritdoc}
   */
  public function handleObjectValues($formValues, $property, $schema) {
    // Let parent process known (static) properties.
    $data = parent::handleObjectValues($formValues, $property, $schema);
    if ($data === FALSE) {
      $data = [];
    }

    // Process additional (dynamic) properties from the __additional container.
    if (isset($schema->additionalProperties)
      && is_object($schema->additionalProperties)
      && isset($formValues['__additional']['items'])
    ) {
      $additional_schema = $schema->additionalProperties;
      $ap_properties = (array) ($additional_schema->properties ?? []);

      foreach ($formValues['__additional']['items'] as $entry) {
        $key = trim($entry['_key'] ?? '');
        if ($key === '') {
          continue;
        }

        // Extract each sub-property value using the existing type handlers.
        $value_obj = [];
        foreach ($ap_properties as $sub_prop => $sub_schema) {
          $sub_value = $this->flattenValues($entry, $sub_prop, $sub_schema);
          if ($sub_value !== FALSE && $sub_value !== NULL) {
            $value_obj[$sub_prop] = $sub_value;
          }
        }

        if (!empty($value_obj)) {
          $data[$key] = $value_obj;
        }
      }
    }

    return $data ?: FALSE;
  }

  /**
   * Flatten values for boolean properties.
   *
   * @param array $formValues
   *   The form values.
   * @param string $property
   *   The property name.
   *
   * @return bool|null
   *   The boolean value or NULL if not set.
   */
  public function handleBooleanValues($formValues, $property) {
    if (!isset($formValues[$property])) {
      return NULL;
    }
    // Checkbox returns 1/0 or true/false - cast to boolean.
    return (bool) $formValues[$property];
  }

}
