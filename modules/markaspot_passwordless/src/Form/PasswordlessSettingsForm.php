<?php

namespace Drupal\markaspot_passwordless\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure markaspot_passwordless settings.
 */
class PasswordlessSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_passwordless_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['markaspot_passwordless.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_passwordless.settings');

    $form['auto_register'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic user registration'),
      '#description' => $this->t('When enabled, new user accounts will be automatically created when someone verifies their email with an OTP code. When disabled, only existing users can log in via passwordless authentication.'),
      '#default_value' => $config->get('auto_register') ?? FALSE,
    ];

    $form['code_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('OTP code lifetime (seconds)'),
      '#description' => $this->t('How long an OTP code remains valid before expiring. Default is 600 seconds (10 minutes).'),
      '#default_value' => $config->get('code_lifetime') ?? 600,
      '#min' => 60,
      '#max' => 3600,
      '#required' => TRUE,
    ];

    $form['max_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum verification attempts'),
      '#description' => $this->t('Maximum number of times a code can be verified before it becomes invalid. Default is 3 attempts.'),
      '#default_value' => $config->get('max_attempts') ?? 3,
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
    ];

    $form['rate_limiting'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate limiting'),
      '#open' => TRUE,
    ];

    $form['rate_limiting']['request_limit_per_email'] = [
      '#type' => 'number',
      '#title' => $this->t('Code requests per email (per hour)'),
      '#description' => $this->t('Maximum number of code requests allowed per email address per hour. Default is 3.'),
      '#default_value' => $config->get('request_limit_per_email') ?? 3,
      '#min' => 1,
      '#max' => 20,
      '#required' => TRUE,
    ];

    $form['rate_limiting']['request_limit_per_ip'] = [
      '#type' => 'number',
      '#title' => $this->t('Code requests per IP (per hour)'),
      '#description' => $this->t('Maximum number of code requests allowed per IP address per hour. Default is 10.'),
      '#default_value' => $config->get('request_limit_per_ip') ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['rate_limiting']['verify_lockout_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Failed verification attempts before lockout'),
      '#description' => $this->t('Number of failed verification attempts before account is locked. Default is 5.'),
      '#default_value' => $config->get('verify_lockout_attempts') ?? 5,
      '#min' => 3,
      '#max' => 20,
      '#required' => TRUE,
    ];

    $form['rate_limiting']['verify_lockout_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Lockout duration (seconds)'),
      '#description' => $this->t('How long to lock out after too many failed attempts. Default is 900 seconds (15 minutes).'),
      '#default_value' => $config->get('verify_lockout_duration') ?? 900,
      '#min' => 60,
      '#max' => 3600,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Ensure code_lifetime is not too short.
    $code_lifetime = $form_state->getValue('code_lifetime');
    if ($code_lifetime < 60) {
      $form_state->setErrorByName('code_lifetime', $this->t('Code lifetime must be at least 60 seconds.'));
    }

    // Ensure max_attempts is reasonable.
    $max_attempts = $form_state->getValue('max_attempts');
    if ($max_attempts < 1) {
      $form_state->setErrorByName('max_attempts', $this->t('Maximum attempts must be at least 1.'));
    }

    // Ensure lockout duration is sufficient.
    $verify_lockout_duration = $form_state->getValue('verify_lockout_duration');
    if ($verify_lockout_duration < 60) {
      $form_state->setErrorByName('verify_lockout_duration', $this->t('Lockout duration must be at least 60 seconds.'));
    }

    // Ensure rate limits are positive.
    if ($form_state->getValue('request_limit_per_email') < 1) {
      $form_state->setErrorByName('request_limit_per_email', $this->t('Request limit per email must be at least 1.'));
    }

    if ($form_state->getValue('request_limit_per_ip') < 1) {
      $form_state->setErrorByName('request_limit_per_ip', $this->t('Request limit per IP must be at least 1.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('markaspot_passwordless.settings')
      ->set('auto_register', $form_state->getValue('auto_register'))
      ->set('code_lifetime', $form_state->getValue('code_lifetime'))
      ->set('max_attempts', $form_state->getValue('max_attempts'))
      ->set('request_limit_per_email', $form_state->getValue('request_limit_per_email'))
      ->set('request_limit_per_ip', $form_state->getValue('request_limit_per_ip'))
      ->set('verify_lockout_attempts', $form_state->getValue('verify_lockout_attempts'))
      ->set('verify_lockout_duration', $form_state->getValue('verify_lockout_duration'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
