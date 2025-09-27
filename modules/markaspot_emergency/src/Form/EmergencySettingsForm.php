<?php

namespace Drupal\markaspot_emergency\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Configure emergency mode settings.
 */
class EmergencySettingsForm extends ConfigFormBase {

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
    $restore_queue = \Drupal::state()->get('markaspot_emergency.original_published_tids', []);
    $restore_count = is_array($restore_queue) ? count($restore_queue) : 0;

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
        'standby' => $this->t('Standby'),
        'active' => $this->t('Active'),
      ],
      '#default_value' => $config->get('emergency_mode.status'),
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
      '#default_value' => $config->get('emergency_mode.force_redirect'),
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
      '#default_value' => $config->get('categories.unpublish_regular'),
      '#description' => $this->t('Automatically unpublish all regular (non-emergency) categories when activating emergency mode.'),
    ];

    $form['categories']['restore_on_deactivation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Restore categories on deactivation'),
      '#default_value' => $config->get('categories.restore_on_deactivation'),
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
      '#default_value' => $config->get('auto_deactivate.enabled'),
      '#description' => $this->t('Automatically deactivate emergency mode after a specified duration.'),
    ];

    $form['auto_deactivate']['duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Duration (hours)'),
      '#default_value' => $config->get('auto_deactivate.duration'),
      '#description' => $this->t('Number of hours after which emergency mode will be automatically deactivated.'),
      '#min' => 1,
      '#max' => 168, // 1 week
      '#states' => [
        'visible' => [
          // Match the nested element name to ensure the state works.
          ':input[name="auto_deactivate[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'details',
      '#title' => $this->t('Emergency Actions'),
      '#open' => FALSE,
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
    $current = $this->config('markaspot_emergency.settings');
    $previous_status = $current->get('emergency_mode.status');
    $new_status = $form_state->getValue('status');

    // Trigger activation/deactivation when status changed via Save.
    if ($new_status !== $previous_status) {
      $controller = \Drupal::service('markaspot_emergency.controller');

      if ($new_status === 'active') {
        $payload = json_encode([
          'mode_type' => $form_state->getValue('mode_type'),
          'force_redirect' => $form_state->getValue('force_redirect'),
          'unpublish_categories' => $form_state->getValue('unpublish_regular'),
          'create_emergency_categories' => TRUE,
        ]);
        $request = new Request([], [], [], [], [], [], $payload);
        $controller->activate($request);
      }
      elseif ($previous_status === 'active' && $new_status === 'off') {
        $payload = json_encode([
          'restore_categories' => $form_state->getValue('restore_on_deactivation'),
        ]);
        $request = new Request([], [], [], [], [], [], $payload);
        $controller->deactivate($request);
      }
    }

    $this->config('markaspot_emergency.settings')
      ->set('emergency_mode.status', $new_status)
      ->set('emergency_mode.mode_type', $form_state->getValue('mode_type'))
      ->set('emergency_mode.force_redirect', $form_state->getValue('force_redirect'))
      ->set('categories.unpublish_regular', $form_state->getValue('unpublish_regular'))
      ->set('categories.restore_on_deactivation', $form_state->getValue('restore_on_deactivation'))
      ->set('auto_deactivate.enabled', $form_state->getValue('enabled'))
      ->set('auto_deactivate.duration', $form_state->getValue('duration'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Form submission handler for activating emergency mode.
   */
  public function activateEmergencyMode(array &$form, FormStateInterface $form_state) {
    try {
      $controller = \Drupal::service('markaspot_emergency.controller');
      // Build a JSON request matching the controller signature.
      $payload = json_encode([
        'mode_type' => $form_state->getValue('mode_type'),
        'force_redirect' => $form_state->getValue('force_redirect'),
        'unpublish_categories' => $form_state->getValue('unpublish_regular'),
        'create_emergency_categories' => TRUE,
      ]);
      $request = new Request([], [], [], [], [], [], $payload);

      $controller->activate($request);

      $this->messenger()->addStatus($this->t('Emergency mode has been activated.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error activating emergency mode: @error', ['@error' => $e->getMessage()]));
    } catch (\Error $e) {
      $this->messenger()->addError($this->t('Error activating emergency mode: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Form submission handler for deactivating emergency mode.
   */
  public function deactivateEmergencyMode(array &$form, FormStateInterface $form_state) {
    try {
      $controller = \Drupal::service('markaspot_emergency.controller');
      $payload = json_encode([
        'restore_categories' => $form_state->getValue('restore_on_deactivation'),
      ]);
      $request = new Request([], [], [], [], [], [], $payload);

      $controller->deactivate($request);

      $this->messenger()->addStatus($this->t('Emergency mode has been deactivated.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error deactivating emergency mode: @error', ['@error' => $e->getMessage()]));
    } catch (\Error $e) {
      $this->messenger()->addError($this->t('Error deactivating emergency mode: @error', ['@error' => $e->getMessage()]));
    }
  }

}
