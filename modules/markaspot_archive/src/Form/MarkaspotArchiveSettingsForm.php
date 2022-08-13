<?php

namespace Drupal\markaspot_archive\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure archive settings for this site.
 */
class MarkaspotArchiveSettingsForm extends ConfigFormBase {
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
    return 'markaspot_archive_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_archive.settings');
    $form['markaspot_archive'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Archive Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('This setting allow you to choose between several archive settings.'),
      '#group' => 'settings',
    ];

    $form['markaspot_archive']['common']['tax_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_status',
      '#description' => $this->t('Match the request status to a Drupal vocabulary (machine_name) of your choice.'),
    ];

    $form['markaspot_archive']['status_archivable'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_archive.settings')->get('tax_status')),
      '#default_value' => $config->get('status_archivable'),
      '#title' => $this->t('Please choose the status for archivable reports.'),
    ];

    $form['markaspot_archive']['status_archived'] = [
      '#type' => 'select',
      '#multiple' => FALSE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_archive.settings')->get('tax_status')),
      '#default_value' => $config->get('status_archived'),
      '#title' => $this->t('Please choose the status for archived reports.'),
    ];

    $form['markaspot_archive']['unpublish'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Unpublish'),
      '#description' => $this->t('Unpublish Service Requests on archiving.'),
      '#default_value' => $config->get('unpublish'),
    ];

    $form['markaspot_archive']['anonymize'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Anonymize personal data'),
      '#description' => $this->t('All data of the field entities below will get anonymized.'),
      '#default_value' => $config->get('anonymize'),
    ];

    $form['markaspot_archive']['anonymize_fields'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getFields(),
      '#default_value' => $config->get('anonymize_fields'),
      '#title' => $this->t('Please choose the fields that will get overwritten on archiving.'),
    ];

    $catOptions = $this->getTaxonomyTermOptions('service_category');
    $form['markaspot_archive']['days'] = [
      '#tree' => TRUE,
      '#type' => 'details',
      '#title' => $this->t('Archive period settings per category'),
      '#description' => $this->t('You can change the period in which archivable content is sent to the archiving status.'),
    // Controls the HTML5 'open' attribute. Defaults to FALSE.
      '#open' => TRUE,
    ];

    foreach ($catOptions as $tid => $category_name) {
      $form['markaspot_archive']['days'][$tid] = [
        '#type' => 'number',
        '#min' => 1,
        '#max' => 1000,
        '#step' => 1,
        '#title' => $this->t('Days for <i>@category_name</i>', ['@category_name' => $category_name]),
        '#default_value' => $config->get('days.' . $tid),
        '#description' => $this->t('How many days to reach back for archiving?'),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('markaspot_archive.settings')
      ->set('tax_status', $values['tax_status'])
      ->set('status_archivable', $values['status_archivable'])
      ->set('status_archived', $values['status_archived'])
      ->set('unpublish', $values['unpublish'])
      ->set('anonymize', $values['anonymize'])
      ->set('anonymize_fields', $values['anonymize_fields'])
      ->set('days', $values['days'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get field definitions.
   */
  public function getFields() {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'service_request');
    return array_keys($definitions);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_archive.settings',
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
