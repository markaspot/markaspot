<?php

namespace Drupal\markaspot_icons;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\iconify_field\Service\IconResolverInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Service for migrating icon field data between different formats.
 */
class IconMigrationService {

  use StringTranslationTrait;

  /**
   * Icon fields covered by the migration gate.
   */
  private const ICON_FIELDS = [
    'field_category_icon',
    'field_status_icon',
  ];

  /**
   * The safe Lucide fallback for icons with no valid equivalent.
   */
  private const DEFAULT_LUCIDE_ICON = 'circle-alert';

  /**
   * Known Lucide aliases and renamed icons.
   *
   * Iconify's resolver only accepts canonical names from the icons collection.
   * It does not resolve the aliases shipped in the collection metadata.
   */
  private const LUCIDE_ALIASES = [
    'alert-circle' => 'circle-alert',
    'alert-triangle' => 'triangle-alert',
    'check-circle' => 'circle-check-big',
    'check-circle-2' => 'circle-check',
    'child' => 'baby',
    'help-circle' => 'circle-question-mark',
    'home' => 'house',
    'minus-circle' => 'circle-minus',
    'more-horizontal' => 'ellipsis',
    'more-vertical' => 'ellipsis-vertical',
    'pause-circle' => 'circle-pause',
    'parking-circle' => 'circle-parking',
    'play-circle' => 'circle-play',
    'plus-circle' => 'circle-plus',
    'stop-circle' => 'circle-stop',
    'tree' => 'trees',
    'unlock' => 'lock-open',
    'user-circle' => 'circle-user',
    'x-circle' => 'circle-x',
  ];

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
   * The optional Iconify resolver.
   *
   * @var \Drupal\iconify_field\Service\IconResolverInterface|null
   */
  protected ?IconResolverInterface $iconResolver;

  /**
   * The renderer used to verify resolver output.
   *
   * @var \Drupal\Core\Render\RendererInterface|null
   */
  protected ?RendererInterface $renderer;

