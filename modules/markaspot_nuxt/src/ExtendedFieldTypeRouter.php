<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt;

use Drupal\json_form_widget\ArrayHelper;
use Drupal\json_form_widget\FieldTypeRouter;
use Drupal\json_form_widget\IntegerHelper;
use Drupal\json_form_widget\ObjectHelper;
use Drupal\json_form_widget\StringHelper;

/**
 * Extended field type router with boolean support.
 *
 * Extends json_form_widget's FieldTypeRouter to add support for boolean
 * fields which are not handled by the base module.
 */
class ExtendedFieldTypeRouter extends FieldTypeRouter {

  /**
   * Boolean helper service.
   */
  protected BooleanHelper $booleanHelper;

  /**
   * Constructor.
   */
  public function __construct(
    StringHelper $string_helper,
    ObjectHelper $object_helper,
    ArrayHelper $array_helper,
    IntegerHelper $integer_helper,
    BooleanHelper $boolean_helper,
  ) {
    parent::__construct($string_helper, $object_helper, $array_helper, $integer_helper);
    $this->booleanHelper = $boolean_helper;
    $this->booleanHelper->setBuilder($this);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormElement($type, $definition, $data, $object_schema = NULL, $form_state = NULL, array $context = []) {
    // Handle boolean type which is not supported by parent.
    if ($type === 'boolean') {
      return $this->booleanHelper->handleBooleanElement($definition, $data, $object_schema);
    }

    // Cast array data to object for object-type fields. This handles the case
    // where JSON values like [] or associative arrays are decoded as PHP arrays
    // instead of stdClass, which would cause a TypeError in handleObjectElement().
    if ($type === 'object' && is_array($data)) {
      $data = (object) $data;
    }

    // Normalize scalar data on object-typed nodes. The Nuxt dashboard stores
    // several map keys (deferredMap, controls.*) and feature toggles in a
    // compact boolean form while the schema describes the expanded object
    // form; the frontend reader accepts both and normalizes booleans to
    // {enabled: bool} on read. Mirror that here so the form renders instead
    // of ObjectHelper throwing a TypeError on non-object data. Any other
    // scalar renders the schema defaults.
    if ($type === 'object' && !is_object($data)) {
      $data = self::normalizeObjectData($data);
    }

    // Delegate all other types to parent (may return null for unhandled types).
    return parent::getFormElement($type, $definition, $data, $object_schema, $form_state, $context);
  }

  /**
   * Normalizes non-object data for an object-typed schema node.
   *
   * @param mixed $data
   *   The stored value.
   *
   * @return object|null
   *   The compact-boolean form expanded to {enabled: bool}, or NULL for any
   *   other scalar so the form falls back to schema defaults.
   */
  public static function normalizeObjectData(mixed $data): ?object {
    if (is_bool($data)) {
      return (object) ['enabled' => $data];
    }
    return NULL;
  }

}
