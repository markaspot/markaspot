<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\Sql\Query as SqlQuery;

/**
 * Phase-1 candidate source backed by the request index read model.
 *
 * Phase 1 of DeferredAccessQueryWrapper only has to return a superset of the
 * rows the access-checked Phase 2 returns, in the same order. The index of
 * markaspot_request_index holds one row per service request, so bundle,
 * jurisdiction, organisation, status and category filters plus the common
 * sorts become one ordered index walk instead of a copy of the full condition
 * tree with its access guards.
 *
 * Two rules keep this safe:
 * - A condition that cannot be translated exactly is left out. That only
 *   widens the superset; Phase 2 still applies every condition and all access
 *   checks.
 * - A sort that cannot be reproduced exactly makes the class decline, because
 *   both phases must agree on order for the candidate window to hold the
 *   requested page.
 *
 * The index is used only while it is complete (one row per service request,
 * nothing queued), so a missing row cannot hide a request.
 *
 * @internal This class is part of the markaspot_nuxt module internals.
 */
final class IndexCandidateQuery {

  /**
   * Main table of the read model.
   */
  private const TABLE = 'markaspot_request_index';

  /**
   * Single-valued paths mapped to index columns.
   */
  private const COLUMNS = [
    'nid' => 'nid',
    'uid' => 'uid',
    'status' => 'published',
    'created' => 'created',
    'changed' => 'changed',
    'field_status' => 'status_tid',
    'field_category' => 'category_tid',
    'field_category.entity:taxonomy_term.field_service_code' => 'service_code',
    'field_status.entity:taxonomy_term.field_open311_mapping' => 'open311_mapping',
  ];

  /**
   * Multi-valued reference fields: first-value column, flag, child table.
   */
  private const MULTI = [
    'field_jurisdiction' => ['jur_gid', 'jur_multi', 'markaspot_request_index_jur'],
    'field_organisation' => ['org_gid', 'org_multi', 'markaspot_request_index_org'],
  ];

  /**
   * Sort paths reproduced by joining the term label in the sort language.
   */
  private const TERM_LABEL_SORTS = [
    'field_category.entity:taxonomy_term.name' => 'category_tid',
    'field_status.entity:taxonomy_term.name' => 'status_tid',
  ];

  /**
   * Per-request cache of the index completeness check.
   */
  private static ?bool $complete = NULL;

  /**
   * Number of rows the last execute() read.
   */
  private int $resultRowCount = 0;

  /**
   * Constructs an IndexCandidateQuery.
   */
  private function __construct(
    private readonly SelectInterface $select,
  ) {
  }

