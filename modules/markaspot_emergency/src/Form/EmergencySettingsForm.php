<?php

namespace Drupal\markaspot_emergency\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure emergency mode settings.
 */
class EmergencySettingsForm extends ConfigFormBase {

  /**
   * The emergency mode service.
   *
   * @var \Drupal\markaspot_emergency\Service\EmergencyModeService
   */
  protected EmergencyModeService $emergencyService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->emergencyService = $container->get('markaspot_emergency.service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['markaspot_emergency.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_emergency_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $jurisdictionOptions = $this->emergencyService->getRootJurisdictionOptions();
    $multipleJurisdictions = count($jurisdictionOptions) > 1;
    $selectedJurisdiction = '';
    $queryParameters = $this->getRequest()->query->all();
    $requestedJurisdiction = $queryParameters['jurisdiction_id'] ?? NULL;
    if (is_string($requestedJurisdiction) || is_int($requestedJurisdiction)) {
      try {
        $resolved = $this->emergencyService->resolveRootJurisdictionId($requestedJurisdiction);
        if (isset($jurisdictionOptions[$resolved])) {
          $selectedJurisdiction = $resolved;
        }
      }
      catch (\InvalidArgumentException) {
        // Keep the explicit empty selection for invalid query values.
      }
    }
    if (!$multipleJurisdictions) {
      $selectedJurisdiction = (int) array_key_first($jurisdictionOptions);
    }

    $policy = $selectedJurisdiction === ''
      ? $this->emergencyService->getPolicyDefaults()
      : $this->emergencyService->getPolicy((int) $selectedJurisdiction);
    $modeState = $selectedJurisdiction === ''
      ? [
        'status' => 'off',
        'mode_type' => $policy['mode_type'],
        'force_redirect' => $policy['force_redirect'],
        'lite_ui' => $policy['lite_ui'],
        'revision' => 0,
        'snapshot' => [],
      ]
      : $this->emergencyService->getModeState((int) $selectedJurisdiction);
    $currentStatus = $modeState['status'];
    $restore_count = count($modeState['snapshot']);

    $form['jurisdiction_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Root jurisdiction'),
      '#options' => $multipleJurisdictions
        ? ['' => $this->t('- Select a root jurisdiction -')] + $jurisdictionOptions
        : $jurisdictionOptions,
      '#default_value' => $selectedJurisdiction,
      '#required' => TRUE,
      '#description' => $this->t('Emergency mode state and category switching are isolated to this root jurisdiction.'),
    ];

    $form['loaded_jurisdiction_id'] = [
      '#type' => 'hidden',
      '#value' => $selectedJurisdiction,
    ];

    if ($multipleJurisdictions) {
      $form['load_jurisdiction'] = [
        '#type' => 'submit',
        '#value' => $this->t('Load jurisdiction'),
        '#submit' => ['::loadJurisdiction'],
        '#limit_validation_errors' => [['jurisdiction_id']],
      ];
    }

    $form['emergency_mode'] = [
      '#type' => 'details',
      '#title' => $this->t('Emergency Mode Configuration'),
      '#open' => TRUE,
    ];

