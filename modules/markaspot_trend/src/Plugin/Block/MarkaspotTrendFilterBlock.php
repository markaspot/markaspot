<?php

namespace Drupal\markaspot_trend\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'vue.js trend block'.
 *
 * @Block(
 *   id = "markaspot_trend_filter_block",
 *   admin_label = @Translation("Mark-a-Spot Trend Filter"),
 * )
 */
class MarkaspotTrendFilterBlock extends BlockBase implements BlockPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function build() {

    $config = $this->getConfiguration();

    return [
      '#theme' => 'markaspot_trend_filter_block',
      '#filter_intro' => $config['filter_intro'],
      '#attached' => [
        'library' => [
          'markaspot_trend/vue',
          'markaspot_trend/vue-router',
          'markaspot_trend/trend',
          'markaspot_trend/filter',
        ],
      ],
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['filter_intro'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Charts and filtering Intro'),
      '#description' => $this->t('A short intro for the charts page'),
      '#default_value' => isset($config['filter_intro']) ? $config['filter_intro'] : 'Refine data by years and months, scroll down to see more charts!',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['filter_intro'] = $values['filter_intro'];
  }
}