<?php

namespace Drupal\markaspot_resubmission\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Configure resubmission settings for this site.
 */
class MarkaspotResubmissionSettingsForm extends ConfigFormBase {

  use StringTranslationTrait;

  /**
   * The Entity Type manager variable.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity
   *   The Entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity) {
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
    return 'markaspot_resubmission_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_resubmission.settings');
    $form['markaspot_resubmission'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Resubmission Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('This setting allow you to choose between several resubmission settings.'),
      '#group' => 'settings',
    ];

    $form['markaspot_resubmission']['common']['tax_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => $config->get('tax_status') ?: 'service_status',
      '#description' => $this->t('Match the request status to a Drupal vocabulary (machine_name) of your choice.'),
    ];

    $form['markaspot_resubmission']['status_resubmissive'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_resubmission.settings')->get('tax_status')),
      '#default_value' => $config->get('status_resubmissive'),
      '#title' => $this->t('Please choose the status for resubmissable reports.'),

    ];

    $form['markaspot_resubmission']['default_resubmission_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Default resubmission period (days)'),
      '#description' => $this->t('Default number of days before sending a resubmission reminder. This can be overridden per category by editing the category term and setting a value in the "Resubmission reminder period" field.'),
      '#default_value' => $config->get('default_resubmission_days') ?: 42,
      '#min' => 1,
      '#max' => 365,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['markaspot_resubmission']['mailtext'] = [
      '#type' => 'textarea',
      '#token_types' => ['site'],
      '#title' => $this->t('Mailtext'),
      '#default_value' => $config->get('mailtext') ?: 'Hello [current-user:name]!',
    ];
    $form['markaspot_resubmission']['interval'] = [
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
    ];

    $form['markaspot_resubmission']['reminder_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Reminder frequency settings'),
      '#description' => $this->t('Control how often reminders are sent for the same service request.'),
      '#open' => TRUE,
    ];

    $form['markaspot_resubmission']['reminder_settings']['reminder_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Reminder interval'),
      '#description' => $this->t('How often to send reminders for the same request after the initial reminder.'),
      '#default_value' => $config->get('reminder_interval') ?: 604800,
      '#options' => [
        86400 => $this->t('1 day'),
        172800 => $this->t('2 days'),
        259200 => $this->t('3 days'),
        432000 => $this->t('5 days'),
        604800 => $this->t('1 week'),
        1209600 => $this->t('2 weeks'),
        2592000 => $this->t('30 days'),
      ],
    ];

    $form['markaspot_resubmission']['reminder_settings']['max_reminders'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum reminders'),
      '#description' => $this->t('Maximum number of reminders to send per request. Set to 0 for unlimited reminders.'),
      '#default_value' => $config->get('max_reminders') ?: 0,
      '#min' => 0,
      '#max' => 100,
      '#step' => 1,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('markaspot_resubmission.settings')
      ->set('tax_status', $values['tax_status'])
      ->set('status_resubmissive', $values['status_resubmissive'])
      ->set('default_resubmission_days', $values['default_resubmission_days'])
      ->set('mailtext', $values['mailtext'])
      ->set('interval', $values['interval'])
      ->set('reminder_interval', $values['reminder_interval'])
      ->set('max_reminders', $values['max_reminders'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_resubmission.settings',
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

    if (empty($machine_name)) {
      return $options;
    }

    try {
      $options_source = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadTree($machine_name);

      foreach ($options_source as $item) {
        $key = $item->tid;
        $value = $item->name;
        $options[$key] = $value;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('markaspot_resubmission')->error('Failed to load taxonomy terms for @vid: @error', [
        '@vid' => $machine_name,
        '@error' => $e->getMessage(),
      ]);
    }

    return $options;
  }

}