    $form['emergency_mode']['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Current Status'),
      '#markup' => $selectedJurisdiction === ''
        ? $this->t('Select and load a root jurisdiction.')
        : $this->t('@status, revision @revision', [
          '@status' => strtoupper($currentStatus),
          '@revision' => $modeState['revision'],
        ]),
      '#description' => $this->t('Runtime state changes only through the explicit action buttons below.'),
    ];

    $form['emergency_mode']['mode_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Mode Type'),
      '#options' => [
        'disaster' => $this->t('Disaster'),
        'crisis' => $this->t('Crisis'),
        'maintenance' => $this->t('Maintenance'),
      ],
      '#default_value' => $currentStatus === 'active'
        ? $modeState['mode_type']
        : $policy['mode_type'],
      '#description' => $this->t('Type of emergency mode to activate.'),
    ];

    $form['emergency_mode']['force_redirect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force redirect to lite UI'),
      '#default_value' => $currentStatus === 'active'
        ? $modeState['force_redirect']
        : $policy['force_redirect'],
      '#description' => $this->t('Automatically redirect all users to the lite UI when emergency mode is active.'),
    ];

    $form['emergency_mode']['lite_ui'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable lite UI'),
      '#default_value' => $currentStatus === 'active'
        ? $modeState['lite_ui']
        : $policy['lite_ui'],
      '#description' => $this->t('Expose the reduced low-bandwidth interface for this runtime profile.'),
    ];

    $form['categories'] = [
      '#type' => 'details',
      '#title' => $this->t('Category Management'),
      '#open' => TRUE,
    ];

    $form['categories']['restore_queue_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Restore queue'),
      '#markup' => $restore_count > 0
        ? $this->t('@count categories will be restored on deactivation.', ['@count' => $restore_count])
        : $this->t('No categories queued for restoration.'),
    ];

    $form['categories']['unpublish_regular'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Unpublish regular categories'),
      '#default_value' => $policy['unpublish_regular'],
      '#description' => $this->t('Automatically unpublish all regular (non-emergency) categories when activating emergency mode.'),
    ];

    $form['auto_deactivate'] = [
      '#type' => 'details',
      '#title' => $this->t('Auto-deactivation'),
      '#open' => TRUE,
    ];

    $form['auto_deactivate']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable auto-deactivation'),
      '#default_value' => $policy['auto_deactivate']['enabled'],
      '#description' => $this->t('Automatically deactivate emergency mode after a specified duration.'),
    ];

    $form['auto_deactivate']['duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Duration (hours)'),
      '#default_value' => $policy['auto_deactivate']['duration'],
      '#description' => $this->t('Number of hours after which emergency mode will be automatically deactivated.'),
      '#min' => 1,
      '#max' => 168,
      '#states' => [
        'visible' => [
          // #tree is FALSE; Drupal renders the input as name="enabled".
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'details',
      '#title' => $this->t('Emergency Actions'),
      '#open' => FALSE,
    ];

    $form['routing'] = [
      '#type' => 'details',
      '#title' => $this->t('Routing Exceptions'),
      '#open' => FALSE,
    ];

    $default_allowed = $policy['allowed_urls'];
    $form['routing']['allowed_urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed URLs (one per line)'),
      '#default_value' => implode("\n", $default_allowed),
      '#description' => $this->t('Paths that will NOT be redirected during emergency or maintenance force-redirect (e.g., "/sos", "/api/emergency-mode/status"). The site root is forbidden. One per line; must start with "/".'),
      '#rows' => 4,
    ];

    $form['maintenance'] = [
      '#type' => 'details',
      '#title' => $this->t('Maintenance Mode'),
      '#open' => FALSE,
    ];

    $form['maintenance']['maintenance_unpublish_non_selected'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Unpublish non-selected categories during maintenance'),
      '#default_value' => $policy['maintenance']['unpublish_non_selected'],
      '#description' => $this->t('If enabled, only the selected categories below remain published while maintenance mode is active. Others will be temporarily unpublished and restored on deactivation.'),
    ];

    $categoryOptions = $selectedJurisdiction === ''
      ? []
      : $this->emergencyService->getScopedCategoryOptions((int) $selectedJurisdiction);
    $selected_tids = $selectedJurisdiction === ''
      ? []
      : $policy['maintenance']['show_only_categories'];
    $selected_tids = array_values(array_intersect($selected_tids, array_keys($categoryOptions)));

    $form['maintenance']['maintenance_show_only_categories'] = [
      '#type' => 'select',
      '#title' => $this->t('Categories to keep published'),
      '#options' => $categoryOptions,
      '#multiple' => TRUE,
      '#default_value' => $selected_tids,
      '#description' => $this->t('Select service categories to keep published when maintenance mode is active (used if the option above is enabled).'),
    ];

    $form['maintenance']['maintenance_force_redirect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force redirect to lite UI in maintenance'),
      '#default_value' => $currentStatus === 'active' && $modeState['mode_type'] === 'maintenance'
        ? $modeState['force_redirect']
        : $policy['maintenance']['force_redirect'],
      '#description' => $this->t('Usually disabled. Enable only if you want to use the lite UI during maintenance.'),
    ];

    $form['maintenance']['maintenance_banner_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Maintenance banner text'),
      '#default_value' => $policy['maintenance']['banner_text'],
      '#description' => $this->t('Optional message shown by the frontend while maintenance mode is active.'),
    ];

    $form['banner'] = [
      '#type' => 'details',
      '#title' => $this->t('CAP Alert Banner System'),
      '#description' => $this->t('Configure Common Alerting Protocol (CAP) compliant banners for high-priority information.'),
      '#open' => FALSE,
    ];

    $form['banner']['banner_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CAP banner system'),
      '#default_value' => $policy['banner']['enabled'],
      '#description' => $this->t('Allow emergency banners to be displayed on the frontend.'),
    ];

    $form['banner']['banner_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Banner message'),
      '#default_value' => $policy['banner']['message'],
      '#description' => $this->t('The text to display in the banner. Leave empty to hide banner.'),
      '#states' => [
        'visible' => [':input[name="banner_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['banner']['banner_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Alert level'),
      '#options' => [
        'info' => $this->t('Info (Blue)'),
        'minor' => $this->t('Minor - CAP (Blue)'),
        'moderate' => $this->t('Moderate - CAP (Amber)'),
        'severe' => $this->t('Severe - CAP (Red)'),
        'extreme' => $this->t('Extreme - CAP (Dark Red)'),
        'warning' => $this->t('Warning (Amber)'),
        'error' => $this->t('Error (Red)'),
        'critical' => $this->t('Critical (Dark Red)'),
        'success' => $this->t('Success (Green)'),
      ],
      '#default_value' => $policy['banner']['level'],
      '#description' => $this->t('Choose alert level. CAP levels follow international Common Alerting Protocol standards.'),
      '#states' => [
        'visible' => [':input[name="banner_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['banner']['banner_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Banner title (optional)'),
      '#default_value' => $policy['banner']['title'],
      '#description' => $this->t('Optional title for the banner. If empty, a default title based on alert level will be used.'),
      '#states' => [
        'visible' => [':input[name="banner_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['banner']['display_conditions'] = [
      '#type' => 'details',
      '#title' => $this->t('Display Conditions'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [':input[name="banner_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['banner']['display_conditions']['emergency_mode_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show only during emergency mode'),
      '#default_value' => $policy['banner']['display_conditions']['emergency_mode_only'],
      '#description' => $this->t('Banner will only be visible when emergency mode is active.'),
    ];

    $form['banner']['display_conditions']['maintenance_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show during maintenance mode'),
      '#default_value' => $policy['banner']['display_conditions']['maintenance_mode'],
      '#description' => $this->t('Banner will be visible during maintenance mode.'),
    ];

    $form['banner']['display_conditions']['always_visible'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always visible'),
      '#default_value' => $policy['banner']['display_conditions']['always_visible'],
      '#description' => $this->t('Banner will always be visible regardless of emergency/maintenance mode.'),
    ];

    $form['actions']['activate'] = [
      '#type' => 'submit',
      '#value' => $currentStatus === 'active'
        ? $this->t('Switch or update active profile')
        : $this->t('Activate emergency mode'),
      '#submit' => ['::activateEmergencyMode'],
      '#button_type' => 'danger',
      '#states' => $multipleJurisdictions ? [
        'disabled' => [':input[name="jurisdiction_id"]' => ['value' => '']],
      ] : [],
    ];

    $form['actions']['deactivate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Deactivate Emergency Mode'),
      '#submit' => ['::deactivateEmergencyMode'],
      '#access' => $currentStatus === 'active',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->savePolicySettings($form_state);
    parent::submitForm($form, $form_state);
  }

  /**
   * Persists every policy field rendered by this form.
   *
   * Runtime action buttons use the same path as the normal Save button so an
   * operator cannot activate a profile with stale banner or routing settings.
   */
  private function savePolicySettings(FormStateInterface $form_state): void {
    $jurisdictionId = (int) $form_state->getValue('jurisdiction_id');
    $this->emergencyService->savePolicy(
      $jurisdictionId,
      $this->buildPolicySettings($form_state),
    );
  }

  /**
   * Builds normalized policy input from the currently validated form values.
   */
  private function buildPolicySettings(FormStateInterface $form_state): array {
    return [
      'mode_type' => (string) $form_state->getValue('mode_type'),
      'force_redirect' => (bool) $form_state->getValue('force_redirect'),
      'lite_ui' => (bool) $form_state->getValue('lite_ui'),
      'unpublish_regular' => (bool) $form_state->getValue('unpublish_regular'),
      'auto_deactivate' => [
        'enabled' => (bool) $form_state->getValue('enabled'),
        'duration' => (int) $form_state->getValue('duration'),
      ],
      'allowed_urls' => $this->normalizeAllowedUrls($form_state->getValue('allowed_urls')),
      'maintenance' => [
        'unpublish_non_selected' => (bool) $form_state->getValue('maintenance_unpublish_non_selected'),
        'show_only_categories' => array_values(array_filter(array_map(
          'intval',
          (array) $form_state->getValue('maintenance_show_only_categories'),
        ))),
        'force_redirect' => (bool) $form_state->getValue('maintenance_force_redirect'),
        'banner_text' => (string) $form_state->getValue('maintenance_banner_text'),
      ],
      'banner' => [
        'enabled' => (bool) $form_state->getValue('banner_enabled'),
        'message' => (string) $form_state->getValue('banner_message'),
        'level' => (string) $form_state->getValue('banner_level'),
        'title' => (string) $form_state->getValue('banner_title'),
        'display_conditions' => [
          'emergency_mode_only' => (bool) $form_state->getValue('emergency_mode_only'),
          'maintenance_mode' => (bool) $form_state->getValue('maintenance_mode'),
          'always_visible' => (bool) $form_state->getValue('always_visible'),
        ],
      ],
    ];
  }

  /**
   * Normalizes configured redirect bypass paths.
   *
   * @return string[]
   *   Unique absolute site paths, never including the site root.
   */
  private function normalizeAllowedUrls(mixed $raw): array {
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $clean = [];
    foreach ($lines ?: [] as $line) {
      $path = trim($line);
      if ($path === '') {
        continue;
      }
      if ($path[0] !== '/') {
        $path = '/' . $path;
      }
      if ($path !== '/') {
        $clean[] = $path;
      }
    }
    return array_values(array_unique($clean));
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $jurisdiction = $form_state->getValue('jurisdiction_id');
    $loaded = $form_state->getValue('loaded_jurisdiction_id');
    if ($jurisdiction === '' || $jurisdiction === NULL) {
      $form_state->setErrorByName('jurisdiction_id', $this->t('Select and load a root jurisdiction.'));
      return;
    }
    $trigger = $form_state->getTriggeringElement();
    if (in_array('::loadJurisdiction', (array) ($trigger['#submit'] ?? []), TRUE)) {
      return;
    }
    if ((string) $jurisdiction !== (string) $loaded) {
      $form_state->setErrorByName('jurisdiction_id', $this->t('Load the selected jurisdiction before saving or changing runtime state.'));
      return;
    }

    $lines = preg_split('/\r\n|\r|\n/', (string) $form_state->getValue('allowed_urls'));
    if (in_array('/', array_map('trim', $lines ?: []), TRUE)) {
      $form_state->setErrorByName('allowed_urls', $this->t('The site root cannot bypass emergency redirect.'));
    }

    $selectedIds = array_values(array_filter(array_map(
      'intval',
      (array) $form_state->getValue('maintenance_show_only_categories'),
    )));
    $allowedIds = array_keys($this->emergencyService->getScopedCategoryOptions((int) $jurisdiction));
    if (array_diff($selectedIds, $allowedIds) !== []) {
      $form_state->setErrorByName('maintenance_show_only_categories', $this->t('Maintenance categories must belong to the loaded root jurisdiction.'));
    }

    $modeType = (string) $form_state->getValue('mode_type');
    $forceRedirect = $modeType === 'maintenance'
      ? (bool) $form_state->getValue('maintenance_force_redirect')
      : (bool) $form_state->getValue('force_redirect');
    if ($forceRedirect && !(bool) $form_state->getValue('lite_ui')) {
      $form_state->setErrorByName('lite_ui', $this->t('Lite UI must be enabled when the selected profile forces a redirect to it.'));
    }
  }

  /**
   * Reloads the form with an explicitly selected root jurisdiction.
   */
  public function loadJurisdiction(array &$form, FormStateInterface $form_state): void {
    $jurisdictionId = (int) $form_state->getValue('jurisdiction_id');
    $routeName = (string) $this->getRequest()->attributes->get('_route');
    $form_state->setRedirect($routeName, [], [
      'query' => ['jurisdiction_id' => $jurisdictionId],
    ]);
  }

  /**
   * Form submission handler for activating emergency mode.
   */
  public function activateEmergencyMode(array &$form, FormStateInterface $form_state): void {
    try {
      $jurisdictionId = (int) $form_state->getValue('jurisdiction_id');
      $modeType = (string) ($form_state->getValue('mode_type') ?? 'disaster');
      $this->emergencyService->activate(
        modeType: $modeType,
        forceRedirect: $modeType === 'maintenance'
          ? (bool) $form_state->getValue('maintenance_force_redirect')
          : (bool) $form_state->getValue('force_redirect'),
        liteUi: (bool) $form_state->getValue('lite_ui'),
        unpublishCategories: $modeType === 'maintenance'
          ? (bool) $form_state->getValue('maintenance_unpublish_non_selected')
          : (bool) $form_state->getValue('unpublish_regular'),
        createEmergencyCategories: TRUE,
        jurisdictionId: $jurisdictionId,
        policy: $this->buildPolicySettings($form_state),
      );
      $this->messenger()->addStatus($this->t('Emergency mode profile has been activated or updated.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Error activating emergency mode: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Form submission handler for deactivating emergency mode.
   */
  public function deactivateEmergencyMode(array &$form, FormStateInterface $form_state): void {
    try {
      $this->emergencyService->deactivate((int) $form_state->getValue('jurisdiction_id'));
      $this->messenger()->addStatus($this->t('Emergency mode has been deactivated.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Error deactivating emergency mode: @error', ['@error' => $e->getMessage()]));
    }
  }

}
