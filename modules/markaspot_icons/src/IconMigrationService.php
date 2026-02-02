<?php

namespace Drupal\markaspot_icons;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for migrating icon field data between different formats.
 */
class IconMigrationService {

  use StringTranslationTrait;

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
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Icon mapping from FontAwesome to modern icon collections.
   *
   * @var array
   */
  protected $iconMappings = [
    'heroicons' => [
      // Waste/Trash
      'fa-trash' => 'i-heroicons-trash',
      'fa-trash-o' => 'i-heroicons-trash',
      
      // Transportation/Infrastructure
      'fa-road' => 'i-heroicons-minus',  // No direct equivalent, use generic
      'fa-car' => 'i-heroicons-truck',
      
      // Nature/Environment
      'fa-tree' => 'i-heroicons-beaker',  // No direct tree, use nature-related
      'fa-tint' => 'i-heroicons-beaker',
      
      // Communication
      'fa-comment' => 'i-heroicons-chat-bubble-left',
      'fa-comment-o' => 'i-heroicons-chat-bubble-left',
      
      // Status indicators
      'fa-check' => 'i-heroicons-check',
      'fa-check-circle' => 'i-heroicons-check-circle',
      'fa-play-circle' => 'i-heroicons-play-circle',
      'fa-hand-stop-o' => 'i-heroicons-hand-raised',
      'fa-stack-overflow' => 'i-heroicons-archive-box',
      'fa-calendar-times-o' => 'i-heroicons-calendar-x-mark',
      
      // Buildings/Places
      'fa-bank' => 'i-heroicons-building-office',
      'fa-building' => 'i-heroicons-building-office',
      'fa-home' => 'i-heroicons-home',
      'fa-hospital-o' => 'i-heroicons-building-office-2',
      
      // Utilities
      'fa-heart-o' => 'i-heroicons-heart',
      'fa-heart' => 'i-heroicons-heart',
      'fa-star' => 'i-heroicons-star',
      'fa-star-o' => 'i-heroicons-star',
      
      // Default fallback
      'default' => 'i-heroicons-exclamation-circle',
    ],
    'lucide' => [
      // Waste/Trash
      'fa-trash' => 'i-lucide-trash-2',
      'fa-trash-o' => 'i-lucide-trash',
      
      // Transportation/Infrastructure
      'fa-road' => 'i-lucide-construction',
      'fa-car' => 'i-lucide-car',
      
      // Nature/Environment
      'fa-tree' => 'i-lucide-tree-pine',
      'fa-tint' => 'i-lucide-droplets',
      
      // Communication
      'fa-comment' => 'i-lucide-message-circle',
      'fa-comment-o' => 'i-lucide-message-circle',
      
      // Status indicators
      'fa-check' => 'i-lucide-check',
      'fa-check-circle' => 'i-lucide-check-circle',
      'fa-play-circle' => 'i-lucide-play-circle',
      'fa-hand-stop-o' => 'i-lucide-hand',
      'fa-stack-overflow' => 'i-lucide-archive',
      'fa-calendar-times-o' => 'i-lucide-calendar-x',
      
      // Buildings/Places
      'fa-bank' => 'i-lucide-building-2',
      'fa-building' => 'i-lucide-building',
      'fa-home' => 'i-lucide-home',
      'fa-hospital-o' => 'i-lucide-building-2',
      
      // Default fallback
      'default' => 'i-lucide-alert-circle',
    ],
    'fa6-solid' => [
      // Direct FontAwesome 6 mapping
      'fa-trash' => 'i-fa6-solid-trash-can',
      'fa-trash-o' => 'i-fa6-solid-trash-can',
      'fa-road' => 'i-fa6-solid-road',
      'fa-tree' => 'i-fa6-solid-tree',
      'fa-tint' => 'i-fa6-solid-droplet',
      'fa-comment' => 'i-fa6-solid-comment',
      'fa-comment-o' => 'i-fa6-solid-comment',
      'fa-check' => 'i-fa6-solid-check',
      'fa-check-circle' => 'i-fa6-solid-circle-check',
      'fa-play-circle' => 'i-fa6-solid-circle-play',
      'fa-hand-stop-o' => 'i-fa6-solid-hand',
      'fa-bank' => 'i-fa6-solid-building-columns',
      'fa-building' => 'i-fa6-solid-building',
      'fa-home' => 'i-fa6-solid-house',
      'fa-heart-o' => 'i-fa6-solid-heart',
      'fa-heart' => 'i-fa6-solid-heart',
      'default' => 'i-fa6-solid-circle-exclamation',
    ],
  ];