  /**
   * The render cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  protected ?CacheBackendInterface $renderCache;

  /**
   * Icon mapping from FontAwesome to modern icon collections.
   *
   * @var array
   */
  protected $iconMappings = [
    'heroicons' => [
      // Waste/Trash.
      'fa-trash' => 'i-heroicons-trash',
      'fa-trash-o' => 'i-heroicons-trash',

      // Transportation/Infrastructure.
  // No direct equivalent, use generic.
      'fa-road' => 'i-heroicons-minus',
      'fa-car' => 'i-heroicons-truck',

      // Nature/Environment.
  // No direct tree, use nature-related.
      'fa-tree' => 'i-heroicons-beaker',
      'fa-tint' => 'i-heroicons-beaker',

      // Communication.
      'fa-comment' => 'i-heroicons-chat-bubble-left',
      'fa-comment-o' => 'i-heroicons-chat-bubble-left',

      // Status indicators.
      'fa-check' => 'i-heroicons-check',
      'fa-check-circle' => 'i-heroicons-check-circle',
      'fa-play-circle' => 'i-heroicons-play-circle',
      'fa-hand-stop-o' => 'i-heroicons-hand-raised',
      'fa-stack-overflow' => 'i-heroicons-archive-box',
      'fa-calendar-times-o' => 'i-heroicons-calendar-x-mark',

      // Buildings/Places.
      'fa-bank' => 'i-heroicons-building-office',
      'fa-building' => 'i-heroicons-building-office',
      'fa-home' => 'i-heroicons-home',
      'fa-hospital-o' => 'i-heroicons-building-office-2',

      // Utilities.
      'fa-heart-o' => 'i-heroicons-heart',
      'fa-heart' => 'i-heroicons-heart',
      'fa-star' => 'i-heroicons-star',
      'fa-star-o' => 'i-heroicons-star',

      // Default fallback.
      'default' => 'i-heroicons-exclamation-circle',
    ],
    'lucide' => [
      // People/Users.
      'fa-male' => 'i-lucide-user',
      'fa-user' => 'i-lucide-user',
      'fa-users' => 'i-lucide-users',

      // Objects/Tools.
      'fa-lightbulb-o' => 'i-lucide-lightbulb',
      'fa-lightbulb' => 'i-lucide-lightbulb',
      'fa-glass' => 'i-lucide-wine',
      'fa-paint-brush' => 'i-lucide-paintbrush',
      'fa-circle-o' => 'i-lucide-circle',
      'fa-circle' => 'i-lucide-circle-dot',
      'fa-wrench' => 'i-lucide-wrench',
      'fa-cog' => 'i-lucide-settings',
      'fa-map-marker' => 'i-lucide-map-pin',

      // Nature/Environment.
      'fa-tree' => 'i-lucide-tree-pine',
      'fa-leaf' => 'i-lucide-leaf',
      'fa-tint' => 'i-lucide-droplets',
      'fa-envira' => 'i-lucide-flower-2',
      'fa-soccer-ball-o' => 'i-lucide-circle-dot',
      'fa-cloud' => 'i-lucide-cloud',

      // Weather.
      'fa-cloud-rain' => 'i-lucide-cloud-rain-wind',
      'fa-sun-o' => 'i-lucide-sun',
      'fa-snowflake-o' => 'i-lucide-snowflake',

      // Waste/Trash.
      'fa-trash' => 'i-lucide-trash',
      'fa-trash-o' => 'i-lucide-trash-2',
      'fa-recycle' => 'i-lucide-recycle',

      // Transportation/Infrastructure.
      'fa-road' => 'i-lucide-construction',
      'fa-car' => 'i-lucide-car',
      'fa-bicycle' => 'i-lucide-bike',
      'fa-parking' => 'i-lucide-circle-parking',
      'fa-bus' => 'i-lucide-bus',

      // Time.
      'fa-clock-o' => 'i-lucide-clock',
      'fa-clock' => 'i-lucide-clock',
      'fa-calendar' => 'i-lucide-calendar',
      'fa-calendar-times-o' => 'i-lucide-calendar-x',

      // Communication.
      'fa-comment' => 'i-lucide-message-circle',
      'fa-comment-o' => 'i-lucide-message-circle',
      'fa-comments' => 'i-lucide-messages-square',
      'fa-envelope' => 'i-lucide-mail',
      'fa-phone' => 'i-lucide-phone',

      // Status indicators.
      'fa-check' => 'i-lucide-check',
      'fa-check-circle' => 'i-lucide-circle-check-big',
      'fa-check-circle-o' => 'i-lucide-circle-check-big',
      'fa-play-circle' => 'i-lucide-circle-play',
      'fa-play-circle-o' => 'i-lucide-circle-play',
      'fa-hand-stop-o' => 'i-lucide-hand',
      'fa-hand-paper-o' => 'i-lucide-hand',
      'fa-stack-overflow' => 'i-lucide-archive',
      'fa-archive' => 'i-lucide-archive',
      'fa-times' => 'i-lucide-x',
      'fa-times-circle' => 'i-lucide-circle-x',
      'fa-exclamation' => 'i-lucide-triangle-alert',
      'fa-exclamation-circle' => 'i-lucide-circle-alert',
      'fa-exclamation-triangle' => 'i-lucide-triangle-alert',
      'fa-warning' => 'i-lucide-triangle-alert',
      'fa-info' => 'i-lucide-info',
      'fa-info-circle' => 'i-lucide-info',
      'fa-question' => 'i-lucide-circle-question-mark',
      'fa-question-circle' => 'i-lucide-circle-question-mark',

      // Buildings/Places.
      'fa-bank' => 'i-lucide-building-2',
      'fa-building' => 'i-lucide-building',
      'fa-building-o' => 'i-lucide-building',
      'fa-home' => 'i-lucide-house',
      'fa-hospital-o' => 'i-lucide-building-2',
      'fa-university' => 'i-lucide-landmark',

      // Miscellaneous.
      'fa-heart' => 'i-lucide-heart',
      'fa-heart-o' => 'i-lucide-heart',
      'fa-star' => 'i-lucide-star',
      'fa-star-o' => 'i-lucide-star',
      'fa-flag' => 'i-lucide-flag',
      'fa-flag-o' => 'i-lucide-flag',
      'fa-bolt' => 'i-lucide-zap',
      'fa-fire' => 'i-lucide-flame',
      'fa-camera' => 'i-lucide-camera',
      'fa-image' => 'i-lucide-image',
      'fa-file' => 'i-lucide-file',
      'fa-folder' => 'i-lucide-folder',
      'fa-search' => 'i-lucide-search',
      'fa-eye' => 'i-lucide-eye',
      'fa-eye-slash' => 'i-lucide-eye-off',
      'fa-lock' => 'i-lucide-lock',
      'fa-unlock' => 'i-lucide-lock-open',
      'fa-key' => 'i-lucide-key',
      'fa-bell' => 'i-lucide-bell',
      'fa-bell-o' => 'i-lucide-bell',

      // Default fallback.
      'default' => 'i-lucide-circle-alert',
    ],
    'fa6-solid' => [
      // Direct FontAwesome 6 mapping.
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
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, MessengerInterface $messenger, ?IconResolverInterface $icon_resolver = NULL, ?RendererInterface $renderer = NULL, ?CacheBackendInterface $render_cache = NULL) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;
    $this->iconResolver = $icon_resolver;
    $this->renderer = $renderer;
    $this->renderCache = $render_cache;
  }

