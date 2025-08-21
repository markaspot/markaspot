<?php

namespace Drupal\markaspot_feedback\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManager;

/**
 * Configure feedback settings for this site.
 */
class MarkaspotFeedbackSettingsForm extends ConfigFormBase {

  use StringTranslationTrait;

  /**
   * The Entity Type manager variable.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity
   *   The Entity type manager service.
   */
  public function __construct(EntityTypeManager $entity) {
    $this->entityTypeManager = $entity;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_feedback_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_feedback.settings');
    $form['markaspot_feedback'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Feedback Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('This setting allow you to choose between several feedback settings.'),
      '#group' => 'settings',
    ];

    // General Settings
    $form['markaspot_feedback']['general'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General Settings'),
      '#collapsible' => FALSE,
      '#weight' => 0,
    ];

    $form['markaspot_feedback']['general']['enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Feedback Module'),
      '#default_value' => $config->get('enable'),
      '#description' => $this->t('Master switch for all feedback functionality. When disabled, the entire feedback system is turned off, including both automated (cron) and manual processing.'),
    ];
    
    $form['markaspot_feedback']['general']['cron_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Automated Processing via Cron'),
      '#default_value' => $config->get('cron_enable'),
      '#description' => $this->t('When disabled, emails will need to be handled via ECA or manually'),
      '#states' => [
        'visible' => [
          ':input[name="markaspot_feedback[general][enable]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['markaspot_feedback']['general']['tax_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Status Vocabulary'),
      '#default_value' => $config->get('tax_status') ?: 'service_status',
      '#description' => $this->t('Match the request status to a Drupal vocabulary (machine_name) of your choice.'),
    ];

    // Common Settings
    $form['markaspot_feedback']['common'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Common Settings'),
      '#collapsible' => FALSE,
      '#weight' => 1,
    ];

    $form['markaspot_feedback']['common']['days'] = [
      '#type' => 'number',
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#title' => $this->t('Waiting period in days'),
      '#default_value' => $config->get('days'),
      '#description' => $this->t('Specify after how many days since a service request was completed that a feedback email should be sent. This helps ensure citizens have had time to verify the resolution.'),
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="markaspot_feedback[general][cron_enable]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['markaspot_feedback']['common']['interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Cron interval'),
      '#description' => $this->t('Time after which the check will we executed'),
      '#default_value' => $config->get('interval'),
      '#options' => [
        60 => $this->t('1 minute'),
        300 => $this->t('5 minutes'),
        3600 => $this->t('1 hour'),
        86400 => $this->t('1 day'),
        172800 => $this->t('2 days'),
        432000 => $this->t('5 days'),
        604800 => $this->t('1 week'),
      ],
      '#states' => [
        'visible' => [
          ':input[name="markaspot_feedback[general][cron_enable]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Citizen Feedback Settings
    $form['markaspot_feedback']['citizen'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Citizen Feedback Settings'),
      '#collapsible' => FALSE,
      '#weight' => 2,
    ];

    $form['markaspot_feedback']['citizen']['status_feedback_enabled'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_feedback.settings')->get('tax_status')),
      '#default_value' => $config->get('status_feedback_enabled'),
      '#title' => $this->t('Feedback eligible statuses'),
      '#description' => $this->t('Choose which status values make a service request eligible for automated citizen feedback collection via cron.'),
    ];

    $form['markaspot_feedback']['citizen']['mailtext'] = [
      '#type' => 'textarea',
      '#token_types' => ['site'],
      '#title' => $this->t('Email template for citizen feedback requests'),
      '#default_value' => $config->get('mailtext') ?: 'Hello [current-user:name]!',
      '#description' => $this->t('Email template sent to citizens requesting feedback. Tokens are supported.'),
    ];

    $form['markaspot_feedback']['citizen']['set_progress_tid'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_feedback.settings')->get('tax_status')),
      '#default_value' => $config->get('set_progress_tid'),
      '#title' => $this->t('Status to set when citizen requests status update'),
      '#description' => $this->t('This status will be applied to the service request when the citizen selects the status update option in the feedback form (typically to reopen).'),
    ];

    $form['markaspot_feedback']['citizen']['set_status_note'] = [
      '#type' => 'textarea',
      '#token_types' => ['site'],
      '#title' => $this->t('Citizen status note template'),
      '#default_value' => $config->get('set_status_note') ?: '',
      '#description' => $this->t('Status note template added when citizen feedback changes status. Tokens are supported.'),
    ];

    // Service Provider Settings
    $form['markaspot_feedback']['service_provider'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Service Provider Settings'),
      '#collapsible' => FALSE,
      '#weight' => 3,
    ];

    $form['markaspot_feedback']['service_provider']['service_provider_completion_status_tid'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_feedback.settings')->get('tax_status')),
      '#default_value' => $config->get('service_provider_completion_status_tid'),
      '#title' => $this->t('Status to set when service provider marks request as completed'),
      '#description' => $this->t('This status will be applied to the service request when a service provider completes the request via the feedback form with ?sp=true parameter.'),
    ];

    $form['markaspot_feedback']['service_provider']['service_provider_status_note'] = [
      '#type' => 'textarea',
      '#token_types' => ['site'],
      '#title' => $this->t('Service provider completion note template'),
      '#default_value' => $config->get('service_provider_status_note') ?: 'Dienstleister hat die Bearbeitung abgeschlossen.',
      '#description' => $this->t('Status note template added when a service provider marks a request as completed. Tokens are supported.'),
    ];

    $form['markaspot_feedback']['service_provider']['enable_dual_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable dual feedback mode'),
      '#default_value' => $config->get('enable_dual_mode') ?: FALSE,
      '#description' => $this->t('When enabled, feedback forms will allow users to switch between citizen feedback and service provider mode on the same page. When disabled, mode is determined only by the ?sp=true URL parameter.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Extract values from fieldsets
    $general = $values['general'] ?? [];
    $common = $values['common'] ?? [];
    $citizen = $values['citizen'] ?? [];
    $service_provider = $values['service_provider'] ?? [];
    
    $this->config('markaspot_feedback.settings')
      // General settings
      ->set('enable', $general['enable'] ?? $values['enable'])
      ->set('cron_enable', $general['cron_enable'] ?? $values['cron_enable'])
      ->set('tax_status', $general['tax_status'] ?? $values['tax_status'])
      
      // Common settings
      ->set('days', $common['days'] ?? $values['days'])
      ->set('interval', $common['interval'] ?? $values['interval'])
      
      // Citizen feedback settings
      ->set('status_feedback_enabled', $citizen['status_feedback_enabled'] ?? $values['status_feedback_enabled'])
      ->set('mailtext', $citizen['mailtext'] ?? $values['mailtext'])
      ->set('set_progress_tid', $citizen['set_progress_tid'] ?? $values['set_progress_tid'])
      ->set('set_status_note', $citizen['set_status_note'] ?? $values['set_status_note'])
      
      // Service provider settings
      ->set('service_provider_completion_status_tid', $service_provider['service_provider_completion_status_tid'] ?? $values['service_provider_completion_status_tid'])
      ->set('service_provider_status_note', $service_provider['service_provider_status_note'] ?? $values['service_provider_status_note'])
      ->set('enable_dual_mode', $service_provider['enable_dual_mode'] ?? $values['enable_dual_mode'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_feedback.settings',
    ];
  }

  /**
   * Helper function to get taxonomy term options for select widget.
   *
   * @parameter string $machine_name
   *   Taxonomy machine name.
   *
   * @return array
   *   Select options for form
   */
  public function getTaxonomyTermOptions($machine_name) {
    $options = [];

    // $vid = taxonomy_vocabulary_machine_name_load($machine_name)->vid;
    $vid = $machine_name;
    $options_source = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadTree($vid);

    foreach ($options_source as $item) {
      $key = $item->tid;
      $value = $item->name;
      $options[$key] = $value;
    }

    return $options;
  }

}