  /**
   * Constructs a IconMigrationService object.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, MessengerInterface $messenger) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;
  }

  /**
   * Gets icon mapping preview for the UI.
   */
  public function getIconMappingPreview($direction, $target_collection) {
    if ($direction === 'fa_to_iconify') {
      return $this->iconMappings[$target_collection] ?? [];
    }
    
    // For iconify_to_fa, reverse the mapping
    $mappings = $this->iconMappings[$target_collection] ?? [];
    return array_flip($mappings);
  }

  /**
   * Gets description for an icon.
   */
  public function getIconDescription($icon) {
    $descriptions = [
      'i-heroicons-trash' => 'Trash/Waste',
      'i-heroicons-chat-bubble-left' => 'Communication',
      'i-heroicons-check' => 'Completed',
      'i-heroicons-building-office' => 'Building/Office',
      'i-lucide-trash-2' => 'Waste Management',
      'i-lucide-tree-pine' => 'Nature/Trees',
      'i-fa6-solid-trash-can' => 'FontAwesome Trash',
    ];

    return $descriptions[$icon] ?? 'Icon';
  }

  /**
   * Batch processing callback for migration.
   */
  public static function batchProcess($entity_type, $direction, $target_collection, $dry_run, &$context) {
    $service = \Drupal::service('markaspot_icons.migration');
    
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $service->getEntityCount($entity_type);
      $context['results']['processed'] = 0;
      $context['results']['updated'] = 0;
      $context['results']['dry_run'] = $dry_run;
    }

    $entities = $service->getEntitiesForMigration($entity_type, $context['sandbox']['progress'], 50);
    
    foreach ($entities as $entity) {
      $service->migrateEntityIcons($entity, $direction, $target_collection, $dry_run);
      $context['sandbox']['progress']++;
      $context['results']['processed']++;
    }

    if ($context['sandbox']['progress'] < $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    } else {
      $context['finished'] = 1;
    }

    $context['message'] = t('Processed @current out of @max entities.', [
      '@current' => $context['sandbox']['progress'],
      '@max' => $context['sandbox']['max'],
    ]);
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();
    
    if ($success) {
      if ($results['dry_run']) {
        $messenger->addMessage(t('Dry run completed. Processed @count entities.', [
          '@count' => $results['processed'],
        ]));
      } else {
        $messenger->addMessage(t('Migration completed successfully. Updated @count entities.', [
          '@count' => $results['processed'],
        ]));
      }
    } else {
      $messenger->addError(t('Migration finished with errors.'));
    }
  }

  /**
   * Gets count of entities to migrate.
   */
  protected function getEntityCount($entity_type) {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    
    if ($entity_type === 'taxonomy_term') {
      $query = $storage->getQuery()
        ->condition('vid', ['service_category', 'service_status'], 'IN')
        ->accessCheck(FALSE);
    } else {
      $query = $storage->getQuery()->accessCheck(FALSE);
    }
    
    return $query->count()->execute();
  }

  /**
   * Gets entities for migration in batches.
   */
  protected function getEntitiesForMigration($entity_type, $offset, $limit) {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    
    if ($entity_type === 'taxonomy_term') {
      $query = $storage->getQuery()
        ->condition('vid', ['service_category', 'service_status'], 'IN')
        ->range($offset, $limit)
        ->accessCheck(FALSE);
    } else {
      $query = $storage->getQuery()
        ->range($offset, $limit)
        ->accessCheck(FALSE);
    }
    
    $ids = $query->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Migrates icons for a single entity.
   */
  protected function migrateEntityIcons($entity, $direction, $target_collection, $dry_run) {
    $icon_fields = ['field_category_icon', 'field_status_icon'];
    $updated = FALSE;

    foreach ($icon_fields as $field_name) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $current_value = $entity->get($field_name)->value;
      if (empty($current_value)) {
        continue;
      }

      $new_value = $this->convertIcon($current_value, $direction, $target_collection);
      
      if ($new_value !== $current_value) {
        if (!$dry_run) {
          $entity->set($field_name, $new_value);
          $updated = TRUE;
        }
        
        $this->loggerFactory->get('markaspot_icons')->info('Icon migration: @old → @new (Entity: @id)', [
          '@old' => $current_value,
          '@new' => $new_value,
          '@id' => $entity->id(),
        ]);
      }
    }

    if ($updated && !$dry_run) {
      $entity->save();
    }
  }

  /**
   * Converts an icon from one format to another.
   */
  protected function convertIcon($icon, $direction, $target_collection) {
    if ($direction === 'fa_to_iconify') {
      $mappings = $this->iconMappings[$target_collection] ?? [];
      return $mappings[$icon] ?? $mappings['default'] ?? $icon;
    } elseif ($direction === 'iconify_to_fa') {
      // Reverse conversion: find FA icon that maps to this iconify icon
      $mappings = $this->iconMappings[$target_collection] ?? [];
      $reversed = array_flip($mappings);
      return $reversed[$icon] ?? $icon;
    }

    return $icon;
  }

}