<?php

namespace Drupal\markaspot_trend\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'vue.js count block'.
 *
 * @Block(
 *   id = "markaspot_trend_count_block",
 *   admin_label = @Translation("Mark-a-Spot Count Trend"),
 * )
 */
class MarkaspotTrendCountBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'markaspot_trend_count_block',
      '#total' => 'Requests total',
      '#attached' => [
        'library' => [
          'markaspot_trend/dateFormat',
          'markaspot_trend/moment',
          'markaspot_trend/vue',
          'markaspot_trend/vue-router',
          'markaspot_trend/axios',
          'markaspot_trend/trend',
          'markaspot_trend/count',
        ],
      ],
    ];
  }

}
