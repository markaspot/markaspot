<?php

namespace Drupal\markaspot_geocoder\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure server-side geocoding settings.
 */
class MarkaspotGeocoderSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_geocoder_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('markaspot_geocoder.settings');

    $envProvider = getenv('GEOCODER_PROVIDER');
    $envApiKey = getenv('GEOCODER_API_KEY');
    $envLanguage = getenv('GEOCODER_LANGUAGE');

    if ($envProvider || $envApiKey || $envLanguage) {
      $overrides = array_filter([
        $envProvider ? "GEOCODER_PROVIDER=$envProvider" : NULL,
        $envApiKey ? 'GEOCODER_API_KEY=(set)' : NULL,
        $envLanguage ? "GEOCODER_LANGUAGE=$envLanguage" : NULL,
      ]);
      $form['env_notice'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">'
          . $this->t('ENV overrides active: @vars. These take precedence over the settings below.', [
            '@vars' => implode(', ', $overrides),
          ])
          . '</div>',
      ];
    }

    $form['markaspot_geocoder'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Server-side Geocoder Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure the geocoding provider for reverse geocoding coordinates to addresses. ENV variables (GEOCODER_PROVIDER, GEOCODER_API_KEY, GEOCODER_LANGUAGE) override these settings when set.'),
      '#group' => 'settings',
    ];

    $form['markaspot_geocoder']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Geocoding Provider'),
      '#options' => [
        'nominatim' => $this->t('Nominatim (OpenStreetMap, free, no API key)'),
        'mapbox' => $this->t('Mapbox (requires API key)'),
      ],
      '#default_value' => $config->get('provider') ?: 'nominatim',
      '#description' => $this->t('Select the geocoding provider. Nominatim is free and requires no API key.'),
    ];

    $form['markaspot_geocoder']['mapbox_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox Access Token'),
      '#default_value' => $config->get('mapbox_token'),
      '#description' => $this->t('Required for Mapbox provider. Get your token at mapbox.com.'),
      '#states' => [
        'visible' => [
          ':input[name="provider"]' => [
            ['value' => 'mapbox'],
          ],
        ],
        'required' => [
          ':input[name="provider"]' => [
            ['value' => 'mapbox'],
          ],
        ],
      ],
    ];

    $form['markaspot_geocoder']['language'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Language'),
      '#default_value' => $config->get('language') ?: 'de',
      '#description' => $this->t('Language code for geocoding results (e.g. de, en, fr).'),
      '#size' => 5,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $provider = $form_state->getValue('provider');
    $token = trim($form_state->getValue('mapbox_token') ?? '');

    if ($provider === 'mapbox' && empty($token) && !getenv('GEOCODER_API_KEY')) {
      $form_state->setErrorByName('mapbox_token', $this->t('Mapbox provider requires an access token (or set GEOCODER_API_KEY environment variable).'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('markaspot_geocoder.settings')
      ->set('provider', $form_state->getValue('provider'))
      ->set('mapbox_token', $form_state->getValue('mapbox_token'))
      ->set('language', $form_state->getValue('language'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'markaspot_geocoder.settings',
    ];
  }

}
