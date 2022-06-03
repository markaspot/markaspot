<?php

namespace Drupal\markaspot_map\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Leaflet Map' Block.
 *
 * @Block(
 *   id = "markaspot_map_request_block",
 *   admin_label = @Translation("Mark-a-Spot Map Requests Block"),
 * )
 */
class MarkaspotMapRequestBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return array(
      '#type' => 'markup',
      '#markup' => '<ul class="notifications"></ul><div id="map" data-drupal-selector="map-request-block" class="map-request-block" data-slideout-ignore><div class="log"><div class="log_header"><span class="left"></span><span class="right"></span></div><ul class="log_list"></ul></div></div>',
      '#attached' => array(
        'library' => array(
          'markaspot_map/dateFormat',
          'markaspot_map/leaflet',
          'markaspot_map/leaflet-awesome-markers',
          'markaspot_map/leaflet-easyButton',
          'markaspot_map/leaflet-fullscreen',
          'markaspot_map/waypoints',
          'markaspot_map/leaflet-heatmap',
          'markaspot_map/font-awesome',
          'markaspot_map/leaflet-timedimension',
          'markaspot_map/leaflet-markercluster',
          'markaspot_map/map',
        ),
      ),
    );
  }

}