  /**
   * Builds the candidate query, or NULL when the index cannot serve it.
   *
   * @param \Drupal\Core\Entity\Query\Sql\Query $source
   *   The access-checked query of the collection.
   * @param int $window
   *   Candidate window (LIMIT, offset 0).
   * @param string|null $tiebreak_direction
   *   Direction of the nid tiebreaker the wrapper appends, or NULL.
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   */
  public static function fromQuery(SqlQuery $source, int $window, ?string $tiebreak_direction, Connection $database): ?self {
    if ($source->getEntityTypeId() !== 'node' || !self::indexIsComplete($database)) {
      return NULL;
    }

    $select = $database->select(self::TABLE, 'i');
    $select->addField('i', 'nid');

    $conditions = [];
    self::collectConjunctiveConditions(CandidateEntityQuery::readCondition($source), $conditions);
    $bundle_seen = FALSE;
    foreach ($conditions as $condition) {
      $field = (string) $condition['field'];
      if ($field === 'type') {
        $values = (array) $condition['value'];
        if (!in_array($condition['operator'] ?? '=', ['=', 'IN', NULL], TRUE) || $values !== ['service_request']) {
          return NULL;
        }
        $bundle_seen = TRUE;
        continue;
      }
      self::applyCondition($select, $field, $condition);
    }
    // The index holds service requests only.
    if (!$bundle_seen) {
      return NULL;
    }

    $sorts = CandidateEntityQuery::readSort($source);
    if ($tiebreak_direction !== NULL) {
      $sorts[] = ['field' => 'nid', 'direction' => $tiebreak_direction, 'langcode' => NULL];
    }
    if ($sorts === []) {
      return NULL;
    }
    $joins = 0;
    foreach ($sorts as $sort) {
      $field = (string) ($sort['field'] ?? '');
      $direction = strtoupper((string) ($sort['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
      if (in_array($field, ['nid', 'created', 'changed'], TRUE)) {
        $select->orderBy('i.' . $field, $direction);
        continue;
      }
      $langcode = $sort['langcode'] ?? NULL;
      if (isset(self::TERM_LABEL_SORTS[$field]) && is_string($langcode) && $langcode !== '') {
        // Same translation choice as LocalizedSortTables: the requested
        // language, else the term's default translation.
        $alias = 't' . $joins++;
        $placeholder = ':' . $alias . '_langcode';
        $select->leftJoin('taxonomy_term_field_data', $alias, "[$alias].[tid] = [i]." . self::TERM_LABEL_SORTS[$field] . " AND ([$alias].[langcode] = $placeholder OR ([$alias].[default_langcode] = 1 AND NOT EXISTS (SELECT 1 FROM {taxonomy_term_field_data} preferred WHERE [preferred].[tid] = [$alias].[tid] AND [preferred].[langcode] = $placeholder)))", [$placeholder => $langcode]);
        $select->orderBy($alias . '.name', $direction);
        continue;
      }
      // Address, request id, organisation label and anything else: collation
      // or delta semantics may differ from Phase 2, so keep Core's Phase 1.
      return NULL;
    }

    $select->range(0, $window);
    return new self($select);
  }

  /**
   * Runs the candidate query.
   *
   * @return array<int, int>
   *   Candidate node IDs keyed by node ID, in sort order.
   */
  public function execute(): array {
    $nids = array_map('intval', $this->select->execute()->fetchCol());
    $this->resultRowCount = count($nids);
    return array_combine($nids, $nids) ?: [];
  }

  /**
   * Returns the number of rows the last execute() read.
   */
  public function getResultRowCount(): int {
    return $this->resultRowCount;
  }

  /**
   * Resets the per-request completeness cache (tests, long-running drush).
   */
  public static function resetCache(): void {
    self::$complete = NULL;
  }

  /**
   * Whether the index holds exactly one row per service request.
   */
  private static function indexIsComplete(Connection $database): bool {
    if (self::$complete !== NULL) {
      return self::$complete;
    }
    self::$complete = FALSE;
    try {
      if (!$database->schema()->tableExists(self::TABLE)) {
        return FALSE;
      }
      $requests = (int) $database->select('node', 'n')
        ->condition('n.type', 'service_request')
        ->countQuery()->execute()->fetchField();
      $indexed = (int) $database->select(self::TABLE, 'i')->countQuery()->execute()->fetchField();
      $queued = (int) $database->select('queue', 'q')
        ->condition('q.name', 'markaspot_request_index%', 'LIKE')
        ->countQuery()->execute()->fetchField();
      self::$complete = $requests === $indexed && $queued === 0;
    }
    catch (\Throwable) {
      self::$complete = FALSE;
    }
    return self::$complete;
  }

  /**
   * Collects leaf conditions reachable through AND groups only.
   *
   * Conditions inside OR groups are skipped: leaving them out widens the
   * candidate set, which is safe.
   *
   * @param \Drupal\Core\Entity\Query\ConditionInterface $group
   *   The condition group.
   * @param array<int, array<string, mixed>> $collected
   *   Collected leaf conditions.
   */
  private static function collectConjunctiveConditions(ConditionInterface $group, array &$collected): void {
    if (strtoupper($group->getConjunction()) !== 'AND') {
      return;
    }
    foreach ($group->conditions() as $condition) {
      if ($condition['field'] instanceof ConditionInterface) {
        self::collectConjunctiveConditions($condition['field'], $collected);
      }
      elseif (is_string($condition['field'])) {
        $collected[] = $condition;
      }
    }
  }

  /**
   * Adds one translated condition to the candidate query, or nothing.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $select
   *   The candidate query.
   * @param string $field
   *   Entity query field path.
   * @param array<string, mixed> $condition
   *   The leaf condition.
   */
  private static function applyCondition(SelectInterface $select, string $field, array $condition): void {
    // Language-specific conditions and negations stay in Phase 2 only.
    if (!empty($condition['langcode'])) {
      return;
    }
    $operator = strtoupper((string) ($condition['operator'] ?? '='));
    $value = $condition['value'];
    $path = self::normalizePath($field);

    if (isset(self::MULTI[$path])) {
      if (!in_array($operator, ['=', 'IN'], TRUE)) {
        return;
      }
      $ids = array_values(array_filter(array_map('intval', (array) $value)));
      if ($ids === []) {
        return;
      }
      [$first, $flag, $child] = self::MULTI[$path];
      // Any delta matching is what the entity query means: the first value,
      // or for multi-valued rows any value in the child table.
      $or = $select->orConditionGroup()
        ->condition('i.' . $first, $ids, 'IN')
        ->where("[i].[$flag] = 1 AND EXISTS (SELECT 1 FROM {" . $child . "} c WHERE [c].[nid] = [i].[nid] AND [c].[gid] IN (:{$child}_ids[]))", [":{$child}_ids[]" => $ids]);
      $select->condition($or);
      return;
    }

    if (!isset(self::COLUMNS[$path])) {
      return;
    }
    $column = 'i.' . self::COLUMNS[$path];
    $allowed = ['=', 'IN', '<', '<=', '>', '>=', 'BETWEEN'];
    if (!in_array($operator, $allowed, TRUE)) {
      return;
    }
    if (in_array($operator, ['IN', 'BETWEEN'], TRUE) && (!is_array($value) || $value === [])) {
      return;
    }
    if (!in_array($operator, ['IN', 'BETWEEN'], TRUE) && !is_scalar($value)) {
      return;
    }
    $select->condition($column, $value, $operator);
  }

  /**
   * Strips the property suffixes JSON:API adds to reference paths.
   */
  private static function normalizePath(string $field): string {
    foreach (['.target_id', '.value', '.entity:group.id', '.entity:taxonomy_term.tid', '.entity:user.uid', '.entity:node.nid'] as $suffix) {
      if (str_ends_with($field, $suffix)) {
        return substr($field, 0, -strlen($suffix));
      }
    }
    return $field;
  }

}
