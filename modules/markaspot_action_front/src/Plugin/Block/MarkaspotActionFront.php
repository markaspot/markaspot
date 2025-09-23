<?php

namespace Drupal\markaspot_action_front\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

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
    // Generate default links using proper URL generation
    $report_link = Link::fromTextAndUrl(
      $this->t('<i aria-hidden="true" class="fas fa-map-marker-alt">&nbsp;</i> Anliegen melden'),
      Url::fromUserInput('/report')
    )->toString();

    $requests_link = Link::fromTextAndUrl(
      $this->t('<i aria-hidden="true" class="fas fa-check-circle">&nbsp;</i> Alle Anliegen'),
      Url::fromUserInput('/requests')
    )->toString();

    $stats_link = Link::fromTextAndUrl(
      $this->t('<i aria-hidden="true" class="fas fa-chart-line">&nbsp;</i> Statistik'),
      Url::fromUserInput('/visualization')
    )->toString();

    return [
      'body' => '<ul class="list-group list-group-horizontal-xxl">
          <li class="list-group-item report">' . $report_link . '</li>
          <li class="list-group-item requests">' . $requests_link . '</li>
          <li class="list-group-item statistics">' . $stats_link . '</li>
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
