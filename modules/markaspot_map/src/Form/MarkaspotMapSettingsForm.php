<?php

namespace Drupal\markaspot_map\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotMapSettingsForm extends ConfigFormBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_map_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_map.settings');
    $form['markaspot_map_blocks'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Map Blocks'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Path settings for the map visualization.'),
      '#group' => 'settings',
    ];
    $form['markaspot_map_blocks']['visualization_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Existing path for visualization page'),
      '#default_value' => $config->get('visualization_path'),
      '#maxlength' => 255,
      '#size' => 45,
      '#description' => $this->t('Specify the path you wish to use for the visualization dashboard. For example: /node/28.'),
      '#required' => TRUE,
    ];
    $form['markaspot_map_blocks']['request_list_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Existing path for request list'),
      '#default_value' => $config->get('request_list_path'),
      '#maxlength' => 255,
      '#size' => 45,
      '#description' => $this->t('Specify the path you wish to use for the request list. For example: /node/28.'),
      '#required' => TRUE,
    ];
    $form['markaspot_map'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Map Types'),
      '#collapsible' => TRUE,
      '#description' => $this->t('This setting allow you too choose a map tile operator of your choose. Be aware that you have to apply the same for the Geolocation Field settings</a>, too.'),
      '#group' => 'settings',
    ];
    $form['markaspot_map']['map_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Map type'),
      '#default_value' => $config->get('map_type'),
      '#options' => [$this->t('Mapbox/MapLibre'), $this->t('Other OSM')],
    ];
    $form['markaspot_map']['mapbox_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox Access Token'),
      '#default_value' => $config->get('mapbox_token'),
      '#description' => $this->t('Insert your Mapbox Access Token (e.g. pk.eyJ1zN2UyOTRxaDkifQ.RYWA5UQ-B5) here'),
    ];
    $form['markaspot_map']['mapbox_style'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox Style Url / MapLibre JSON Url'),
      '#default_value' => $config->get('mapbox_style'),
      '#description' => $this->t('Mapbox Style Url (e.g. mapbox://styles/mapbox/streets-v8) here'),
    ];
    $form['markaspot_map']['mapbox_style_dark'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox Style Url / MapLibre JSON Url for dark modes'),
      '#default_value' => $config->get('mapbox_style_dark'),
      '#description' => $this->t('Mapbox Style Url (e.g. mapbox://styles/mapbox/streets-v8) here'),
    ];
    $form['markaspot_map']['maplibre'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable MapLibre'),
      '#default_value' => $config->get('maplibre'),
      // Use the #states property to make this checkbox dependent on the Mapbox/MapLibre selection
      '#states' => [
        'visible' => [
          ':input[name="map_type"]' => ['value' => '0'],
        ],
      ],
    ];
    $form['markaspot_map']['osm_custom_tile_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tile URL, if not from Mapbox'),
      '#default_value' => $config->get('osm_custom_tile_url'),
      '#description' => $this->t('If you want to use a different tile service, enter the url pattern, e.g. http://{s}.somedomain.com/your-api-key/{z}/{x}/{y}.png'),
    ];
    $form['markaspot_map']['wms_service'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WMS Service'),
      '#default_value' => $config->get('wms_service'),
    ];
    $form['markaspot_map']['wms_layer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WMS Layer ID'),
      '#default_value' => $config->get('wms_layer'),
      '#description' => $this->t('Enter the layer ID like "layer:layer"'),
    ];
    $form['markaspot_map']['map_background'] = [
      '#type' => 'textfield',
      '#size' => 6,
      '#title' => $this->t('Define a background color the map container'),
      '#default_value' => $config->get('map_background'),
      '#description' => $this->t('This should be of similar tone of the tile style'),
    ];
    $form['markaspot_map']['osm_custom_attribution'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Attribution Statement, if not from Mapbox'),
      '#default_value' => $config->get('osm_custom_attribution'),
      '#description' => $this->t('If you use an alternative Operator for serving tiles show special attribution'),
    ];
    $form['markaspot_map']['timeline_date_format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dateformat'),
      '#default_value' => $config->get('timeline_date_format'),
      '#description' => $this->t('Dateformat'),
    ];
    $form['markaspot_map']['timeline_period'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Timeline Period'),
      '#default_value' => $config->get('timeline_period'),
      '#description' => $this->t('Timeline period'),
    ];
    $form['markaspot_map']['timeline_fps'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Timeline Period FPS'),
      '#default_value' => $config->get('timeline_fps'),
      '#description' => $this->t('Timeline period frame per seconds'),
    ];

    $form['markaspot_map']['nid_selector'] = [
      '#type' => 'textfield',
      '#size' => 10,
      '#title' => $this->t('Selector to read service request nids from markup'),
      '#default_value' => $config->get('nid_selector'),
      '#description' => $this->t('Enter a valid DOM selector, e.g ".row article'),
    ];
    $form['markaspot_map']['zoom_initial'] = [
      '#type' => 'textfield',
      '#size' => 2,
      '#title' => $this->t('Map zoom level on start'),
      '#default_value' => $config->get('zoom_initial'),
      '#description' => $this->t('Enter the map zoom level on Load'),
    ];

    $form['markaspot_map']['center_lat'] = [
      '#type' => 'textfield',
      '#size' => 10,
      '#title' => $this->t('Latitude value for the map center'),
      '#default_value' => $config->get('center_lat'),
      '#description' => $this->t('Enter in decimal format, e.g 50.21'),
    ];
    $form['markaspot_map']['center_lng'] = [

      '#type' => 'textfield',
      '#size' => 10,
      '#title' => $this->t('Longitude value for the map center'),
      '#default_value' => $config->get('center_lng'),
      '#description' => $this->t('Enter in decimal format, e.g 6.8232'),
    ];

    $default_svg = '<div class="fa {mapIconSymbol}" style="color: {mapIconColor}"><svg class="icon" width="50" height="50"
