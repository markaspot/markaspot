<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\node\NodeInterface;

/**
 * Compares the request index with its sources. Read-only.
 */
final class RequestIndexVerifier {

  /**
   * Node ID span scanned per drift query.
   */
  private const DRIFT_SPAN = 5000;

  /**
   * Requests loaded per projector diff chunk.
   */
  private const DIFF_CHUNK = 100;

  /**
   * Constructs the verifier.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly RequestIndexProjector $projector,
  ) {}

  /**
   * Compares the row set with the service requests.
   *
   * @return array{requests: int, indexed: int, missing: int, orphans: int}
   *   Service requests, index rows, requests without a row and rows without
   *   a service request.
   */
  public function parity(): array {
    $base = $this->tableMapping()->getBaseTable();
    $requests = (int) $this->database->select($base, 'n')
      ->condition('n.type', RequestIndexProjector::BUNDLE)
      ->countQuery()->execute()->fetchField();
    $indexed = (int) $this->database->select(RequestIndexWriter::TABLE, 'i')
      ->countQuery()->execute()->fetchField();

    $missing = $this->database->select($base, 'n');
    $missing->leftJoin(RequestIndexWriter::TABLE, 'i', '[i].[nid] = [n].[nid]');
    $missing->condition('n.type', RequestIndexProjector::BUNDLE)->isNull('i.nid');

    $orphans = $this->database->select(RequestIndexWriter::TABLE, 'i');
    $orphans->leftJoin($base, 'n', '[n].[nid] = [i].[nid] AND [n].[type] = :bundle', [':bundle' => RequestIndexProjector::BUNDLE]);
    $orphans->isNull('n.nid');

    return [
      'requests' => $requests,
      'indexed' => $indexed,
      'missing' => (int) $missing->countQuery()->execute()->fetchField(),
      'orphans' => (int) $orphans->countQuery()->execute()->fetchField(),
    ];
  }

  /**
   * Re-projects requests in memory and compares them with the stored rows.
   *
   * @param int $sample
   *   Number of random requests; 0 checks every request.
   *
   * @return array{checked: int, differences: array<int, list<string>>}
   *   Checked requests and the differing columns per node ID.
   */
  public function diff(int $sample = 500): array {
    $query = $this->database->select($this->tableMapping()->getBaseTable(), 'n')
      ->fields('n', ['nid'])
      ->condition('n.type', RequestIndexProjector::BUNDLE);
    if ($sample > 0) {
      $query->orderRandom()->range(0, $sample);
    }
    else {
      $query->orderBy('n.nid');
    }
    $nids = array_map('intval', $query->execute()->fetchCol());

    $storage = $this->entityTypeManager->getStorage('node');
    $checked = 0;
    $differences = [];
    foreach (array_chunk($nids, self::DIFF_CHUNK) as $chunk) {
      $storage->resetCache($chunk);
      $nodes = $storage->loadMultiple($chunk);
      $stored = $this->storedRecords($chunk);
      foreach ($chunk as $nid) {
        $node = $nodes[$nid] ?? NULL;
        if (!$node instanceof NodeInterface) {
          continue;
        }
        $checked++;
        $columns = $this->compare($this->projector->build($node), $stored[$nid] ?? NULL);
        if ($columns !== []) {
          $differences[$nid] = $columns;
        }
      }
      $storage->resetCache($chunk);
    }

    return ['checked' => $checked, 'differences' => $differences];
  }

