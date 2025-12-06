<?php

namespace Drupal\markaspot_open311\Form;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Open311 settings for Mark-a-Spot.
 */
class MarkaspotOpen311SettingsForm extends ConfigFormBase {
  use StringTranslationTrait;

  /**
   * The Entity Type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Constructor for the settings form.
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
  protected function getEditableConfigNames() {
    return ['markaspot_open311.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_open311.settings');

    // Content Types and Taxonomies Configuration.
    $form['markaspot_open311']['content_types'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Content Types and Taxonomies'),
      '#description' => $this->t('Configure which content types and taxonomies are used for service requests.'),
      '#collapsible' => TRUE,
      '#group' => 'settings',
    ];

    $form['markaspot_open311']['content_types']['bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Service Request Content Type'),
      '#default_value' => $config->get('bundle') ?: 'service_request',
      '#description' => $this->t('The machine name of the content type used for service requests.'),
      '#required' => TRUE,
    ];

    $form['markaspot_open311']['content_types']['tax_category'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Service Category Vocabulary'),
      '#default_value' => $config->get('tax_category') ?: 'service_category',
      '#description' => $this->t('The machine name of the vocabulary used for service categories.'),
      '#required' => TRUE,
    ];

    $form['markaspot_open311']['content_types']['tax_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Service Status Vocabulary'),
      '#default_value' => $config->get('tax_status') ?: 'service_status',
      '#description' => $this->t('The machine name of the vocabulary used for service request statuses.'),
      '#required' => TRUE,
    ];

    // Status Configuration.
    $form['markaspot_open311']['status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Status Configuration'),
      '#description' => $this->t('Configure how service request statuses are handled.'),
      '#collapsible' => TRUE,
      '#group' => 'settings',
    ];

    $statusOptions = $this->getTaxonomyTermOptions($config->get('tax_status'));

    $form['markaspot_open311']['status']['status_open_start'] = [
      '#type' => 'select',
      '#title' => $this->t('Initial Status for New Requests'),
      '#options' => $statusOptions,
      '#default_value' => $config->get('status_open_start'),
      '#description' => $this->t('The status that will be automatically assigned to new service requests.'),
      '#required' => TRUE,
    ];

    $form['markaspot_open311']['status']['status_open'] = [
      '#type' => 'select',
      '#title' => $this->t('Open Request Statuses'),
      '#multiple' => TRUE,
      '#options' => $statusOptions,
      '#default_value' => $config->get('status_open'),
      '#description' => $this->t('Select all statuses that indicate an open/active request.'),
      '#required' => TRUE,
    ];

    $form['markaspot_open311']['status']['status_closed'] = [
      '#type' => 'select',
      '#title' => $this->t('Closed Request Statuses'),
      '#multiple' => TRUE,
      '#options' => $statusOptions,
      '#default_value' => $config->get('status_closed'),
      '#description' => $this->t('Select all statuses that indicate a closed/completed request.'),
      '#required' => TRUE,
    ];

    // Status Notes Configuration.
    $form['markaspot_open311']['status_notes'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Status Notes Configuration'),
      '#description' => $this->t('Configure automatic status note creation.'),
      '#collapsible' => TRUE,
      '#group' => 'settings',
    ];

    $form['markaspot_open311']['status_notes']['status_note_auto_create'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-create status notes on status change'),
      '#default_value' => $config->get('status_note_auto_create') ?? TRUE,
      '#description' => $this->t('When enabled, automatically creates a status note when the status changes. Disable if the frontend handles status notes.'),
    ];

    $form['markaspot_open311']['status_notes']['status_note_created'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Initial Status Note Text'),
      '#default_value' => $config->get('status_note_created') ?? 'The service request has been created.',
      '#description' => $this->t('Default text for the initial status note when a new request is created. Leave empty to skip.'),
      '#states' => [
        'visible' => [
          ':input[name="status_note_auto_create"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['markaspot_open311']['status_notes']['status_note_changed'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Status Changed Note Text'),
      '#default_value' => $config->get('status_note_changed') ?? 'Status changed.',
      '#description' => $this->t('Default text for status notes when status changes. Leave empty to skip auto-creation on status change.'),
      '#states' => [
        'visible' => [
          ':input[name="status_note_auto_create"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Field Access Configuration.
    $form['markaspot_open311']['field_access'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Open311 Field Access'),
      '#description' => $this->t('Configure which fields are exposed through the Open311 API for different access levels.'),
      '#collapsible' => TRUE,
      '#group' => 'settings',
    ];

    // Get all available fields for service requests
    $fields = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'service_request');

    $options = [];
    foreach ($fields as $field_name => $field_definition) {
      // Exclude core technical fields
      if (!in_array($field_name, [
        'nid', 'uuid', 'vid', 'type', 'revision_timestamp',
        'revision_uid', 'revision_log', 'uid', 'default_langcode',
        'revision_default', 'revision_translation_affected', 'langcode'
      ])) {
        $options[$field_name] = $field_definition->getLabel() . ' (' . $field_name . ')';
      }
    }

    $roles = [
      'public' => [
        'title' => $this->t('Public API Access'),
        'description' => $this->t('Fields accessible to unauthenticated API requests.'),
      ],
      'user' => [
        'title' => $this->t('Authenticated User API Access'),
        'description' => $this->t('Additional fields accessible to authenticated API users.'),
      ],
      'manager' => [
        'title' => $this->t('Manager API Access'),
        'description' => $this->t('Additional fields accessible to API users with manager permissions.'),
      ],
    ];

    foreach ($roles as $role => $info) {
      $form['markaspot_open311']['field_access'][$role . '_fields'] = [
        '#type' => 'select',
        '#title' => $info['title'],
        '#multiple' => TRUE,
        '#options' => $options,
        '#default_value' => $config->get('field_access.' . $role . '_fields') ?: [],
        '#description' => $info['description'],
        '#size' => 15,
      ];
    }

    // General Settings.
    $form['markaspot_open311']['general'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General Settings'),
      '#collapsible' => TRUE,
      '#group' => 'settings',
    ];

    $form['markaspot_open311']['general']['node_options_status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default Publication Status'),
      '#default_value' => $config->get('node_options_status'),
      '#options' => [
        0 => $this->t('Unpublished - Requests require manual review before publication'),
        1 => $this->t('Published - Requests are automatically published'),
      ],
      '#description' => $this->t('Choose whether new service requests should be published automatically.'),
    ];

    $form['markaspot_open311']['general']['nid_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Request Limit'),
      '#default_value' => $config->get('nid_limit'),
      '#description' => $this->t('Maximum number of service requests to return in API responses. Leave empty for no limit.'),
      '#min' => 1,
    ];

    $form['markaspot_open311']['general']['revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Request Revisions'),
      '#default_value' => $config->get('revisions'),
      '#description' => $this->t('Track changes to service requests by creating new revisions.'),
    ];

    // Group Integration Settings.
    $form['markaspot_open311']['group_integration'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Group Integration'),
      '#description' => $this->t('Configure integration with the Group module for group-based filtering.'),
      '#collapsible' => TRUE,
      '#group' => 'settings',
    ];

    // Check if Group module is enabled.
    $group_module_enabled = \Drupal::moduleHandler()->moduleExists('group');

    $form['markaspot_open311']['group_integration']['group_filter_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Group Filtering'),
      '#default_value' => $config->get('group_filter_enabled') ?? FALSE,
      '#description' => $this->t('Allow API requests to filter service requests by user group membership using the <code>group_filter=1</code> parameter.'),
      '#disabled' => !$group_module_enabled,
    ];

    if (!$group_module_enabled) {
      $form['markaspot_open311']['group_integration']['group_filter_enabled']['#description'] .= '<br><strong>' . $this->t('Note: The Group module is not installed.') . '</strong>';
    }

    $form['markaspot_open311']['group_integration']['group_filter_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group Type for Filtering'),
      '#default_value' => $config->get('group_filter_type') ?? 'org',
      '#description' => $this->t('The group type machine name to use for filtering (e.g., "org"). Users will only see requests assigned to groups of this type that they are members of.'),
      '#states' => [
        'visible' => [
          ':input[name="group_filter_enabled"]' => ['checked' => TRUE],
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

    $this->config('markaspot_open311.settings')
      // Content Types
      ->set('bundle', $values['bundle'])
      ->set('tax_category', $values['tax_category'])
      ->set('tax_status', $values['tax_status'])
      // Status Configuration
      ->set('status_open_start', $values['status_open_start'])
      ->set('status_open', $values['status_open'])
      ->set('status_closed', $values['status_closed'])
      // Status Notes Configuration
      ->set('status_note_auto_create', $values['status_note_auto_create'])
      ->set('status_note_created', $values['status_note_created'])
      ->set('status_note_changed', $values['status_note_changed'])
      // Field Access
      ->set('field_access.public_fields', $values['public_fields'])
      ->set('field_access.user_fields', $values['user_fields'])
      ->set('field_access.manager_fields', $values['manager_fields'])
      // General Settings
      ->set('node_options_status', $values['node_options_status'])
      ->set('nid_limit', $values['nid_limit'])
      ->set('revisions', $values['revisions'])
      // Group Integration
      ->set('group_filter_enabled', $values['group_filter_enabled'])
      ->set('group_filter_type', $values['group_filter_type'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Gets taxonomy terms as options for select fields.
   *
   * @param string $machine_name
   *   The vocabulary machine name.
   *
   * @return array
   *   Array of term options.
   */
  private function getTaxonomyTermOptions($machine_name) {
    $options = [];

    if (!empty($machine_name)) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadTree($machine_name);

      foreach ($terms as $term) {
        $options[$term->tid] = $term->name;
      }
    }

    return $options;
  }
}
