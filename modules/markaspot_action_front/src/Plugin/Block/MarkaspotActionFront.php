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
        <li class="list-group-item report"><a href="/report">Report</a></li>
        <li class="list-group-item requests"><a href="/requests">Requests</a></li>
        <li class="list-group-item statistics"><a href="/visualization">Stats</a></li>
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
    return [
      '#type' => 'markup',
      '#markup' => $this->t($this->configuration['body']['value']),
    ];
  }

}