  /**
   * Validates icon fields and optionally repairs every reported value.
   *
   * @param bool $fix
   *   Whether safe planned repairs should be persisted.
   *
   * @return array<int, array<string, mixed>>
   *   One report row per invalid or unverifiable icon value.
   */
  public function validateAndRepair(bool $fix = FALSE): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    $report = [];

    foreach (array_chunk($ids, 100, TRUE) as $idChunk) {
      $terms = $storage->loadMultiple($idChunk);

      foreach ($terms as $term) {
        if (!$term instanceof TermInterface) {
          continue;
        }

        $termChanged = FALSE;
        foreach (array_keys($term->getTranslationLanguages()) as $langcode) {
          $translation = $term->getTranslation($langcode);

          foreach (self::ICON_FIELDS as $fieldName) {
            if (!$translation->hasField($fieldName)) {
              continue;
            }

            $field = $translation->get($fieldName);
            if (!$field->getFieldDefinition()->isTranslatable() &&
              $langcode !== $term->language()->getId()) {
              continue;
            }

            foreach ($field as $delta => $item) {
              $value = (string) ($item->value ?? '');
              if ($value === '') {
                continue;
              }

              $plan = $this->inspectIconValue($value);
              if ($plan['issue'] === NULL) {
                continue;
              }

              $replacement = $plan['replacement'];
              $action = !empty($plan['fixable']) ? 'would-fix' : 'unresolved';
              if ($fix && is_string($replacement) && $replacement !== $value) {
                $item->set('value', $replacement);
                $termChanged = TRUE;
                $action = 'fixed';
              }

              $report[] = [
                'term_id' => (int) $term->id(),
                'term' => $translation->label(),
                'langcode' => $langcode,
                'field' => $fieldName,
                'delta' => $delta,
                'value' => $value,
                'replacement' => $replacement,
                'issue' => $plan['issue'],
                'repair' => $plan['repair'],
                'action' => $action,
              ];
            }
          }
        }

        if ($fix && $termChanged) {
          $term->save();
        }
      }
    }

    if ($fix && $report !== []) {
      $storage->resetCache();
      if ($this->renderCache) {
        $this->renderCache->deleteAll();
      }
    }

