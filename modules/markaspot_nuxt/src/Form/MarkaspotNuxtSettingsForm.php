<?php

namespace Drupal\markaspot_nuxt\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Nuxt frontend settings for this site.
 */
class MarkaspotNuxtSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_nuxt_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_nuxt.settings');

    $form['markaspot_nuxt'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Nuxt Frontend Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure frontend URL settings for Mark-a-Spot Nuxt integration.'),
      '#group' => 'settings',
    ];

    $form['markaspot_nuxt']['frontend'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Frontend Configuration'),
      '#collapsible' => FALSE,
      '#weight' => 0,
    ];

    $form['markaspot_nuxt']['frontend']['frontend_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Frontend Base URL'),
      '#default_value' => $config->get('frontend_base_url') ?: '',
      '#description' => $this->t('The base URL of your Nuxt frontend application (e.g., https://example.com:3001). This URL will be used for generating frontend links in emails and other communications and is available via the [markaspot_frontend:url] token. If empty, Drupal backend URLs will be used.'),
      '#placeholder' => 'https://example.com:3001',
    ];

    $form['markaspot_nuxt']['frontend']['frontend_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Frontend URL Generation'),
      '#default_value' => $config->get('frontend_enabled') ?: FALSE,
      '#description' => $this->t('When enabled, system-generated URLs (like confirmation links) will point to the frontend instead of the Drupal backend.'),
    ];

    $form['markaspot_nuxt']['api'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Configuration'),
      '#collapsible' => FALSE,
      '#weight' => 1,
    ];

    $form['markaspot_nuxt']['api']['api_cors_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CORS for API endpoints'),
      '#default_value' => $config->get('api_cors_enabled') ?: FALSE,
      '#description' => $this->t('Allow cross-origin requests to Mark-a-Spot API endpoints from the frontend domain.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Extract values from fieldsets
    $frontend = $values['frontend'] ?? [];
    $api = $values['api'] ?? [];

    $this->config('markaspot_nuxt.settings')
      // Frontend settings
      ->set('frontend_base_url', $frontend['frontend_base_url'] ?? $values['frontend_base_url'])
      ->set('frontend_enabled', $frontend['frontend_enabled'] ?? $values['frontend_enabled'])

      // API settings
      ->set('api_cors_enabled', $api['api_cors_enabled'] ?? $values['api_cors_enabled'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_nuxt.settings',
    ];
  }

}
