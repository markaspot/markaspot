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
    $value = $items[$delta]->value ?? 'map';
    $element += [
      '#type' => 'textfield',
      '#default_value' => $value,
      '#attributes' => ['class' => ["icon-widget"]],
      '#size' => 12,
      '#maxlength' => 40,
      '#element_validate' => [
        [$this, 'validate'],
      ],
      '#attached' => [
        // Add bpptstrap and fontAwesome libraries.
        'library' => [
          'fa_icon_class/iconpicker',
        ],
      ],
    ];
    return ['value' => $element];
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
