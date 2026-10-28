<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\Query\Sql\Query as SqlQuery;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_nuxt\JsonApi\CandidateEntityQuery;
use Drupal\markaspot_nuxt\JsonApi\DeferredAccessQueryWrapper;
use Drupal\markaspot_nuxt\JsonApi\LocalizedSortQuery;
use Drupal\markaspot_request_index\RequestIndexReader;
use Drupal\markaspot_request_index\RequestIndexRebuilder;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\markaspot_request_index\Kernel\RequestIndexKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Compares index-backed dashboard candidates with Core's queries.
 *
 * Every case runs the access-checked query three ways: directly, through
 * DeferredAccessQueryWrapper with the request index reader and through the
 * wrapper without it (CandidateEntityQuery). All three must return the same
 * ordered node IDs, and the query log proves which Phase 1 ran and which
 * conditions it applied. All names, codes and addresses are fictional.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
final class IndexCandidateQueryKernelTest extends RequestIndexKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation', 'markaspot_request_id'];

  /**
   * Page length as JSON:API passes it: page size 10 plus one.
   */
  private const LENGTH = 11;

  /**
   * Account that sees only published requests of the child jurisdiction.
   */
  private UserInterface $districtStaff;

  /**
   * Account that bypasses node and group access: sees every request.
   */
  private UserInterface $editor;

  /**
   * A root jurisdiction that only multi-valued requests reference.
   */
  private Group $neighbourJurisdiction;

  /**
   * Category terms keyed by a short name.
   *
   * @var array<string, \Drupal\taxonomy\Entity\Term>
   */
  private array $categories = [];

  /**
   * Status terms keyed by a short name.
   *
   * @var array<string, \Drupal\taxonomy\Entity\Term>
   */
  private array $statuses = [];

  /**
   * Node IDs of requests with three jurisdictions; the neighbour at delta 2.
   *
   * @var list<int>
   */
  private array $multiJurisdictionIds = [];

  /**
   * Node IDs of requests with organisations B and A, in that order.
   *
   * @var list<int>
   */
  private array $multiOrganisationIds = [];

  /**
   * Messages logged by the request index channel.
   *
   * @var \Psr\Log\AbstractLogger
   */
  private AbstractLogger $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    $translation = $this->container->get('content_translation.manager');
    $translation->setEnabled('taxonomy_term', 'service_category', TRUE);
    $translation->setEnabled('taxonomy_term', 'service_status', TRUE);

    $this->neighbourJurisdiction = Group::create(['type' => 'jur', 'label' => 'Fictional neighbour']);
    $this->neighbourJurisdiction->save();

    // The base category has no German translation; one label order flips in
    // German. Two codes do not survive the projector's trim and truncation.
    $this->categories['bench'] = $this->category;
    foreach ([
      'zebra' => ['Zebra crossing', 'Apfelbaum', 'ZEB-2'],
      'apple' => ['Apple tree', 'Zaun', 'APL-3'],
      'padded' => ['Padded code', NULL, ' PAD-9'],
      'long' => ['Long code', NULL, str_repeat('L', 70)],
    ] as $key => [$label, $german, $code]) {
      $term = Term::create([
        'vid' => 'service_category',
        'name' => $label,
        'langcode' => 'en',
        'field_service_code' => $code,
      ]);
      if ($german !== NULL) {
        $term->addTranslation('de', ['name' => $german]);
      }
      $term->save();
      $this->categories[$key] = $term;
    }
    $this->statuses['open'] = $this->statusOpen;
    // Shipped config keeps field_open311_mapping untranslatable; this fixture
    // makes its storage translatable to prove that the index then leaves the
    // condition to Phase 2. field_service_code stays as shipped, so its
    // conditions are applied by the index.
    $mapping_storage = FieldStorageConfig::loadByName('taxonomy_term', 'field_open311_mapping');
    $this->assertInstanceOf(FieldStorageConfig::class, $mapping_storage);
    $mapping_storage->setTranslatable(TRUE)->save();
    $this->statuses['closed'] = Term::create([
      'vid' => 'service_status',
      'name' => 'Done',
      'langcode' => 'en',
      'field_open311_mapping' => 'closed',
    ]);
    $this->statuses['closed']->save();
    // Open in the default translation, closed in German: Core matches either.
    $this->statuses['diverging'] = Term::create([
      'vid' => 'service_status',
      'name' => 'Waiting',
      'langcode' => 'en',
      'field_open311_mapping' => 'open',
    ]);
    $this->statuses['diverging']->addTranslation('de', ['name' => 'Wartend', 'field_open311_mapping' => 'closed']);
    $this->statuses['diverging']->save();

    $this->createRequests();
    $this->createAccounts();

    $this->logger = new class() extends AbstractLogger {

      /**
       * Logged messages with their placeholders replaced.
       *
       * @var list<string>
       */
      public array $messages = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->messages[] = strtr((string) $message, array_map('strval', array_filter($context, 'is_scalar')));
      }

    };
    $this->container->get('logger.factory')->addLogger($this->logger);

    // Fixture saves may queue re-projections; drain them, then forget any
    // parity verdict cached while the fixture was built.
    $this->container->get('markaspot_request_index.rebuilder')->drain();
    $this->reader()->resetParity();
    $this->assertTrue($this->reader()->isUsable());
  }

  /**
   * Index, Core Phase 1 and the direct query agree on every page.
   */
  public function testIndexCandidatesMatchCoreQueries(): void {
    $this->assertFixture();
    $neighbour = (int) $this->neighbourJurisdiction->id();
    $child = (int) $this->childJurisdiction->id();
    $cases = [
      'no filter' => static fn(SqlQuery $q) => NULL,
      'jurisdiction at delta 2' => static fn(SqlQuery $q) => $q->condition('field_jurisdiction', [$neighbour], 'IN'),
      'two jurisdiction conditions' => static fn(SqlQuery $q) => $q
        ->condition('field_jurisdiction', [$child, $neighbour], 'IN')
        ->condition('field_jurisdiction.target_id', $neighbour),
      'organisation at delta 1' => fn(SqlQuery $q) => $q->condition('field_organisation', [(int) $this->organisationA->id()], 'IN'),
      'service code' => static fn(SqlQuery $q) => $q->condition(
        'field_category.entity:taxonomy_term.field_service_code',
        ['ZEB-2', 'BENCH-1'],
        'IN',
      ),
      'service code with whitespace' => static fn(SqlQuery $q) => $q->condition('field_category.entity:taxonomy_term.field_service_code', ' PAD-9'),
      'service code longer than the column' => static fn(SqlQuery $q) => $q->condition('field_category.entity:taxonomy_term.field_service_code', [str_repeat('L', 70)], 'IN'),
      'open311 mapping of any translation' => static fn(SqlQuery $q) => $q->condition('field_status.entity:taxonomy_term.field_open311_mapping', 'closed'),
      'created range' => static fn(SqlQuery $q) => $q->condition('created', 1700000000 + 600, '>=')->condition('created', 1700000000 + 3600, '<'),
      'status tid IN' => fn(SqlQuery $q) => $q->condition('field_status', [
        (int) $this->statuses['closed']->id(),
        (int) $this->statuses['diverging']->id(),
      ], 'IN'),
      'OR group' => function (SqlQuery $q): void {
        $q->condition($q->orConditionGroup()
          ->condition('field_category', (int) $this->categories['zebra']->id())
          ->condition('field_organisation', (int) $this->organisationB->id()));
      },
      'NOT IN' => fn(SqlQuery $q) => $q->condition('field_category', [(int) $this->categories['apple']->id()], 'NOT IN'),
    ];
    $sorts = [
      '-created' => [['created', 'DESC', NULL]],
      'changed' => [['changed', 'ASC', NULL]],
      'nid' => [['nid', 'DESC', NULL]],
      'request_order' => [['request_id', 'ASC', NULL, 'record']],
      'category_order de' => [['field_category.entity:taxonomy_term.name', 'ASC', 'de', 'localized']],
      'category_order en' => [['field_category.entity:taxonomy_term.name', 'DESC', 'en', 'localized']],
      '-status_order de, -created' => [
        ['field_status.entity:taxonomy_term.name', 'DESC', 'de', 'localized'],
        ['created', 'DESC', NULL],
      ],
    ];
    // Whether the index query carries the case's condition: applied where the
    // index matches like Core, left to Phase 2 where it cannot.
    $applied = [
      'service code' => ['/"i"\."service_code" IN/', TRUE],
      'service code with whitespace' => ['/"i"\."service_code"/', FALSE],
      'service code longer than the column' => ['/"i"\."service_code"/', FALSE],
      'open311 mapping of any translation' => ['/"i"\."open311_mapping"/', FALSE],
      'status tid IN' => ['/"i"\."status_tid" IN/', TRUE],
      'jurisdiction at delta 2' => ['/markaspot_request_index_jur" "?c"? WHERE/', TRUE],
    ];
    $non_empty = [];
    foreach ([$this->districtStaff, $this->editor] as $account) {
      $this->container->get('current_user')->setAccount($account);
      foreach ($cases as $case => $condition) {
        foreach ($sorts as $sort_name => $sort) {
          foreach ([0, 1, 2] as $page) {
            $label = sprintf('%s / %s / page %d / uid %d', $case, $sort_name, $page + 1, $account->id());
            $build = fn(): SqlQuery => $this->buildQuery($condition, $sort, $page * (self::LENGTH - 1));
            $expected = array_values($build()->execute());
            [$indexed, $index_reads, $index_sql] = $this->runWrapper($build(), TRUE);
            [$core, $core_reads] = $this->runWrapper($build(), FALSE);
            $this->assertSame($expected, $indexed, "Index path: $label");
            $this->assertSame($expected, $core, "Core path: $label");
            $this->assertGreaterThan(0, $index_reads, "The index answered Phase 1: $label");
            $this->assertSame(0, $core_reads, "No index without the reader: $label");
            if (isset($applied[$case])) {
              [$pattern, $expected_applied] = $applied[$case];
              $hits = count(preg_grep($pattern, $index_sql) ?: []);
              $this->assertSame($expected_applied ? count($index_sql) : 0, $hits, "Index condition applied: $label");
            }
            if ($expected !== []) {
              $non_empty[(int) $account->id()][$case] = TRUE;
            }
          }
        }
      }
    }
    // No comparison was vacuous: the editor gets rows for every case, the
    // district account for most.
    $this->assertSame(array_keys($cases), array_keys($non_empty[(int) $this->editor->id()] ?? []));
    $this->assertGreaterThanOrEqual(9, count($non_empty[(int) $this->districtStaff->id()] ?? []));
    $this->assertSame([], $this->logger->messages);

    // A tenant makes the field_service_code instance translatable and gives a
    // German category translation its own code. Core matches that code, the
    // index row keeps the default translation's code: the index must leave
    // the condition to Phase 2.
    $field = FieldConfig::loadByName('taxonomy_term', 'service_category', 'field_service_code');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setTranslatable(TRUE)->save();
    $term_storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $term_storage->resetCache();
    $zebra = $term_storage->load($this->categories['zebra']->id());
    $this->assertInstanceOf(Term::class, $zebra);
    $zebra->getTranslation('de')->set('field_service_code', 'ZEB-DE');
    $zebra->save();
    $this->container->get('markaspot_request_index.rebuilder')->drain();
    $this->reader()->resetParity();
    $this->assertSame('ZEB-2', $this->database()->select('markaspot_request_index', 'i')->fields('i', ['service_code'])
      ->condition('category_tid', $zebra->id())->range(0, 1)->execute()->fetchField());
    $this->container->get('current_user')->setAccount($this->editor);
    $build = fn(): SqlQuery => $this->buildQuery(
      static fn(SqlQuery $q) => $q->condition('field_category.entity:taxonomy_term.field_service_code', 'ZEB-DE'),
      [['created', 'DESC', NULL]],
      0,
    );
    $expected = array_values($build()->execute());
    $this->assertNotEmpty($expected, 'Core matches the code of the German translation.');
    [$indexed, $reads, $index_sql] = $this->runWrapper($build(), TRUE);
    $this->assertSame($expected, $indexed);
    $this->assertGreaterThan(0, $reads);
    $this->assertSame([], preg_grep('/"i"\."service_code"/', $index_sql), 'The translatable term field is left to Phase 2.');
  }

  /**
   * The narrow account forces widening; the widened window exhausts the set.
   */
  public function testNarrowScopeWidensAndExhausts(): void {
    $this->container->get('current_user')->setAccount($this->districtStaff);
    $visible = array_values($this->buildQuery(static fn() => NULL, [['created', 'DESC', NULL]], 0, 1000)->execute());
    $all = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'service_request')->count()->execute();
    $this->assertGreaterThan(100, $all, 'Phase 1 fills its first window.');
    $this->assertLessThan(intdiv($all, 4), count($visible), 'Phase 2 rejects most candidates.');
    $this->assertGreaterThan(2 * self::LENGTH, count($visible));

    // Page 3 needs 31 visible rows; the first window of 120 candidates holds
    // fewer, so the wrapper widens until Phase 1 runs out of candidates.
    $build = fn(): SqlQuery => $this->buildQuery(static fn() => NULL, [['created', 'DESC', NULL]], 2 * (self::LENGTH - 1));
    Database::startLog('narrow_scope');
    $indexed = array_values((new DeferredAccessQueryWrapper($build(), $this->container->get('entity_field.manager'), $this->reader()))->execute());
    $windows = [];
    foreach (Database::getLog('narrow_scope') as $entry) {
      if ($this->isIndexCandidateRead($entry['query'])) {
        preg_match('/LIMIT (\d+)/', $entry['query'], $limit);
        $windows[] = (int) ($limit[1] ?? 0);
      }
    }
    $this->assertSame(array_values($build()->execute()), $indexed);
    $this->assertSame(array_slice($visible, 20, self::LENGTH), $indexed);
    $this->assertGreaterThanOrEqual(2, count($windows), 'The wrapper widened the index window.');
    $this->assertGreaterThan($all, end($windows), 'The last window exceeded the candidate set: exhausted.');
  }

  /**
   * The index declines what it cannot reproduce or while it is not usable.
   */
  public function testDeclineCases(): void {
    $this->container->get('current_user')->setAccount($this->editor);
    $plain = fn(): SqlQuery => $this->buildQuery(static fn() => NULL, [['created', 'DESC', NULL]], 0);
    $declines = [
      'langcode on created' => fn(): SqlQuery => $this->buildQuery(static fn() => NULL, [['created', 'DESC', 'en']], 0),
      'langcode on nid' => fn(): SqlQuery => $this->buildQuery(static fn() => NULL, [['nid', 'DESC', 'de']], 0),
      // Core's own langcode sort, not registered by LocalizedSortQuery.
      'unregistered term-label sort' => fn(): SqlQuery => $this->buildQuery(static fn() => NULL, [
        ['field_category.entity:taxonomy_term.name', 'ASC', 'de'],
      ], 0),
    ];
    foreach ($declines as $label => $build) {
      [$indexed, $reads] = $this->runWrapper($build(), TRUE);
      [$core] = $this->runWrapper($build(), FALSE);
      $this->assertSame(0, $reads, $label);
      $this->assertSame($core, $indexed, $label);
    }
    // A registered entry with another direction does not count either.
    $query = $this->buildQuery(static fn() => NULL, [
      ['field_category.entity:taxonomy_term.name', 'ASC', 'de', 'localized'],
    ], 0);
    $sort = CandidateEntityQuery::readSort($query);
    $query->addMetaData('dashboard_localized_sorts', [['direction' => 'DESC'] + $sort[0]]);
    $this->assertSame(0, $this->runWrapper($query, TRUE)[1], 'Direction differs from the registered sort.');

    $expected = array_values($plain()->execute());
    $this->assertCount(self::LENGTH, $expected);
    $assert_declined = function (string $label) use ($plain, $expected): void {
      [$indexed, $reads] = $this->runWrapper($plain(), TRUE);
      $this->assertSame(0, $reads, $label);
      $this->assertSame($expected, $indexed, $label);
    };
    $this->assertGreaterThan(0, $this->runWrapper($plain(), TRUE)[1], 'Usable index before the decline cases.');

    // A queued item, through the queue API.
    $queue = $this->container->get('queue')->get(RequestIndexRebuilder::QUEUE, TRUE);
    $queue->createItem(['op' => 'nids', 'nids' => []]);
    $assert_declined('queued item');
    $queue->deleteQueue();

    // A request marked stale, although rows and counts match.
    $stale_set = $this->container->get('markaspot_request_index.stale_set');
    $stale_set->add([$expected[0]]);
    $assert_declined('stale set non-empty');
    $stale_set->clear();
    $this->assertGreaterThan(0, $this->runWrapper($plain(), TRUE)[1], 'Usable again.');

    // A missing row, with the cached parity verdict reset.
    $this->database()->delete('markaspot_request_index')->condition('nid', $expected[0])->execute();
    $this->reader()->resetParity();
    $assert_declined('row parity');
    $this->container->get('markaspot_request_index.writer')->reproject([$expected[0]]);
    $this->reader()->resetParity();

    // A translated service request: Core orders by the newest created value
    // over all translation rows, the index holds the default translation's.
    // The parity count of node data rows keeps the index out.
    $this->container->get('content_translation.manager')->setEnabled('node', 'service_request', TRUE);
    $oldest = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('langcode', 'en')
      ->sort('nid')
      ->range(0, 1)
      ->execute();
    $translated_nid = (int) reset($oldest);
    $translated = Node::load($translated_nid);
    $this->assertInstanceOf(Node::class, $translated);
    $translated->addTranslation('de', [
      'title' => 'Fiktiver Bericht',
      'request_id' => $translated->get('request_id')->value,
      'created' => 1800000000,
    ])->save();
    $this->reader()->resetParity();
    $this->assertSame($translated_nid, (int) array_values($plain()->execute())[0], 'The translation moved the request to the top.');
    [$indexed, $reads] = $this->runWrapper($plain(), TRUE);
    $this->assertSame(array_values($plain()->execute()), $indexed, 'translated request');
    $this->assertSame(0, $reads, 'translated request');
    $translated = Node::load($translated_nid);
    $this->assertInstanceOf(Node::class, $translated);
    $translated->removeTranslation('de');
    $translated->save();
    $this->reader()->resetParity();
    $this->assertGreaterThan(0, $this->runWrapper($plain(), TRUE)[1], 'Usable again without the translation.');

    // A translation data row without its node row, as an old partial delete
    // leaves it: the entity query never reaches it, so the index stays usable.
    $orphan = $this->database()->select('node_field_data', 'd')->fields('d')
      ->condition('nid', $translated_nid)->execute()->fetchAssoc();
    $this->assertIsArray($orphan);
    $orphan['nid'] = $orphan['vid'] = 990000;
    $orphan['langcode'] = 'de';
    $orphan['default_langcode'] = 0;
    $this->database()->insert('node_field_data')->fields($orphan)->execute();
    $this->reader()->resetParity();
    $this->assertGreaterThan(0, $this->runWrapper($plain(), TRUE)[1], 'An orphaned data row does not disable the index.');
    $this->database()->delete('node_field_data')->condition('nid', 990000)->execute();

    // A database error while reading the candidates is logged once, without
    // values, and Phase 1 falls back to Core for every widening round.
    $this->database()->schema()->dropTable('markaspot_request_index_jur');
    $this->container->get('current_user')->setAccount($this->districtStaff);
    $jurisdictions = [(int) $this->neighbourJurisdiction->id(), (int) $this->childJurisdiction->id()];
    $build = fn(): SqlQuery => $this->buildQuery(
      static fn(SqlQuery $q) => $q->condition('field_jurisdiction', $jurisdictions, 'IN'),
      [['created', 'DESC', NULL]],
      2 * (self::LENGTH - 1),
    );
    $expected_narrow = array_values($build()->execute());
    $this->assertNotEmpty($expected_narrow);
    [$indexed] = $this->runWrapper($build(), TRUE);
    $this->assertSame($expected_narrow, $indexed);
    $this->assertCount(1, $this->logger->messages);
    $this->assertStringContainsString('Reading the request index failed; the dashboard list fell back to the entity query', $this->logger->messages[0]);
    $this->assertStringContainsString('DatabaseExceptionWrapper', $this->logger->messages[0]);
    foreach (['markaspot_request_index_jur', 'SELECT', 'EXISTS'] as $value) {
      $this->assertStringNotContainsString($value, $this->logger->messages[0]);
    }
    // The failed read is cached as unusable: the next request neither reads
    // the index nor logs again.
    [$indexed, $reads] = $this->runWrapper($build(), TRUE);
    $this->assertSame($expected_narrow, $indexed);
    $this->assertSame(0, $reads);
    $this->assertCount(1, $this->logger->messages);

    // A missing main table makes the reader decline; logged as well.
    $this->logger->messages = [];
    $this->container->get('current_user')->setAccount($this->editor);
    $this->database()->schema()->dropTable('markaspot_request_index');
    $this->reader()->resetParity();
    $assert_declined('missing table');
    $this->assertCount(1, $this->logger->messages);
    $this->assertStringContainsString('Reading the request index failed', $this->logger->messages[0]);
    $this->assertFalse($this->reader()->isUsable());
    $assert_declined('missing table, cached');
    $this->assertCount(1, $this->logger->messages, 'A broken index logs once per parity TTL.');
  }

  /**
   * An unsorted lookup by request ID runs through the index or unchanged.
   *
   * Without a sort Core's Phase 1 copy would cost as much as the query, so
   * only the index answers it; when the index cannot, the inner query runs
   * once, unchanged.
   */
  public function testRequestIdLookupWithoutSort(): void {
    $root = (int) $this->rootJurisdiction->id();
    $child = (int) $this->childJurisdiction->id();
    $cases = [
      // Request 1004 lies in the root jurisdiction, 1003 in the child and is
      // unpublished, 1006 in the child and published.
      'request id' => [
        static fn(SqlQuery $q) => $q->condition('request_id', '1004-2026'),
        TRUE,
      ],
      'request id value with jurisdiction' => [
        static fn(SqlQuery $q) => $q
          ->condition('request_id.value', '1006-2026')
          ->condition('field_jurisdiction.target_id', [$root, $child], 'IN'),
        TRUE,
      ],
      'two request ids' => [
        static fn(SqlQuery $q) => $q->condition('request_id', ['1003-2026', '1006-2026'], 'IN'),
        TRUE,
      ],
      'request id with whitespace' => [
        static fn(SqlQuery $q) => $q->condition('request_id', ' 1004-2026'),
        FALSE,
      ],
      // Collations of the index and the node data table may differ beyond
      // printable ASCII.
      'request id with a non-ASCII character' => [
        static fn(SqlQuery $q) => $q->condition('request_id', '1004-2026ß'),
        FALSE,
      ],
    ];
    $build = function (callable $condition): SqlQuery {
      $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery();
      $this->assertInstanceOf(SqlQuery::class, $query);
      $query->accessCheck(TRUE)->condition('type', 'service_request');
      $condition($query);
      $query->range(0, 51);
      $this->assertSame([], CandidateEntityQuery::readSort($query));
      return $query;
    };
    $node_queries = function (string $key): int {
      return count(array_filter(
        Database::getLog($key),
        static fn(array $entry): bool => str_contains($entry['query'], 'node_field_data') && str_contains($entry['query'], 'request_id'),
      ));
    };
    $hits = [];
    foreach ([$this->districtStaff, $this->editor] as $account) {
      $this->container->get('current_user')->setAccount($account);
      foreach ($cases as $case => [$condition, $applied]) {
        $label = sprintf('%s / uid %d', $case, $account->id());
        $expected = array_values($build($condition)->execute());
        sort($expected);
        $key = 'request_id_lookup_' . bin2hex(random_bytes(4));
        Database::startLog($key);
        [$indexed, $reads, $sql] = $this->runWrapper($build($condition), TRUE);
        $this->assertSame($expected, $indexed, "Index path: $label");
        // A value the index cannot match exactly is left to Phase 2: the
        // index still answers, with a wider candidate set.
        $this->assertGreaterThan(0, $reads, "Index read: $label");
        $this->assertSame($reads, $node_queries($key), "One Phase 2 per index read, no Core Phase 1: $label");
        $this->assertCount($applied ? $reads : 0, preg_grep('/"i"\."request_id" (=|IN)/', $sql) ?: [], "Index condition applied: $label");
        [$direct, $direct_reads] = $this->runWrapper($build($condition), FALSE);
        sort($direct);
        $this->assertSame(0, $direct_reads, $label);
        $this->assertSame($expected, $direct, "Without the reader: $label");
        $hits[(int) $account->id()][$case] = count($expected);
      }
    }
    // Phase 2 stays the authority: the district account sees request 1006
    // only, the editor all three.
    $this->assertSame([
      'request id' => 0,
      'request id value with jurisdiction' => 1,
      'two request ids' => 1,
      'request id with whitespace' => 0,
      'request id with a non-ASCII character' => 0,
    ], $hits[(int) $this->districtStaff->id()]);
    $this->assertSame([
      'request id' => 1,
      'request id value with jurisdiction' => 1,
      'two request ids' => 2,
      'request id with whitespace' => 0,
      'request id with a non-ASCII character' => 0,
    ], $hits[(int) $this->editor->id()]);

    // An unusable index leaves the unsorted query to Core, run once.
    $this->container->get('current_user')->setAccount($this->editor);
    $queue = $this->container->get('queue')->get(RequestIndexRebuilder::QUEUE, TRUE);
    $queue->createItem(['op' => 'nids', 'nids' => []]);
    $key = 'request_id_lookup_unusable';
    Database::startLog($key);
    [$result, $reads] = $this->runWrapper($build($cases['request id'][0]), TRUE);
    $this->assertSame(0, $reads);
    $this->assertCount(1, $result);
    $this->assertSame(1, $node_queries($key), 'No Core Phase 1 copy for an unsorted query.');
    $queue->deleteQueue();
  }

  /**
   * Builds an access-checked collection query as CachedCountEntityResource.
   *
   * @param callable(\Drupal\Core\Entity\Query\Sql\Query): mixed $condition
   *   Adds the case's conditions.
   * @param list<array{0: string, 1: string, 2: string|null, 3?: string}> $sorts
   *   Field, direction, langcode and an optional "localized" (registered via
   *   LocalizedSortQuery) or "record" (request_order) marker.
   * @param int $offset
   *   Page offset.
   * @param int $length
   *   Page length.
   */
  private function buildQuery(callable $condition, array $sorts, int $offset, int $length = self::LENGTH): SqlQuery {
    $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery();
    $this->assertInstanceOf(SqlQuery::class, $query);
    $query->accessCheck(TRUE)->condition('type', 'service_request');
    $condition($query);
    $localized = [];
    $record = [];
    foreach ($sorts as $position => $sort) {
      $query->sort($sort[0], $sort[1], $sort[2]);
      if (($sort[3] ?? NULL) === 'localized') {
        $localized[] = $position;
      }
      if (($sort[3] ?? NULL) === 'record') {
        $record[] = $position;
      }
    }
    if ($localized !== []) {
      $query = LocalizedSortQuery::wrap($query, $localized);
    }
    CandidateEntityQuery::useRecordOrderSort($query, $record);
    $current = CandidateEntityQuery::readSort($query);
    if (!in_array('nid', array_column($current, 'field'), TRUE)) {
      $query->sort('nid', $current[0]['direction'] ?? 'ASC');
    }
    $query->range($offset, $length);
    return $query;
  }

  /**
   * Runs the wrapper with or without the index reader.
   *
   * @return array{0: list<int|string>, 1: int, 2: list<string>}
   *   Result node IDs in order, the number of index candidate reads and
   *   their SQL.
   */
  private function runWrapper(SqlQuery $query, bool $with_index): array {
    $key = 'index_candidates_' . bin2hex(random_bytes(4));
    Database::startLog($key);
    $wrapper = new DeferredAccessQueryWrapper($query, $this->container->get('entity_field.manager'), $with_index ? $this->reader() : NULL);
    $result = array_values($wrapper->execute());
    $reads = [];
    foreach (Database::getLog($key) as $entry) {
      if ($this->isIndexCandidateRead($entry['query'])) {
        $reads[] = $entry['query'];
      }
    }
    return [$result, count($reads), $reads];
  }

  /**
   * Whether a logged query is the ordered candidate read of the index.
   */
  private function isIndexCandidateRead(string $sql): bool {
    return (bool) preg_match('/^SELECT "i"\."nid" AS "nid"\s+FROM\s+"[^"]*markaspot_request_index" "i".*ORDER BY/s', $sql);
  }

  /**
   * Returns the request index reader.
   */
  private function reader(): RequestIndexReader {
    $reader = $this->container->get('markaspot_request_index.reader');
    $this->assertInstanceOf(RequestIndexReader::class, $reader);
    return $reader;
  }

  /**
   * Creates 140 requests across jurisdictions, statuses and categories.
   *
   * Every third request lies in the child jurisdiction and every sixth is
   * also published, so the district account sees about one in five.
   * Created timestamps repeat in triples to exercise the nid tiebreaker.
   */
  private function createRequests(): void {
    $categories = array_values($this->categories);
    $statuses = array_values($this->statuses);
    for ($i = 0; $i < 140; $i++) {
      $values = [
        'title' => 'Fictional report ' . $i,
        'langcode' => $i % 2 ? 'und' : 'en',
        'status' => $i % 6 !== 3,
        'request_id' => sprintf('%d-2026', 1000 + $i),
        'created' => 1700000000 + intdiv($i, 3) * 60,
        'changed' => 1700500000 + ($i * 37) % 97,
        'field_jurisdiction' => [$i % 3 === 0 ? $this->childJurisdiction->id() : $this->rootJurisdiction->id()],
        'field_organisation' => [$i % 3 === 0 ? $this->organisationB->id() : $this->organisationA->id()],
        'field_status' => $statuses[$i % count($statuses)]->id(),
        'field_category' => $i % 11 === 5 ? NULL : $categories[$i % count($categories)]->id(),
      ];
      if ($i % 20 === 8) {
        $values['field_jurisdiction'] = [
          $this->childJurisdiction->id(),
          $this->rootJurisdiction->id(),
          $this->neighbourJurisdiction->id(),
        ];
      }
      if ($i % 10 === 6) {
        $values['field_organisation'] = [$this->organisationB->id(), $this->organisationA->id()];
      }
      $nid = (int) $this->createRequest($values)->id();
      if ($i % 20 === 8) {
        $this->multiJurisdictionIds[] = $nid;
      }
      if ($i % 10 === 6) {
        $this->multiOrganisationIds[] = $nid;
      }
    }
  }

  /**
   * Creates the district staff member and the editor.
   */
  private function createAccounts(): void {
    $role = Role::load('authenticated') ?? Role::create(['id' => 'authenticated', 'label' => 'Authenticated']);
    $role->grantPermission('access content')->save();
    Role::create(['id' => 'editor', 'label' => 'Editor'])->grantPermission('bypass node access')->save();
    // Group access has no bypass permission: the editor role administers
    // every jurisdiction and organisation as an outsider.
    foreach (['jur', 'org'] as $group_type) {
      GroupRole::create([
        'id' => $group_type . '-editor',
        'label' => 'Editor',
        'group_type' => $group_type,
        'scope' => 'outsider',
        'global_role' => 'editor',
        'admin' => TRUE,
      ])->save();
    }
    $this->editor = User::create(['name' => 'editor', 'status' => 1, 'roles' => ['editor']]);
    $this->editor->save();
    GroupRole::create([
      'id' => 'jur-district_staff',
      'label' => 'District staff',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => ['view group_node:service_request entity'],
    ])->save();
    $this->districtStaff = User::create(['name' => 'district-staff', 'status' => 1]);
    $this->districtStaff->save();
    $this->childJurisdiction->addMember($this->districtStaff, ['group_roles' => ['jur-district_staff']]);
  }

  /**
   * Asserts that the fixture holds the rows the cases rely on.
   */
  private function assertFixture(): void {
    $neighbour = (int) $this->neighbourJurisdiction->id();
    foreach ($this->multiJurisdictionIds as $nid) {
      $row = $this->row($nid);
      $this->assertSame('1', $row['jur_multi'] ?? NULL);
      $this->assertNotSame((string) $neighbour, $row['jur_gid'] ?? NULL, 'The neighbour is only in the child table.');
      $this->assertContains($neighbour, $this->children('jur', $nid));
      $this->assertSame($neighbour, (int) Node::load($nid)->get('field_jurisdiction')->get(2)?->target_id, 'The neighbour is at delta 2.');
    }
    foreach ($this->multiOrganisationIds as $nid) {
      $row = $this->row($nid);
      $this->assertSame('1', $row['org_multi'] ?? NULL);
      $this->assertSame((string) $this->organisationB->id(), $row['org_gid'] ?? NULL);
    }
    $this->assertNotEmpty($this->multiJurisdictionIds);
    $this->assertNotEmpty($this->multiOrganisationIds);
    $this->assertSame('PAD-9', $this->database()->select('markaspot_request_index', 'i')->fields('i', ['service_code'])
      ->condition('category_tid', $this->categories['padded']->id())->range(0, 1)->execute()->fetchField());
    $changed = $this->database()->select('markaspot_request_index', 'i')->fields('i', ['changed'])->distinct()->execute()->fetchCol();
    $this->assertGreaterThan(10, count($changed), 'Changed timestamps vary.');
  }

}
