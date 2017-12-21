<?php

namespace Drupal\fa_icon_class\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'fa_icon_class_simple_text' formatter.
 *
 * @FieldFormatter(
 *   id = "fa_icon_class",
 *   module = "fa_icon_class",
 *   label = @Translation("Simple text-based formatter"),
 *   field_types = {
 *     "fa_icon_class"
 *   }
 * )
 */
class IconClassFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = array();

    foreach ($items as $delta => $item) {
      $elements[$delta] = array(
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => array(
          'class' => 'fa ' . $item->value,
        ),
        '#value' => '',
      );
    }

    return $elements;
  }

}
