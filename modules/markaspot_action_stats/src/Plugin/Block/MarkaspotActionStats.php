<?php

namespace Drupal\markaspot_action_stats\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Stats feature Block' block.
 *
 * @Block(
 *   id = "Markaspot Action Stats",
 *   category = @Translation("Mark-a-Spot"),
 *   admin_label = @Translation("Mark-a-Spot: Stats Action")
 * )
 */
class MarkaspotActionStats extends BlockBase implements BlockPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {

    return [
      'body' =>
        [
          'value' => '<ul class="list-group list-group-horizontal-xxl">
            <li class="list-group-item heatmap"><a href="#" tabindex="-1">Heatmap</a></li>
            <li class="list-group-item time-control"><a href="#" tabindex="-1">Zeitstrahl</a></li>
          </ul>',
          'format' => 'full_html',
        ],
      'label_display' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['body'] = [
      '#type' => 'text_format',
      '#format' => $this->configuration['body']['format'],
      '#title' => 'Body',
      '#default_value' => $this->configuration['body']['value'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['body'] = $form_state->getValue('body');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $markup = $this->configuration['body']['value'] ?? '';
    return [
      '#type' => 'markup',
      '#markup' => $markup,
    ];
  }

}
