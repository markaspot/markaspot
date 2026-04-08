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

    // District mapping configuration.
    $form['district_mapping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('District Taxonomy Mapping'),
      '#description' => $this->t('Map geocoder response properties to taxonomy vocabularies. Each mapping defines which geocoder property populates which taxonomy field on service requests. Available properties depend on the provider: Nominatim returns suburb, quarter, neighbourhood, city_district, borough. Mapbox returns neighbourhood (neighborhood), suburb (locality), city_district (district).'),
    ];

    $mappings = $config->get('district_mappings') ?: [];

    // Provide sensible defaults for the form if no mappings exist.
    if (empty($mappings)) {
      $mappings = [
        [
          'geocoder_properties' => ['city_district', 'borough'],
          'field' => 'field_district',
          'vocabulary' => 'district',
          'auto_create' => FALSE,
        ],
      ];
    }

    $form['district_mapping']['mappings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Geocoder properties (fallback chain)'),
        $this->t('Field'),
        $this->t('Vocabulary'),
        $this->t('Auto-create'),
        $this->t('Enabled'),
      ],
    ];

    $availableFields = [
      'field_district' => 'field_district (District)',
      'field_sublocality' => 'field_sublocality (Sublocality)',
    ];

    $availableVocabs = [
      'district' => 'district',
      'sublocality' => 'sublocality',
    ];

    // Always show two rows: one for district, one for sublocality.
    $rows = [
      [
        'geocoder_properties' => $mappings[0]['geocoder_properties'] ?? ['city_district', 'borough'],
        'field' => $mappings[0]['field'] ?? 'field_district',
        'vocabulary' => $mappings[0]['vocabulary'] ?? 'district',
        'auto_create' => $mappings[0]['auto_create'] ?? FALSE,
        'enabled' => !empty($mappings[0]),
      ],
      [
        'geocoder_properties' => $mappings[1]['geocoder_properties'] ?? ['suburb', 'quarter', 'neighbourhood'],
        'field' => $mappings[1]['field'] ?? 'field_sublocality',
        'vocabulary' => $mappings[1]['vocabulary'] ?? 'sublocality',
        'auto_create' => $mappings[1]['auto_create'] ?? FALSE,
        'enabled' => !empty($mappings[1]),
      ],
    ];

    foreach ($rows as $i => $row) {
      $form['district_mapping']['mappings'][$i]['geocoder_properties'] = [
        '#type' => 'textfield',
        '#default_value' => implode(', ', $row['geocoder_properties']),
        '#description' => $i === 0 ? $this->t('Comma-separated, first match wins.') : '',
        '#size' => 40,
      ];
      $form['district_mapping']['mappings'][$i]['field'] = [
        '#type' => 'select',
        '#options' => $availableFields,
        '#default_value' => $row['field'],
      ];
      $form['district_mapping']['mappings'][$i]['vocabulary'] = [
        '#type' => 'select',
        '#options' => $availableVocabs,
        '#default_value' => $row['vocabulary'],
      ];
      $form['district_mapping']['mappings'][$i]['auto_create'] = [
        '#type' => 'checkbox',
        '#default_value' => $row['auto_create'],
      ];
      $form['district_mapping']['mappings'][$i]['enabled'] = [
        '#type' => 'checkbox',
        '#default_value' => $row['enabled'],
      ];
    }

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
    $mappingsInput = $form_state->getValue('mappings') ?: [];
    $mappings = [];

    foreach ($mappingsInput as $row) {
      if (empty($row['enabled'])) {
        continue;
      }
      $properties = array_map('trim', explode(',', $row['geocoder_properties']));
      $properties = array_filter($properties);

      if (empty($properties) || empty($row['field']) || empty($row['vocabulary'])) {
        continue;
      }

      $mappings[] = [
        'geocoder_properties' => array_values($properties),
        'field' => $row['field'],
        'vocabulary' => $row['vocabulary'],
        'auto_create' => (bool) $row['auto_create'],
      ];
    }

    $this->config('markaspot_geocoder.settings')
      ->set('provider', $form_state->getValue('provider'))
      ->set('mapbox_token', $form_state->getValue('mapbox_token'))
      ->set('language', $form_state->getValue('language'))
      ->set('district_mappings', $mappings)
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