xmlns="http://www.w3.org/2000/svg" xml:space="preserve" version="1.1"><defs>
<filter id="dropshadow" height="130%"><feDropShadow dx="-0.8" dy="-0.8" stdDeviation="2"
    flood-color="black" flood-opacity="0.5"/></filter></defs><g><path filter="url(#dropshadow)"
        fill="{mapIconFill}" d="m25,2.55778c-7.27846,0 -15.7703,4.44805 -15.7703,15.7703c0,7.68272 12.13107,24.6661 15.7703,29.11415c3.23497,-4.44804 15.7703,-21.02687 15.7703,-29.11415c0,-11.32225 -8.49184,-15.7703 -15.7703,-15.7703z"
        id="path4133"/></g></svg></div>';

    $form['markaspot_map']['marker'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Marker SVG / Markup'),
      '#default_value' => $config->get('marker') !== '' ? $config->get('marker') : $default_svg,
      '#description' => $this->t('SVG for the marker'),
    ];
    $form['markaspot_map']['iconAnchor'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Icon Anchor'),
      '#default_value' => $config->get('iconAnchor') !== '' ? $config->get('iconAnchor') : "[25, 30]",
      '#description' => $this->t('SVG for the marker, https://leafletjs.com/examples/custom-icons/'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('markaspot_map.settings')
      ->set('request_list_path', $values['request_list_path'])
      ->set('visualization_path', $values['visualization_path'])
      ->set('map_type', $values['map_type'])
      ->set('maplibre', $values['maplibre'])
      ->set('mapbox_token', $values['mapbox_token'])
      ->set('mapbox_style', $values['mapbox_style'])
      ->set('mapbox_style_dark', $values['mapbox_style_dark'])
      ->set('osm_custom_tile_url', $values['osm_custom_tile_url'])
      ->set('wms_service', $values['wms_service'])
      ->set('wms_layer', $values['wms_layer'])
      ->set('osm_custom_attribution', $values['osm_custom_attribution'])
      ->set('map_background', $values['map_background'])
      ->set('timeline_date_format', $values['timeline_date_format'])
      ->set('timeline_period', $values['timeline_period'])
      ->set('timeline_fps', $values['timeline_fps'])
      ->set('nid_selector', $values['nid_selector'])
      ->set('zoom_initial', $values['zoom_initial'])
      ->set('center_lat', $values['center_lat'])
      ->set('center_lng', $values['center_lng'])
      ->set('marker', $values['marker'])
      ->set('iconAnchor', $values['iconAnchor'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_map.settings',
    ];
  }

}
