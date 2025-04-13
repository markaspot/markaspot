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

    $catOptions = $this->getTaxonomyTermOptions('service_category');
    $form['markaspot_publisher']['days'] = [
      '#tree' => TRUE,
      '#type' => 'details',
      '#title' => $this->t('Publishing period settings per category'),
      '#description' => $this->t('You can set the waiting period (in days) before unpublished content is published.'),
      // Controls the HTML5 'open' attribute. Defaults to FALSE.
      '#open' => TRUE,
    ];

    foreach ($catOptions as $tid => $category_name) {
      $form['markaspot_publisher']['days'][$tid] = [
        '#type' => 'number',
        '#min' => 1,
        '#max' => 1000,
        '#step' => 1,
        '#title' => $this->t('Days for <i>@category_name</i>', ['@category_name' => $category_name]),
        '#default_value' => $config->get('days.' . $tid),
        '#description' => $this->t('How many days to wait before publishing?'),
      ];
    }
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
      ->set('days', $values['days'])
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