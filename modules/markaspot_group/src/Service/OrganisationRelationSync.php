<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Repairs organisation relations from field_organisation, the authority.
 *
 * For each service request the group_node:service_request relations to
 * organisation groups are made to match field_organisation. Jurisdiction
 * relations are never read for writing, and the node is never loaded for
 * saving.
 *
 * Relations are written through the Database API on the two Group storage
 * tables instead of the entity API, on purpose. Group 3 re-saves the grouped
 * node in GroupRelationship::postSave() (insert) and ::postDelete(), and the
 * markaspot_group insert hook sends the organisation assignment mail for a
 * relation whose organisation is in the field. Both are exactly what a drift
 * repair must not do: no node save, no revision, no ECA event, no mail. The
 * direct write is the scoped suppression; no hook runs, so nothing has to be
 * switched off globally. In exchange this class does what the entity API
 * would have done that still matters: per-node sync locks, Group's cache tag
 * invalidation, entity cache resets, and a guard that refuses to write when
 * the relationship schema has fields it does not know.
 *
 * Nothing that consumes relations is lost: node access grants and the
 * request index derive from fields, Group access reads the relationship
 * table at query time.
 */
final class OrganisationRelationSync implements ContainerInjectionInterface {

  /**
   * Organisation group bundle, as _markaspot_group_get_org_group_type().
   */
  public const ORGANISATION_GROUP_TYPE = 'org';

  /**
   * The relation plugin of service requests.
   */
  public const PLUGIN_ID = 'group_node:service_request';

  /**
   * Default number of requests locked and written per transaction.
   */
  public const BATCH_SIZE = 25;

  /**
   * Maximum number of requests locked and written per transaction.
   *
   * A concurrent save of a locked request waits one second for the lock and
   * then fails, so a transaction must stay far below that.
   */
  public const MAX_BATCH_SIZE = 50;

  /**
   * Service request bundle.
   */
  private const BUNDLE = 'service_request';

  /**
   * Requests read per scan query.
   */
  private const SCAN_CHUNK = 1000;

  /**
   * Fields the guard accepts on the relationship tables, per table kind.
   */
  private const KNOWN_FIELDS = [
    'base' => ['id', 'langcode', 'type', 'uuid'],
    'data' => [
      'changed',
      'created',
      'default_langcode',
      'entity_id',
      'gid',
      'group_type',
      'id',
      'label',
      'langcode',
      'plugin_id',
      'type',
      'uid',
    ],
  ];

  /**
   * Group facts keyed by group ID: bundle and organisation jurisdiction.
   *
   * @var array<int, array{bundle: string|null, jurisdiction: int|null}>
   */
  private array $groups = [];

  /**
   * Root jurisdiction IDs keyed by jurisdiction ID.
   *
   * @var array<int, int|null>
   */
  private array $roots = [];

  /**
   * Organisation relationship type ID, FALSE until looked up.
   */
  private string|false|null $bundle = FALSE;

