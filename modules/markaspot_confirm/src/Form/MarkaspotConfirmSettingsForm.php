<?php

namespace Drupal\markaspot_confirm\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Configure confirmation settings for this site.
 */
class MarkaspotConfirmSettingsForm extends ConfigFormBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_confirm_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_confirm.settings');

    $form['markaspot_confirm'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Confirmation Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure how confirmation emails and URLs are generated.'),
      '#group' => 'settings',
    ];

    // General Settings
    $form['markaspot_confirm']['general'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General Settings'),
      '#collapsible' => FALSE,
      '#weight' => 0,
    ];

    $form['markaspot_confirm']['general']['frontend_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Frontend Base URL'),
      '#default_value' => $config->get('frontend_base_url') ?: '',
      '#description' => $this->t('The base URL of your frontend application where confirmation links should point (e.g., https://example.com:3001). This value is also exposed via the [markaspot_frontend:url] token. If empty, Drupal backend URLs are used.'),
      '#placeholder' => 'https://example.com:3001',
    ];

    // Email Template Settings
    $form['markaspot_confirm']['email'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email Template Settings'),
      '#collapsible' => FALSE,
      '#weight' => 1,
    ];

    $form['markaspot_confirm']['email']['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email Subject'),
      '#default_value' => $config->get('email.subject') ?: $this->t('Please confirm your service request'),
      '#description' => $this->t('The subject line for confirmation emails. Tokens are supported.'),
      '#required' => TRUE,
    ];

    $form['markaspot_confirm']['email']['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email Body Template'),
      '#default_value' => $config->get('email.body') ?: $this->getDefaultEmailTemplate(),
      '#description' => $this->t('The email body template. Add any tokens or placeholders you populate via ECA or custom logic.'),
      '#rows' => 10,
      '#required' => TRUE,
    ];

    // API Settings
    $form['markaspot_confirm']['api'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Settings'),
      '#collapsible' => FALSE,
      '#weight' => 2,
    ];

    $form['markaspot_confirm']['api']['success_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Success Message'),
      '#default_value' => $config->get('api.success_message') ?: $this->t('Thanks for approving this request.'),
      '#description' => $this->t('Message returned when confirmation is successful.'),
      '#required' => TRUE,
    ];

    $form['markaspot_confirm']['api']['not_found_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Not Found Message'),
      '#default_value' => $config->get('api.not_found_message') ?: $this->t('This request could not be found.'),
      '#description' => $this->t('Message returned when the UUID is not found.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Extract values from fieldsets following markaspot_feedback pattern
    $general = $values['general'] ?? [];
    $email = $values['email'] ?? [];
    $api = $values['api'] ?? [];

    $this->config('markaspot_confirm.settings')
      // General settings
      ->set('frontend_base_url', $general['frontend_base_url'] ?? $values['frontend_base_url'])

      // Email settings
      ->set('email.subject', $email['subject'] ?? $values['subject'])
      ->set('email.body', $email['body'] ?? $values['body'])

      // API settings
      ->set('api.success_message', $api['success_message'] ?? $values['success_message'])
      ->set('api.not_found_message', $api['not_found_message'] ?? $values['not_found_message'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_confirm.settings',
    ];
  }

  /**
   * Get default email template.
   *
   * @return string
   *   Default email template.
   */
  private function getDefaultEmailTemplate() {
    return $this->t('Hello,

We have received your service request and need your confirmation to process it.

Please confirm your request by clicking the following link:
[confirmation_url]

Thank you for using our service.

Best regards,
The Service Team');
  }

}
