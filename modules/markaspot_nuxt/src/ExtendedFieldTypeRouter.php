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

    // Delegate all other types to parent (may return null for unhandled types).
    return parent::getFormElement($type, $definition, $data, $object_schema, $form_state, $context);
  }

}
