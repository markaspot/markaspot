<?php

namespace Drupal\markaspot_trend\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'vue.js trend vlock'.
 *
 * @Block(
 *   id = "markaspot_trend_organisation_block",
 *   admin_label = @Translation("Mark-a-Spot Organisation Trend"),
 * )
 */
class MarkaspotTrendOrganisationBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'markaspot_trend_organisation_block',
      '#attached' => [
        'library' => [
          'markaspot_trend/dateFormat',
          'markaspot_trend/moment',
          'markaspot_trend/axios',
          'markaspot_trend/vue',
          'markaspot_trend/vue-router',
          'markaspot_trend/chartjs',
          'markaspot_trend/vuechartjs',
          'markaspot_trend/trend',
          'markaspot_trend/filter',
          'markaspot_trend/organisation',
        ],
      ],
    ];
  }

}