  /**
   * Constructs the sync.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly LanguageManagerInterface $languageManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Built through the class resolver from existing services, so a Drush run
   * right after a deploy does not depend on a rebuilt container.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('uuid'),
      $container->get('datetime.time'),
      $container->get('language_manager'),
      $container->get('cache_tags.invalidator'),
      $container->get('logger.factory')->get('markaspot_group'),
    );
  }

  /**
   * Yields the drifted service requests in node ID order.
   *
   * @param array{jurisdiction?: int|null, nid?: int|null, organisations?: list<int>, limit?: int|null} $filter
   *   Optional filters: a jurisdiction (with its descendants), one node, the
   *   organisations a drift must involve on either side, and the maximum
   *   number of drifted requests to yield.
   *
   * @return \Generator<int, \Drupal\markaspot_group\Service\OrganisationRelationDiff>
   *   Diffs of requests whose field and relations disagree.
   */
  public function scan(array $filter = []): \Generator {
    $nodeTables = $this->nodeTables();
    $jurisdictionIds = isset($filter['jurisdiction'])
      ? $this->jurisdictionScope((int) $filter['jurisdiction'])
      : NULL;
    $organisations = array_fill_keys(array_map('intval', $filter['organisations'] ?? []), TRUE);
    $limit = isset($filter['limit']) ? max(0, (int) $filter['limit']) : NULL;
    $yielded = 0;
    $last = 0;

    while ($limit === NULL || $yielded < $limit) {
      $query = $this->database->select($nodeTables['data'], 'd')
        ->fields('d', ['nid'])
        ->condition('d.type', self::BUNDLE)
        ->condition('d.default_langcode', 1)
        ->condition('d.nid', $last, '>')
        ->orderBy('d.nid')
        ->range(0, self::SCAN_CHUNK);
      if (isset($filter['nid'])) {
        $query->condition('d.nid', (int) $filter['nid']);
      }
      if ($jurisdictionIds !== NULL) {
        $scope = $this->database->select($nodeTables['jurisdiction'], 'j')
          ->fields('j', ['entity_id'])
          ->condition('j.deleted', 0)
          ->condition('j.' . $nodeTables['jurisdiction_column'], $jurisdictionIds, 'IN');
        $query->condition('d.nid', $scope, 'IN');
      }
      $nids = array_map('intval', $query->execute()->fetchCol());
      if ($nids === []) {
        return;
      }
      $last = end($nids);

      foreach ($this->loadState($nids) as $diff) {
        if (!$diff->isDrift()) {
          continue;
        }
        if ($organisations !== [] && array_intersect_key($organisations, array_flip(array_merge($diff->fieldIds, $diff->relationIds()))) === []) {
          continue;
        }
        yield $diff;
        $yielded++;
        if ($limit !== NULL && $yielded >= $limit) {
          return;
        }
      }
    }
  }

  /**
   * Applies the current diff of each request, one short transaction per chunk.
   *
   * The requests are processed in chunks of at most $batchSize. Each chunk
   * takes the per-node sync lock that markaspot_group's own field and
   * relation sync uses, re-reads its state and writes it in one transaction,
   * and releases the locks before the next chunk starts. A concurrent node
   * save therefore cannot interleave, and it waits for one short transaction
   * at most. A request whose lock is held elsewhere is left for a rerun.
   *
   * @param list<int> $nids
   *   Service request node IDs.
   * @param int $batchSize
   *   Requests per transaction, capped at MAX_BATCH_SIZE.
   *
   * @return array<int, array{diff: \Drupal\markaspot_group\Service\OrganisationRelationDiff|null, result: string}>
   *   Per node ID the re-read diff and one of "applied", "skipped" (only
   *   field organisations that cannot get a relation), "blocked", "unchanged"
   *   (no drift any more), "locked" or "missing".
   */
  public function apply(array $nids, int $batchSize = self::BATCH_SIZE): array {
    $this->assertWritable();
    $nids = array_values(array_unique(array_filter(array_map('intval', $nids), static fn(int $nid): bool => $nid > 0)));
    $results = [];
    foreach (array_chunk($nids, max(1, min($batchSize, self::MAX_BATCH_SIZE))) as $chunk) {
      $results += $this->applyChunk($chunk);
    }
    return $results;
  }

