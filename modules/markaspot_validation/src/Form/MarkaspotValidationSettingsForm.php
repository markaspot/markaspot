<?php

namespace Drupal\markaspot_validation\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotValidationSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a MarkaspotValidationSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_validation_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_validation.settings');
    $form['markaspot_validation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Validation Types'),
      '#collapsible' => TRUE,
      '#description' => $this->t('This setting allow you to choose a map tile operator of your choose. Be aware that you have to apply the same for the Geolocation Field settings</a>, too.'),
      '#group' => 'settings',
    ];

    $form['markaspot_validation']['wkt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Polygon in WKT Format'),
      '#default_value' => $config->get('wkt'),
      '#description' => $this->t('Place your polygon wkt here. You can <a href="@wkt-editor">create and edit</a> the vectors online. Leave this empty, if you don\'t need polygon validation.', ['@wkt-editor' => 'https://arthur-e.github.io/Wicket/sandbox-gmaps3.html']),
    ];
    $form['markaspot_validation']['multiple_reports'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Multiple Reports prevention check'),
      '#default_value' => $config->get('multiple_reports'),
      '#description' => $this->t('Checks whether a certain number of service requests have been submitted per e-mail address used.'),
    ];
    $form['markaspot_validation']['max_count'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum number of service requests'),
      '#default_value' => $config->get('max_count'),
      '#description' => $this->t('How many service requests per day are permitted?'),
    ];
    $form['markaspot_validation']['duplicate_check'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Duplicate Request check enabled'),
      '#default_value' => $config->get('duplicate_check'),
      '#description' => $this->t('Check if new requests get a duplicate check.'),
    ];

    $form['markaspot_validation']['radius'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Duplicate Request check Radius'),
      '#default_value' => $config->get('radius'),
      '#description' => $this->t('Validate if new requests are possible duplicates within this radius.'),
    ];

    $form['markaspot_validation']['unit'] = [
      '#type' => 'radios',
      '#title' => $this->t('Duplicate Radius Unit'),
      '#default_value' => $config->get('unit'),
      '#options' => [
        'meters' => $this->t('Meters'),
        'yards' => $this->t('Yards'),
      ],
      '#description' => $this->t('Validate if new requests are possible duplicates within this radius.'),
    ];

    $form['markaspot_validation']['hint'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Validate duplicates only as a hint'),
      '#default_value' => $config->get('hint'),
      '#description' => $this->t('Users can ignore this validation note by resubmitting the report form.'),
    ];

    $term_options = [];
    $term_ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'service_status')
      ->sort('weight')
      ->sort('name')
      ->accessCheck(FALSE)
      ->execute();
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids);
    foreach ($terms as $term) {
      $term_options[$term->id()] = $term->label();
    }

    $form['markaspot_validation']['excluded_statuses'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Exclude statuses from duplicate check'),
      '#default_value' => $config->get('excluded_statuses') ?? [],
      '#options' => $term_options,
      '#description' => $this->t('Nodes in these statuses are not considered duplicates. Useful for closed/resolved reports.'),
    ];

    $form['markaspot_validation']['treshold'] = [
      '#type' => 'number',
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#title' => $this->t('Iterations treshold'),
      '#default_value' => $config->get('treshold'),
      '#description' => $this->t('Increase this number if you feel that too few validation notices are displayed.'),
    ];
    $form['markaspot_validation']['days'] = [
      '#type' => 'number',
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#title' => $this->t('Duplicate reach back in days'),
      '#default_value' => $config->get('days'),
      '#description' => $this->t('How many days to reach back for similar requests.'),
    ];

    $form['markaspot_validation']['defaultLocation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force new location if default lat/lon is posted'),
      '#default_value' => $config->get('defaultLocation'),
      '#description' => $this->t('Remove this setting if you use field_unlocated feature'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $excluded_statuses = array_values(array_map('intval', array_filter($values['excluded_statuses'])));
    $this->config('markaspot_validation.settings')
      ->set('wkt', $values['wkt'])
      ->set('multiple_reports', $values['multiple_reports'])
      ->set('max_count', (int) $values['max_count'])
      ->set('duplicate_check', $values['duplicate_check'])
      ->set('radius', $values['radius'])
      ->set('unit', $values['unit'])
      ->set('days', (int) $values['days'])
      ->set('hint', $values['hint'])
      ->set('treshold', (int) $values['treshold'])
      ->set('defaultLocation', $values['defaultLocation'])
      ->set('excluded_statuses', $excluded_statuses)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_validation.settings',
    ];
  }

}
