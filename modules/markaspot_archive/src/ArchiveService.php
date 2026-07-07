<?php

namespace Drupal\markaspot_archive;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Class ArchiveService finds all service requests that can be archived.
 */
class ArchiveService implements ArchiveServiceInterface {

  /**
   * Entity manager Service Object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new ArchiveService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    LoggerChannelInterface $logger,
    Connection $database,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->logger = $logger;
    $this->database = $database;
  }

  /**
   * Helper function.
   *
   * @param array $array
   *   Array with keys.
   *
   * @return array
   *   Return flattened array
   */
  public function arrayFlatten(array $array) {
    $result = [];
    foreach ($array as $value) {
      array_push($result, $value);
    }
    return $result;
  }

  /**
   * Load service requests.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Return nodes.
   */
  public function load(bool $all = FALSE, bool $advance_rotation = TRUE, bool $log = TRUE): array {
    $config = $this->configFactory->get('markaspot_archive.settings');
    // Global default days before archive if term override is empty.
    $default_days = (int) $config->get('default_days');
    // Status terms eligible for archiving.
    $status_tids = $this->arrayFlatten((array) $config->get('status_archivable'));
    $node_storage = $this->entityTypeManager->getStorage('node');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    // Load service category term IDs.
    $category_items = $term_storage->loadTree('service_category');
    $category_ids = array_map(function ($item) {
      return $item->tid;
    }, $category_items);

    // Get a rotating subset of categories to process.
    $last_processed_index = $this->state->get('markaspot_archive.last_category_index', 0);

    // Process up to 10 categories per run, with rotation for fairness.
    $total_categories = count($category_ids);
    $batch_size = 10;

    if ($last_processed_index >= $total_categories) {
      $last_processed_index = 0;
    }

    // Get the categories for this run.
    if ($all) {
      $categories = $category_ids;
    }
    elseif ($total_categories <= $batch_size) {
      // If we have fewer than batch_size categories, just process all of them.
      $categories = $category_ids;
    }
    else {
      // Create a rotating window through the categories.
      $categories = array_slice($category_ids, $last_processed_index, $batch_size);
      // If we're near the end, wrap around to the beginning.
      if (count($categories) < $batch_size) {
        $categories = array_merge(
          $categories,
          array_slice($category_ids, 0, $batch_size - count($categories))
        );
      }
      // Update the index for next time.
      if ($advance_rotation) {
        $this->state->set('markaspot_archive.last_category_index',
          ($last_processed_index + $batch_size) % $total_categories);
      }
    }

    if ($log) {
      $this->logger->notice(
        'Processing categories @start to @end of @total (batch size: @batch)',
        [
          '@start' => $last_processed_index + 1,
          '@end' => $all ? $total_categories : min($last_processed_index + $batch_size, $total_categories),
          '@total' => $total_categories,
          '@batch' => $all ? $total_categories : $batch_size,
        ]
      );
    }
    $nids = [];
    if ($log) {
      $this->logger->notice('Processing @count categories in this run', ['@count' => count($categories)]);
    }
    foreach ($categories as $category_tid) {
      // Determine archive threshold days: term override or default.
      $term = $term_storage->load($category_tid);
      $retention = $this->getArchiveRetentionForCategory((int) $category_tid, $default_days);
      $day = $retention['days'];
      // Calculate cutoff timestamp.
      $date = strtotime('-' . $day . ' days');

      // Count query first to determine how many potential nodes would match.
      $count_query = $node_storage->getQuery()
        ->condition('field_category', $category_tid)
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $status_tids, 'IN');
      $count_query->accessCheck(FALSE);
      $count_result = $count_query->count()->execute();

      // Debug the query.
      if ($log) {
        $this->logger->notice(
          'Category @cat (@term): days=@days, cutoff=@cutoff, status_tids=@status, potential_matches=@count',
          [
            '@cat' => $category_tid,
            '@term' => $term ? $term->label() : 'unknown',
            '@days' => $day,
            '@cutoff' => date('Y-m-d H:i:s', $date),
            '@status' => implode(',', $status_tids),
            '@count' => $count_result,
          ]
        );
      }

      // Now run the actual query with range limit.
      $query = $node_storage->getQuery()
        ->condition('field_category', $category_tid)
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $status_tids, 'IN');
      if (!$all) {
        // Limit to 20 nodes per category.
        $query->range(0, 20);
      }
      $query->accessCheck(FALSE);

