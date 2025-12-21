<?php

namespace Drupal\markaspot_nuxt\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Nuxt frontend settings for this site.
 */
class MarkaspotNuxtSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_nuxt_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_nuxt.settings');

    $form['markaspot_nuxt'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Nuxt Frontend Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure frontend URL settings for Mark-a-Spot Nuxt integration.'),
      '#group' => 'settings',
    ];

    $form['markaspot_nuxt']['frontend'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Frontend Configuration'),
      '#collapsible' => FALSE,
      '#weight' => 0,
    ];

    $form['markaspot_nuxt']['frontend']['frontend_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Frontend Base URL'),
      '#default_value' => $config->get('frontend_base_url') ?: '',
      '#description' => $this->t('The base URL of your Nuxt frontend application (e.g., https://example.com:3001). This URL will be used for generating frontend links in emails and other communications and is available via the [markaspot_frontend:url] token. If empty, Drupal backend URLs will be used.'),
      '#placeholder' => 'https://example.com:3001',
    ];

    $form['markaspot_nuxt']['frontend']['frontend_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Frontend URL Generation'),
      '#default_value' => $config->get('frontend_enabled') ?: FALSE,
      '#description' => $this->t('When enabled, system-generated URLs (like confirmation links) will point to the frontend instead of the Drupal backend.'),
    ];

    $form['markaspot_nuxt']['api'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Configuration'),
      '#collapsible' => FALSE,
      '#weight' => 1,
    ];

    $form['markaspot_nuxt']['api']['api_cors_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CORS for API endpoints'),
      '#default_value' => $config->get('api_cors_enabled') ?: FALSE,
      '#description' => $this->t('Allow cross-origin requests to Mark-a-Spot API endpoints from the frontend domain.'),
    ];

    // Map Configuration.
    $form['markaspot_nuxt']['map'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Map Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('Configure map settings for the Nuxt frontend.'),
      '#weight' => 2,
    ];

    $form['markaspot_nuxt']['map']['mapbox_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox Access Token'),
      '#default_value' => $config->get('mapbox_token'),
      '#description' => $this->t('Your Mapbox API token (e.g., pk.eyJ1zN2UyOTRxaDkifQ.RYWA5UQ-B5)'),
    ];

    $form['markaspot_nuxt']['map']['mapbox_style'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox/MapLibre Style URL (Light Mode)'),
      '#default_value' => $config->get('mapbox_style'),
      '#description' => $this->t('Style URL or path to style.json (e.g., mapbox://styles/mapbox/streets-v12)'),
    ];

    $form['markaspot_nuxt']['map']['mapbox_style_dark'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox/MapLibre Style URL (Dark Mode)'),
      '#default_value' => $config->get('mapbox_style_dark'),
      '#description' => $this->t('Style URL for dark mode (e.g., mapbox://styles/mapbox/dark-v11)'),
    ];

    $form['markaspot_nuxt']['map']['osm_custom_attribution'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Main Style Attribution'),
      '#default_value' => $config->get('osm_custom_attribution'),
      '#rows' => 2,
      '#description' => $this->t('Attribution text for the main map style (e.g., © Mapbox © OpenStreetMap contributors)'),
    ];

    // Fallback configuration.
    $form['markaspot_nuxt']['map']['fallback'] = [
      '#type' => 'details',
      '#title' => $this->t('Fallback Style Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Configure a backup map style in case the primary style fails to load.'),
    ];

    $form['markaspot_nuxt']['map']['fallback']['fallback_style'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback Style URL (Light Mode)'),
      '#default_value' => $config->get('fallback_style'),
      '#description' => $this->t('Backup style URL (e.g., MapTiler or alternative provider)'),
    ];

    $form['markaspot_nuxt']['map']['fallback']['fallback_style_dark'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback Style URL (Dark Mode)'),
      '#default_value' => $config->get('fallback_style_dark'),
      '#description' => $this->t('Backup style URL for dark mode'),
    ];

    $form['markaspot_nuxt']['map']['fallback']['fallback_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback Service API Key'),
      '#default_value' => $config->get('fallback_api_key'),
      '#description' => $this->t('API key for the fallback tile service (e.g., MapTiler API key)'),
    ];

    $form['markaspot_nuxt']['map']['fallback']['fallback_attribution'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fallback Style Attribution'),
      '#default_value' => $config->get('fallback_attribution'),
      '#rows' => 2,
      '#description' => $this->t('Attribution text for the fallback style'),
    ];

    // Map position.
    $form['markaspot_nuxt']['map']['position'] = [
      '#type' => 'details',
      '#title' => $this->t('Default Map Position'),
      '#open' => TRUE,
    ];

    $form['markaspot_nuxt']['map']['position']['zoom_initial'] = [
      '#type' => 'number',
      '#title' => $this->t('Initial Zoom Level'),
      '#default_value' => $config->get('zoom_initial') ?: 13,
      '#min' => 1,
      '#max' => 20,
      '#description' => $this->t('Default zoom level when the map loads (1-20)'),
    ];

    $form['markaspot_nuxt']['map']['position']['center_lat'] = [
      '#type' => 'number',
      '#title' => $this->t('Center Latitude'),
      '#default_value' => $config->get('center_lat'),
      '#step' => 0.000001,
      '#description' => $this->t('Latitude for map center (e.g., 51.4556)'),
    ];

    $form['markaspot_nuxt']['map']['position']['center_lng'] = [
      '#type' => 'number',
      '#title' => $this->t('Center Longitude'),
      '#default_value' => $config->get('center_lng'),
      '#step' => 0.000001,
      '#description' => $this->t('Longitude for map center (e.g., 6.8528)'),
    ];

    // Geocoding Configuration.
    $form['markaspot_nuxt']['geocoding'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Geocoding Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('Configure geocoding settings for address search. These settings apply to all geocoding providers (Mapbox, Nominatim, Photon). By default, no country restriction is applied for worldwide compatibility.'),
      '#weight' => 3,
    ];

    $form['markaspot_nuxt']['geocoding']['geocoding_country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Geocoding Country Code'),
      '#default_value' => $config->get('geocoding_country') ?: '',
      '#maxlength' => 2,
      '#size' => 5,
      '#description' => $this->t('ISO 3166-1 alpha-2 country code (e.g., de, us, fr, gb). Limits address search results to the specified country. <strong>Leave empty for worldwide search (default).</strong> Common codes: de=Germany, us=USA, fr=France, gb=UK, nl=Netherlands.'),
    ];

    $form['markaspot_nuxt']['geocoding']['geocoding_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Geocoding Region (optional)'),
      '#default_value' => $config->get('geocoding_region') ?: '',
      '#description' => $this->t('Optional region name to bias geocoding results towards a specific area (e.g., nordrhein-westfalen, bavaria, california). Works best with Mapbox. Leave empty for country-wide or worldwide search.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('markaspot_nuxt.settings')
      ->set('frontend_base_url', $values['frontend_base_url'])
      ->set('frontend_enabled', $values['frontend_enabled'])
      ->set('api_cors_enabled', $values['api_cors_enabled'])
      ->set('mapbox_token', $values['mapbox_token'])
      ->set('mapbox_style', $values['mapbox_style'])
      ->set('mapbox_style_dark', $values['mapbox_style_dark'])
      ->set('osm_custom_attribution', $values['osm_custom_attribution'])
      ->set('fallback_style', $values['fallback_style'])
      ->set('fallback_style_dark', $values['fallback_style_dark'])
      ->set('fallback_api_key', $values['fallback_api_key'])
      ->set('fallback_attribution', $values['fallback_attribution'])
      ->set('zoom_initial', $values['zoom_initial'])
      ->set('center_lat', $values['center_lat'])
      ->set('center_lng', $values['center_lng'])
      ->set('geocoding_country', strtolower(trim($values['geocoding_country'] ?? '')))
      ->set('geocoding_region', trim($values['geocoding_region'] ?? ''))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_nuxt.settings',
    ];
  }

}
