<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\Sql\Query as SqlQuery;
use Drupal\markaspot_request_index\RequestIndexReader;

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
 * Translations: the projector writes the default translation of a request,
 * while Phase 2 reads every translation row of node_field_data and the field
 * tables. Both agree because service_request is untranslated:
 * language.content_settings.node.service_request has default_langcode und
 * and language_alterable false and the bundle is not content-translatable,
 * so every request has exactly one translation; RequestIndexReader checks
 * that on every parity count. Sorts or conditions that name a langcode
 * select a translation and are therefore never translated; term-label sorts
 * are the exception, see TERM_LABEL_SORTS. Term fields are matched like Core
 * only while they are untranslatable, see TERM_COLUMNS.
 *
 * The wrapper uses this class only while RequestIndexReader::isUsable() says
 * the index is complete and current (one row per service request, nothing
 * queued, no request marked stale), so a missing or stale row cannot hide a
 * request. A database failure while reading the candidate query is logged
 * without values and makes the wrapper fall back to CandidateEntityQuery.
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
   *
   * Paths through a field of REFERENCE_FIELDS or into TERM_COLUMNS are only
   * translated while the field definitions confirm the index holds the same
   * value Core matches; see isExact().
   */
  private const COLUMNS = [
    'nid' => 'nid',
    'uid' => 'uid',
    'status' => 'published',
    'created' => 'created',
    'changed' => 'changed',
    'request_id' => 'request_id',
    'field_status' => 'status_tid',
    'field_category' => 'category_tid',
    'field_category.entity:taxonomy_term.field_service_code' => 'service_code',
    'field_status.entity:taxonomy_term.field_open311_mapping' => 'open311_mapping',
  ];

  /**
   * Node reference fields the projector reads at delta 0 only.
   *
   * Core matches any delta, so a condition through one of them is translated
   * only while its storage is single-valued (cardinality 1 in the profile).
   */
  private const REFERENCE_FIELDS = ['field_status', 'field_category'];

  /**
   * Term-sourced columns: the term bundle and field the value comes from.
   *
   * The projector stores the value of the term's default translation, while
   * Core joins the term field table without a langcode and matches the value
   * of any translation row. An untranslatable field stores the default
   * translation's row only, so both agree; a translatable one may not. The
   * shipped config keeps both untranslatable (field_service_code: storage
   * translatable, instance not; field_open311_mapping: instance translatable,
   * storage not), but either flag is one click in the content language
   * settings, so the definition is read on every query.
   */
  private const TERM_COLUMNS = [
    'service_code' => ['service_category', 'field_service_code'],
    'open311_mapping' => ['service_status', 'field_open311_mapping'],
  ];

  /**
   * Varchar columns and their lengths (markaspot_request_index_schema()).
   *
   * The projector trims and truncates these values, so only = and IN with
   * values that survive that unchanged are translated. The index table and
   * node_field_data may carry different collations (seen on a tenant:
   * utf8mb4_unicode_ci against utf8mb4_general_ci). The case-insensitive
   * collations agree for an ASCII value against an ASCII stored value but
   * not beyond (unicode_ci matches "ß" to "ss", general_ci does not), so
   * other values are left to Phase 2. Not covered: an ASCII value typed as a
   * near-match of a stored non-ASCII value ("Strase" for "Straße") or a
   * _bin/_cs collation on one side; request IDs and service codes are ASCII.
   */
  private const STRING_COLUMN_LENGTHS = [
    'request_id' => 64,
    'service_code' => 64,
    'open311_mapping' => 32,
  ];

  /**
   * Multi-valued reference fields: row column, multi flag, child table.
   *
   * The row column holds one value only: jur_gid the most specific
   * jurisdiction, org_gid the organisation at delta 0. The child table holds
   * every delta of the field.
   */
  private const MULTI = [
    'field_jurisdiction' => ['jur_gid', 'jur_multi', 'markaspot_request_index_jur'],
    'field_organisation' => ['org_gid', 'org_multi', 'markaspot_request_index_org'],
  ];

  /**
   * Sort paths reproduced by joining the term label in the sort language.
   *
   * Only served for sorts that LocalizedSortQuery::wrap() registered: their
   * Phase 2 joins the term row of the sort language, else the term's default
   * translation, exactly like the join below. Core's plain langcode sort
   * applies the langcode to the request's reference row as well and is not
   * reproducible here.
   */
  private const TERM_LABEL_SORTS = [
    'field_category.entity:taxonomy_term.name' => 'category_tid',
    'field_status.entity:taxonomy_term.name' => 'status_tid',
  ];

  /**
   * Property suffixes JSON:API appends to reference paths.
   */
  private const PATH_SUFFIXES = [
    '.target_id',
    '.value',
    '.entity:group.id',
    '.entity:taxonomy_term.tid',
    '.entity:user.uid',
    '.entity:node.nid',
  ];

  /**
   * Sort paths read from index columns. Never with a langcode.
   */
  private const PLAIN_SORTS = ['nid', 'created', 'changed'];

  /**
   * Number of rows the last execute() read.
   */
  private int $resultRowCount = 0;

  /**
   * Constructs an IndexCandidateQuery.
   */
  private function __construct(
    private readonly SelectInterface $select,
    private readonly RequestIndexReader $reader,
  ) {
  }

  /**
   * Builds the candidate query, or NULL when the index cannot serve it.
   *
   * Does not check whether the index is usable: the wrapper asks
   * RequestIndexReader::isUsable() once per execution.
   *
   * @param \Drupal\Core\Entity\Query\Sql\Query $source
   *   The access-checked query of the collection.
   * @param int $window
   *   Candidate window (LIMIT, offset 0).
   * @param string|null $tiebreak_direction
   *   Direction of the nid tiebreaker the wrapper appends, or NULL.
   * @param \Drupal\markaspot_request_index\RequestIndexReader $reader
   *   The request index reader, for failure reports.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface|null $field_manager
   *   Field definitions; without them conditions on configurable fields are
   *   left to Phase 2.
   */
  public static function fromQuery(SqlQuery $source, int $window, ?string $tiebreak_direction, RequestIndexReader $reader, ?EntityFieldManagerInterface $field_manager): ?self {
    if ($source->getEntityTypeId() !== 'node') {
      return NULL;
    }
    // Building only reads the source query; the database is first touched by
    // execute().
    $select = self::build($source, $window, $tiebreak_direction, $field_manager);
    return $select === NULL ? NULL : new self($select, $reader);
  }

  /**
   * Runs the candidate query.
   *
   * @return array<int, int>|null
   *   Candidate node IDs keyed by node ID, in sort order, or NULL when the
   *   database failed; the failure is logged without values.
   */
  public function execute(): ?array {
    try {
      $nids = array_map('intval', $this->select->execute()->fetchCol());
    }
    catch (DatabaseException $exception) {
      $this->reader->reportReadFailure($exception);
      return NULL;
    }
    $this->resultRowCount = count($nids);
    return $nids === [] ? [] : array_combine($nids, $nids);
  }

  /**
   * Returns the number of rows the last execute() read.
   */
  public function getResultRowCount(): int {
    return $this->resultRowCount;
  }

  /**
   * Translates the source query, or returns NULL to decline.
   *
   * @param \Drupal\Core\Entity\Query\Sql\Query $source
   *   The access-checked query of the collection.
   * @param int $window
   *   Candidate window (LIMIT, offset 0).
   * @param string|null $tiebreak_direction
   *   Direction of the nid tiebreaker the wrapper appends, or NULL.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface|null $field_manager
   *   Field definitions, or NULL.
   */
  private static function build(SqlQuery $source, int $window, ?string $tiebreak_direction, ?EntityFieldManagerInterface $field_manager): ?SelectInterface {
    $select = CandidateEntityQuery::readConnection($source)->select(self::TABLE, 'i');
    $select->addField('i', 'nid');

    $conditions = [];
    self::collectConjunctiveConditions(CandidateEntityQuery::readCondition($source), $conditions);
    $bundle_seen = FALSE;
    foreach ($conditions as $condition) {
      $field = (string) $condition['field'];
      if ($field === 'type') {
        $operator = strtoupper((string) ($condition['operator'] ?? '='));
        if (!in_array($operator, ['=', 'IN'], TRUE) || (array) $condition['value'] !== ['service_request']) {
          return NULL;
        }
        $bundle_seen = TRUE;
        continue;
      }
      self::applyCondition($select, $field, $condition, $field_manager);
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
    $registered = $source->getMetaData('dashboard_localized_sorts');
    $registered = is_array($registered) ? $registered : [];
    $joins = 0;
    foreach ($sorts as $sort) {
      $field = (string) ($sort['field'] ?? '');
      $direction = strtoupper((string) ($sort['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
      $langcode = $sort['langcode'] ?? NULL;
      if (in_array($field, self::PLAIN_SORTS, TRUE)) {
        // With a langcode Core sorts on that translation's row, which the
        // index does not have.
        if ($langcode !== NULL && $langcode !== '') {
          return NULL;
        }
        $select->orderBy('i.' . $field, $direction);
        continue;
      }
      if (isset(self::TERM_LABEL_SORTS[$field]) && is_string($langcode) && $langcode !== ''
        && self::isRegisteredLocalizedSort($sort, $registered)) {
        // Same translation choice as LocalizedSortTables: the requested
        // language, else the term's default translation.
        // One placeholder per occurrence: a named placeholder used twice
        // relies on emulated prepares.
        $alias = 't' . $joins++;
        $own = ':request_index_sort_langcode_' . $select->nextPlaceholder();
        $preferred = ':request_index_sort_langcode_' . $select->nextPlaceholder();
        $select->leftJoin('taxonomy_term_field_data', $alias, "[$alias].[tid] = [i]." . self::TERM_LABEL_SORTS[$field] . " AND ([$alias].[langcode] = $own OR ([$alias].[default_langcode] = 1 AND NOT EXISTS (SELECT 1 FROM {taxonomy_term_field_data} preferred WHERE [preferred].[tid] = [$alias].[tid] AND [preferred].[langcode] = $preferred)))", [
          $own => $langcode,
          $preferred => $langcode,
        ]);
        $select->orderBy($alias . '.name', $direction);
        continue;
      }
      // Address, request id, organisation label, unregistered or plain
      // langcode term sorts and anything else: collation, delta or
      // translation semantics may differ from Phase 2, so keep Core's Phase 1.
      return NULL;
    }

    $select->range(0, $window);
    return $select;
  }

  /**
   * Whether LocalizedSortQuery::wrap() registered exactly this sort.
   *
   * @param array<string, mixed> $sort
   *   The sort entry of the source query.
   * @param array<mixed> $registered
   *   The dashboard_localized_sorts metadata of the source query.
   */
  private static function isRegisteredLocalizedSort(array $sort, array $registered): bool {
    foreach ($registered as $entry) {
      if (is_array($entry)
        && ($entry['field'] ?? NULL) === ($sort['field'] ?? NULL)
        && ($entry['direction'] ?? NULL) === ($sort['direction'] ?? NULL)
        && ($entry['langcode'] ?? NULL) === ($sort['langcode'] ?? NULL)) {
        return TRUE;
      }
    }
    return FALSE;
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
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface|null $field_manager
   *   Field definitions, or NULL.
   */
  private static function applyCondition(SelectInterface $select, string $field, array $condition, ?EntityFieldManagerInterface $field_manager): void {
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
      $values = (array) $value;
      if (array_filter($values, static fn(mixed $item): bool => !is_scalar($item)) !== []) {
        return;
      }
      $ids = array_values(array_filter(array_map('intval', $values)));
      if ($ids === []) {
        return;
      }
      [$column, $flag, $child] = self::MULTI[$path];
      // The entity query matches any delta. A single-valued request has its
      // only value in the row column. A multi-valued one may match at any
      // delta, which only the child table holds: jur_gid is the most
      // specific jurisdiction, not delta 0. Access guards may add a second
      // condition on the same field, so every condition gets its own
      // placeholder.
      $placeholder = ':request_index_gids_' . $select->nextPlaceholder() . '[]';
      $or = $select->orConditionGroup()
        ->condition('i.' . $column, $ids, 'IN')
        ->where("[i].[$flag] = 1 AND EXISTS (SELECT 1 FROM {" . $child . "} c WHERE [c].[nid] = [i].[nid] AND [c].[gid] IN ($placeholder))", [$placeholder => $ids]);
      $select->condition($or);
      return;
    }

    if (!isset(self::COLUMNS[$path])) {
      return;
    }
    $column = self::COLUMNS[$path];
    if (!self::isExact($path, $column, $field_manager)) {
      return;
    }
    $allowed = isset(self::STRING_COLUMN_LENGTHS[$column])
      ? ['=', 'IN']
      : ['=', 'IN', '<', '<=', '>', '>=', 'BETWEEN'];
    if (!in_array($operator, $allowed, TRUE)) {
      return;
    }
    $values = in_array($operator, ['IN', 'BETWEEN'], TRUE) ? $value : [$value];
    if (!is_array($values) || $values === [] || ($operator === 'BETWEEN' && count($values) !== 2)) {
      return;
    }
    foreach ($values as $item) {
      if (!is_scalar($item)) {
        return;
      }
      if (isset(self::STRING_COLUMN_LENGTHS[$column])) {
        // The projector stores trim()med values cut to the column length.
        // A value that would not survive that unchanged could miss rows.
        $item = (string) $item;
        if ($item !== trim($item) || mb_strlen($item) > self::STRING_COLUMN_LENGTHS[$column]
          || preg_match('/[^\x20-\x7E]/', $item) === 1) {
          return;
        }
      }
    }
    $select->condition('i.' . $column, $value, $operator);
  }

  /**
   * Whether the index column holds every value Core matches for the path.
   *
   * Base fields always do. A path through a reference field needs a
   * single-valued storage, and a term-sourced column an untranslatable,
   * single-valued term field. Unknown definitions count as inexact: leaving
   * the condition to Phase 2 only widens the candidate set.
   *
   * @param string $path
   *   Normalized entity query field path.
   * @param string $column
   *   The index column of the path.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface|null $field_manager
   *   Field definitions, or NULL.
   */
  private static function isExact(string $path, string $column, ?EntityFieldManagerInterface $field_manager): bool {
    $node_field = explode('.', $path, 2)[0];
    if (!in_array($node_field, self::REFERENCE_FIELDS, TRUE)) {
      return TRUE;
    }
    if ($field_manager === NULL) {
      return FALSE;
    }
    $storage = $field_manager->getFieldStorageDefinitions('node')[$node_field] ?? NULL;
    if ($storage === NULL || $storage->isMultiple()) {
      return FALSE;
    }
    if (!isset(self::TERM_COLUMNS[$column])) {
      return TRUE;
    }
    [$bundle, $term_field] = self::TERM_COLUMNS[$column];
    $definition = $field_manager->getFieldDefinitions('taxonomy_term', $bundle)[$term_field] ?? NULL;
    return $definition !== NULL
      && !$definition->isTranslatable()
      && !$definition->getFieldStorageDefinition()->isMultiple();
  }

  /**
   * Strips the property suffixes JSON:API adds to reference paths.
   */
  private static function normalizePath(string $field): string {
    foreach (self::PATH_SUFFIXES as $suffix) {
      if (str_ends_with($field, $suffix)) {
        return substr($field, 0, -strlen($suffix));
      }
    }
    return $field;
  }

}