      $result = $query->execute();
      if (!empty($result)) {
        $nids = array_merge($nids, $result);
        if ($log) {
          $this->logger->notice('Found @count archivable nodes for category @cat', [
            '@count' => count($result),
            '@cat' => $category_tid,
          ]);
        }
      }
    }

    // Return a limited number of nodes to process this run.
    if (!$all) {
      $nids = array_slice($nids, 0, 50);
    }
    if ($log) {
      $this->logger->notice('Returning @count nodes for archiving', ['@count' => count($nids)]);
    }
    return $node_storage->loadMultiple($nids);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeConfiguredFields(array $configured_fields): array {
    $fields = [];
    foreach ($configured_fields as $field_name => $value) {
      $name = is_numeric($field_name) ? $value : $field_name;
      if (
        !is_string($name)
        || $name === ''
        || !is_scalar($value)
        || is_numeric($value)
        || $value === ''
        || $value === FALSE
      ) {
        continue;
      }
      $fields[$name] = $name;
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function anonymize(EntityInterface $archivable, array $anonymize_fields): array {
    if (!$archivable instanceof FieldableEntityInterface) {
      return [];
    }

    $anonymized_values = [];
    foreach ($this->fieldNames($anonymize_fields) as $field_name) {
      if (!$archivable->hasField($field_name)) {
        continue;
      }

      $items = $archivable->get($field_name);
      $type = $items->getFieldDefinition()->getType();
      $value = $this->anonymizedValue($type);
      if ($value === NULL) {
        if ($this->fieldHasValue($archivable, $field_name)) {
          $this->logger->warning('field @field of type @type skipped, not anonymizable', [
            '@field' => $field_name,
            '@type' => $type,
          ]);
        }
        continue;
      }

      $anonymized_values[$field_name] = [
        'type' => $type,
        'value' => $value,
      ];
      foreach ($this->fieldableTranslations($archivable) as $translation) {
        $translation_items = $translation->get($field_name);
        if ($translation_items->isEmpty()) {
          continue;
        }
        foreach ($translation_items as $item) {
          $item->set('value', $value);
          if ($type === 'text_with_summary') {
            $item->set('summary', $value);
          }
        }
      }
    }

    return $anonymized_values;
  }

  /**
   * {@inheritdoc}
   */
  public function anonymizeRevisions(EntityInterface $archivable, array $anonymized_values): int {
    if (
      !$archivable instanceof FieldableEntityInterface
      || !$archivable instanceof RevisionableInterface
      || $anonymized_values === []
    ) {
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage($archivable->getEntityTypeId());
    if (!$storage instanceof RevisionableStorageInterface) {
      return 0;
    }

    $current_revision_id = (int) $archivable->getRevisionId();
    $revision_ids = array_map('intval', $storage->revisionIds($archivable));
    $previous_revision_ids = array_values(array_filter(
      $revision_ids,
      static function (int $revision_id) use ($current_revision_id): bool {
        return $revision_id !== $current_revision_id;
      }
    ));
    if ($previous_revision_ids === []) {
      return 0;
    }

    $updated = 0;
    foreach ($anonymized_values as $field_name => $info) {
      if (!$archivable->hasField($field_name) || !isset($info['value'])) {
        continue;
      }

      $column_info = $this->revisionColumnInfo(
        $archivable,
        $field_name,
        (string) ($info['type'] ?? '')
      );
      if ($column_info === NULL) {
        $this->logger->warning(
          'Revisions of field @field use a shared table and were not ' .
          'anonymized; previous revisions may retain data.',
          ['@field' => $field_name]
        );
        continue;
      }

      $fields = [
        $column_info['columns']['value'] => $info['value'],
      ];
      if (isset($column_info['columns']['summary'])) {
        $fields[$column_info['columns']['summary']] = $info['value'];
      }

      // Direct revision table updates avoid entity hooks and new revision rows.
      $updated += $this->database->update($column_info['table'])
        ->fields($fields)
        ->condition('entity_id', $archivable->id())
        ->condition('revision_id', $previous_revision_ids, 'IN')
        ->execute();
    }

    if ($updated > 0) {
      $storage->resetCache([$archivable->id()]);
    }
    return $updated;
  }

  /**
   * {@inheritdoc}
   */
  public function previewAnonymizeFields(EntityInterface $archivable, array $anonymize_fields): array {
    if (!$archivable instanceof FieldableEntityInterface) {
      return [];
    }

    $fields = [];
    foreach ($this->fieldNames($anonymize_fields) as $field_name) {
      if (!$archivable->hasField($field_name)) {
        continue;
      }
      $items = $archivable->get($field_name);
      if (
        !$this->fieldHasValue($archivable, $field_name)
        || !$this->isAnonymizableType($items->getFieldDefinition()->getType())
      ) {
        continue;
      }
      $fields[] = $field_name;
    }

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getArchiveRetention(NodeInterface $node): array {
    $config = $this->configFactory->get('markaspot_archive.settings');
    $default_days = (int) $config->get('default_days');
    $category_tid = 0;
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $category_tid = (int) $node->get('field_category')->target_id;
    }
    return $this->getArchiveRetentionForCategory($category_tid, $default_days);
  }

  /**
   * Returns supported field names from a field list or associative map.
   *
   * @param array $fields
   *   Field names as values or keys.
   *
   * @return string[]
   *   Field machine names.
   */
  protected function fieldNames(array $fields): array {
    $names = [];
    foreach ($fields as $key => $value) {
      $field_name = is_numeric($key) ? $value : $key;
      if (is_string($field_name) && $field_name !== '') {
        $names[$field_name] = $field_name;
      }
    }
    return array_values($names);
  }

  /**
   * Builds an anonymized value for supported field types.
   *
   * @param string $type
   *   Field type.
   *
   * @return int|string|null
   *   Replacement value, or NULL when the type is not supported.
   */
  protected function anonymizedValue(string $type): int|string|null {
    switch ($type) {
      case 'email':
        return $this->randomToken() . '@anonymized.off';

      case 'telephone':
        return '+49-0123459995555';

      case 'boolean':
        return 0;

      case 'string':
      case 'string_long':
      case 'text':
      case 'text_long':
      case 'text_with_summary':
        return $this->randomToken();
    }

    return NULL;
  }

  /**
   * Returns TRUE when a field type has a safe anonymization strategy.
   */
  protected function isAnonymizableType(string $type): bool {
    return in_array($type, [
      'email',
      'telephone',
      'boolean',
      'string',
      'string_long',
      'text',
      'text_long',
      'text_with_summary',
    ], TRUE);
  }

  /**
   * Builds a short opaque replacement token.
   */
  protected function randomToken(): string {
    return bin2hex(random_bytes(5));
  }

  /**
   * Returns TRUE when any translation has a non-empty field value.
   */
  protected function fieldHasValue(FieldableEntityInterface $entity, string $field_name): bool {
    foreach ($this->fieldableTranslations($entity) as $translation) {
      if (!$translation->get($field_name)->isEmpty()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns all fieldable translations for an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   Fieldable entity.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface[]
   *   Fieldable translations keyed by language code.
   */
  protected function fieldableTranslations(FieldableEntityInterface $entity): array {
    if (!$entity instanceof TranslatableInterface) {
      return [$entity->language()->getId() => $entity];
    }

    $translations = [];
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      if ($entity->hasTranslation($langcode)) {
        $translation = $entity->getTranslation($langcode);
        if ($translation instanceof FieldableEntityInterface) {
          $translations[$langcode] = $translation;
        }
      }
    }

    return $translations ?: [$entity->language()->getId() => $entity];
  }

  /**
   * Returns the dedicated revision table and value column for a field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   Fieldable entity.
   * @param string $field_name
   *   Field machine name.
   * @param string $type
   *   Field type.
   *
   * @return array{table: string, columns: array<string, string>}|null
   *   Revision table information, or NULL if the field cannot be updated.
   */
  protected function revisionColumnInfo(FieldableEntityInterface $entity, string $field_name, string $type): ?array {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if (!$storage instanceof SqlContentEntityStorage) {
      return NULL;
    }

    $field_storage = $entity->get($field_name)->getFieldDefinition()->getFieldStorageDefinition();
    $table_mapping = $storage->getTableMapping();
    $table = $table_mapping->getDedicatedRevisionTableName($field_storage);
    $value_column = $table_mapping->getFieldColumnName($field_storage, 'value');
    $schema = $this->database->schema();
    if (!$schema->tableExists($table) || !$schema->fieldExists($table, $value_column)) {
      return NULL;
    }

    $columns = [
      'value' => $value_column,
    ];
    if ($type === 'text_with_summary') {
      $summary_column = $table_mapping->getFieldColumnName($field_storage, 'summary');
      if ($schema->fieldExists($table, $summary_column)) {
        $columns['summary'] = $summary_column;
      }
    }

    return [
      'table' => $table,
      'columns' => $columns,
    ];
  }

  /**
   * Returns the active retention period for a category.
   *
   * @param int $category_tid
   *   Service category term ID.
   * @param int $default_days
   *   Default retention days.
   *
   * @return array{days: int, source: string}
   *   Retention days and source.
   */
  protected function getArchiveRetentionForCategory(int $category_tid, int $default_days): array {
    if ($category_tid > 0) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($category_tid);
      if ($term && $term->hasField('field_archive_days') && !$term->get('field_archive_days')->isEmpty()) {
        $override = (int) $term->get('field_archive_days')->value;
        if ($override > 0) {
          return [
            'days' => $override,
            'source' => 'term override',
          ];
        }
      }
    }

    return [
      'days' => $default_days,
      'source' => 'default',
    ];
  }

}
