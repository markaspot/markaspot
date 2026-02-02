<?php

namespace Drupal\markaspot_icons\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_icons\IconMigrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Migrate icon field data between different field types.
 */
class IconMigrationForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The icon migration service.
   *
   * @var \Drupal\markaspot_icons\IconMigrationService
   */
  protected $iconMigration;

  /**
   * Constructs a IconMigrationForm object.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, IconMigrationService $icon_migration) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->iconMigration = $icon_migration;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_icons.migration')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_icons_migration';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('This tool helps you migrate icon field data between FontAwesome Icon Class and Iconify Field formats.') . '</p>',
    ];

    $form['migration_direction'] = [
      '#type' => 'radios',
      '#title' => $this->t('Migration Direction'),
      '#default_value' => 'fa_to_iconify',
      '#options' => [
        'fa_to_iconify' => $this->t('FontAwesome Icon Class → Iconify Field'),
        'iconify_to_fa' => $this->t('Iconify Field → FontAwesome Icon Class'),
      ],
      '#required' => TRUE,
    ];

    $form['target_collection'] = [
      '#type' => 'select',
      '#title' => $this->t('Target Icon Collection'),
      '#description' => $this->t('When migrating to Iconify, choose which collection to map FontAwesome icons to.'),
      '#default_value' => 'heroicons',
      '#options' => [
        'heroicons' => $this->t('Heroicons (Recommended for Nuxt UI)'),
        'lucide' => $this->t('Lucide Icons'),
        'fa6-solid' => $this->t('FontAwesome 6 Solid (Direct mapping)'),
      ],
      '#states' => [
        'visible' => [
          ':input[name="migration_direction"]' => ['value' => 'fa_to_iconify'],
        ],
      ],
    ];

    $form['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Entity Types to Migrate'),
      '#default_value' => ['taxonomy_term'],
      '#options' => [
        'taxonomy_term' => $this->t('Taxonomy Terms (Categories & Status)'),
        'node' => $this->t('Content (if applicable)'),
      ],
    ];

    // Preview the mapping
    $form['preview'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Icon Mapping Preview'),
      '#description' => $this->t('Preview of how icons will be converted:'),
    ];

    $mappings = $this->iconMigration->getIconMappingPreview('fa_to_iconify', 'heroicons');
    $preview_table = [
      '#type' => 'table',
      '#header' => [
        $this->t('Current Icon'),
        $this->t('New Icon'),
        $this->t('Description'),
      ],
      '#rows' => [],
    ];

    foreach (array_slice($mappings, 0, 10) as $old_icon => $new_icon) {
      $preview_table['#rows'][] = [
        $old_icon,
        $new_icon,
        $this->iconMigration->getIconDescription($new_icon),
      ];
    }

    $form['preview']['table'] = $preview_table;

    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry Run'),
      '#description' => $this->t('Preview changes without actually modifying data.'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Migration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $direction = $form_state->getValue('migration_direction');
    $target_collection = $form_state->getValue('target_collection');
    $entity_types = array_filter($form_state->getValue('entity_types'));
    $dry_run = $form_state->getValue('dry_run');

    $batch = [
      'title' => $this->t('Migrating Icon Field Data'),
      'operations' => [],
      'init_message' => $this->t('Starting icon migration...'),
      'progress_message' => $this->t('Processed @current out of @total entities.'),
      'error_message' => $this->t('Migration encountered an error.'),
      'finished' => '\Drupal\markaspot_icons\IconMigrationService::batchFinished',
    ];

    foreach ($entity_types as $entity_type) {
      $batch['operations'][] = [
        '\Drupal\markaspot_icons\IconMigrationService::batchProcess',
        [$entity_type, $direction, $target_collection, $dry_run],
      ];
    }

    batch_set($batch);
  }

}