<?php

namespace Drupal\fa_icon_class\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'fa_icon_class' widget.
 *
 * @FieldWidget(
 *   id = "fa_icon_class",
 *   module = "fa_icon_class",
 *   label = @Translation("Class value as text"),
 *   field_types = {
 *     "fa_icon_class"
 *   }
 * )
 */
class TextWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $value = isset($items[$delta]->value) ? $items[$delta]->value : 'map';
    $element += array(
      '#type' => 'textfield',
      '#default_value' => $value,
      '#attributes' => array('class' => array("icon-widget")),
      '#size' => 12,
      '#maxlength' => 20,
      '#element_validate' => array(
        array($this, 'validate'),
      ),
      '#attached' => array(
        // Add bpptstrap and fontAwesome libraries.
        'library' => array(
          'fa_icon_class/fontawesome-iconpicker',
          'fa_icon_class/iconpicker',
          'fa_icon_class/bootstrap',
          'fa_icon_class/font-awesome',
        ),
      ),
    );
    return array('value' => $element);
  }

  /**
   * Validate the color text field.
   */
  public function validate($element, FormStateInterface $form_state) {
    $value = $element['#value'];
    if (strlen($value) == 0) {
      $form_state->setValueForElement($element, '');
      return;
    }
  }

}
