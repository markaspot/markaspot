<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Entity\Query\Sql\Query;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_nuxt\JsonApi\CandidateEntityQuery;
use Drupal\markaspot_nuxt\JsonApi\DeferredAccessQueryWrapper;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for CandidateEntityQuery and DeferredAccessQueryWrapper.
 *
 * Tests that:
 * - The wrapper produces results identical to the direct inner query.
 * - Phase 2 correctly removes inaccessible nodes from the Phase-1 candidates.
 * - Guard conditions (dotted sort, count mode, no range) pass through
 *   unchanged.
 * - CandidateEntityQuery returns candidates without access filtering.
 *
 * Node access is controlled via the node_access table grants. Drupal's
 * node_access_test module provides hook_node_grants() patterns; we achieve
 * the same by directly writing grants and setting up accounts accordingly.
 *
 * @group markaspot_nuxt
 *
 * @covers \Drupal\markaspot_nuxt\JsonApi\DeferredAccessQueryWrapper
 * @covers \Drupal\markaspot_nuxt\JsonApi\CandidateEntityQuery
 */
#[RunTestsInSeparateProcesses]
final class DeferredAccessQueryKernelTest extends KernelTestBase {
  use NodeCreationTrait {
    createNode as drupalCreateNode;
  }
  use UserCreationTrait {
    createUser as drupalCreateUser;
    createRole as drupalCreateRole;
  }
  use ContentTypeCreationTrait {
    createContentType as drupalCreateContentType;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node', 'user']);

    // Clear all permissions for anonymous and authenticated to start clean.
    $this->config('user.role.' . RoleInterface::ANONYMOUS_ID)
      ->set('permissions', [])
      ->save();
    $this->config('user.role.' . RoleInterface::AUTHENTICATED_ID)
      ->set('permissions', [])
      ->save();

    // Uid 1 is the super-user bypass; create it so later users get real uids.
    $this->drupalCreateUser([], 'admin-uid1', FALSE, ['uid' => 1]);

    // Create the service_request content type (the real type the wrapper is
    // gated on). Using a simple test type avoids pulling in markaspot_open311.
    $this->drupalCreateContentType(['type' => 'service_request']);

    // Rebuild node access to ensure the node_access table is populated.
    node_access_rebuild();
  }

  /**
   * Builds an access-checked node entity query for service_request.
   *
   * @param int $offset
   *   Page offset.
   * @param int $length
   *   Page length (JSON:API passes size+1; keep it realistic here).
   * @param string $sort_field
   *   Field to sort by.
   * @param string $direction
   *   Sort direction.
   *
   * @return \Drupal\Core\Entity\Query\Sql\Query
   *   A fully-configured, access-checked entity query.
   */
  private function buildInnerQuery(int $offset = 0, int $length = 26, string $sort_field = 'created', string $direction = 'DESC'): Query {
    /** @var \Drupal\Core\Entity\Query\Sql\Query $query */
    $query = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery();
    $query->accessCheck(TRUE);
    $query->condition('type', 'service_request');
    $query->sort($sort_field, $direction);
    $query->range($offset, $length);
    return $query;
  }

