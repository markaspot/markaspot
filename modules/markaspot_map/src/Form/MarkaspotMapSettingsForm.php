<?php

namespace Drupal\markaspot_map\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotMapSettingsForm extends ConfigFormBase {

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
    $form['markaspot_map_blocks'] = array(
      '#type' => 'fieldset',
      '#title' => t('Map Blocks'),
      '#collapsible' => TRUE,
      '#description' => t('Path settings for the map visualization.'),
      '#group' => 'settings',
    );
    $form['markaspot_map_blocks']['visualization_path'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Existing path for visualization page'),
      '#default_value' => $config->get('visualization_path'),
      '#maxlength' => 255,
      '#size' => 45,
      '#description' => $this->t('Specify the path you wish to use for the visualization dashboard. For example: /node/28.'),
      '#required' => TRUE,
    );
    $form['markaspot_map_blocks']['request_list_path'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Existing path for request list'),
      '#default_value' => $config->get('request_list_path'),
      '#maxlength' => 255,
      '#size' => 45,
      '#description' => $this->t('Specify the path you wish to use for the request list. For example: /node/28.'),
      '#required' => TRUE,
    );
    $form['markaspot_map'] = array(
      '#type' => 'fieldset',
      '#title' => t('Map Types'),
      '#collapsible' => TRUE,
      '#description' => t('This setting allow you too choose a map tile operator of your choose. Be aware that you have to apply the same for the Geolocation Field settings</a>, too.'),
      '#group' => 'settings',
    );
    $form['markaspot_map']['map_type'] = array(
      '#type' => 'radios',
      '#title' => t('Map type'),
      '#default_value' => $config->get('map_type'),
      '#options' => array(t('Google Maps'), t('Mapbox'), t('Other OSM')),
    );
    $form['markaspot_map']['mapbox'] = array(
      '#type' => 'textfield',
      '#title' => t('Mapbox Map ID'),
      '#default_value' => $config->get('mapbox'),
      '#description' => t('Insert your Map ID (e.g. markaspot.Ejs23a) here'),
    );
    $form['markaspot_map']['osm_custom_tile_url'] = array(
      '#type' => 'textfield',
      '#title' => t('Tile URL, if not from Mapbox'),
      '#default_value' => $config->get('osm_custom_tile_url'),
      '#description' => t('If you want to use a different tile service, enter the url pattern, e.g. http://{s}.somedomain.com/your-api-key/{z}/{x}/{y}.png'),
    );
    $form['markaspot_map']['wms_service'] = array(
      '#type' => 'textfield',
      '#title' => t('WMS Service'),
      '#default_value' => $config->get('wms_service'),
    );
    $form['markaspot_map']['wms_layer'] = array(
      '#type' => 'textfield',
      '#title' => t('WMS Layer ID'),
      '#default_value' => $config->get('wms_layer'),
      '#description' => t('Enter the layer ID like "layer:layer"'),
    );
    $form['markaspot_map']['map_background'] = array(
      '#type' => 'textfield',
      '#size' => 6,
      '#title' => t('Define a background color the map container'),
      '#default_value' => $config->get('map_background'),
      '#description' => t('This should be of similar tone of the tile style'),
    );
    $form['markaspot_map']['osm_custom_attribution'] = array(
      '#type' => 'textarea',
      '#title' => t('Attribution Statement, if not from Mapbox'),
      '#default_value' => $config->get('osm_custom_attribution'),
      '#description' => t('If you use an alternative Operator for serving tiles show special attribution'),
    );
    $form['markaspot_map']['timeline_date_format'] = array(
      '#type' => 'textfield',
      '#title' => t('Dateformat'),
      '#default_value' => $config->get('timeline_date_format'),
      '#description' => t('Dateformat'),
    );
    $form['markaspot_map']['timeline_period'] = array(
      '#type' => 'textfield',
      '#title' => t('Timeline Period'),
      '#default_value' => $config->get('timeline_period'),
      '#description' => t('Timeline period'),
    );
    $form['markaspot_map']['timeline_fps'] = array(
      '#type' => 'textfield',
      '#title' => t('Timeline Period FPS'),
      '#default_value' => $config->get('timeline_fps'),
      '#description' => t('Timeline period frame per seconds'),
    );

    $form['markaspot_map']['nid_selector'] = array(
      '#type' => 'textfield',
      '#size' => 10,
      '#title' => t('Selector to read service request nids from markup'),
      '#default_value' => $config->get('nid_selector'),
      '#description' => t('Enter a valid DOM selector, e.g ".row article'),
    );
    $form['markaspot_map']['zoom_initial'] = array(
      '#type' => 'textfield',
      '#size' => 2,
      '#title' => t('Map zoom level on start'),
      '#default_value' => $config->get('zoom_initial'),
      '#description' => t('Enter the map zoom level on Load'),
    );

    $form['markaspot_map']['center_lat'] = array(
      '#type' => 'textfield',
      '#size' => 10,
      '#title' => t('Latitude value for the map center'),
      '#default_value' => $config->get('center_lat'),
      '#description' => t('Enter in decimal format, e.g 50.21'),
    );
    $form['markaspot_map']['center_lng'] = array(

      '#type' => 'textfield',
      '#size' => 10,

      '#title' => t('Longitude value for the map center'),
      '#default_value' => $config->get('center_lng'),
      '#description' => t('Enter in decimal format, e.g 6.8232'),
    );

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
      ->set('mapbox', $values['mapbox'])
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
