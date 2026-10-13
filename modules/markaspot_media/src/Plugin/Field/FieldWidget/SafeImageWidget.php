<?php

namespace Drupal\markaspot_media\Plugin\Field\FieldWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\image\Plugin\Field\FieldWidget\ImageWidget;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Keeps item-level image violations attached to a valid form element.
 *
 * Core's image widget expects a property path such as "0.alt". File reference
 * access violations instead target the whole item ("0"). Keep those errors on
 * the image element rather than returning NULL to FormState::setError().
 */
class SafeImageWidget extends ImageWidget {

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state) {
    $path = explode('.', $error->getPropertyPath());
    if (!isset($path[1]) || !isset($element[$path[1]]) || !is_array($element[$path[1]])) {
      return $element;
    }
    return parent::errorElement($element, $error, $form, $form_state);
  }

}
