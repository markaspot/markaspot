<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\Query\Sql\Query as SqlQuery;

/**
 * Two-phase entity query wrapper that defers access checks to a narrow IN-list.
 *
 * PROBLEM:
 * On large tenants (155k+ service_request nodes), JSON:API collection queries
 * take 932-1439ms. The cause is:
 * 1. The group module's EntityQueryAlter adds 2 LEFT JOINs + OR-access
 *    conditions to every node query.
 * 2. Drupal Core's Query::prepare() marks node queries as non-simple because
 *    node_field_data is a data table. addSort() then emits GROUP BY + ORDER BY
 *    max(created) instead of ORDER BY created.
 * 3. ORDER BY <aggregate> LIMIT n forces MySQL to materialise and sort ALL
 *    matching rows before it can return the first page, destroying the index
 *    stop that would otherwise satisfy the LIMIT.
 *
 * FIX (Two-Phase Pattern):
 * Phase 1 — run a simplified query without access tags (CandidateEntityQuery
 *   with isSimpleQuery()=TRUE) to retrieve a candidate set of nids using a
 *   plain ORDER BY created DESC LIMIT window. Cost: ~0ms (index walk).
 * Phase 2 — run the original access-checked query with an additional
 *   `nid IN (candidates)` condition. The IN-list is tiny; MySQL evaluates the
 *   full access JOINs on at most `window` rows. Cost: ~2ms.
 *
 * CACHEABILITY NOTE:
 * The wrapper is invoked inside EntityResource::executeQueryInRenderContext().
 * Phase 2 carries the same cache tags as the original query (it is a clone
 * with an additional condition). The 'node_access' query tag fires on Phase
 * 2, so the group module's cache context contributions (e.g. 'user') are
 * attached to the response by the access-checker hooks exactly as with the
 * unmodified query. No special cacheability handling is required here.
 *
 * ACCESS CORRECTNESS NOTE (vs uid>0 guard on CountCacheQueryWrapper):
 * Unlike the count cache there is no user-scope guard here. Phase 1 produces
 * candidates WITHOUT access — it may include nodes the current user cannot
 * read. Phase 2 applies the FULL access-checked query to that narrow set,
 * so inaccessible nodes are never returned to callers. The fix is safe for all
 * roles. There is also no cache-flooding vector (nothing is cached by this
 * wrapper).
 *
 * @internal This class is part of the markaspot_nuxt module internals.
 */
final class DeferredAccessQueryWrapper implements QueryInterface {
  /**
   * Maximum offset + length for which the two-phase path is attempted.
   *
   * Beyond 2048 rows, IN-list overhead could exceed the savings from the simple
   * Phase 1 query. For large offsets the original query is used unchanged.
   * Not to be confused with MAX_CANDIDATE_WINDOW: this constant bounds the
   * REQUESTED page depth (offset + length) at entry; the candidate window that
   * the widening loop may grow to is bounded separately below.
   *
   * @var int
   */
  private const MAX_TWO_PHASE_RANGE = 2048;

  /**
   * Multiplier applied to the candidate window on each widening step.
   *
   * @var int
   */
  private const WINDOW_WIDEN_FACTOR = 4;

  /**
   * Upper bound for the Phase-1 candidate window.
   *
   * With the ORDER BY alias remap in CandidateEntityQuery, Phase 1 is an
   * index walk whose cost is proportional to the window, so even this widest
   * window costs single-digit milliseconds plus one IN-list Phase 2 — orders
   * of magnitude cheaper than one run of the aggregate-sort inner query.
   * Once the window exceeds this cap without filling the page, the wrapper
   * gives up and runs the unmodified inner query.
   *
   * @var int
   */
  private const MAX_CANDIDATE_WINDOW = 8192;

  /**
   * Whether execute() was called after count() was chained on this wrapper.
   *
   * When TRUE, execute() passes straight through to the inner query so the
   * count query (used by getCollectionCountQuery) works correctly.
   *
   * @var bool
   */
  private bool $isCount = FALSE;

  /**
   * Constructs a DeferredAccessQueryWrapper.
   *
   * @param \Drupal\Core\Entity\Query\Sql\Query $inner
   *   The fully-configured, access-checked entity query from EntityResource.
   *   Must NOT be mutated by this wrapper; Phase 2 and fallback both need it
   *   in its original state.
   */
  public function __construct(
    private readonly SqlQuery $inner,
  ) {
  }

