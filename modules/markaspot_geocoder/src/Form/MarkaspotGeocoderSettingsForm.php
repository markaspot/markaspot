<?php

namespace Drupal\markaspot_geocoder\Form;

use Psr\Log\LoggerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure server-side geocoding settings.
 *
 * The form follows two non-negotiable rules:
 * - The Mapbox access token is never echoed back into HTML. The field uses
 *   a password input with an empty default. An empty submit preserves the
 *   stored token. When GEOCODER_API_KEY is set in the environment, the
 *   field is disabled and the config write is skipped so the token cannot
 *   drift into exported config via drush cex.
 * - District mappings are never dropped silently. The full sequence stored
 *   in config is rendered one row per mapping, plus one empty add-row. An
 *   AJAX "Add mapping row" button grows the table. Submit walks the full
 *   set. Field and vocabulary selects are populated from the live entity
 *   field map and the taxonomy vocabulary storage so previously stored
 *   values stay visible even when the underlying field or vocabulary was
 *   renamed: missing values appear as disabled "(missing)" options.
 */
class MarkaspotGeocoderSettingsForm extends ConfigFormBase {

  /**
   * Minimum number of mapping rows rendered for new installs.
   */
  private const MIN_MAPPING_ROWS = 1;

  /**
   * The entity field manager service.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The entity type manager service.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel for this module.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    /** @var \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory */
    $loggerFactory = $container->get('logger.factory');
    $instance->logger = $loggerFactory->get('markaspot_geocoder');
    return $instance;
  }

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

    $tokenStored = (string) ($config->get('mapbox_token') ?? '');

    $form['markaspot_geocoder']['mapbox_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Mapbox Access Token'),
      // Never echo the stored token back into HTML.
      '#default_value' => '',
      '#description' => $this->t('Prefer the GEOCODER_API_KEY environment variable for production deployments. Leave this field empty to keep the existing stored token; enter a value only to replace it. Tokens entered here are written to Drupal configuration and may end up in config exports.'),
      '#attributes' => [
        'autocomplete' => 'new-password',
      ],
      '#states' => [
        'visible' => [
          ':input[name="provider"]' => [
            ['value' => 'mapbox'],
          ],
        ],
      ],
    ];

    if ($envApiKey) {
      // ENV is the source of truth. Disable the form field and skip the
      // config write in submitForm() so the token cannot drift into config.
      $form['markaspot_geocoder']['mapbox_token']['#disabled'] = TRUE;
      $form['markaspot_geocoder']['mapbox_token']['#description'] = $this->t('Managed via the GEOCODER_API_KEY environment variable. Saving this form will not modify the stored token.');
    }
    elseif ($tokenStored !== '') {
      $form['markaspot_geocoder']['mapbox_token']['#description'] = $this->t('A token is currently stored in configuration. Leave empty to keep it; enter a new value to replace it. Prefer the GEOCODER_API_KEY environment variable for production deployments.');
    }

    $form['markaspot_geocoder']['language'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Language'),
      '#default_value' => $config->get('language') ?: 'de',
      '#description' => $this->t('Language code for geocoding results (e.g. de, en, fr).'),
      '#size' => 5,
    ];

    // District mapping table.
    $form['district_mapping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('District Taxonomy Mapping'),
      '#description' => $this->t('Map geocoder response properties to taxonomy vocabularies. Each mapping defines which geocoder property populates which taxonomy field on service requests. Available properties depend on the provider: Nominatim returns suburb, quarter, neighbourhood, city_district, borough. Mapbox returns neighbourhood (neighborhood), suburb (locality), city_district (district).'),
    ];

    $form['district_mapping']['mappings_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'markaspot-geocoder-mappings-wrapper',
      ],
    ];

    $form['district_mapping']['mappings_wrapper']['mappings'] = $this->buildMappingsTable($form_state, $config->get('district_mappings') ?: []);

    $form['district_mapping']['mappings_wrapper']['add_row'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add mapping row'),
      '#submit' => ['::addMappingRowSubmit'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::mappingsAjaxCallback',
        'wrapper' => 'markaspot-geocoder-mappings-wrapper',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds the dynamic district-mapping table element.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $storedMappings
   *   The mappings sequence currently persisted in config.
   *
   * @return array
   *   A renderable table element.
   */
  protected function buildMappingsTable(FormStateInterface $form_state, array $storedMappings): array {
    // Row count is driven by form state across AJAX rebuilds. On first render
    // we seed it from the stored count, with one extra row so a fresh install
    // still shows a usable empty row to start from.
    $storedCount = count($storedMappings);
    $rowCount = $form_state->get('mapping_row_count');
    if ($rowCount === NULL) {
      $rowCount = max($storedCount + 1, self::MIN_MAPPING_ROWS + 1);
      $form_state->set('mapping_row_count', $rowCount);
    }

    [$fieldOptions, $vocabOptions] = $this->buildFieldAndVocabOptions($storedMappings);

    $table = [
      '#type' => 'table',
      '#header' => [
        $this->t('Geocoder properties (fallback chain)'),
        $this->t('Field'),
        $this->t('Vocabulary'),
        $this->t('Auto-create'),
        $this->t('Enabled'),
        $this->t('Delete'),
      ],
      '#tree' => TRUE,
      '#empty' => $this->t('No mappings configured.'),
    ];

    for ($i = 0; $i < $rowCount; $i++) {
      $stored = $storedMappings[$i] ?? [];
      $properties = $stored['geocoder_properties'] ?? [];
      $field = (string) ($stored['field'] ?? '');
      $vocab = (string) ($stored['vocabulary'] ?? '');
      $autoCreate = !empty($stored['auto_create']);
      $enabled = $stored !== [];

      $rowFieldOptions = $this->ensureOptionVisible($fieldOptions, $field);
      $rowVocabOptions = $this->ensureOptionVisible($vocabOptions, $vocab);

      $table[$i]['geocoder_properties'] = [
        '#type' => 'textfield',
        '#default_value' => is_array($properties) ? implode(', ', $properties) : '',
        '#description' => $i === 0 ? $this->t('Comma-separated, first match wins.') : '',
        '#size' => 40,
      ];
      $table[$i]['field'] = [
        '#type' => 'select',
        '#options' => $rowFieldOptions,
        '#empty_option' => $this->t('- Select field -'),
        '#default_value' => $field !== '' ? $field : NULL,
      ];
      $table[$i]['vocabulary'] = [
        '#type' => 'select',
        '#options' => $rowVocabOptions,
        '#empty_option' => $this->t('- Select vocabulary -'),
        '#default_value' => $vocab !== '' ? $vocab : NULL,
      ];
      $table[$i]['auto_create'] = [
        '#type' => 'checkbox',
        '#default_value' => $autoCreate,
      ];
      $table[$i]['enabled'] = [
        '#type' => 'checkbox',
        '#default_value' => $enabled,
      ];
      $table[$i]['delete'] = [
        '#type' => 'checkbox',
        '#default_value' => FALSE,
      ];
    }

    return $table;
  }

  /**
   * Submit handler for the "Add mapping row" AJAX button.
   */
  public function addMappingRowSubmit(array &$form, FormStateInterface $form_state): void {
    $count = $form_state->get('mapping_row_count') ?? self::MIN_MAPPING_ROWS;
    $form_state->set('mapping_row_count', $count + 1);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback that returns the rebuilt mappings wrapper.
   */
  public function mappingsAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['district_mapping']['mappings_wrapper'];
  }

  /**
   * Builds the field and vocabulary option lists for the mapping selects.
   *
   * Falls back to a static option list when the entity field manager cannot
   * resolve any taxonomy reference fields on node:service_request (fresh
   * profile install before bundle field map is populated). Stored values
   * that no longer resolve are merged in as "(missing)" entries by the
   * caller via ::ensureOptionVisible() so existing config stays editable.
   *
   * @param array $storedMappings
   *   The mappings sequence currently persisted in config.
   *
   * @return array
   *   A two-element list [$fieldOptions, $vocabOptions].
   */
  protected function buildFieldAndVocabOptions(array $storedMappings): array {
    $fieldOptions = [];
    try {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'service_request');
      foreach ($definitions as $name => $definition) {
        if ($definition->getType() !== 'entity_reference') {
          continue;
        }
        $settings = $definition->getSettings();
        if (($settings['target_type'] ?? NULL) !== 'taxonomy_term') {
          continue;
        }
        $fieldOptions[$name] = sprintf('%s (%s)', $name, (string) $definition->getLabel());
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not load node:service_request field definitions for mapping form | Type: @type', [
        '@type' => get_class($e),
      ]);
    }

    if ($fieldOptions === []) {
      $this->logger->notice('No taxonomy reference fields found on node:service_request, falling back to static defaults in the geocoder mapping form.');
      $fieldOptions = [
        'field_district' => 'field_district',
        'field_sublocality' => 'field_sublocality',
      ];
      foreach ($storedMappings as $mapping) {
        $name = (string) ($mapping['field'] ?? '');
        if ($name !== '' && !isset($fieldOptions[$name])) {
          $fieldOptions[$name] = $name;
        }
      }
    }

    asort($fieldOptions, SORT_NATURAL);

    $vocabOptions = [];
    try {
      $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
      foreach ($vocabularies as $vid => $vocabulary) {
        $vocabOptions[$vid] = sprintf('%s (%s)', (string) $vocabulary->label(), $vid);
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not load taxonomy vocabularies for mapping form | Type: @type', [
        '@type' => get_class($e),
      ]);
    }

    if ($vocabOptions === []) {
      foreach ($storedMappings as $mapping) {
        $vid = (string) ($mapping['vocabulary'] ?? '');
        if ($vid !== '' && !isset($vocabOptions[$vid])) {
          $vocabOptions[$vid] = $vid;
        }
      }
    }

    asort($vocabOptions, SORT_NATURAL);

    return [$fieldOptions, $vocabOptions];
  }

  /**
   * Ensures a stored option remains visible in a select, marked as missing.
   *
   * @param array $options
   *   The base option list keyed by machine name.
   * @param string $stored
   *   The stored value that must remain selectable.
   *
   * @return array
   *   The option list with the stored value appended if it was missing.
   */
  protected function ensureOptionVisible(array $options, string $stored): array {
    if ($stored === '' || isset($options[$stored])) {
      return $options;
    }
    $options[$stored] = sprintf('%s (missing)', $stored);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $provider = $form_state->getValue('provider');
    $token = trim((string) ($form_state->getValue('mapbox_token') ?? ''));
    $envApiKey = getenv('GEOCODER_API_KEY');
    $storedToken = (string) ($this->config('markaspot_geocoder.settings')->get('mapbox_token') ?? '');

    if ($provider === 'mapbox' && $token === '' && $storedToken === '' && !$envApiKey) {
      $form_state->setErrorByName('mapbox_token', $this->t('Mapbox provider requires an access token (or set the GEOCODER_API_KEY environment variable).'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('markaspot_geocoder.settings');

    $config
      ->set('provider', (string) $form_state->getValue('provider'))
      ->set('language', (string) $form_state->getValue('language'));

    // Token handling:
    // - ENV set: never write the config, ENV is the source of truth.
    // - ENV unset and empty submit: leave the stored token untouched.
    // - ENV unset and non-empty submit: replace the stored token.
    if (!getenv('GEOCODER_API_KEY')) {
      $submittedToken = trim((string) ($form_state->getValue('mapbox_token') ?? ''));
      if ($submittedToken !== '') {
        $config->set('mapbox_token', $submittedToken);
      }
    }

    $config->set('district_mappings', $this->collectMappings($form_state))->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Collects the district-mapping sequence from the submitted form values.
   *
   * Walks every submitted row and skips rows that are unchecked, marked for
   * deletion, or missing required values. No silent drops: a row that was
   * present in the form is also present in the submitted set; only the
   * explicit enabled/delete controls and emptiness filter rows out.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The sanitized mappings sequence ready to be written to config.
   */
  protected function collectMappings(FormStateInterface $form_state): array {
    $rows = $form_state->getValue('mappings') ?: [];
    $mappings = [];

    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (empty($row['enabled']) || !empty($row['delete'])) {
        continue;
      }

      $properties = array_values(array_filter(array_map(
        static fn ($p) => is_scalar($p) ? trim((string) $p) : '',
        explode(',', (string) ($row['geocoder_properties'] ?? ''))
      )));
      $field = is_scalar($row['field'] ?? NULL) ? trim((string) $row['field']) : '';
      $vocab = is_scalar($row['vocabulary'] ?? NULL) ? trim((string) $row['vocabulary']) : '';

      if ($properties === [] || $field === '' || $vocab === '') {
        continue;
      }

      $mappings[] = [
        'field' => $field,
        'vocabulary' => $vocab,
        'geocoder_properties' => $properties,
        'auto_create' => !empty($row['auto_create']),
      ];
    }

    return $mappings;
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