  /**
   * Locks, re-reads and writes one chunk of requests in one transaction.
   *
   * @param list<int> $nids
   *   Service request node IDs.
   *
   * @return array<int, array{diff: \Drupal\markaspot_group\Service\OrganisationRelationDiff|null, result: string}>
   *   Results keyed by node ID, as apply() returns them.
   */
  private function applyChunk(array $nids): array {
    $results = [];
    $locked = [];
    try {
      foreach ($nids as $nid) {
        if ($this->acquireLock($nid)) {
          $locked[] = $nid;
        }
        else {
          $results[$nid] = ['diff' => NULL, 'result' => 'locked'];
        }
      }
      if ($locked === []) {
        return $results;
      }

      $tags = [];
      $touched = [];
      $added = 0;
      $removed = 0;
      $transaction = $this->database->startTransaction();
      try {
        $states = $this->loadState($locked);
        $labels = $this->labels(array_keys(array_filter($states, static fn(OrganisationRelationDiff $diff): bool => $diff->add !== [])));
        foreach ($locked as $nid) {
          $diff = $states[$nid] ?? NULL;
          if ($diff === NULL) {
            $results[$nid] = ['diff' => NULL, 'result' => 'missing'];
            continue;
          }
          if (!$diff->hasChanges()) {
            $results[$nid] = [
              'diff' => $diff,
              'result' => $diff->blocked !== NULL ? 'blocked' : ($diff->isDrift() ? 'skipped' : 'unchanged'),
            ];
            continue;
          }
          foreach ($diff->remove as $relationshipId => $gid) {
            $tags = array_merge($tags, $this->cacheTags($gid, $nid, $relationshipId));
            $touched[] = $relationshipId;
          }
          $this->deleteRelationships(array_keys($diff->remove));
          foreach ($diff->add as $gid) {
            $touched[] = $this->insertRelationship($gid, $nid, $labels[$nid] ?? '');
            $tags = array_merge($tags, $this->cacheTags($gid, $nid));
          }
          $added += count($diff->add);
          $removed += count($diff->remove);
          $results[$nid] = ['diff' => $diff, 'result' => 'applied'];
        }
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      // Commit before invalidating, so no reader caches the old state again.
      unset($transaction);

      if ($touched !== []) {
        $this->entityTypeManager->getStorage('group_relationship')->resetCache($touched);
        $this->entityTypeManager->getAccessControlHandler('node')->resetCache();
        $this->cacheTagsInvalidator->invalidateTags(array_values(array_unique($tags)));
        $this->logger->notice('Organisation relation sync: @added relations added, @removed removed on @requests service requests.', [
          '@added' => $added,
          '@removed' => $removed,
          '@requests' => count(array_filter($results, static fn(array $result): bool => $result['result'] === 'applied')),
        ]);
      }
    }
    finally {
      foreach ($locked as $nid) {
        $this->releaseLock($nid);
      }
    }

    return $results;
  }

  /**
   * Explains why relations cannot be written here, NULL when they can.
   */
  public function writeProblem(): ?string {
    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $entityType = $storage->getEntityType();
    if (!$storage instanceof SqlEntityStorageInterface || $entityType->isRevisionable()) {
      return 'group_relationship does not use non-revisionable SQL storage.';
    }
    $mapping = $storage->getTableMapping();
    if (!$mapping instanceof DefaultTableMapping || $mapping->getDataTable() === NULL) {
      return 'group_relationship has no default table mapping with a data table.';
    }
    foreach (['base' => $mapping->getBaseTable(), 'data' => $mapping->getDataTable()] as $kind => $table) {
      $fields = $mapping->getFieldNames($table);
      sort($fields);
      if ($fields !== self::KNOWN_FIELDS[$kind]) {
        return sprintf('Unexpected fields in %s: %s.', $table, implode(', ', array_diff($fields, self::KNOWN_FIELDS[$kind])) ?: 'missing known fields');
      }
    }

    $bundle = $this->relationshipBundle();
    if ($bundle === NULL) {
      return sprintf('Organisation groups do not install %s.', self::PLUGIN_ID);
    }
    // Computed fields without table storage (such as path) stay empty on a
    // new relation, as they do for one created through the entity API.
    $known = array_merge(self::KNOWN_FIELDS['base'], self::KNOWN_FIELDS['data']);
    $extra = [];
    foreach ($this->entityFieldManager->getFieldDefinitions('group_relationship', $bundle) as $name => $definition) {
      if (!in_array($name, $known, TRUE) && !$definition->isComputed() && !$definition->getFieldStorageDefinition()->hasCustomStorage()) {
        $extra[] = $name;
      }
    }
    if ($extra !== []) {
      return sprintf('Relationship type %s has fields this sync cannot fill: %s.', $bundle, implode(', ', $extra));
    }
    return NULL;
  }

  /**
   * Throws when relations cannot be written safely.
   */
  public function assertWritable(): void {
    $problem = $this->writeProblem();
    if ($problem !== NULL) {
      throw new \RuntimeException('Refusing to write organisation relations: ' . $problem);
    }
  }

  /**
   * Returns a jurisdiction and its descendants.
   *
   * @return list<int>
   *   Jurisdiction group IDs.
   */
  public function jurisdictionScope(int $jurisdictionId): array {
    $group = $this->entityTypeManager->getStorage('group')->load($jurisdictionId);
    if (!$group instanceof GroupInterface || $group->bundle() !== $this->jurisdictionGroupType()) {
      throw new \InvalidArgumentException(sprintf('Group %d is not a %s jurisdiction.', $jurisdictionId, $this->jurisdictionGroupType()));
    }
    $ids = array_map('intval', $this->hierarchyResolver->getDescendantIds($jurisdictionId));
    $ids[] = $jurisdictionId;
    return array_values(array_unique($ids));
  }

  /**
   * Reads field, relation and jurisdiction state of requests.
   *
   * @param list<int> $nids
   *   Service request node IDs.
   *
   * @return array<int, \Drupal\markaspot_group\Service\OrganisationRelationDiff>
   *   Diffs keyed by node ID, for existing service requests only.
   */
  private function loadState(array $nids): array {
    if ($nids === []) {
      return [];
    }
    $tables = $this->nodeTables();

    $published = $this->database->select($tables['data'], 'd')
      ->fields('d', ['nid', 'status'])
      ->condition('d.type', self::BUNDLE)
      ->condition('d.default_langcode', 1)
      ->condition('d.nid', $nids, 'IN')
      ->execute()
      ->fetchAllKeyed();

    $fieldIds = [];
    $result = $this->database->select($tables['organisation'], 'o')
      ->fields('o', ['entity_id', $tables['organisation_column']])
      ->condition('o.bundle', self::BUNDLE)
      ->condition('o.deleted', 0)
      ->condition('o.entity_id', $nids, 'IN')
      ->execute();
    foreach ($result as $row) {
      $gid = (int) $row->{$tables['organisation_column']};
      if ($gid > 0) {
        $fieldIds[(int) $row->entity_id][$gid] = $gid;
      }
    }

    // The first delta of the default translation, as
    // _markaspot_group_node_jurisdiction_id() reads it from the loaded node.
    $jurisdictions = [];
    $query = $this->database->select($tables['jurisdiction'], 'j');
    $query->innerJoin($tables['data'], 'jd', '[jd].[nid] = [j].[entity_id] AND [jd].[langcode] = [j].[langcode] AND [jd].[default_langcode] = 1');
    $result = $query
      ->fields('j', ['entity_id', $tables['jurisdiction_column']])
      ->condition('j.bundle', self::BUNDLE)
      ->condition('j.deleted', 0)
      ->condition('j.delta', 0)
      ->condition('j.entity_id', $nids, 'IN')
      ->execute();
    foreach ($result as $row) {
      $jurisdictions[(int) $row->entity_id] ??= (int) $row->{$tables['jurisdiction_column']};
    }

    $relations = [];
    $result = $this->database->select('group_relationship_field_data', 'gr')
      ->fields('gr', ['id', 'gid', 'entity_id'])
      ->condition('gr.plugin_id', self::PLUGIN_ID)
      ->condition('gr.group_type', self::ORGANISATION_GROUP_TYPE)
      ->condition('gr.default_langcode', 1)
      ->condition('gr.entity_id', $nids, 'IN')
      ->execute();
    foreach ($result as $row) {
      $relations[(int) $row->entity_id][(int) $row->id] = (int) $row->gid;
    }

    $this->loadGroups(array_merge([], ...array_map('array_values', $fieldIds)));
    $singleOrganisation = $this->configFactory->get('markaspot_group.settings')->get('single_organisation_assignment') === TRUE;

    $diffs = [];
    foreach ($published as $nid => $status) {
      $nid = (int) $nid;
      $ids = array_values($fieldIds[$nid] ?? []);
      $jurisdictionId = isset($jurisdictions[$nid]) && $jurisdictions[$nid] > 0 ? $jurisdictions[$nid] : NULL;
      $problems = [];
      foreach ($ids as $gid) {
        $problem = $this->additionProblem($gid, $jurisdictionId);
        if ($problem !== NULL) {
          $problems[$gid] = $problem;
        }
      }
      $diffs[$nid] = OrganisationRelationDiff::fromState(
        $nid,
        (int) $status === 1,
        $jurisdictionId,
        $ids,
        $relations[$nid] ?? [],
        $problems,
        $singleOrganisation && count($ids) > 1 ? 'single_organisation_violation' : NULL,
      );
    }
    return $diffs;
  }

  /**
   * Explains why a field organisation must not get a relation.
   *
   * Mirrors _markaspot_group_org_group_matches_jurisdiction(): the
   * organisation's jurisdiction is the request's or its root.
   */
  private function additionProblem(int $gid, ?int $jurisdictionId): ?string {
    $group = $this->groups[$gid] ?? NULL;
    if ($group === NULL || $group['bundle'] === NULL) {
      return 'missing_group';
    }
    if ($group['bundle'] !== self::ORGANISATION_GROUP_TYPE) {
      return 'not_organisation';
    }
    if ($jurisdictionId === NULL) {
      return 'request_without_jurisdiction';
    }
    if ($group['jurisdiction'] === NULL) {
      return 'organisation_without_jurisdiction';
    }
    if ($group['jurisdiction'] === $jurisdictionId) {
      return NULL;
    }
    if (!array_key_exists($jurisdictionId, $this->roots)) {
      $this->roots[$jurisdictionId] = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
    }
    return $this->roots[$jurisdictionId] === $group['jurisdiction'] ? NULL : 'jurisdiction_mismatch';
  }

  /**
   * Caches bundle and jurisdiction of groups not seen yet.
   *
   * @param list<int> $gids
   *   Group IDs.
   */
  private function loadGroups(array $gids): void {
    $missing = array_values(array_diff(array_unique($gids), array_keys($this->groups)));
    if ($missing === []) {
      return;
    }
    $groups = $this->entityTypeManager->getStorage('group')->loadMultiple($missing);
    foreach ($missing as $gid) {
      $group = $groups[$gid] ?? NULL;
      if (!$group instanceof GroupInterface) {
        $this->groups[$gid] = ['bundle' => NULL, 'jurisdiction' => NULL];
        continue;
      }
      $jurisdiction = $group->hasField('field_jurisdiction') && !$group->get('field_jurisdiction')->isEmpty()
        ? (int) $group->get('field_jurisdiction')->target_id
        : NULL;
      $this->groups[$gid] = ['bundle' => $group->bundle(), 'jurisdiction' => $jurisdiction ?: NULL];
    }
  }

  /**
   * Inserts one organisation relation row set, as Group's storage would.
   *
   * @return int
   *   The new relationship entity ID.
   */
  private function insertRelationship(int $gid, int $nid, string $label): int {
    $mapping = $this->relationshipMapping();
    $bundle = (string) $this->relationshipBundle();
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $now = $this->time->getCurrentTime();
    $column = static fn(string $field): string => (string) current($mapping->getColumnNames($field));

    $id = (int) $this->database->insert($mapping->getBaseTable())
      ->fields([
        $column('type') => $bundle,
        $column('uuid') => $this->uuid->generate(),
        $column('langcode') => $langcode,
      ])
      ->execute();
    $this->database->insert((string) $mapping->getDataTable())
      ->fields([
        $column('id') => $id,
        $column('type') => $bundle,
        $column('langcode') => $langcode,
        // Owner 0: the relation is written by the system, not by an account.
        $column('uid') => 0,
        $column('gid') => $gid,
        $column('entity_id') => $nid,
        $column('label') => mb_substr($label, 0, 255),
        $column('created') => $now,
        $column('changed') => $now,
        $column('plugin_id') => self::PLUGIN_ID,
        $column('group_type') => self::ORGANISATION_GROUP_TYPE,
        $column('default_langcode') => 1,
      ])
      ->execute();
    return $id;
  }

  /**
   * Deletes relationship rows from every table of the entity type.
   *
   * @param list<int> $ids
   *   Relationship entity IDs.
   */
  private function deleteRelationships(array $ids): void {
    if ($ids === []) {
      return;
    }
    $mapping = $this->relationshipMapping();
    $idColumn = (string) current($mapping->getColumnNames('id'));
    foreach ([$mapping->getBaseTable(), (string) $mapping->getDataTable()] as $table) {
      $this->database->delete($table)->condition($idColumn, $ids, 'IN')->execute();
    }
    foreach ($this->entityFieldManager->getFieldStorageDefinitions('group_relationship') as $definition) {
      if ($mapping->requiresDedicatedTableStorage($definition)) {
        $this->database->delete($mapping->getDedicatedDataTableName($definition))
          ->condition('entity_id', $ids, 'IN')
          ->execute();
      }
    }
  }

  /**
   * Returns the cache tags Group invalidates for a relation write.
   *
   * GroupRelationship::getListCacheTagsToInvalidate() plus the entity type
   * and bundle list tags, the deleted entity's own tag, and the node tags the
   * re-save in GroupRelationship::postSave() would have invalidated.
   *
   * @return list<string>
   *   Cache tags.
   */
  private function cacheTags(int $gid, int $nid, ?int $relationshipId = NULL): array {
    $plugin = self::PLUGIN_ID;
    $tags = [
      'group_relationship_list',
      'group_relationship_list:' . $this->relationshipBundle(),
      "group_relationship_list:group:$gid",
      "group_relationship_list:entity:$nid",
      "group_relationship_list:plugin:$plugin",
      "group_relationship_list:plugin:$plugin:group:$gid",
      "group_relationship_list:plugin:$plugin:entity:$nid",
      "node:$nid",
      'node_list',
      'node_list:' . self::BUNDLE,
    ];
    if ($relationshipId !== NULL) {
      $tags[] = "group_relationship:$relationshipId";
    }
    return $tags;
  }

  /**
   * Returns default-translation titles, used as relationship labels.
   *
   * @param list<int> $nids
   *   Node IDs.
   *
   * @return array<int, string>
   *   Titles keyed by node ID.
   */
  private function labels(array $nids): array {
    if ($nids === []) {
      return [];
    }
    return $this->database->select($this->nodeTables()['data'], 'd')
      ->fields('d', ['nid', 'title'])
      ->condition('d.nid', $nids, 'IN')
      ->condition('d.default_langcode', 1)
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Returns the organisation relationship type ID, NULL when not installed.
   */
  private function relationshipBundle(): ?string {
    if ($this->bundle !== FALSE) {
      return $this->bundle;
    }
    $storage = $this->entityTypeManager->getStorage('group_relationship_type');
    $id = $storage instanceof GroupRelationshipTypeStorageInterface
      ? $storage->getRelationshipTypeId(self::ORGANISATION_GROUP_TYPE, self::PLUGIN_ID)
      : NULL;
    $this->bundle = $id !== NULL && $storage->load($id) !== NULL ? $id : NULL;
    return $this->bundle;
  }

  /**
   * Returns the relationship table mapping.
   */
  private function relationshipMapping(): DefaultTableMapping {
    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $mapping = $storage instanceof SqlEntityStorageInterface ? $storage->getTableMapping() : NULL;
    if (!$mapping instanceof DefaultTableMapping) {
      throw new \LogicException('group_relationship requires the default SQL table mapping.');
    }
    return $mapping;
  }

  /**
   * Returns the node tables and columns this sync reads.
   *
   * @return array{data: string, organisation: string, organisation_column: string, jurisdiction: string, jurisdiction_column: string}
   *   Table and column names.
   */
  private function nodeTables(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $mapping = $storage instanceof SqlEntityStorageInterface ? $storage->getTableMapping() : NULL;
    if (!$mapping instanceof DefaultTableMapping) {
      throw new \LogicException('The organisation relation sync requires SQL node storage.');
    }
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('node');
    foreach (['field_organisation', 'field_jurisdiction'] as $field) {
      if (!isset($definitions[$field])) {
        throw new \RuntimeException(sprintf('Node field %s does not exist.', $field));
      }
    }
    return [
      'data' => (string) $mapping->getDataTable(),
      'organisation' => $mapping->getFieldTableName('field_organisation'),
      'organisation_column' => $mapping->getFieldColumnName($definitions['field_organisation'], 'target_id'),
      'jurisdiction' => $mapping->getFieldTableName('field_jurisdiction'),
      'jurisdiction_column' => $mapping->getFieldColumnName($definitions['field_jurisdiction'], 'target_id'),
    ];
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  private function jurisdictionGroupType(): string {
    $configured = $this->configFactory->get('markaspot_open311.settings')->get('jurisdiction_group_type');
    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

  /**
   * Acquires markaspot_group's per-request sync lock.
   */
  private function acquireLock(int $nid): bool {
    return !function_exists('_markaspot_group_acquire_service_request_sync_lock')
      || _markaspot_group_acquire_service_request_sync_lock($nid);
  }

  /**
   * Releases markaspot_group's per-request sync lock.
   */
  private function releaseLock(int $nid): void {
    if (function_exists('_markaspot_group_release_service_request_sync_lock')) {
      _markaspot_group_release_service_request_sync_lock($nid);
    }
  }

}