  /**
   * {@inheritdoc}
   *
   * Orchestrates the two-phase query. Falls back to the inner query when
   * guards are not satisfied.
   *
   * Guards (any violation -> passthrough to $inner->execute()):
   * - Range is set on the inner query and
   *   offset + length <= MAX_TWO_PHASE_RANGE
   * - Sort is present on the inner query
   * - No sort field contains '.' (dotted fields traverse relationships and
   *   cause join multiplications that would make CandidateEntityQuery
   *   produce wrong orderings)
   * - count() was not called on this wrapper (count-mode -> always passthrough)
   */
  public function execute() {
    if ($this->isCount) {
      return $this->inner->execute();
    }

    // CandidateEntityQuery is a subclass of SqlQuery and can read its
    // protected properties via the static helper methods.
    $inner_range = CandidateEntityQuery::readRange($this->inner);
    $inner_sort = CandidateEntityQuery::readSort($this->inner);

    // Guard: range must be set and within the two-phase window.
    if (empty($inner_range)) {
      return $this->inner->execute();
    }
    $offset = (int) ($inner_range['start'] ?? 0);
    $length = (int) ($inner_range['length'] ?? 0);
    if ($length === 0 || ($offset + $length) > self::MAX_TWO_PHASE_RANGE) {
      return $this->inner->execute();
    }

    // Guard: at least one sort must be present.
    if (empty($inner_sort)) {
      return $this->inner->execute();
    }

    // Guard: no dotted sort fields (relationship traversal via Tables::addField
    // can cause row multiplication on un-deduplicated joins in simple mode).
    $has_nid_sort = FALSE;
    foreach ($inner_sort as $sort_entry) {
      $sort_field = (string) ($sort_entry['field'] ?? '');
      if (str_contains($sort_field, '.')) {
        return $this->inner->execute();
      }
      if ($sort_field === 'nid') {
        $has_nid_sort = TRUE;
      }
    }

    // Deterministic tiebreaker: rows with identical sort values have no
    // defined order in SQL, so the candidate window of Phase 1 and the page
    // slice of Phase 2 could disagree on ties (and differ from request to
    // request). Append a nid sort to BOTH phases, using the direction of the
    // first sort of the inner query. Skipped when the inner query already
    // sorts on nid. The Phase-1 index walk survives this: with
    // "created DESC, nid DESC" InnoDB still stops on the created index
    // because the PK is implicitly part of every secondary index
    // (~1ms on a 155k-node database).
    $tiebreak_direction = $has_nid_sort
        ? NULL
        : (string) ($inner_sort[0]['direction'] ?? 'ASC');

    return $this->executeTwoPhase($offset, $length, $tiebreak_direction);
  }