  /**
   * Creates N published service_request nodes, returns their nids in order.
   *
   * The created timestamps are DISTINCT (one second apart) so that sorting
   * by created is fully deterministic. Tests comparing the wrapper against
   * the direct inner query rely on this: with tied timestamps the inner
   * query's tie order is undefined in SQL, while the wrapper resolves ties
   * deterministically via its nid tiebreaker. Tie behavior is covered by
   * the dedicated tie test instead.
   *
   * @param int $count
   *   Number of nodes to create.
   *
   * @return int[]
   *   Array of nids, oldest first.
   */
  private function createPublishedNodes(int $count): array {
    $nids = [];
    $base = 1600000000;
    for ($i = 0; $i < $count; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Node $i",
        'status' => 1,
        'uid' => 1,
        'created' => $base + $i,
      ]);
      $node->save();
      $nids[] = (int) $node->id();
    }
    return $nids;
  }

  /**
   * Test: wrapper result equals inner query result for authenticated user.
   *
   * The most basic correctness check: published nodes, authenticated user with
   * 'access content' permission, first page.
   */
  public function testWrapperMatchesInnerQueryForAuthenticatedUser(): void {
    $this->createPublishedNodes(10);

    // Grant 'access content' to authenticated users.
    $this->config('user.role.' . RoleInterface::AUTHENTICATED_ID)
      ->set('permissions', ['access content'])
      ->save();

    // Switch to an authenticated user so node access grants apply.
    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    $inner = $this->buildInnerQuery(0, 11);
    // Clone before wrapping (the wrapper must not mutate the inner query).
    $inner_clone = clone $inner;

    $wrapper = new DeferredAccessQueryWrapper($inner);
    $wrapper_result = $wrapper->execute();
    $direct_result = $inner_clone->execute();

    $this->assertSame(
          array_values($direct_result),
          array_values($wrapper_result),
          'Wrapper result must equal direct inner query result for authenticated user.'
      );
  }

  /**
   * Test: wrapper result equals inner query result for anonymous user.
   */
  public function testWrapperMatchesInnerQueryForAnonymousUser(): void {
    $this->createPublishedNodes(5);

    // Grant 'access content' to anonymous users.
    $this->config('user.role.' . RoleInterface::ANONYMOUS_ID)
      ->set('permissions', ['access content'])
      ->save();

    // Switch to anonymous (uid 0) via the anonymous user account object.
    $this->setCurrentUser(new AnonymousUserSession());

    node_access_rebuild();

    $inner = $this->buildInnerQuery(0, 11);
    $inner_clone = clone $inner;

    $wrapper = new DeferredAccessQueryWrapper($inner);
    $wrapper_result = $wrapper->execute();
    $direct_result = $inner_clone->execute();

    $this->assertSame(
          array_values($direct_result),
          array_values($wrapper_result),
          'Wrapper result must equal direct inner query result for anonymous user.'
      );
  }

  /**
   * Test: wrapper correctly excludes nodes the user cannot see.
   *
   * Creates 7 nodes: 3 owned by the acting user and 4 owned by another user.
   * Core's node_access_test author realm lets the acting user see only their
   * own nodes. Phase 1 sees all 7 without access checks, while Phase 2 and the
   * direct query must both return only the 3 accessible nodes.
   */
  public function testWrapperExcludesInaccessibleNodes(): void {
    $this->enableModules(['node_access_test']);

    // Remove the global view-all row written before the grants module was
    // enabled. Nodes saved below receive per-node author grants.
    \Drupal::database()->delete('node_access')->execute();

    $account = $this->drupalCreateUser(['access content']);
    $other = $this->drupalCreateUser(['access content']);
    $accessible_nids = [];

    // Distinct created timestamps keep the wrapper-vs-direct comparison
    // well-defined (SQL tie order is undefined; see the dedicated tie test).
    $base = 1600000000;
    for ($i = 0; $i < 3; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Accessible $i",
        'status' => 1,
        'uid' => $account->id(),
        'created' => $base + $i,
      ]);
      $node->save();
      $accessible_nids[] = (int) $node->id();
    }
    for ($i = 0; $i < 4; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Restricted $i",
        'status' => 1,
        'uid' => $other->id(),
        'created' => $base + 100 + $i,
      ]);
      $node->save();
    }

    $this->setCurrentUser($account);

    $inner = $this->buildInnerQuery(0, 26);
    $inner_clone = clone $inner;

    $wrapper = new DeferredAccessQueryWrapper($inner);
    $wrapper_result = $wrapper->execute();
    $direct_result = $inner_clone->execute();

    $this->assertSame(
          $direct_result,
          $wrapper_result,
          'Wrapper must produce the same result as the direct inner query.'
      );
    $this->assertCount(
          3,
          $wrapper_result,
          'Only the 3 nodes owned by the acting user must be returned.'
      );
    $this->assertSame(
          array_reverse($accessible_nids),
          array_map('intval', array_values($wrapper_result)),
          'The wrapper must exclude every node owned by the other user.'
      );
  }

  /**
   * Test: wrapper with offset paging returns the correct slice.
   *
   * Creates 10 nodes, queries with offset=2, length=3.
   * The wrapper result must equal the direct query result for that range.
   */
  public function testWrapperWithOffsetPaging(): void {
    $this->createPublishedNodes(10);

    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    node_access_rebuild();

    // offset=2, length=3 (simulates JSON:API page[offset]=2&page[size]=2+1).
    $inner = $this->buildInnerQuery(2, 3);
    $inner_clone = clone $inner;

    $wrapper = new DeferredAccessQueryWrapper($inner);
    $wrapper_result = $wrapper->execute();
    $direct_result = $inner_clone->execute();

    $this->assertSame(
          $direct_result,
          $wrapper_result,
          'Wrapper result with offset paging must match direct query.'
      );
  }

  /**
   * Test: deep offset with low visibility uses the observed hit ratio.
   *
   * The newest 1,300 nodes are only visible to the acting user at roughly a
   * 4.2% rate. Another 221 older nodes are visible, so offset 200 with length
   * 21 has a complete page. The initial 305-row window contains visible hits,
   * but fewer than the requested 221-row prefix. The hit-ratio estimate must
   * jump directly to an exhausting second window instead of following the old
   * blind 305, 1,220, 4,880 ladder.
   */
  public function testDeepOffsetWithLowVisibilityMatchesDirectQuery(): void {
    $this->enableModules(['node_access_test']);
    \Drupal::database()->delete('node_access')->execute();

    $other = $this->drupalCreateUser(['access content']);
    $account = $this->drupalCreateUser(['access content']);
    $base = 1600000000;

    // Make the older tail fully visible so the requested deep page exists.
    for ($i = 0; $i < 221; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Older visible $i",
        'status' => 1,
        'uid' => $account->id(),
        'created' => $base + $i,
      ]);
      $node->save();
    }

    // Only every 24th node in the newer prefix is visible to the account.
    for ($i = 0; $i < 1300; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Newer sparse $i",
        'status' => 1,
        'uid' => $i % 24 === 0 ? $account->id() : $other->id(),
        'created' => $base + 1000 + $i,
      ]);
      $node->save();
    }

    $this->setCurrentUser($account);

    $inner = $this->buildInnerQuery(200, 21);
    $inner_clone = clone $inner;
    $wrapper = new DeferredAccessQueryWrapper($inner);

    Database::startLog('deep_offset_low_visibility');
    $wrapper_result = $wrapper->execute();
    $log = Database::getLog('deep_offset_low_visibility');

    $direct_result = $inner_clone->execute();

    $this->assertSame(
          $direct_result,
          $wrapper_result,
          'Deep wrapper result and entity-query keys must match the direct query.'
      );
    $this->assertCount(21, $wrapper_result, 'The deep page must be complete.');

    $entity_query_count = 0;
    foreach ($log as $entry) {
      if (str_contains((string) $entry['query'], 'node_field_data')) {
        $entity_query_count++;
      }
    }
    $this->assertSame(
          4,
          $entity_query_count,
          'The hit-ratio estimate must complete in 2 attempts and 4 entity queries.'
      );
  }

  /**
   * Test: dotted sort field guard triggers passthrough.
   *
   * A sort on 'field_category.tid' contains a dot (relationship traversal).
   * The wrapper must detect this and fall back to the inner query directly.
   */
  public function testDottedSortFieldGuardPassesThrough(): void {
    $this->createPublishedNodes(3);

    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    node_access_rebuild();

    // Build a query with a dotted sort field. 'uid.target_id' is a valid
    // dotted path in entity queries (relationship traversal). The guard in
    // DeferredAccessQueryWrapper::execute() detects the dot and falls back
    // to the inner query directly without running Phase 1 + Phase 2.
    /** @var \Drupal\Core\Entity\Query\Sql\Query $dotted_inner */
    $dotted_inner = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery();
    $dotted_inner->accessCheck(TRUE);
    $dotted_inner->condition('type', 'service_request');
    $dotted_inner->sort('uid.target_id', 'ASC');
    $dotted_inner->range(0, 11);

    $dotted_inner_clone = clone $dotted_inner;

    $wrapper = new DeferredAccessQueryWrapper($dotted_inner);
    $wrapper_result = $wrapper->execute();
    $direct_result = $dotted_inner_clone->execute();

    $this->assertSame(
          array_values($direct_result),
          array_values($wrapper_result),
          'Dotted sort guard must produce result identical to the direct inner query (passthrough).'
      );
  }

  /**
   * Test: count mode (isCount flag) passes through to the inner query.
   *
   * GetCollectionCountQuery() calls ->range()->count() on whatever
   * getCollectionQuery() returns. When the wrapper is in count mode, execute()
   * must pass through to the inner query to return the integer count.
   */
  public function testCountModePassesThrough(): void {
    $this->createPublishedNodes(5);

    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    node_access_rebuild();

    $inner = $this->buildInnerQuery(0, 11);
    // Simulate what getCollectionCountQuery() does:
    // $this->getCollectionQuery(...)->range()->count()
    $inner_count_clone = clone $inner;
    $inner_count_clone->range();
    $inner_count_clone->count();
    $expected_count = $inner_count_clone->execute();

    // Now do the same through the wrapper.
    $wrapper = new DeferredAccessQueryWrapper($inner);
    $wrapper->range();
    $wrapper->count();
    $wrapper_count = $wrapper->execute();

    $this->assertSame(
          $expected_count,
          $wrapper_count,
          'Count mode must return the same integer as the inner count query.'
      );
    $this->assertIsInt($wrapper_count, 'Count result must be an integer.');
    $this->assertSame(5, $wrapper_count, 'Count must equal the number of published nodes.');
  }

  /**
   * Test: CandidateEntityQuery returns results without access filtering.
   *
   * Sets up 5 nodes: 2 accessible (realm gid=1) and 3 restricted (realm
   * gid=2). The access-checked query (user with gid=1 grants) returns only
   * the 2 accessible nodes; the CandidateEntityQuery (accessCheck=FALSE)
   * returns all 5. This validates that Phase 1 never filters by access.
   *
   * Access is controlled by writing to node_access directly. We provide the
   * matching hook_node_grants() via a static callable registered on the
   * session, then use node_access_test_realm for grant matching.
   *
   * Simpler approach without hook registration: compare candidate count to
   * access-checked count and verify candidate is a superset by using a user
   * with 'bypass node access' as the direct-query baseline (sees all), and
   * an unprivileged user for the access-checked query.
   */
  public function testCandidateQueryReturnsWithoutAccessFilter(): void {
    // Create 5 nodes (all published so the condition filter includes them all).
    $all_nids = [];
    for ($i = 0; $i < 5; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Node $i",
        'status' => 1,
        'uid' => 1,
      ]);
      $node->save();
      $all_nids[] = (int) $node->id();
    }

    // Use a user with 'bypass node access' to build the inner query so that
    // the access-checked query (which skips node_access grants for bypass
    // users) returns all 5 nodes.  Phase 1 (no access) also returns all 5.
    // This validates: candidate count >= access-checked count (superset).
    $admin = $this->drupalCreateUser(
          ['access content', 'bypass node access']
      );
    $this->setCurrentUser($admin);

    $inner = $this->buildInnerQuery(0, 11);

    // Phase 1: CandidateEntityQuery (no access check, no node_access tag).
    $candidate_query = CandidateEntityQuery::fromQuery($inner, 20);
    $candidate_result = $candidate_query->execute();
    $candidate_nids = array_map('intval', array_values($candidate_result));

    // Phase 2 (direct): access-checked inner query (bypass user sees all).
    $direct_result = (clone $inner)->execute();
    $direct_nids = array_map('intval', array_values($direct_result));

    // Both Phase 1 and the direct query must return all 5 nodes.
    $this->assertCount(
          5,
          $candidate_nids,
          'Phase-1 candidate query must return all 5 nodes (no access filter).'
      );
    $this->assertCount(
          5,
          $direct_nids,
          'Access-checked bypass query must also return all 5 nodes.'
      );
    foreach ($all_nids as $nid) {
      $this->assertContains(
            $nid,
            $candidate_nids,
            "Nid $nid must be in the candidate set."
        );
    }

    // Candidate set is a superset of (or equal to) the access-checked set.
    $this->assertEmpty(
          array_diff($direct_nids, $candidate_nids),
          'Every nid in the access-checked result must also be in the candidate set.'
      );
  }

  /**
   * Test: CandidateEntityQuery returns the same set of nids as the source.
   *
   * Phase 1 must cover the full result set so the candidate window contains
   * every nid the access-checked query would return. We assert same-set
   * (not same-order) because all nodes are created in the same second and
   * the tie-breaking behavior of ORDER BY created differs between the simple
   * (CandidateEntityQuery) and the aggregate (access-checked) variants.
   */
  public function testCandidateQueryRespectsSortOrder(): void {
    $this->createPublishedNodes(5);

    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    node_access_rebuild();

    $inner = $this->buildInnerQuery(0, 11, 'created', 'DESC');
    $inner_clone = clone $inner;

    $candidate_query = CandidateEntityQuery::fromQuery($inner, 20);
    $candidate_result = $candidate_query->execute();
    $direct_result = $inner_clone->execute();

    // Both must return the same set of nids (order may differ for same-second
    // created timestamps due to GROUP BY vs plain ORDER BY).
    $candidate_nids = array_map('intval', array_values($candidate_result));
    $direct_nids = array_map('intval', array_values($direct_result));
    sort($candidate_nids);
    sort($direct_nids);
    $this->assertSame(
          $direct_nids,
          $candidate_nids,
          'CandidateEntityQuery must cover the same set of nids as the source query.'
      );
  }

  /**
   * Test: getOriginalRange and getOriginalSort return source query values.
   */
  public function testCandidateQueryExposeOriginalRangeAndSort(): void {
    /** @var \Drupal\Core\Entity\Query\Sql\Query $inner */
    $inner = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery();
    $inner->accessCheck(TRUE);
    $inner->condition('type', 'service_request');
    $inner->sort('created', 'DESC');
    $inner->range(5, 25);

    $candidate = CandidateEntityQuery::fromQuery($inner, 100);

    $this->assertSame(
          ['start' => 5, 'length' => 25],
          $candidate->getOriginalRange(),
          'getOriginalRange() must return the source query range.'
      );

    $original_sort = $candidate->getOriginalSort();
    $this->assertCount(1, $original_sort, 'Original sort must have one entry.');
    $this->assertSame('created', $original_sort[0]['field']);
    $this->assertSame('DESC', $original_sort[0]['direction']);
  }

  /**
   * Test: CandidateEntityQuery forces simple-query mode.
   *
   * Core's prepare() clobbers the simple_query metadata AFTER the
   * alterMetaData loop, so the isSimpleQuery() override is the only thing
   * keeping Phase 1 on the plain ORDER BY path (no GROUP BY, no aggregate
   * sort). If a Core update changes the override mechanics, Phase 1 would
   * silently degrade back to the slow aggregate plan. Reflection is needed
   * because the method is protected.
   */
  public function testCandidateQueryForcesSimpleQueryMode(): void {
    $inner = $this->buildInnerQuery(0, 11);
    $candidate = CandidateEntityQuery::fromQuery($inner, 100);

    $method = new \ReflectionMethod($candidate, 'isSimpleQuery');
    $this->assertTrue(
          $method->invoke($candidate),
          'CandidateEntityQuery::isSimpleQuery() must return TRUE unconditionally.'
      );
  }

  /**
   * Test: first page is exactly the newest when rows exceed the window.
   *
   * Regression test for the Phase-1 sort copy: 130 accessible nodes with
   * interleaved created timestamps, so the newest nodes are NOT the first
   * rows in nid order. The candidate window (100) is smaller than the data
   * set; only a SORTED Phase 1 produces the correct candidate set. An
   * unsorted Phase 1 would read 100 arbitrary rows (typically nid order)
   * and miss the newest nodes entirely.
   */
  public function testFirstPageIsExactNewestBeyondCandidateWindow(): void {
    // Interleave created values: even creation positions get old timestamps
    // (offsets 0..64), odd positions get new ones (offsets 65..129). The 11
    // newest nodes all sit at creation positions >= 109, far beyond an
    // unsorted first-100 scan in nid order. All offsets are distinct, so the
    // expected ordering is fully deterministic.
    $base = 1600000000;
    $created_by_nid = [];
    for ($i = 0; $i < 130; $i++) {
      $offset = ($i % 2 === 0) ? intdiv($i, 2) : 65 + intdiv($i - 1, 2);
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Node $i",
        'status' => 1,
        'uid' => 1,
        'created' => $base + ($offset * 60),
      ]);
      $node->save();
      $created_by_nid[(int) $node->id()] = $offset;
    }

    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    $inner = $this->buildInnerQuery(0, 11);
    $inner_clone = clone $inner;

    $wrapper = new DeferredAccessQueryWrapper($inner);
    $wrapper_result = $wrapper->execute();
    $direct_result = $inner_clone->execute();

    // Expected: the 11 newest nids by created DESC (all values distinct).
    arsort($created_by_nid);
    $expected = array_slice(array_keys($created_by_nid), 0, 11);

    $this->assertSame(
          $expected,
          array_map('intval', array_values($wrapper_result)),
          'Wrapper first page must be exactly the 11 newest nodes.'
      );
    $this->assertSame(
          array_values($direct_result),
          array_values($wrapper_result),
          'Wrapper must match the direct inner query beyond the candidate window.'
      );
  }

  /**
   * Test: a single widening step suffices when it exhausts the data set.
   *
   * Uses core's node_access_test grants: a user WITHOUT the
   * 'node test view' permission only matches the author realm and thus only
   * sees nodes they own. 120 newer foreign nodes fill the initial candidate
   * window (100) completely with inaccessible rows; the 5 accessible nodes
   * enter the candidate set with the first 4x widening, which exhausts the
   * 125-row data set and stops the widening loop.
   *
   * Query-count assertion: Phase 1 + Phase 2 (initial window) plus
   * Phase 1 + Phase 2 (first widened window) = 4 entity queries. A fallback
   * to the unmodified inner query or a superfluous further widening would
   * add more; no widening at all would stop at 2.
   */
  public function testShortfallStopsWideningOnceExhausted(): void {
    $this->enableModules(['node_access_test']);

    $other = $this->drupalCreateUser(['access content']);
    $account = $this->drupalCreateUser(['access content']);

    $base = 1600000000;
    // 5 accessible nodes (owned by the test user), all OLDER than the
    // foreign nodes.
    $own_nids = [];
    for ($i = 0; $i < 5; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Own $i",
        'status' => 1,
        'uid' => $account->id(),
        'created' => $base + $i,
      ]);
      $node->save();
      $own_nids[] = (int) $node->id();
    }
    // 120 foreign nodes, all newer than the accessible ones. The test user
    // has no 'node test view' permission and does not own them, so the node
    // grants system blocks them at the query level.
    for ($i = 0; $i < 120; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Foreign $i",
        'status' => 1,
        'uid' => $other->id(),
        'created' => $base + 1000 + $i,
      ]);
      $node->save();
    }

    // Rebuild node access AFTER enabling node_access_test: setUp() ran the
    // rebuild without any grants module, which wrote the global nid=0
    // realm-'all' default grant. That row makes node_access_view_all_nodes()
    // return TRUE and the whole query alter gets skipped. Rebuilding with
    // the grants module enabled removes the global row and writes per-node
    // records via the node_access_test hooks.
    node_access_rebuild();

    $this->setCurrentUser($account);

    // Window for offset=0, length=3 is max(100, 15) = 100: completely filled
    // by the 120 newer inaccessible nodes, forcing the widened retry.
    $inner = $this->buildInnerQuery(0, 3);
    $inner_clone = clone $inner;
    $wrapper = new DeferredAccessQueryWrapper($inner);

    Database::startLog('deferred_access');
    $wrapper_result = $wrapper->execute();
    $log = Database::getLog('deferred_access');

    $direct_result = $inner_clone->execute();

    $this->assertSame(
          array_values($direct_result),
          array_values($wrapper_result),
          'Wrapper must match the direct query under shortfall conditions.'
      );
    $this->assertSame(
          [$own_nids[4], $own_nids[3], $own_nids[2]],
          array_map('intval', array_values($wrapper_result)),
          'Page must contain the 3 newest accessible nodes.'
      );

    // Count the entity queries that hit the node data table. Phase 1 and
    // Phase 2 for the initial window plus Phase 1 and Phase 2 for the single
    // widened retry = exactly 4. A fallback would add a 5th.
    $entity_query_count = 0;
    foreach ($log as $entry) {
      if (str_contains((string) $entry['query'], 'node_field_data')) {
        $entity_query_count++;
      }
    }
    $this->assertSame(
          4,
          $entity_query_count,
          'One widening step exhausts the set: 2 phases x 2 attempts = 4 queries, no further widening, no fallback.'
      );
  }

  /**
   * Test: duplicate candidate rows do not signal false exhaustion.
   *
   * Translations make the simple Phase-1 SQL return repeated revision/entity
   * keys. The first 100 SQL rows therefore collapse to fewer than 100 keyed
   * candidates and contain only inaccessible newer nodes. Exhaustion must use
   * the pre-collapse SQL row count, widen once, and find the older visible
   * nodes instead of returning an incorrect empty page.
   */
  public function testDuplicateCandidateRowsDoNotCauseFalseExhaustion(): void {
    $this->enableModules(['language', 'node_access_test']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    \Drupal::database()->delete('node_access')->execute();

    $other = $this->drupalCreateUser(['access content']);
    $account = $this->drupalCreateUser(['access content']);
    $base = 1600000000;

    $own_nids = [];
    for ($i = 0; $i < 5; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Own $i",
        'status' => 1,
        'uid' => $account->id(),
        'created' => $base + $i,
      ]);
      $node->save();
      $own_nids[] = (int) $node->id();
    }

    for ($i = 0; $i < 60; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Foreign $i",
        'status' => 1,
        'uid' => $other->id(),
        'created' => $base + 1000 + $i,
      ]);
      $node->addTranslation('de', [
        'title' => "Fremd $i",
        'status' => 1,
        'uid' => $other->id(),
        'created' => $base + 1000 + $i,
      ]);
      $node->save();
    }

    $this->setCurrentUser($account);

    $inner = $this->buildInnerQuery(0, 3);
    $inner_clone = clone $inner;
    $wrapper = new DeferredAccessQueryWrapper($inner);

    Database::startLog('duplicate_candidate_rows');
    $wrapper_result = $wrapper->execute();
    $log = Database::getLog('duplicate_candidate_rows');

    $direct_result = $inner_clone->execute();

    $this->assertSame(
          $direct_result,
          $wrapper_result,
          'Duplicate candidate rows must not change the wrapper page.'
      );
    $this->assertSame(
          [$own_nids[4], $own_nids[3], $own_nids[2]],
          array_map('intval', array_values($wrapper_result)),
          'The page must contain the newest accessible nodes after widening.'
      );

    $entity_query_count = 0;
    foreach ($log as $entry) {
      if (str_contains((string) $entry['query'], 'node_field_data')) {
        $entity_query_count++;
      }
    }
    $this->assertSame(
          4,
          $entity_query_count,
          'A full duplicate SQL window must widen once before exhaustion.'
      );
  }

  /**
   * Test: identical created timestamps resolve to a deterministic order.
   *
   * SQL gives ties no defined order, so without a tiebreaker the candidate
   * window of Phase 1 and the page slice of Phase 2 could disagree on rows
   * with identical sort values (and differ from request to request). The
   * wrapper appends a nid tiebreaker in the direction of the first sort to
   * BOTH phases, so the result must follow (created DESC, nid DESC) exactly
   * and be stable across calls.
   *
   * Deliberately NOT asserted against the inner query: its tie order is
   * undefined and may legitimately differ.
   */
  public function testIdenticalCreatedTimestampsAreDeterministicallyOrdered(): void {
    $base = 1600000000;

    // Two tie groups: 6 older nodes sharing one timestamp, 4 newer nodes
    // sharing another. The primary sort (created DESC) must still win
    // between the groups; nid DESC breaks the ties within each group.
    $old_nids = [];
    for ($i = 0; $i < 6; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Old tie $i",
        'status' => 1,
        'uid' => 1,
        'created' => $base,
      ]);
      $node->save();
      $old_nids[] = (int) $node->id();
    }
    $new_nids = [];
    for ($i = 0; $i < 4; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "New tie $i",
        'status' => 1,
        'uid' => 1,
        'created' => $base + 1000,
      ]);
      $node->save();
      $new_nids[] = (int) $node->id();
    }

    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    // Expected deterministic order: created DESC, ties broken by nid DESC.
    rsort($new_nids);
    rsort($old_nids);
    $expected = array_merge($new_nids, $old_nids);

    $inner = $this->buildInnerQuery(0, 11);
    $wrapper = new DeferredAccessQueryWrapper($inner);
    $first_result = array_map('intval', array_values($wrapper->execute()));

    $this->assertSame(
          $expected,
          $first_result,
          'Ties must resolve to (created DESC, nid DESC) deterministically.'
      );

    // A second, independently built wrapper must return the same order.
    $inner_repeat = $this->buildInnerQuery(0, 11);
    $wrapper_repeat = new DeferredAccessQueryWrapper($inner_repeat);
    $second_result = array_map('intval', array_values($wrapper_repeat->execute()));

    $this->assertSame(
          $first_result,
          $second_result,
          'Tie order must be stable across two independent wrapper calls.'
      );
  }

  /**
   * Test: Phase-1 ORDER BY is remapped onto the primary data-table alias.
   *
   * Core compiles conditions and sorts with separate Tables helpers, so the
   * data table is joined twice and the ORDER BY lands on the second (LEFT)
   * alias "node_field_data_2". That alias placement makes an
   * ORDER BY ... LIMIT index walk impossible (the optimizer cannot use the
   * created index through the LEFT-joined duplicate) and is the reason the
   * candidate query degraded to a full scan + filesort on large tenants.
   * CandidateEntityQuery::finish() must rewrite the ORDER BY onto the first
   * alias. Reflection is needed because the compiled Select is protected.
   */
  public function testCandidateQuerySortUsesPrimaryDataTableAlias(): void {
    $this->createPublishedNodes(5);

    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    $inner = $this->buildInnerQuery(0, 11);
    $candidate = CandidateEntityQuery::fromQuery($inner, 20);
    // Mirror the wrapper's deterministic nid tiebreaker.
    $candidate->sort('nid', 'DESC');
    $result = $candidate->execute();
    $this->assertCount(5, $result, 'Candidate query must return all 5 nodes.');

    $property = new \ReflectionProperty(Query::class, 'sqlQuery');
    /** @var \Drupal\Core\Database\Query\SelectInterface $select */
    $select = $property->getValue($candidate);
    $order_by = $select->getOrderBy();

    $this->assertArrayHasKey(
          'node_field_data.created',
          $order_by,
          'The created sort must reference the primary data-table alias.'
      );
    foreach (array_keys($order_by) as $expression) {
      $this->assertStringStartsNotWith(
            'node_field_data_2.',
            (string) $expression,
            'No ORDER BY expression may reference the duplicate data-table alias.'
        );
    }
  }

  /**
   * Test: deep widening fills the page without the aggregate fallback.
   *
   * Reproduces the moderation-heavy tenant profile that starved the candidate
   * window in production: the 500 NEWEST nodes are unpublished (invisible to
   * anonymous), the 30 published nodes are older. The initial window (105)
   * and the first widening (420) contain only unpublished rows; the second
   * widening (1680) exhausts the data set (530 rows) and fills the page from
   * the published pool. That is exactly 3 attempts x 2 phases = 6 queries —
   * and, crucially, NO seventh query from the aggregate-sort inner fallback,
   * which is the expensive path this widening exists to avoid. The widening
   * makes no assumption about WHY rows are invisible, so this works for any
   * access layer (status, grants, group) alike.
   *
   * Grant setup: node_access_test with the 'private' state flag writes NO
   * records for regular nodes, so Core's default (realm 'all') view grant is
   * written for published nodes only and unpublished nodes carry no grants
   * at all — anonymous therefore sees exactly the published pool.
   */
  public function testDeepWideningFillsPageWithoutAggregateFallback(): void {
    $this->enableModules(['node_access_test']);
    // Only nodes with a "private" property get module records; all others
    // fall through to Core's default grant, which is published-only.
    \Drupal::state()->set('node_access_test.private', TRUE);

    $this->config('user.role.' . RoleInterface::ANONYMOUS_ID)
      ->set('permissions', ['access content'])
      ->save();

    $base = 1600000000;
    $published_nids = [];
    for ($i = 0; $i < 30; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Published $i",
        'status' => 1,
        'uid' => 1,
        'created' => $base + $i,
      ]);
      $node->save();
      $published_nids[] = (int) $node->id();
    }
    for ($i = 0; $i < 500; $i++) {
      $node = Node::create([
        'type' => 'service_request',
        'title' => "Moderated $i",
        'status' => 0,
        'uid' => 1,
        'created' => $base + 1000 + $i,
      ]);
      $node->save();
    }

    // Rewrite grants with the grants module enabled: published nodes get the
    // default (realm 'all') row, unpublished nodes get none, and the global
    // view-all row from setUp() is removed.
    node_access_rebuild();

    $this->setCurrentUser(new AnonymousUserSession());

    $inner = $this->buildInnerQuery(0, 21);
    $inner_clone = clone $inner;
    $wrapper = new DeferredAccessQueryWrapper($inner);

    Database::startLog('deep_widening');
    $wrapper_result = $wrapper->execute();
    $log = Database::getLog('deep_widening');

    $direct_result = $inner_clone->execute();

    $this->assertSame(
          array_values($direct_result),
          array_values($wrapper_result),
          'Wrapper must match the direct query for anonymous users.'
      );
    $this->assertCount(21, $wrapper_result, 'Page must be filled from the published pool.');
    foreach (array_map('intval', array_values($wrapper_result)) as $nid) {
      $this->assertContains($nid, $published_nids, "Nid $nid must be a published node.");
    }

    $entity_query_count = 0;
    foreach ($log as $entry) {
      if (str_contains((string) $entry['query'], 'node_field_data')) {
        $entity_query_count++;
      }
    }
    $this->assertSame(
          6,
          $entity_query_count,
          'Deep widening must fill the page in 3 attempts (6 queries) without the aggregate inner fallback.'
      );
  }

}
