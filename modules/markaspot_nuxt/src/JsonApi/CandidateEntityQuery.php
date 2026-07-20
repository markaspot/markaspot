<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\Sql\Query as SqlQuery;

/**
 * A simplified Phase-1 candidate query for DeferredAccessQueryWrapper.
 *
 * This subclass copies the state of a fully-configured Sql\Query and forces
 * the query to execute as a "simple query" -- no GROUP BY, no aggregate
 * ORDER BY expression -- so that ORDER BY created DESC LIMIT n can use an
 * index scan instead of a full filesort.
 *
 * WHY A SUBCLASS IS NECESSARY:
 * Drupal Core's Query::prepare() in
 * core/lib/Drupal/Core/Entity/Query/Sql/Query.php unconditionally sets the
 * simple_query metadata to FALSE whenever the entity
 * type has a data table (which is always true for `node`):
 *
 *   $simple_query = TRUE;
 *   if ($this->entityType->getDataTable()) {
 *     $simple_query = FALSE;
 *   }
 *   ...
 *   $this->sqlQuery->addMetaData('simple_query', $simple_query);
 *
 * The metadata is written AFTER the alterMetaData loop, so calling
 * `$query->addMetaData('simple_query', TRUE)` from the outside has no effect.
 * Query::isSimpleQuery() (called from addSort()) does check the metadata,
 * but by then it has already been clobbered by prepare(). Overriding
 * isSimpleQuery() in a subclass is the only reliable way to force the
 * simple code path.
 *
 * The subclass also disables access checking ($accessCheck = FALSE) so the
 * group module's EntityQueryAlter does NOT add its LEFT JOINs to this query.
 * Phase 2 (in DeferredAccessQueryWrapper) runs the real access-checked query
 * with an IN-list narrowed to these candidates, so access is always enforced.
 *
 * CORE UPDATE CHECKLIST (verify these method signatures are unchanged):
 * - Query::__construct(EntityTypeInterface, $conjunction, Connection, array)
 * - Query::prepare() — check the simple_query clobber is still in this method
 * - Query::isSimpleQuery() — must still be protected and called from addSort()
 * - Query::addSort() — must still call $this->isSimpleQuery()
 * - Query::finish() — must still apply $this->range
 * All are in core/lib/Drupal/Core/Entity/Query/Sql/Query.php.
 *
 * @internal This class is part of the markaspot_nuxt module internals.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class CandidateEntityQuery extends SqlQuery {
  /**
   * The range from the original (source) query, captured before construction.
   *
   * @var array{start: int, length: int}
   */
  private array $originalRange;

  /**
   * The sort array from the original (source) query.
   *
   * Captured before construction so the factory can pass it to the constructor.
   *
   * @var array<int, array{field: string, direction: string, langcode: string|null}>
   */
  private array $originalSort;

  /**
   * Constructs a CandidateEntityQuery.
   *
   * Use the static factory fromQuery() instead of calling this directly.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   Entity type definition.
   * @param string $conjunction
   *   Query conjunction ('AND').
   * @param \Drupal\Core\Database\Connection $connection
   *   Database connection.
   * @param array $namespaces
   *   Namespaces for the Condition class lookup.
   * @param array $original_range
   *   Range captured from the source query before it is overridden.
   * @param array $original_sort
   *   Sort captured from the source query.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    $conjunction,
    Connection $connection,
    array $namespaces,
    array $original_range,
    array $original_sort,
  ) {
    parent::__construct($entity_type, $conjunction, $connection, $namespaces);
    $this->originalRange = $original_range;
    $this->originalSort = $original_sort;
  }

  /**
   * Forces simple-query mode regardless of data-table presence.
   *
   * Core's prepare() sets simple_query=FALSE on the sqlQuery metadata when
   * the entity type has a data table. Our override here returns TRUE
   * unconditionally so addSort() skips GROUP BY and aggregate expressions.
   * This gives MySQL a plain ORDER BY that can be satisfied by an index scan.
   *
   * {@inheritdoc}
   */
  protected function isSimpleQuery(): bool {
    return TRUE;
  }

  /**
   * Consolidates ORDER BY aliases after Core has compiled the query.
   *
   * {@inheritdoc}
   */
  protected function finish() {
    parent::finish();
    $this->remapSortToPrimaryDataTableAlias();
    return $this;
  }

  /**
   * Rewrites ORDER BY expressions to reuse the first data-table join alias.
   *
   * Core joins the data table TWICE: condition compilation and addSort() each
   * construct their own Tables helper, and the join-dedup cache lives on the
   * Tables instance, not on the Select query. The condition side registers
   * "node_field_data" (INNER) first; addSort() later registers a second
   * instance "node_field_data_2" (LEFT) with an identical join condition and
   * sorts on that one:
   *
   *   INNER JOIN node_field_data ON node_field_data.nid = base_table.nid
   *   LEFT JOIN node_field_data node_field_data_2
   *     ON node_field_data_2.nid = base_table.nid
   *   ...ORDER BY node_field_data_2.created DESC
   *
   * MariaDB cannot drive an ORDER BY ... LIMIT index walk through the
   * LEFT-joined duplicate: the optimizer does not recognise the two aliases as
   * the same row source, so it falls back to scanning all rows of the base
   * table and filesorting (measured: 660ms on a 152k-node tenant). Rewriting
   * the ORDER BY onto the first alias lets the optimizer pick node_field_data
   * as the driving table via the created index and stop after LIMIT rows
   * (measured: 121 index entries read instead of 152k).
   *
   * Semantics are unchanged: aliases are only consolidated when they join the
   * SAME physical table with a textually identical join condition (after
   * normalising the alias itself), so both aliases resolve to the same row
   * set per base-table row. For rows removed by an INNER primary alias the
   * duplicate's sort value is irrelevant because the row is not in the result.
   * A langcode-restricted join condition differs textually and is therefore
   * never merged. Known limitation: node base fields (including 'created')
   * ARE translatable, so on a multilingual site where translations carry
   * DIVERGING sort values AND a condition filters per-langcode rows on the
   * primary alias, the post-remap ranking aggregates over the surviving rows
   * only and can under-rank a node out of the candidate window. The wrapper's
   * shortfall widening/fallback absorbs underfill; a silently displaced row
   * requires that narrow combination, which does not occur in Mark-a-Spot
   * tenants (untranslated service_request content).
   *
   * Only ORDER BY is rewritten. The duplicate join itself and its SELECT
   * expression stay in place; after the rewrite the duplicate degrades to a
   * cheap eq_ref lookup on the LIMIT-sized result.
   */
  private function remapSortToPrimaryDataTableAlias(): void {
    $data_table = $this->entityType->getDataTable();
    if (!$data_table) {
      return;
    }

    // Group all joins of the data table by their normalised join condition.
    // The first alias registered for a given condition becomes the canonical
    // one; later duplicates are remapped onto it. Join conditions reference
    // the alias in bracketed form ("[node_field_data_2].[nid] =
    // [base_table].[nid]"); normalise both the bracketed and the bare form so
    // a formatting change in Core cannot silently disable the consolidation.
    $tables = $this->sqlQuery->getTables();
    $condition_groups = [];
    foreach ($tables as $alias => $info) {
      $table = $info['table'] ?? NULL;
      if (!is_string($table) || $table !== $data_table) {
        continue;
      }
      $join_condition = (string) ($info['condition'] ?? '');
      $normalized = str_replace('[' . $alias . ']', '%', $join_condition);
      $normalized = preg_replace(
            '/\b' . preg_quote((string) $alias, '/') . '\./',
            '%.',
            $normalized
        );
      $condition_groups[$normalized][] = (string) $alias;
    }

    $alias_map = [];
    foreach ($condition_groups as $aliases) {
      $primary = array_shift($aliases);
      foreach ($aliases as $duplicate) {
        $alias_map[$duplicate] = $primary;
      }
    }
    if (!$alias_map) {
      return;
    }

    $order_by = &$this->sqlQuery->getOrderBy();
    $remapped = [];
    foreach ($order_by as $expression => $direction) {
      $parts = explode('.', (string) $expression, 2);
      if (count($parts) === 2 && isset($alias_map[$parts[0]])) {
        $expression = $alias_map[$parts[0]] . '.' . $parts[1];
      }
      $remapped[$expression] = $direction;
    }
    $order_by = $remapped;
  }

  /**
   * Returns the range captured from the source query.
   *
   * @return array{start: int, length: int}
   *   The start/length range of the original query.
   */
  public function getOriginalRange(): array {
    return $this->originalRange;
  }

  /**
   * Returns the sort array captured from the source query.
   *
   * @return array<int, array{field: string, direction: string, langcode: string|null}>
   *   The sort specifications of the original query.
   */
  public function getOriginalSort(): array {
    return $this->originalSort;
  }

  /**
   * Reads the range property from a Sql\Query instance.
   *
   * PHP allows a subclass to access the protected properties of any instance
   * of its parent class. This static helper is the only way to read $range
   * from outside the SqlQuery hierarchy without reflection.
   *
   * @param \Drupal\Core\Entity\Query\Sql\Query $query
   *   The query whose range to read.
   *
   * @return array
   *   The range array (may be empty if no range was set).
   */
  public static function readRange(SqlQuery $query): array {
    return $query->range;
  }

  /**
   * Reads the sort property from a Sql\Query instance.
   *
   * @param \Drupal\Core\Entity\Query\Sql\Query $query
   *   The query whose sort to read.
   *
   * @return array
   *   The sort array (may be empty if no sort was set).
   */
  public static function readSort(SqlQuery $query): array {
    return $query->sort;
  }

  /**
   * Factory: builds a CandidateEntityQuery from a fully-configured Sql\Query.
   *
   * Copies conditions, sorts, revision flags, and conjunctions from $source.
   * Overrides:
   * - accessCheck = FALSE (group EntityQueryAlter is skipped; Phase 2
   *   enforces access)
   * - range = [0, $window]
   *
   * Note on accessing protected members: PHP allows a subclass to read the
   * protected properties of an instance of the parent class when the access
   * occurs within the subclass. CandidateEntityQuery extends SqlQuery which
   * extends QueryBase -- all protected properties declared in QueryBase and
   * SqlQuery are accessible here.
   *
   * @param \Drupal\Core\Entity\Query\Sql\Query $source
   *   The fully-configured, access-checked query to clone state from.
   * @param int $window
   *   The LIMIT for the candidate query (offset 0 always).
   *
   * @return static
   *   A new CandidateEntityQuery ready to execute.
   *
   * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
   */
  public static function fromQuery(SqlQuery $source, int $window): static {
    // Capture the range and sort before constructing (so we can store them).
    // The readRange/readSort helpers exploit the subclass protected-access
    // rule: they are in the SqlQuery hierarchy and can read $source->range.
    $original_range = self::readRange($source);
    $original_sort = self::readSort($source);

    $instance = new static(
          $source->entityType,
          $source->conjunction,
          $source->connection,
          $source->namespaces,
          $original_range,
          $original_sort,
      );

    // Copy conditions: clone so we do not accidentally mutate the source when
    // we later add the nid-IN condition in Phase 2. The condition object
    // tracks a linked back-reference to the parent query; cloning breaks that
    // reference which is fine because we only use root-level conditions.
    $instance->condition = clone $source->condition;

    // Copy the sort specifications. addSort() consumes $this->sort during
    // compile; without this copy Phase 1 would run UNSORTED and the candidate
    // window would contain arbitrary rows instead of the top-N rows of the
    // requested ordering.
    $instance->sort = $source->sort;

    // Copy revision flags.
    $instance->allRevisions = $source->allRevisions;
    $instance->latestRevision = $source->latestRevision;

    // Copy alter tags and metadata (e.g. bundle key, pager_size).
    $instance->alterTags = $source->alterTags;
    $instance->alterMetaData = $source->alterMetaData;

    // Phase 1 does NOT access-check: the group module's EntityQueryAlter fires
    // on 'node_access' tag (added by prepare() when accessCheck is TRUE). By
    // setting accessCheck = FALSE here we prevent the 2 LEFT JOINs that cause
    // the aggregate sort performance problem. Phase 2 uses the real
    // access-checked query constrained to this candidate nid set.
    $instance->accessCheck = FALSE;

    // Candidate window: start at 0, fetch $window rows.
    $instance->range = ['start' => 0, 'length' => $window];

    return $instance;
  }

}
