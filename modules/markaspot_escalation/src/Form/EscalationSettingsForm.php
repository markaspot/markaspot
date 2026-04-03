<?php

declare(strict_types=1);

namespace Drupal\markaspot_escalation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\RoleInterface;

/**
 * Configure escalation and delegation settings.
 */
class EscalationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_escalation_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['markaspot_escalation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('markaspot_escalation.settings');

    $form['escalation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable escalation'),
      '#description' => $this->t('Allow service requests to be escalated to a higher jurisdiction.'),
      '#default_value' => $config->get('escalation_enabled') ?? TRUE,
    ];

    $form['delegation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable delegation'),
      '#description' => $this->t('Allow service requests to be delegated to other organisations.'),
      '#default_value' => $config->get('delegation_enabled') ?? FALSE,
    ];

    $form['auto_escalation'] = [
      '#type' => 'details',
      '#title' => $this->t('Auto-escalation'),
      '#open' => TRUE,
    ];

    $form['auto_escalation']['auto_escalation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable auto-escalation'),
      '#description' => $this->t('Automatically escalate service requests via cron based on category-level escalation days.'),
      '#default_value' => $config->get('auto_escalation_enabled') ?? FALSE,
    ];

    $form['auto_escalation']['cron_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Cron interval'),
      '#description' => $this->t('Interval in seconds between auto-escalation cron runs.'),
      '#default_value' => $config->get('cron_interval') ?? 86400,
      '#min' => 60,
      '#max' => 604800,
      '#field_suffix' => $this->t('seconds'),
      '#states' => [
        'visible' => [
          ':input[name="auto_escalation_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Build role options from available user roles.
    $roles = user_role_names(TRUE);
    unset($roles[RoleInterface::AUTHENTICATED_ID]);

    $form['notification_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Notification roles'),
      '#description' => $this->t('Roles that receive email notifications on escalation and delegation events.'),
      '#options' => $roles,
      '#default_value' => $config->get('notification_roles') ?? [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $interval = (int) $form_state->getValue('cron_interval');
    if ($interval < 60) {
      $form_state->setErrorByName('cron_interval', $this->t('Cron interval must be at least 60 seconds.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('markaspot_escalation.settings')
      ->set('escalation_enabled', (bool) $form_state->getValue('escalation_enabled'))
      ->set('delegation_enabled', (bool) $form_state->getValue('delegation_enabled'))
      ->set('auto_escalation_enabled', (bool) $form_state->getValue('auto_escalation_enabled'))
      ->set('cron_interval', (int) $form_state->getValue('cron_interval'))
      ->set('notification_roles', array_values(array_filter($form_state->getValue('notification_roles'))))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