  /**
   * Reports requests whose field_organisation differs from their relations.
   *
   * This is source drift between the field (the authority) and the
   * group_node organisation relations, not index staleness. The definition
   * is the one of OrganisationRelationDiff::isDrift(), which the repair
   * command drush mas:org-relations:sync uses: default-language relations
   * only, and a duplicate relation to the same organisation is drift.
   *
   * @param int $top
   *   Number of most frequent field/relation combinations to return.
   *
   * @return array{available: bool, checked: int, drifted: int, patterns: array<string, int>, combinations: array<string, int>}
   *   Checked requests, drifted requests, counts per pattern and the most
   *   frequent combinations of organisation IDs.
   */
  public function drift(int $top = 10): array {
    $result = ['available' => FALSE, 'checked' => 0, 'drifted' => 0, 'patterns' => [], 'combinations' => []];
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('node');
    if (!$this->projector->hasSourceField('field_organisation') || !isset($definitions['field_organisation'])) {
      return $result;
    }
    $mapping = $this->tableMapping();
    $fieldTable = $mapping->getFieldTableName('field_organisation');
    $fieldColumn = $mapping->getFieldColumnName($definitions['field_organisation'], 'target_id');
    $dataTable = (string) $mapping->getDataTable();
    $result['available'] = TRUE;

    $max = (int) $this->database->select($mapping->getBaseTable(), 'n')
      ->condition('n.type', RequestIndexProjector::BUNDLE)
      ->fields('n', ['nid'])
      ->orderBy('n.nid', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $combinations = [];
    for ($from = 0; $from < $max; $from += self::DRIFT_SPAN) {
      $to = $from + self::DRIFT_SPAN;
      $published = $this->database->select($dataTable, 'd')
        ->fields('d', ['nid', 'status'])
        ->condition('d.type', RequestIndexProjector::BUNDLE)
        ->condition('d.default_langcode', 1)
        ->condition('d.nid', $from, '>')
        ->condition('d.nid', $to, '<=')
        ->execute()
        ->fetchAllKeyed();

      $fieldSets = $this->pairs($this->database->select($fieldTable, 'f')
        ->fields('f', ['entity_id', $fieldColumn])
        ->condition('f.bundle', RequestIndexProjector::BUNDLE)
        ->condition('f.deleted', 0)
        ->condition('f.entity_id', $from, '>')
        ->condition('f.entity_id', $to, '<=')
        ->distinct());
      // One entry per relation, duplicates included.
      $relations = [];
      $relationQuery = $this->database->select('group_relationship_field_data', 'gr')
        ->fields('gr', ['entity_id', 'gid'])
        ->condition('gr.plugin_id', 'group_node:service_request')
        ->condition('gr.group_type', 'org')
        ->condition('gr.default_langcode', 1)
        ->condition('gr.entity_id', $from, '>')
        ->condition('gr.entity_id', $to, '<=');
      foreach ($relationQuery->execute()->fetchAll(FetchAs::List) as [$owner, $gid]) {
        if ((int) $gid > 0) {
          $relations[(int) $owner][] = (int) $gid;
        }
      }

      foreach ($published as $nid => $status) {
        $result['checked']++;
        $fieldIds = $fieldSets[$nid] ?? [];
        $relationAll = $relations[$nid] ?? [];
        sort($relationAll);
        $relationIds = array_values(array_unique($relationAll));
        $duplicates = count($relationAll) !== count($relationIds);
        if ($fieldIds === $relationIds && !$duplicates) {
          continue;
        }
        $result['drifted']++;
        $shape = $fieldIds === $relationIds ? 'duplicate relations' : self::driftPattern($fieldIds, $relationIds);
        $pattern = ((int) $status === 1 ? 'published' : 'unpublished') . ': ' . $shape;
        $result['patterns'][$pattern] = ($result['patterns'][$pattern] ?? 0) + 1;
        $combination = sprintf(
          'field=%s relation=%s',
          $fieldIds === [] ? '-' : implode(',', $fieldIds),
          $relationAll === [] ? '-' : implode(',', $relationAll),
        );
        $combinations[$combination] = ($combinations[$combination] ?? 0) + 1;
      }
    }

    arsort($result['patterns']);
    arsort($combinations);
    $result['combinations'] = array_slice($combinations, 0, max(0, $top), TRUE);
    return $result;
  }

  /**
   * Names the shape of a field/relation disagreement.
   *
   * @param list<int> $fieldIds
   *   Sorted field_organisation IDs.
   * @param list<int> $relationIds
   *   Sorted organisation relation group IDs.
   */
  public static function driftPattern(array $fieldIds, array $relationIds): string {
    if ($fieldIds === []) {
      return 'relation only, field empty';
    }
    if ($relationIds === []) {
      return 'field only, no relation';
    }
    $shared = array_intersect($fieldIds, $relationIds);
    if ($shared === []) {
      return 'field and relation disjoint';
    }
    if (count($shared) === count($fieldIds)) {
      return 'relation has extra organisations';
    }
    if (count($shared) === count($relationIds)) {
      return 'field has extra organisations';
    }
    return 'field and relation overlap partially';
  }

  /**
   * Lists the differing parts of an expected and a stored record.
   *
   * @param \Drupal\markaspot_request_index\RequestIndexRecord $expected
   *   Freshly projected record.
   * @param array{row: array<string, mixed>, organisations: list<int>, jurisdictions: list<int>}|null $stored
   *   Stored record, NULL when the row is missing.
   *
   * @return list<string>
   *   Column names, "organisations", "jurisdictions" or "row missing".
   */
  private function compare(RequestIndexRecord $expected, ?array $stored): array {
    if ($stored === NULL) {
      return ['row missing'];
    }
    $differences = [];
    foreach ($expected->row as $column => $value) {
      $actual = $stored['row'][$column] ?? NULL;
      if (($value === NULL) !== ($actual === NULL) || (string) $value !== (string) $actual) {
        $differences[] = $column;
      }
    }
    foreach (['organisations', 'jurisdictions'] as $property) {
      $ids = $expected->{$property};
      sort($ids);
      if ($ids !== $stored[$property]) {
        $differences[] = $property;
      }
    }
    return $differences;
  }

  /**
   * Loads stored rows and child rows for node IDs.
   *
   * @param list<int> $nids
   *   Node IDs.
   *
   * @return array<int, array{row: array<string, mixed>, organisations: list<int>, jurisdictions: list<int>}>
   *   Stored records keyed by node ID.
   */
  private function storedRecords(array $nids): array {
    $records = [];
    $rows = $this->database->select(RequestIndexWriter::TABLE, 'i')
      ->fields('i')
      ->condition('i.nid', $nids, 'IN')
      ->execute()
      ->fetchAllAssoc('nid', FetchAs::Associative);
    foreach ($rows as $nid => $row) {
      $records[(int) $nid] = ['row' => $row, 'organisations' => [], 'jurisdictions' => []];
    }
    foreach (RequestIndexWriter::CHILD_TABLES as $property => $table) {
      $children = $this->pairs($this->database->select($table, 'c')
        ->fields('c', ['nid', 'gid'])
        ->condition('c.nid', $nids, 'IN'));
      foreach ($children as $nid => $gids) {
        if (isset($records[$nid])) {
          $records[$nid][$property] = $gids;
        }
      }
    }
    return $records;
  }

  /**
   * Groups (id, gid) pairs of a two-column query into sorted ID lists.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   Query selecting the owner ID first and the group ID second.
   *
   * @return array<int, list<int>>
   *   Sorted distinct group IDs keyed by owner ID.
   */
  private function pairs(SelectInterface $query): array {
    $sets = [];
    foreach ($query->execute()->fetchAll(FetchAs::List) as [$owner, $gid]) {
      $gid = (int) $gid;
      if ($gid > 0) {
        $sets[(int) $owner][$gid] = $gid;
      }
    }
    foreach ($sets as $owner => $gids) {
      sort($gids);
      $sets[$owner] = $gids;
    }
    return $sets;
  }

  /**
   * Returns the node table mapping.
   */
  private function tableMapping(): DefaultTableMapping {
    $storage = $this->entityTypeManager->getStorage('node');
    if (!$storage instanceof SqlEntityStorageInterface) {
      throw new \LogicException('The request index requires SQL node storage.');
    }
    $mapping = $storage->getTableMapping();
    if (!$mapping instanceof DefaultTableMapping) {
      throw new \LogicException('The request index requires the default table mapping.');
    }
    return $mapping;
  }

}
