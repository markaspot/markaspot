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

    $form['markaspot_feedback']['enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Feedback Module'),
      '#default_value' => $config->get('enable'),
      '#description' => $this->t('Master switch for all feedback functionality. When disabled, the entire feedback system is turned off, including both automated (cron) and manual processing.'),
    ];
    
    $form['markaspot_feedback']['cron_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Automated Processing via Cron'),
      '#default_value' => $config->get('cron_enable'),
      '#description' => $this->t('When disabled, emails will need to be handled via ECA or manually'),
      '#states' => [
        'visible' => [
          ':input[name="markaspot_feedback[enable]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['markaspot_feedback']['common']['tax_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => $config->get('tax_status') ?: 'service_status',
      '#description' => $this->t('Match the request status to a Drupal vocabulary (machine_name) of your choice.'),
    ];

    $form['markaspot_feedback']['status_feedback_enabled'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_feedback.settings')->get('tax_status')),
      '#default_value' => $config->get('status_feedback_enabled'),
      '#title' => $this->t('Please choose which status values make a service request eligible for feedback collection'),
    ];


    $form['markaspot_feedback']['mailtext'] = [
      '#type' => 'textarea',
      '#token_types' => ['site'],
      '#title' => $this->t('Mailtext'),
      '#default_value' => $config->get('mailtext') ?: 'Hello [current-user:name]!',
    ];

    $form['markaspot_feedback']['set_status_note'] = [
      '#type' => 'textarea',
      '#token_types' => ['site'],
      '#title' => $this->t('Status Note to be set.'),
      '#default_value' => $config->get('set_status_note') ?: '',
    ];

    $form['markaspot_feedback']['set_progress_tid'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_feedback.settings')->get('tax_status')),
      '#default_value' => $config->get('set_progress_tid'),
      '#title' => $this->t('Status to set when user requests status update via feedback form'),
      '#description' => $this->t('This status will be applied to the service request when the user selects the status update option in the feedback form.'),
    ];


    $form['markaspot_feedback']['days'] = [
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
          ':input[name="markaspot_feedback[cron_enable]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['markaspot_feedback']['interval'] = [
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
          ':input[name="markaspot_feedback[cron_enable]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('markaspot_feedback.settings')
      ->set('enable', $values['enable'])
      ->set('cron_enable', $values['cron_enable'])
      ->set('tax_status', $values['tax_status'])
      ->set('status_feedback_enabled', $values['status_feedback_enabled'])
      ->set('set_progress_tid', $values['set_progress_tid'])
      ->set('set_status_note', $values['set_status_note'])
      ->set('days', $values['days'])
      ->set('mailtext', $values['mailtext'])
      ->set('interval', $values['interval'])
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
