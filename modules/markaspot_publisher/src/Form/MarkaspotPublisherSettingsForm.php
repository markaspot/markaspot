<?php

namespace Drupal\markaspot_publisher\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure publisher settings for this site.
 */
class MarkaspotPublisherSettingsForm extends ConfigFormBase {
  use StringTranslationTrait;

  /**
   * The Entity Type manager variable.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The Entity Field manager variable.
   *
   * @var Drupal\Core\Entity\EntityFieldManagerImterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity
   *   The Entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManager $entity, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_publisher_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_publisher.settings');
    $form['markaspot_publisher'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Publisher Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('This setting allows you to choose which service requests to publish after a configurable time period.'),
      '#group' => 'settings',
    ];

    $form['markaspot_publisher']['common']['tax_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_status',
      '#description' => $this->t('Match the request status to a Drupal vocabulary (machine_name) of your choice.'),
    ];

    $form['markaspot_publisher']['status_publishable'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_publisher.settings')->get('tax_status')),
      '#default_value' => $config->get('status_publishable'),
      '#title' => $this->t('Please choose the statuses for publishable reports.'),
      '#description' => $this->t('Service requests with these statuses will be eligible for publishing after the configured time.'),
    ];

    // Cron control: enable/interval.
    $form['markaspot_publisher']['cron_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable cron-based publishing'),
      '#description' => $this->t('Run the publishing enqueue on cron at the configured interval.'),
      '#default_value' => $config->get('cron_enable'),
    ];
    $form['markaspot_publisher']['cron_always_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always run on every cron'),
      '#description' => $this->t('If checked, publishing will run on every cron invocation, ignoring the interval.'),
      '#default_value' => $config->get('cron_always_run'),
    ];
    $form['markaspot_publisher']['cron_interval'] = [
      '#type' => 'number',
      '#min' => 0,
      '#title' => $this->t('Cron interval (seconds)'),
      '#description' => $this->t('Number of seconds to wait before next publishing run. Set to 0 to run on every cron.'),
      '#default_value' => $config->get('cron_interval'),
    ];
    // Global default publishing period (days) for categories without override.
    $form['markaspot_publisher']['default_days'] = [
      '#type' => 'number',
      '#min' => 1,
      '#title' => $this->t('Default publishing period (days)'),
      '#default_value' => $config->get('default_days'),
      '#description' => $this->t('Global default number of days before publishing for categories without override.'),
    ];

    // Time threshold to determine intentional unpublishing.
    $form['markaspot_publisher']['manual_unpublish_threshold'] = [
      '#type' => 'number',
      '#min' => 1,
      '#title' => $this->t('Manual unpublish detection threshold (hours)'),
      '#default_value' => $config->get('manual_unpublish_threshold') ?: 6,
      '#description' => $this->t('If a node was modified more than this many hours after creation, it will be considered manually unpublished and not republished automatically.'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('markaspot_publisher.settings')
      ->set('tax_status', $values['tax_status'])
      ->set('status_publishable', $values['status_publishable'])
      ->set('cron_enable', $values['cron_enable'])
      ->set('cron_always_run', $values['cron_always_run'])
      ->set('cron_interval', $values['cron_interval'])
      ->set('default_days', $values['default_days'])
      ->set('manual_unpublish_threshold', $values['manual_unpublish_threshold'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_publisher.settings',
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
