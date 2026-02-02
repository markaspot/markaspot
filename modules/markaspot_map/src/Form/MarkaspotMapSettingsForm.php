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

    // Fallback tile service configuration.
    $form['markaspot_map']['fallback_style'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback Style Url / MapLibre JSON Url'),
      '#default_value' => $config->get('fallback_style'),
      '#description' => $this->t('Fallback style URL to use when primary style fails (e.g. MapTiler, Mapbox, or other MapLibre style)'),
    ];
    $form['markaspot_map']['fallback_style_dark'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback Style Url / MapLibre JSON Url for dark modes'),
      '#default_value' => $config->get('fallback_style_dark'),
      '#description' => $this->t('Fallback style URL for dark mode when primary style fails'),
    ];
    $form['markaspot_map']['fallback_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback Service API Key'),
      '#default_value' => $config->get('fallback_api_key'),
      '#description' => $this->t('API key for the fallback tile service (e.g. MapTiler API key: pk.abc123...)'),
    ];
    $form['markaspot_map']['fallback_attribution'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fallback Service Attribution'),
      '#default_value' => $config->get('fallback_attribution'),
      '#description' => $this->t('Attribution text for the fallback tile service (e.g. © MapTiler © OpenStreetMap contributors)'),
    ];
    $form['markaspot_map']['osm_custom_tile_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tile URL, if not from Mapbox'),
      '#default_value' => $config->get('osm_custom_tile_url'),
      '#description' => $this->t('If you want to use a different tile service, enter the url pattern, e.g. http://{s}.somedomain.com/your-api-key/{z}/{x}/{y}.png'),
    ];
    $form['markaspot_map']['osm_custom_attribution'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Attribution Statement, if not from Mapbox'),
      '#default_value' => $config->get('osm_custom_attribution'),
      '#description' => $this->t('If you use an alternative Operator for serving tiles show special attribution'),
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
      ->set('mapbox_token', $values['mapbox_token'])
      ->set('mapbox_style', $values['mapbox_style'])
      ->set('mapbox_style_dark', $values['mapbox_style_dark'])
      ->set('fallback_style', $values['fallback_style'])
      ->set('fallback_style_dark', $values['fallback_style_dark'])
      ->set('fallback_api_key', $values['fallback_api_key'])
      ->set('fallback_attribution', $values['fallback_attribution'])
      ->set('osm_custom_tile_url', $values['osm_custom_tile_url'])
      ->set('osm_custom_attribution', $values['osm_custom_attribution'])
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
