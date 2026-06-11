<?php

namespace Drupal\markaspot_emergency\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure emergency mode settings.
 */
class EmergencySettingsForm extends ConfigFormBase {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
    $instance->state = $container->get('state');
    $instance->entityTypeManager = $container->get('entity_type.manager');
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
    $config = $this->config('markaspot_emergency.settings');

    // Read runtime status from State (not Config).
    $currentStatus = $this->emergencyService->getStatus();
    $restore_queue = $this->state->get('markaspot_emergency.original_published_tids', []);
    $restore_count = is_array($restore_queue) ? count($restore_queue) : 0;
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $form['emergency_mode'] = [
      '#type' => 'details',
      '#title' => $this->t('Emergency Mode Configuration'),
      '#open' => TRUE,
    ];

    $form['emergency_mode']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Current Status'),
      '#options' => [
        'off' => $this->t('Off'),
        'active' => $this->t('Active'),
      ],
      '#default_value' => $currentStatus,
      '#description' => $this->t('Current emergency mode status.'),
    ];

    $form['emergency_mode']['mode_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Mode Type'),
      '#options' => [
        'disaster' => $this->t('Disaster'),
        'crisis' => $this->t('Crisis'),
        'maintenance' => $this->t('Maintenance'),
      ],
      '#default_value' => $config->get('emergency_mode.mode_type'),
      '#description' => $this->t('Type of emergency mode to activate.'),
    ];

    $form['emergency_mode']['force_redirect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force redirect to lite UI'),
      '#default_value' => (bool) $config->get('emergency_mode.force_redirect'),
      '#description' => $this->t('Automatically redirect all users to the lite UI when emergency mode is active.'),
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
      '#default_value' => (bool) $config->get('categories.unpublish_regular'),
      '#description' => $this->t('Automatically unpublish all regular (non-emergency) categories when activating emergency mode.'),
    ];

    $form['categories']['restore_on_deactivation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Restore categories on deactivation'),
      '#default_value' => (bool) $config->get('categories.restore_on_deactivation'),
      '#description' => $this->t('Automatically restore regular categories when deactivating emergency mode.'),
    ];

    $form['auto_deactivate'] = [
      '#type' => 'details',
      '#title' => $this->t('Auto-deactivation'),
      '#open' => TRUE,
    ];

    $form['auto_deactivate']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable auto-deactivation'),
      '#default_value' => (bool) $config->get('auto_deactivate.enabled'),
      '#description' => $this->t('Automatically deactivate emergency mode after a specified duration.'),
    ];

    $form['auto_deactivate']['duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Duration (hours)'),
      '#default_value' => $config->get('auto_deactivate.duration'),
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

    $default_allowed = (array) ($config->get('allowed_urls') ?? []);
    $form['routing']['allowed_urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed URLs (one per line)'),
      '#default_value' => implode("\n", $default_allowed),
      '#description' => $this->t('Paths that will NOT be redirected during emergency or maintenance force-redirect (e.g., "/", "/sos", "/api/emergency-mode/status"). One per line; must start with "/".'),
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
      '#default_value' => (bool) $config->get('maintenance.unpublish_non_selected'),
      '#description' => $this->t('If enabled, only the selected categories below remain published while maintenance mode is active. Others will be temporarily unpublished and restored on deactivation.'),
    ];

    $selected_tids = $config->get('maintenance.show_only_categories') ?: [];
    $selected_terms = !empty($selected_tids) ? $term_storage->loadMultiple($selected_tids) : [];

    $form['maintenance']['maintenance_show_only_categories'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Categories to keep published'),
      '#target_type' => 'taxonomy_term',
      '#selection_settings' => [
        'target_bundles' => ['service_category' => 'service_category'],
      ],
      '#tags' => TRUE,
      '#default_value' => $selected_terms,
      '#description' => $this->t('Select service categories to keep published when maintenance mode is active (used if the option above is enabled).'),
    ];

    $form['maintenance']['maintenance_force_redirect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force redirect to lite UI in maintenance'),
      '#default_value' => (bool) $config->get('maintenance.force_redirect'),
      '#description' => $this->t('Usually disabled. Enable only if you want to use the lite UI during maintenance.'),
    ];

    $form['maintenance']['maintenance_banner_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Maintenance banner text'),
      '#default_value' => (string) ($config->get('maintenance.banner_text') ?: ''),
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
      '#default_value' => (bool) $config->get('banner.enabled'),
      '#description' => $this->t('Allow emergency banners to be displayed on the frontend.'),
    ];

    $form['banner']['banner_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Banner message'),
      '#default_value' => (string) ($config->get('banner.message') ?: ''),
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
      '#default_value' => (string) ($config->get('banner.level') ?: 'info'),
      '#description' => $this->t('Choose alert level. CAP levels follow international Common Alerting Protocol standards.'),
      '#states' => [
        'visible' => [':input[name="banner_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['banner']['banner_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Banner title (optional)'),
      '#default_value' => (string) ($config->get('banner.title') ?: ''),
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
      '#default_value' => (bool) $config->get('banner.display_conditions.emergency_mode_only'),
      '#description' => $this->t('Banner will only be visible when emergency mode is active.'),
    ];

    $form['banner']['display_conditions']['maintenance_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show during maintenance mode'),
      '#default_value' => (bool) ($config->get('banner.display_conditions.maintenance_mode') ?? TRUE),
      '#description' => $this->t('Banner will be visible during maintenance mode.'),
    ];

    $form['banner']['display_conditions']['always_visible'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always visible'),
      '#default_value' => (bool) $config->get('banner.display_conditions.always_visible'),
      '#description' => $this->t('Banner will always be visible regardless of emergency/maintenance mode.'),
    ];

    $form['actions']['activate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Activate Emergency Mode'),
      '#submit' => ['::activateEmergencyMode'],
      '#button_type' => 'danger',
    ];

    $form['actions']['deactivate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Deactivate Emergency Mode'),
      '#submit' => ['::deactivateEmergencyMode'],
      '#states' => [
        'visible' => [
          ':input[name="status"]' => ['value' => 'active'],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Detect status transition to trigger side-effects from the UI Save action.
    $previousStatus = $this->emergencyService->getStatus();
    $newStatus = $form_state->getValue('status');

    if ($newStatus !== $previousStatus) {
      if ($newStatus === 'active') {
        $this->emergencyService->activate(
          modeType: $form_state->getValue('mode_type') ?? 'disaster',
          forceRedirect: (bool) $form_state->getValue('force_redirect'),
          liteUi: TRUE,
          unpublishCategories: (bool) $form_state->getValue('unpublish_regular'),
          createEmergencyCategories: TRUE,
        );
      }
      elseif ($previousStatus === 'active' && $newStatus === 'off') {
        $this->emergencyService->deactivate(
          restoreCategories: (bool) $form_state->getValue('restore_on_deactivation'),
        );
      }
    }

    // Save policy/display config (never runtime status here).
    $this->config('markaspot_emergency.settings')
      ->set('emergency_mode.mode_type', $form_state->getValue('mode_type'))
      ->set('emergency_mode.force_redirect', (bool) $form_state->getValue('force_redirect'))
      ->set('categories.unpublish_regular', (bool) $form_state->getValue('unpublish_regular'))
      ->set('categories.restore_on_deactivation', (bool) $form_state->getValue('restore_on_deactivation'))
      ->set('auto_deactivate.enabled', (bool) $form_state->getValue('enabled'))
      ->set('auto_deactivate.duration', (int) $form_state->getValue('duration'))
      ->set('allowed_urls', (function ($raw) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $clean = [];
        foreach ($lines as $line) {
          $v = trim($line);
          if ($v === '') {
            continue;
          }
          if ($v[0] !== '/') {
            $v = '/' . $v;
          }
          $clean[] = $v;
        }
        return array_values(array_unique($clean));
      })($form_state->getValue('allowed_urls')))
      ->set('maintenance.unpublish_non_selected', (bool) $form_state->getValue('maintenance_unpublish_non_selected'))
      ->set('maintenance.force_redirect', (bool) $form_state->getValue('maintenance_force_redirect'))
      ->set('maintenance.banner_text', (string) $form_state->getValue('maintenance_banner_text'))
      ->set('maintenance.show_only_categories', array_values(array_filter(array_map(function ($item) {
        return isset($item['target_id']) ? (int) $item['target_id'] : NULL;
      }, (array) $form_state->getValue('maintenance_show_only_categories')))))
      ->set('banner.enabled', (bool) $form_state->getValue('banner_enabled'))
      ->set('banner.message', (string) $form_state->getValue('banner_message'))
      ->set('banner.level', (string) $form_state->getValue('banner_level'))
      ->set('banner.title', (string) $form_state->getValue('banner_title'))
      ->set('banner.display_conditions.emergency_mode_only', (bool) $form_state->getValue('emergency_mode_only'))
      ->set('banner.display_conditions.maintenance_mode', (bool) $form_state->getValue('maintenance_mode'))
      ->set('banner.display_conditions.always_visible', (bool) $form_state->getValue('always_visible'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Form submission handler for activating emergency mode.
   */
  public function activateEmergencyMode(array &$form, FormStateInterface $form_state): void {
    try {
      $this->emergencyService->activate(
        modeType: $form_state->getValue('mode_type') ?? 'disaster',
        forceRedirect: (bool) $form_state->getValue('force_redirect'),
        liteUi: TRUE,
        unpublishCategories: (bool) $form_state->getValue('unpublish_regular'),
        createEmergencyCategories: TRUE,
      );
      $this->messenger()->addStatus($this->t('Emergency mode has been activated.'));
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
      $this->emergencyService->deactivate(
        restoreCategories: (bool) $form_state->getValue('restore_on_deactivation'),
      );
      $this->messenger()->addStatus($this->t('Emergency mode has been deactivated.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Error deactivating emergency mode: @error', ['@error' => $e->getMessage()]));
    }
  }

}
