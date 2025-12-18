<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt;

use Drupal\json_form_widget\ValueHandler;

/**
 * Extended value handler with boolean support.
 *
 * Extends json_form_widget's ValueHandler to add support for boolean
 * fields which are not handled by the base module.
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
