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

    $form['markaspot_feedback']['common']['tax_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => $config->get('tax_status') ?: 'service_status',
      '#description' => $this->t('Match the request status to a Drupal vocabulary (machine_name) of your choice.'),
    ];

    $form['markaspot_feedback']['status_resubmissive'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_feedback.settings')->get('tax_status')),
      '#default_value' => $config->get('status_resubmissive'),
      '#title' => $this->t('Please choose the status for resubmissable reports.'),
    ];

    $form['markaspot_feedback']['days'] = [
      '#tree' => TRUE,
      '#title' => $this->t('Feedback period settings'),
      '#description' => $this->t('You can change the period in which content is notified for being submissive.'),
      '#open' => TRUE,
      '#type' => 'number',
      '#step' => '1',
      '#default_value' =>  $config->get('days'),
      '#required' => TRUE,
      '#weight' => '0',
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
      '#title' => $this->t('Please choose the status to be set when the re-opening option is chosen by citizens'),
    ];

    $form['markaspot_feedback']['set_archive_tid'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_feedback.settings')->get('tax_status')),
      '#default_value' => $config->get('set_archive_tid'),
      '#title' => $this->t('Please choose the status to be set as archive'),
    ];

    $form['markaspot_feedback']['days'] = [
      '#type' => 'number',
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#title' => $this->t('Period in days'),
      '#default_value' => $config->get('days'),
      '#description' => $this->t('Enter here after how many days an e-mail should be sent.')

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
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('markaspot_feedback.settings')
      ->set('tax_status', $values['tax_status'])
      ->set('status_resubmissive', $values['status_resubmissive'])
      ->set('set_progress_tid', $values['set_progress_tid'])
      ->set('set_archive_tid', $values['set_archive_tid'])
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
