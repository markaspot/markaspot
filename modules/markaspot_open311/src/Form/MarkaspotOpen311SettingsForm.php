<?php

namespace Drupal\markaspot_open311\Form;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotOpen311SettingsForm extends ConfigFormBase {

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
    return 'markaspot_open311_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_open311.settings');

    $form['markaspot_open311']['common'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Open311 Settings and Service Discovery'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure the Open311 Server Settings (status, publishing settings and Georeport v2 Service Discovery). See http://wiki.open311.org/Service_Discovery. This service discovery specification is meant to be read-only and can be provided either dynamically or using a manually edited static file.'),
      '#group' => 'settings',
    ];

    $form['markaspot_open311']['common']['bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_request',
      '#description' => $this->t('Match the service request to a Drupal content-type (machine_name) of your choice'),
    ];

    $form['markaspot_open311']['common']['tax_category'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_category',
      '#description' => $this->t('Match the request category to a Drupal vocabulary (machine_name) of your choice'),
    ];

    $form['markaspot_open311']['common']['tax_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_status',
      '#description' => $this->t('Match the request status to a Drupal vocabulary (machine_name) of your choice'),
    ];

    $form['markaspot_open311']['common']['node_options_status'] = [
      '#type' => 'radios',
      '#default_value' => $config->get('node_options_status'),
      '#options' => [0 => $this->t('Unpublished'), 1 => $this->t('Published')],
      '#title' => $this->t('Choose the publish status of incoming reports'),
    ];

    $form['markaspot_open311']['common']['status_open_start'] = [
      '#type' => 'select',
      '#multiple' => FALSE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open_start'),
      '#title' => $this->t('Choose the status that gets applied when creating reports by third party apps'),
    ];

    $form['markaspot_open311']['common']['status_open'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open'),
      '#title' => $this->t('Please choose the status for open reports'),
    ];

    $form['markaspot_open311']['common']['status_closed'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_closed'),
      '#title' => $this->t('Please choose the status for closed reports'),
    ];

    $form['markaspot_open311']['common']['status_open'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open'),
      '#title' => $this->t('Please choose the status for open reports'),
    ];

    $form['markaspot_open311']['common']['nid-limit'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Limit settings'),
      '#default_value' => $config->get('nid-limit'),
      '#description' => $this->t('Set the maximum number of requests by nids.'),
    ];

    $form['markaspot_open311']['common']['revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Revisions'),
      '#default_value' => $config->get('revisions'),
      '#description' => $this->t('Enable revisions and set revision_log_messages via api'),
    ];

    $form['markaspot_open311']['discovery'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Open311 Service Discovery'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure the Open311 Server Settings (status, publishing settings and Georeport v2 Service Discovery). See http://wiki.open311.org/Service_Discovery. This service discovery specification is meant to be read-only and can be provided either dynamically or using a manually edited static file.'),
      '#group' => 'settings',
    ];
    $form['markaspot_open311']['discovery']['changeset'] = [
      '#type' => 'textfield',
      '#default_value' => date('c', time()),
      '#title' => $this->t('Changeset'),
      '#description' => $this->t('Sortable field that specifies the last time this document was updated'),
    ];

    $form['markaspot_open311']['discovery']['key_service'] = [
      '#type' => 'textarea',
      '#default_value' => $config->get('discovery.key_service'),
      '#title' => $this->t('Human readable information on how to get an API key'),
    ];
    $form['markaspot_open311']['discovery']['contact'] = [
      '#type' => 'textarea',
      '#default_value' => $config->get('discovery.contact'),
      '#title' => $this->t('Open311 Contact Details'),
    ];

    foreach ($config->get('discovery.endpoints') as $key => $value) {

      foreach ($value['formats'] as $key => $format) {
        $options[$format] = $format;
      }

      $form['markaspot_open311']['discovery']['endpoints'][$key]['type'] = [
        '#type' => 'textfield',
        '#default_value' => $value['type'],
        '#title' => $this->t('Type'),
      ];
      $form['markaspot_open311']['discovery']['endpoints'][$key]['formats'] = [
        '#type' => 'checkboxes',
        '#options' => $options,
        '#title' => $this->t('Formats'),
        '#description' => $this->t('Data structure of supported MIME types.'),
      ];
      $form['markaspot_open311']['discovery']['endpoints'][$key]['specification'] = [
        '#type' => 'textfield',
        '#default_value' => $value['specification'],
        '#title' => $this->t('Specification'),
        '#description' => $this->t('The token of the service specification that is supported. This token will be defined by each spec. In general the format is a URL that identifies the specification and version number much like an XMLNS declaration. (eg http://wiki.open311.org/GeoReport_v2)'),
      ];
      $form['markaspot_open311']['discovery']['endpoints'][$key]['changeset'] = [
        '#type' => 'textfield',
        '#default_value' => date('c', time()),
        '#title' => $this->t('Changeset'),
        '#description' => $this->t('Sortable field that specifies the last time this document was updated'),
      ];

    }

    return parent::buildForm($form, $form_state);
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

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    // var_dump($values);
    $this->config('markaspot_open311.settings')

      ->set('status_open', $values['status_open'])
      ->set('status_closed', $values['status_closed'])
      ->set('status_open_start', $values['status_open_start'])
      ->set('node_options_status', $values['node_options_status'])
      ->set('changeset', $values['changeset'])
      ->set('key_service', $values['key_service'])
      ->set('type', $values['type'])
      ->set('contact', $values['contact'])
      ->set('bundle', $values['bundle'])
      ->set('tax_category', $values['tax_category'])
      ->set('tax_status', $values['tax_status'])
      ->set('nid-limit', $values['nid-limit'])
      ->set('revisions', $values['revisions'])
      ->set('discovery.endpoints', [[
        'changeset' => $values['changeset'],
        'specification' => $values['specification'],
        'type' => $values['type'],
        'formats' => array_keys($values['formats']),
      ],
      ]
      )
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_open311.settings',
    ];
  }

}
