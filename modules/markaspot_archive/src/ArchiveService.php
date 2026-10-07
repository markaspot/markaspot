<?php

namespace Drupal\markaspot_archive;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Class ArchiveService finds all service requests that can be archived.
 */
class ArchiveService implements ArchiveServiceInterface {

  /**
   * Archived nodes the backfill anonymizes per cron run at most.
   */
  public const BACKFILL_LIMIT = 50;

  /**
   * Seconds the backfill may spend per cron run.
   */
  public const BACKFILL_TIME_BUDGET = 20;

  /**
   * State key of the last node id the backfill has processed.
   */
  public const BACKFILL_CURSOR_STATE = 'markaspot_archive.backfill_last_nid';

  /**
   * Telephone value written by ::anonymizedValue().
   */
  protected const ANONYMIZED_PHONE = '+49-0123459995555';

  /**
   * Entities anonymized during this request.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, true>
   */
  protected \WeakMap $anonymizedEntities;

  /**
   * Anonymized values waiting for the revision cleanup after the save.
   *
   * @var \WeakMap<\Drupal\node\NodeInterface, array>
   */
  protected \WeakMap $pendingRevisionValues;

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
    $this->anonymizedEntities = new \WeakMap();
    $this->pendingRevisionValues = new \WeakMap();
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