  /**
   * Runs the two-phase logic and returns the Phase 2 result.
   *
   * Starts with the initial candidate window and, while the page cannot be
   * filled and Phase 1 is not exhausted, widens the window by
   * WINDOW_WIDEN_FACTOR up to MAX_CANDIDATE_WINDOW. On moderation-heavy
   * tenants where only a small fraction of the newest rows is visible to the
   * requesting account (measured: 7 of the newest 105 nodes published on a
   * 152k-node tenant), the page typically fills after two or three widenings
   * at a few milliseconds each. This deliberately encodes NO assumption
   * about WHY rows are invisible — it behaves identically for status,
   * group-based access, or any future access layer, so it cannot silently
   * omit rows the way an access-specific candidate pre-filter could.
   * Only when the capped widening still cannot fill the page is the original
   * inner query executed unchanged (correctness over speed).
   *
   * @param int $offset
   *   The page offset from the inner query's range.
   * @param int $length
   *   The page length from the inner query's range (JSON:API passes size+1).
   * @param string|null $tiebreak_direction
   *   Direction for the deterministic nid tiebreaker appended to both
   *   phases, or NULL when the inner query already sorts on nid.
   *
   * @return array<int|string, int|string>
   *   Entity query result in [revision_id => entity_id] format.
   */
  private function executeTwoPhase(int $offset, int $length, ?string $tiebreak_direction): array {
    $initial_window = $offset + max(100, 5 * $length);

    // The first attempt always runs, even if the initial window already
    // exceeds the cap; the cap only limits how far widening may go. Widening
    // clamps to the cap so the ladder always includes one attempt AT the cap
    // before giving up (a straight multiplication could jump past it and
    // skip a cheap final attempt for deep pages).
    $window = $initial_window;
    while (TRUE) {
      // Phase 1: fast candidate query without access checks.
      $candidate_query = CandidateEntityQuery::fromQuery($this->inner, $window);
      if ($tiebreak_direction !== NULL) {
        $candidate_query->sort('nid', $tiebreak_direction);
      }
      $raw_result = $candidate_query->execute();

      // No candidates at all: the access-checked result is empty too, because
      // Phase 1 operates on a superset of the rows visible to the user.
      if (empty($raw_result)) {
        return [];
      }

      // Exhausted = Phase 1 returned fewer rows than the window (there is
      // nothing beyond this set). Use the RAW result count before dedupe for
      // this check: if the DB returned < $window rows, there are no more
      // rows to fetch.
      $exhausted = count($raw_result) < $window;

      // Deduplicate while preserving insertion order. Duplicates arise from
      // multi-value fields or translated data-table rows when the simple
      // query joins against node_field_data. The window has slack for this.
      $candidates = array_values(array_unique($raw_result));

      // Phase 2: the original access-checked query, narrowed to candidates.
      // The same nid tiebreaker is appended so ties resolve identically in
      // both phases (a divergent tie order could page candidates out).
      /** @var \Drupal\Core\Entity\Query\Sql\Query $phase2 */
      $phase2 = clone $this->inner;
      if ($tiebreak_direction !== NULL) {
        $phase2->sort('nid', $tiebreak_direction);
      }
      $phase2->condition('nid', $candidates, 'IN');
      $phase2_result = $phase2->execute();

      // Done when the page is full, or when Phase 1 already saw every
      // matching row (nothing beyond the window could add results). A
      // shortfall while more rows exist beyond the window widens and
      // retries.
      if ($exhausted || count($phase2_result) >= $length) {
        return $phase2_result;
      }

      if ($window >= self::MAX_CANDIDATE_WINDOW) {
        break;
      }
      $window = min($window * self::WINDOW_WIDEN_FACTOR, self::MAX_CANDIDATE_WINDOW);
    }

    // The capped widening could not fill the page: fall back to the
    // unmodified inner query. Correctness over speed.
    return $this->inner->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return $this->inner->getEntityTypeId();
  }

  /**
   * {@inheritdoc}
   */
  public function condition($field, $value = NULL, $operator = NULL, $langcode = NULL) {
    $result = $this->inner->condition($field, $value, $operator, $langcode);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function exists($field, $langcode = NULL) {
    $result = $this->inner->exists($field, $langcode);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function notExists($field, $langcode = NULL) {
    $result = $this->inner->notExists($field, $langcode);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function pager($limit = 10, $element = NULL) {
    $result = $this->inner->pager($limit, $element);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function range($start = NULL, $length = NULL) {
    $result = $this->inner->range($start, $length);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function sort($field, $direction = 'ASC', $langcode = NULL) {
    $result = $this->inner->sort($field, $direction, $langcode);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   *
   * Sets the count-mode flag so execute() passes through to the inner query.
   * getCollectionCountQuery() calls ->range()->count() on whatever
   * getCollectionQuery() returns. When this wrapper is returned from
   * getCollectionQuery(), the count path must not run the two-phase logic.
   */
  public function count() {
    $this->isCount = TRUE;
    $result = $this->inner->count();
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tableSort(&$headers) {
    $result = $this->inner->tableSort($headers);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function accessCheck($access_check = TRUE) {
    $result = $this->inner->accessCheck($access_check);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function andConditionGroup() {
    return $this->inner->andConditionGroup();
  }

  /**
   * {@inheritdoc}
   */
  public function orConditionGroup() {
    return $this->inner->orConditionGroup();
  }

  /**
   * {@inheritdoc}
   */
  public function currentRevision() {
    $result = $this->inner->currentRevision();
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function latestRevision() {
    $result = $this->inner->latestRevision();
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function allRevisions() {
    $result = $this->inner->allRevisions();
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function addTag($tag) {
    $result = $this->inner->addTag($tag);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function hasTag($tag) {
    return $this->inner->hasTag($tag);
  }

  /**
   * {@inheritdoc}
   */
  public function hasAllTags(/* string ...$tags*/) {
    return $this->inner->hasAllTags(...func_get_args());
  }

  /**
   * {@inheritdoc}
   */
  public function hasAnyTag(/* string ...$tags*/) {
    return $this->inner->hasAnyTag(...func_get_args());
  }

  /**
   * {@inheritdoc}
   */
  public function addMetaData($key, $object) {
    $result = $this->inner->addMetaData($key, $object);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetaData($key) {
    return $this->inner->getMetaData($key);
  }

}