    return $report;
  }

  /**
   * Inspects an icon using the configured resolver when necessary.
   *
   * @param string $icon
   *   The stored icon value.
   *
   * @return array<string, mixed>
   *   The validation and repair plan.
   */
  public function inspectIconValue(string $icon): array {
    if (str_starts_with($icon, 'fa-')) {
      $resolverAvailable = $this->iconResolver && $this->renderer
        ? FALSE
        : NULL;
      return $this->planIconRepair($icon, $resolverAvailable);
    }

    $parsed = $this->parseLucideIcon($icon);
    if ($parsed === NULL) {
      return $this->planIconRepair($icon);
    }

    $alias = $this->findLucideAlias($parsed['name']);
    if ($alias !== NULL) {
      return [
        'issue' => 'lucide-alias',
        'replacement' => $this->formatLucideIcon($parsed['format'], $alias),
        'repair' => 'alias',
        'fixable' => TRUE,
      ];
    }

    if (!$this->iconResolver || !$this->renderer) {
      return $this->planIconRepair($icon);
    }

    try {
      $renderArray = $this->iconResolver->getIcon(
        'lucide:' . $parsed['name'],
        ['width' => '24', 'height' => '24'],
      );
      $svg = (string) $this->renderer->renderRoot($renderArray);
    }
    catch (\Throwable) {
      $plan = $this->planIconRepair($icon);
      $plan['issue'] = 'resolver-error';
      $plan['repair'] = 'manual-review';
      return $plan;
    }

    return $this->planIconRepair($icon, str_contains($svg, '<svg'));
  }

  /**
   * Creates a deterministic repair plan for one stored icon value.
   *
   * Passing NULL for resolvability models a missing resolver. In that case,
   * canonical Lucide values are reported but never changed.
   *
   * @param string $icon
   *   The stored icon value.
   * @param bool|null $resolvable
   *   Whether a canonical Lucide icon rendered as SVG, or NULL if unknown.
   *
   * @return array<string, mixed>
   *   Keys are issue, replacement, repair, and fixable.
   */
  public function planIconRepair(string $icon, ?bool $resolvable = NULL): array {
    if (str_starts_with($icon, 'fa-')) {
      $mapped = $this->iconMappings['lucide'][$icon] ?? NULL;
      $repair = 'mapping';

      if ($mapped === NULL) {
        $faName = substr($icon, 3);
        if (isset(self::LUCIDE_ALIASES[$faName])) {
          $mapped = 'i-lucide-' . self::LUCIDE_ALIASES[$faName];
          $repair = 'alias';
        }
        else {
          if ($resolvable === NULL) {
            return [
              'issue' => 'fontawesome',
              'replacement' => NULL,
              'repair' => 'manual-review',
              'fixable' => FALSE,
            ];
          }
          $mapped = $this->formatLucideIcon('iconify', self::DEFAULT_LUCIDE_ICON);
          $repair = 'fallback';
        }
      }

      $mappedParsed = $this->parseLucideIcon($mapped);
      if ($mappedParsed !== NULL && isset(self::LUCIDE_ALIASES[$mappedParsed['name']])) {
        $mapped = $this->formatLucideIcon(
          $mappedParsed['format'],
          self::LUCIDE_ALIASES[$mappedParsed['name']],
        );
      }

      return [
        'issue' => 'fontawesome',
        'replacement' => $mapped,
        'repair' => $repair,
        'fixable' => TRUE,
      ];
    }

    $parsed = $this->parseLucideIcon($icon);
    if ($parsed === NULL) {
      return [
        'issue' => NULL,
        'replacement' => NULL,
        'repair' => 'none',
        'fixable' => FALSE,
      ];
    }

    if (isset(self::LUCIDE_ALIASES[$parsed['name']])) {
      return [
        'issue' => 'lucide-alias',
        'replacement' => $this->formatLucideIcon(
          $parsed['format'],
          self::LUCIDE_ALIASES[$parsed['name']],
        ),
        'repair' => 'alias',
        'fixable' => TRUE,
      ];
    }

    if ($resolvable === TRUE) {
      return [
        'issue' => NULL,
        'replacement' => NULL,
        'repair' => 'none',
        'fixable' => FALSE,
      ];
    }

    if ($resolvable === FALSE) {
      return [
        'issue' => 'lucide-unresolved',
        'replacement' => $this->formatLucideIcon(
          $parsed['format'],
          self::DEFAULT_LUCIDE_ICON,
        ),
        'repair' => 'fallback',
        'fixable' => TRUE,
      ];
    }

    return [
      'issue' => 'resolver-unavailable',
      'replacement' => NULL,
      'repair' => 'manual-review',
      'fixable' => FALSE,
    ];
  }

  /**
   * Parses supported Lucide storage formats.
   *
   * @return array{format: string, name: string}|null
   *   The parsed format and icon name, or NULL for unrelated values.
   */
  private function parseLucideIcon(string $icon): ?array {
    if (preg_match('/^lucide:([a-z0-9_-]+)$/i', $icon, $matches)) {
      return ['format' => 'colon', 'name' => strtolower($matches[1])];
    }
    if (preg_match('/^i-lucide-([a-z0-9_-]+)$/i', $icon, $matches)) {
      return ['format' => 'iconify', 'name' => strtolower($matches[1])];
    }

    return NULL;
  }

  /**
   * Finds a canonical Lucide name in fixed and collection-provided aliases.
   */
  private function findLucideAlias(string $name): ?string {
    if (isset(self::LUCIDE_ALIASES[$name])) {
      return self::LUCIDE_ALIASES[$name];
    }
    if (!$this->iconResolver) {
      return NULL;
    }

    try {
      $collection = $this->iconResolver->loadCollection('lucide');
      $parent = $collection['aliases'][$name]['parent'] ?? NULL;
    }
    catch (\Throwable) {
      return NULL;
    }

    return is_string($parent) && $parent !== '' ? $parent : NULL;
  }

  /**
   * Formats a canonical Lucide name like its stored source value.
   */
  private function formatLucideIcon(string $format, string $name): string {
    return $format === 'colon' ? 'lucide:' . $name : 'i-lucide-' . $name;
  }

  /**
   * Gets icon mapping preview for the UI.
   */
  public function getIconMappingPreview($direction, $target_collection) {
    if ($direction === 'fa_to_iconify') {
      return $this->iconMappings[$target_collection] ?? [];
    }

    // For iconify_to_fa, reverse the mapping.
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
    }
    else {
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
      }
      else {
        $messenger->addMessage(t('Migration completed successfully. Updated @count entities.', [
          '@count' => $results['processed'],
        ]));
      }
    }
    else {
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
    }
    else {
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
    }
    else {
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
    }
    elseif ($direction === 'iconify_to_fa') {
      // Reverse conversion: find FA icon that maps to this iconify icon.
      $mappings = $this->iconMappings[$target_collection] ?? [];
      $reversed = array_flip($mappings);
      return $reversed[$icon] ?? $icon;
    }

    return $icon;
  }

}