    $this->anonymizedEntities[$archivable] = TRUE;
    return $anonymized_values;
  }

  /**
   * {@inheritdoc}
   */
  public function anonymizeOnArchiveTransition(NodeInterface $node): array {
    if ($node->bundle() !== 'service_request' || !$node->hasField('field_status')) {
      return [];
    }
    $config = $this->configFactory->get('markaspot_archive.settings');
    $archived_status = (string) $config->get('status_archived');
    if ($config->get('anonymize') != 1 || $archived_status === '') {
      return [];
    }
    if ((string) $node->get('field_status')->target_id !== $archived_status) {
      return [];
    }
    $original = $this->originalNode($node);
    if ($original !== NULL && (string) $original->get('field_status')->target_id === $archived_status) {
      return [];
    }
    // The queue worker and the staff command anonymize before they save.
    if (isset($this->anonymizedEntities[$node])) {
      return [];
    }

    $fields = $this->normalizeConfiguredFields((array) $config->get('anonymize_fields'));
    if ($fields === []) {
      return [];
    }
    $anonymized_values = $this->anonymize($node, $fields);
    if ($anonymized_values === []) {
      return [];
    }
    if (!$node->isNew()) {
      $this->pendingRevisionValues[$node] = $anonymized_values;
    }
    $this->logger->notice(
      'Node ID @nid anonymized on transition to the archived status: @fields',
      [
        '@nid' => $node->isNew() ? 'new' : $node->id(),
        '@fields' => implode(', ', array_keys($anonymized_values)),
      ]
    );
    return $anonymized_values;
  }

  /**
   * {@inheritdoc}
   */
  public function finishArchiveTransition(NodeInterface $node): int {
    if (!isset($this->pendingRevisionValues[$node])) {
      return 0;
    }
    $anonymized_values = $this->pendingRevisionValues[$node];
    unset($this->pendingRevisionValues[$node]);
    return $this->anonymizeRevisions($node, $anonymized_values);
  }

  /**
   * {@inheritdoc}
   */
  public function backfillArchived(
    int $limit = self::BACKFILL_LIMIT,
    int $time_budget = self::BACKFILL_TIME_BUDGET,
  ): int {
    $config = $this->configFactory->get('markaspot_archive.settings');
    $archived_status = (string) $config->get('status_archived');
    if ($config->get('anonymize') != 1 || $archived_status === '') {
      return 0;
    }
    $fields = $this->normalizeConfiguredFields((array) $config->get('anonymize_fields'));
    $storage = $this->entityTypeManager->getStorage('node');
    $detectable = $this->detectableFields($fields);
    if ($detectable === []) {
      return 0;
    }

    $cursor = (int) $this->state->get(self::BACKFILL_CURSOR_STATE, 0);
    $nids = $this->backfillCandidates($archived_status, $detectable, $cursor, $limit);
    if ($nids === []) {
      // Start over on the next run to catch nodes archived meanwhile.
      if ($cursor > 0) {
        $this->state->set(self::BACKFILL_CURSOR_STATE, 0);
      }
      return 0;
    }

    $started = microtime(TRUE);
    $anonymized = 0;
    $failed = 0;
    foreach ($nids as $nid) {
      if (microtime(TRUE) - $started > $time_budget) {
        break;
      }
      $cursor = (int) $nid;
      try {
        $node = $storage->load($nid);
        if (!$node instanceof NodeInterface) {
          continue;
        }
        $anonymized_values = $this->anonymize($node, $fields);
        // A field empty on the current revision may still hold plain data in
        // previous revisions.
        foreach ($detectable as $field_name => $type) {
          if (!isset($anonymized_values[$field_name]) && $node->hasField($field_name)) {
            $anonymized_values[$field_name] = [
              'type' => $type,
              'value' => $this->anonymizedValue($type),
            ];
          }
        }
        if ($anonymized_values === []) {
          continue;
        }
        // Keep the changed time: anonymizing is no change of the request.
        $node->setSyncing(TRUE);
        $node->save();
        $this->anonymizeRevisions($node, $anonymized_values);
        $anonymized++;
      }
      catch (DatabaseException $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        $failed++;
        $this->logger->warning(
          'Archived node @nid could not be anonymized: @error',
          ['@nid' => $nid, '@error' => $e->getMessage()]
        );
      }
    }
    $this->state->set(self::BACKFILL_CURSOR_STATE, $cursor);

    if ($anonymized > 0 || $failed > 0) {
      $this->logger->notice(
        'Anonymized @count archived service requests with plain contact data (@failed failed, up to node ID @nid).',
        ['@count' => $anonymized, '@failed' => $failed, '@nid' => $cursor]
      );
    }
    return $anonymized;
  }

  /**
   * Returns the configured fields whose values reveal plain contact data.
   *
   * E-mail and telephone values show whether they were anonymized; string
   * values are anonymized to a 10 character hex token.
   *
   * @param array $fields
   *   Configured field machine names.
   *
   * @return array<string, string>
   *   Field types keyed by field machine name.
   */
  protected function detectableFields(array $fields): array {
    $detectable = [];
    foreach ($this->fieldNames($fields) as $field_name) {
      $type = $this->nodeFieldStorage($field_name)?->getType();
      if (in_array($type, ['email', 'telephone', 'string'], TRUE)) {
        $detectable[$field_name] = $type;
      }
    }
    return $detectable;
  }

  /**
   * Returns archived requests with plain contact data in any revision.
   *
   * Joins the revision tables of the contact fields with the current status,
   * so values left in previous revisions are found as well.
   *
   * @param string $archived_status
   *   The archived status term id.
   * @param array<string, string> $detectable
   *   Field types keyed by field machine name.
   * @param int $cursor
   *   Only node ids above this one are returned.
   * @param int $limit
   *   Maximum number of node ids.
   *
   * @return int[]
   *   Node ids in ascending order.
   */
  protected function backfillCandidates(string $archived_status, array $detectable, int $cursor, int $limit): array {
    $storage = $this->entityTypeManager->getStorage('node');
    if (!$storage instanceof SqlContentEntityStorage) {
      return [];
    }
    $mapping = $storage->getTableMapping();
    $status = $this->nodeFieldStorage('field_status');
    if ($status === NULL || !$mapping->requiresDedicatedTableStorage($status)) {
      return [];
    }
    $status_table = $mapping->getDedicatedDataTableName($status);
    $status_column = $mapping->getFieldColumnName($status, 'target_id');

    $nids = [];
    foreach ($detectable as $field_name => $type) {
      $definition = $this->nodeFieldStorage($field_name);
      if ($definition === NULL || !$mapping->requiresDedicatedTableStorage($definition)) {
        continue;
      }
      $column = 'r.' . $mapping->getFieldColumnName($definition, 'value');
      $query = $this->database->select($mapping->getDedicatedRevisionTableName($definition), 'r');
      $query->join($status_table, 's', 's.entity_id = r.entity_id');
      $query->addField('r', 'entity_id');
      $query->condition('s.bundle', 'service_request')
        ->condition('s.' . $status_column, $archived_status)
        ->condition('r.entity_id', $cursor, '>')
        ->condition($column, '', '<>');
      match ($type) {
        'email' => $query->condition($column, '%' . $this->database->escapeLike('@anonymized.off'), 'NOT LIKE'),
        'telephone' => $query->condition($column, self::ANONYMIZED_PHONE, '<>'),
        default => $query->condition($column, '^[0-9a-f]{10}$', 'NOT REGEXP'),
      };
      $query->distinct()->orderBy('r.entity_id')->range(0, $limit);
      $nids = array_merge($nids, array_map('intval', $query->execute()->fetchCol()));
    }
    $nids = array_values(array_unique($nids));
    sort($nids);
    return array_slice($nids, 0, $limit);
  }

  /**
   * Returns the storage definition of a configurable node field.
   */
  protected function nodeFieldStorage(string $field_name): ?FieldStorageDefinitionInterface {
    $definition = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('node.' . $field_name);
    return $definition instanceof FieldStorageDefinitionInterface ? $definition : NULL;
  }

  /**
   * Returns the unchanged node of the save in progress, if any.
   */
  protected function originalNode(NodeInterface $node): ?NodeInterface {
    // getOriginal() exists since Drupal 11.2; the property before.
    $original = method_exists($node, 'getOriginal')
      ? $node->getOriginal()
      : ($node->original ?? NULL);
    return $original instanceof NodeInterface ? $original : NULL;
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
        return self::ANONYMIZED_PHONE;

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
