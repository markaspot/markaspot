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
    return [
      'markaspot_passwordless.settings',
      'markaspot_passwordless.mail',
    ];
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

    // Mail template settings.
    $mail_config = $this->config('markaspot_passwordless.mail');

    $form['mail'] = [
      '#type' => 'details',
      '#title' => $this->t('Email template'),
      '#open' => FALSE,
    ];

    $form['mail']['mail_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#description' => $this->t('Available tokens: @expires_in, @minutes, @platform_name. @code is intentionally not rendered in the subject.'),
      '#default_value' => $mail_config->get('verification_code.subject') ?? '',
      '#required' => TRUE,
    ];

    $form['mail']['mail_preheader'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preheader'),
      '#description' => $this->t('Inbox preview text. Available tokens: @expires_in, @minutes, @platform_name. @code is intentionally not rendered in the preheader.'),
      '#default_value' => $mail_config->get('verification_code.preheader') ?? '',
      '#required' => TRUE,
    ];

    $form['mail']['mail_headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Headline'),
      '#description' => $this->t('Headline shown above the code. Available tokens: @code, @expires_in, @minutes, @platform_name.'),
      '#default_value' => $mail_config->get('verification_code.headline') ?? '',
      '#required' => TRUE,
    ];

    $form['mail']['mail_subtext'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Subtext'),
      '#description' => $this->t('Text shown below the code. Available tokens: @code, @expires_in, @minutes, @platform_name.'),
      '#default_value' => $mail_config->get('verification_code.subtext') ?? '',
      '#rows' => 3,
      '#required' => TRUE,
    ];

    $form['mail']['mail_plain_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Plain text body'),
      '#description' => $this->t('Plain text fallback. Available tokens: @code, @expires_in, @minutes, @platform_name.'),
      '#default_value' => $mail_config->get('verification_code.plain_text') ?? '',
      '#rows' => 5,
      '#required' => TRUE,
    ];

    $form['mail']['mail_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Legacy body'),
      '#description' => $this->t('Legacy fallback for older templates and locale overrides. Available tokens: @code, @expires_in, @minutes, @platform_name. The jurisdiction email footer is appended automatically.'),
      '#default_value' => $mail_config->get('verification_code.body') ?? '',
      '#rows' => 8,
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

    $this->config('markaspot_passwordless.mail')
      ->set('verification_code.subject', $form_state->getValue('mail_subject'))
      ->set('verification_code.preheader', $form_state->getValue('mail_preheader'))
      ->set('verification_code.headline', $form_state->getValue('mail_headline'))
      ->set('verification_code.subtext', $form_state->getValue('mail_subtext'))
      ->set('verification_code.plain_text', $form_state->getValue('mail_plain_text'))
      ->set('verification_code.body', $form_state->getValue('mail_body'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
