<?php

namespace Drupal\markaspot_vision\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Mark-a-Spot Vision settings.
 *
 * Provides a provider-agnostic configuration for AI vision services,
 * supporting any OpenAI-compatible API (OpenAI, Azure, Qwen, Ollama, etc.).
 */
class MarkaspotVisionSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['markaspot_vision.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_vision_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_vision.settings');

    $form['file_upload'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('File Upload Settings'),
    ];

    $form['file_upload']['multiple_uploads'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow multiple file uploads'),
      '#default_value' => $config->get('multiple_uploads') ?? TRUE,
    ];

    $form['service'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AI Vision Service Settings'),
      '#description' => $this->t('Configure any OpenAI-compatible vision API (OpenAI, Azure OpenAI, Qwen Vision, Ollama, etc.).'),
    ];

    $form['service']['auth_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Authentication Type'),
      '#options' => [
        'bearer' => $this->t('Bearer Token (OpenAI, Qwen, most providers)'),
        'api_key_header' => $this->t('API Key Header (Azure OpenAI)'),
        'none' => $this->t('No Authentication (local Ollama)'),
      ],
      '#default_value' => $config->get('auth_type') ?? 'bearer',
      '#required' => TRUE,
      '#description' => $this->t('Select the authentication method required by your AI provider.'),
    ];

    $form['service']['api_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('Your API key for the vision service. Leave empty for providers that do not require authentication.'),
      '#states' => [
        'invisible' => [
          ':input[name="auth_type"]' => ['value' => 'none'],
        ],
      ],
    ];

    $form['service']['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API Endpoint URL'),
      '#default_value' => $config->get('api_url') ?? 'https://api.openai.com/v1/chat/completions',
      '#description' => $this->t('The full URL for the chat completions endpoint. Examples:<br>
        - OpenAI: <code>https://api.openai.com/v1/chat/completions</code><br>
        - Azure: <code>https://YOUR-RESOURCE.openai.azure.com/openai/deployments/YOUR-DEPLOYMENT/chat/completions?api-version=2024-02-15-preview</code><br>
        - Qwen: <code>https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions</code><br>
        - Ollama: <code>http://localhost:11434/v1/chat/completions</code>'),
      '#required' => TRUE,
    ];

    $form['service']['ai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Name'),
      '#default_value' => $config->get('ai_model') ?? 'gpt-4o',
      '#description' => $this->t('The model identifier (e.g., gpt-4o, gpt-4-vision-preview, qwen-vl-max, llava).'),
      '#required' => TRUE,
    ];

    $form['service']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Prompt (Optional)'),
      '#default_value' => $config->get('system_prompt'),
      '#description' => $this->t('Optional system message to set the AI behavior context. Leave empty to skip the system message.'),
      '#rows' => 4,
    ];

    $form['service']['image_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Image Processing Prompt'),
      '#default_value' => $config->get('image_prompt') ?? 'Describe what you see in this image.',
      '#description' => $this->t('Use <code>{categories}</code> as a placeholder for the list of categories.'),
      '#required' => TRUE,
      '#rows' => 10,
    ];

    $form['service']['ai_parameters'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Parameters'),
      '#open' => FALSE,
    ];

    $form['service']['ai_parameters']['temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#description' => $this->t('Controls randomness in the response (0.0 to 1.0). Leave empty to use provider default.'),
      '#default_value' => $config->get('temperature'),
      '#step' => 0.1,
      '#min' => 0,
      '#max' => 1,
      '#required' => FALSE,
    ];

    $form['service']['ai_parameters']['top_p'] = [
      '#type' => 'number',
      '#title' => $this->t('Top P'),
      '#description' => $this->t('Controls diversity via nucleus sampling (0.0 to 1.0). Leave empty to use provider default.'),
      '#default_value' => $config->get('top_p'),
      '#step' => 0.05,
      '#min' => 0,
      '#max' => 1,
      '#required' => FALSE,
    ];

    $form['service']['ai_parameters']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens'),
      '#description' => $this->t('Maximum length of the response. Leave empty to use provider default.'),
      '#default_value' => $config->get('max_tokens'),
      '#min' => 1,
      '#max' => 4096,
      '#required' => FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $auth_type = $form_state->getValue('auth_type');
    $api_key = trim($form_state->getValue('api_key') ?? '');
    $api_url = $form_state->getValue('api_url');

    // Validate API key is present when authentication is required.
    if ($auth_type !== 'none' && empty($api_key)) {
      $form_state->setError(
        $form['service']['api_key'],
        $this->t('An API key is required for the selected authentication type.')
      );
    }

    // Validate API URL format.
    if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
      $form_state->setError(
        $form['service']['api_url'],
        $this->t('The API endpoint URL must be a valid URL.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('markaspot_vision.settings');

    // Save basic settings.
    $config
      ->set('multiple_uploads', $form_state->getValue('multiple_uploads'))
      ->set('auth_type', $form_state->getValue('auth_type'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('api_url', $form_state->getValue('api_url'))
      ->set('ai_model', $form_state->getValue('ai_model'))
      ->set('system_prompt', $form_state->getValue('system_prompt'))
      ->set('image_prompt', $form_state->getValue('image_prompt'));

    // Handle AI parameters - explicitly clear if empty.
    foreach (['temperature', 'top_p', 'max_tokens'] as $key) {
      $value = $form_state->getValue($key);
      if ($value === '' || $value === NULL) {
        $config->clear($key);
      }
      else {
        $config->set($key, $value);
      }
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
