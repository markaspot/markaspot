<?php

namespace Drupal\markaspot_action_front\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Main Navigation Block' block.
 *
 * @Block(
 *   id = "Markaspot Action Front",
 *   category = @Translation("Mark-a-Spot"),
 *   admin_label = @Translation("Mark-a-Spot: Front Action")
 * )
 */
class MarkaspotActionFront extends BlockBase implements BlockPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {

    return [
      'body' => '<ul class="list-group list-group-horizontal-xxl">
          <li class="list-group-item report"><a href="/report"><i aria-hidden="true" class="fas fa-map-marker-alt">&nbsp;</i> Anliegen melden</a></li>
          <li class="list-group-item requests"><a href="/requests"><i aria-hidden="true" class="fas fa-check-circle">&nbsp;</i> Alle Anliegen</a></li>
          <li class="list-group-item statistics"><a href="/visualization"><i aria-hidden="true" class="fas fa-chart-line">&nbsp;</i> Statistik</a></li>
        </ul>',
      'label_display' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['body'] = [
      '#type' => 'text_format',
      '#format' => 'full_html',
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
