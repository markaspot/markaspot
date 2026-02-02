<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Mark-a-Spot AI settings.
 *
 * This form allows administrators to configure AI provider credentials,
 * duplicate detection parameters, and token tracking limits.
 */
class MarkaspotAiSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['markaspot_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('markaspot_ai.settings');

    // Provider Configuration section.
    $form['provider'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Provider Configuration'),
      '#open' => TRUE,
    ];

    $form['provider']['default_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Provider'),
      '#description' => $this->t('Select the AI provider to use for embeddings and chat completions.'),
      '#options' => [
        'openai' => $this->t('OpenAI'),
      ],
      '#default_value' => $config->get('default_provider') ?? 'openai',
      '#required' => TRUE,
    ];

    // OpenAI Configuration.
    $form['provider']['openai'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OpenAI Configuration'),
      '#states' => [
        'visible' => [
          ':input[name="default_provider"]' => ['value' => 'openai'],
        ],
      ],
    ];

    // Check if API key is provided via environment variable.
    $env_key = getenv('OPENAI_API_KEY');
    $existing_config_key = $config->get('providers.openai.api_key');

    if (!empty($env_key)) {
      // Environment variable is set - show info and disable config field.
      $form['provider']['openai']['api_key_status'] = [
        '#type' => 'item',
        '#markup' => '<div class="messages messages--status">' .
          $this->t('<strong>API key loaded from environment variable</strong> (OPENAI_API_KEY). This is the recommended secure approach.') .
          '</div>',
        '#weight' => -1,
      ];
      $form['provider']['openai']['api_key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('API Key'),
        '#description' => $this->t('Using environment variable OPENAI_API_KEY. To change, update your server environment.'),
        '#default_value' => '••••••••' . substr($env_key, -4),
        '#disabled' => TRUE,
      ];
    }
    else {
      // No env var - allow config entry with security warning.
      $form['provider']['openai']['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key'),
        '#description' => $this->t('Your OpenAI API key. <strong>Recommended:</strong> Set OPENAI_API_KEY environment variable instead for better security.'),
        '#default_value' => '',
        '#attributes' => [
          'autocomplete' => 'off',
        ],
      ];

      if (!empty($existing_config_key)) {
        $form['provider']['openai']['api_key']['#description'] = $this->t('API key is configured in database. Leave empty to keep existing, or enter new key to replace. <strong>Recommended:</strong> Use OPENAI_API_KEY environment variable instead.');
        $form['provider']['openai']['api_key_status'] = [
          '#type' => 'item',
          '#markup' => '<div class="messages messages--warning">' .
            $this->t('API key stored in config database. Consider using environment variable for better security.') .
            '</div>',
          '#weight' => -1,
        ];
      }
    }

    $form['provider']['openai']['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('The base URL for the OpenAI API. Use default unless using Azure or a compatible endpoint.'),
      '#default_value' => $config->get('providers.openai.api_url') ?? 'https://api.openai.com/v1',
      '#required' => TRUE,
    ];

    $form['provider']['openai']['chat_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Chat Model'),
      '#description' => $this->t('Model to use for chat completions (e.g., gpt-4o, gpt-4o-mini, gpt-3.5-turbo).'),
      '#default_value' => $config->get('providers.openai.chat_model') ?? 'gpt-4o',
      '#required' => TRUE,
    ];

    $form['provider']['openai']['embedding_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embedding Model'),
      '#description' => $this->t('Model to use for generating embeddings (e.g., text-embedding-3-large, text-embedding-3-small).'),
      '#default_value' => $config->get('providers.openai.embedding_model') ?? 'text-embedding-3-large',
      '#required' => TRUE,
    ];

    $form['provider']['openai']['auth_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Authentication Type'),
      '#description' => $this->t('How to authenticate with the API.'),
      '#options' => [
        'bearer' => $this->t('Bearer Token (Authorization header)'),
        'api_key_header' => $this->t('API Key Header (api-key)'),
        'none' => $this->t('No Authentication'),
      ],
      '#default_value' => $config->get('providers.openai.auth_type') ?? 'bearer',
      '#required' => TRUE,
    ];

    // Duplicate Detection section.
    $form['duplicate_detection'] = [
      '#type' => 'details',
      '#title' => $this->t('Duplicate Detection'),
      '#open' => TRUE,
    ];

    $form['duplicate_detection']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Duplicate Detection'),
      '#description' => $this->t('Automatically scan new service requests for potential duplicates based on content similarity and location.'),
      '#default_value' => $config->get('duplicate_detection.enabled') ?? TRUE,
    ];

    $form['duplicate_detection']['similarity_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity Threshold'),
      '#description' => $this->t('Minimum cosine similarity score (0-1) to consider requests as potential duplicates. Higher values = stricter matching.'),
      '#default_value' => $config->get('duplicate_detection.similarity_threshold') ?? 0.85,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['duplicate_detection']['radius_meters'] = [
      '#type' => 'number',
      '#title' => $this->t('Search Radius (meters)'),
      '#description' => $this->t('Maximum distance in meters between requests to consider them as duplicates. Set to 0 to disable geographic filtering.'),
      '#default_value' => $config->get('duplicate_detection.radius_meters') ?? 500,
      '#min' => 0,
      '#max' => 10000,
      '#step' => 50,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['duplicate_detection']['time_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Time Window (days)'),
      '#description' => $this->t('Only compare against requests created within this many days. Older requests will be ignored.'),
      '#default_value' => $config->get('duplicate_detection.time_window_days') ?? 30,
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['duplicate_detection']['auto_flag'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-flag Potential Duplicates'),
      '#description' => $this->t('Automatically flag service requests that have potential duplicates. Requires the Flag module or a field_duplicate_flag field.'),
      '#default_value' => $config->get('duplicate_detection.auto_flag') ?? FALSE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Token Tracking section.
    $form['token_tracking'] = [
      '#type' => 'details',
      '#title' => $this->t('Token Tracking'),
      '#open' => TRUE,
    ];

    $form['token_tracking']['tracking_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Token Tracking'),
      '#description' => $this->t('Track API token usage for cost monitoring and budget control.'),
      '#default_value' => $config->get('token_tracking.enabled') ?? TRUE,
    ];

    $form['token_tracking']['daily_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily Token Limit'),
      '#description' => $this->t('Maximum tokens to use per day. Set to 0 for unlimited. API calls will be blocked when limit is reached.'),
      '#default_value' => $config->get('token_tracking.daily_limit') ?? 100000,
      '#min' => 0,
      '#max' => 10000000,
      '#step' => 1000,
      '#states' => [
        'visible' => [
          ':input[name="tracking_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['token_tracking']['alert_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Alert Threshold'),
      '#description' => $this->t('Percentage of daily limit (0-1) at which to trigger a warning. For example, 0.9 = warn at 90% usage.'),
      '#default_value' => $config->get('token_tracking.alert_threshold') ?? 0.9,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#states' => [
        'visible' => [
          ':input[name="tracking_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add current usage summary if tracking is enabled.
    if ($config->get('token_tracking.enabled')) {
      try {
        /** @var \Drupal\markaspot_ai\Service\TokenTrackingService $tracking */
        $tracking = \Drupal::service('markaspot_ai.token_tracking');
        $usage = $tracking->getDailyUsage();
        $dailyLimit = (int) $config->get('token_tracking.daily_limit');

        $usage_text = $this->t('Today: @total tokens used (@input input, @output output) from @count requests.', [
          '@total' => number_format($usage['total_tokens']),
          '@input' => number_format($usage['total_input_tokens']),
          '@output' => number_format($usage['total_output_tokens']),
          '@count' => number_format($usage['request_count']),
        ]);

        if ($dailyLimit > 0) {
          $percent = round(($usage['total_tokens'] / $dailyLimit) * 100, 1);
          $usage_text .= ' ' . $this->t('(@percent% of daily limit)', ['@percent' => $percent]);
        }

        $form['token_tracking']['current_usage'] = [
          '#type' => 'item',
          '#title' => $this->t('Current Usage'),
          '#markup' => '<strong>' . $usage_text . '</strong>',
          '#weight' => -10,
        ];
      }
      catch (\Exception $e) {
        // Service not available, skip usage display.
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate similarity threshold.
    $threshold = (float) $form_state->getValue('similarity_threshold');
    if ($threshold < 0 || $threshold > 1) {
      $form_state->setErrorByName('similarity_threshold', $this->t('Similarity threshold must be between 0 and 1.'));
    }

    // Validate alert threshold.
    $alert = (float) $form_state->getValue('alert_threshold');
    if ($alert < 0 || $alert > 1) {
      $form_state->setErrorByName('alert_threshold', $this->t('Alert threshold must be between 0 and 1.'));
    }

    // Validate API URL.
    $api_url = $form_state->getValue('api_url');
    if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('api_url', $this->t('API URL must be a valid URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('markaspot_ai.settings');

    // Save default provider.
    $config->set('default_provider', $form_state->getValue('default_provider'));

    // Save OpenAI configuration.
    $new_api_key = $form_state->getValue('api_key');
    if (!empty($new_api_key)) {
      $config->set('providers.openai.api_key', $new_api_key);
    }
    // Keep existing key if field was empty.

    $config->set('providers.openai.api_url', $form_state->getValue('api_url'));
    $config->set('providers.openai.chat_model', $form_state->getValue('chat_model'));
    $config->set('providers.openai.embedding_model', $form_state->getValue('embedding_model'));
    $config->set('providers.openai.auth_type', $form_state->getValue('auth_type'));

    // Save duplicate detection settings.
    $config->set('duplicate_detection.enabled', (bool) $form_state->getValue('enabled'));
    $config->set('duplicate_detection.similarity_threshold', (float) $form_state->getValue('similarity_threshold'));
    $config->set('duplicate_detection.radius_meters', (int) $form_state->getValue('radius_meters'));
    $config->set('duplicate_detection.time_window_days', (int) $form_state->getValue('time_window_days'));
    $config->set('duplicate_detection.auto_flag', (bool) $form_state->getValue('auto_flag'));

    // Save token tracking settings.
    $config->set('token_tracking.enabled', (bool) $form_state->getValue('tracking_enabled'));
    $config->set('token_tracking.daily_limit', (int) $form_state->getValue('daily_limit'));
    $config->set('token_tracking.alert_threshold', (float) $form_state->getValue('alert_threshold'));

    $config->save();

    // Invalidate usage cache.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['markaspot_ai:usage']);

    parent::submitForm($form, $form_state);
  }

}
