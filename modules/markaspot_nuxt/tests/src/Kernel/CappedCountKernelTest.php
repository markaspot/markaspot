<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\Query\Sql\Query as SqlQuery;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupRole;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\markaspot_nuxt\JsonApi\CachedCountEntityResource;
use Drupal\markaspot_nuxt\JsonApi\DeferredAccessQueryWrapper;
use Drupal\markaspot_request_index\RequestIndexRebuilder;
use Drupal\markaspot_request_index\RequestIndexReader;
use Drupal\node\Entity\Node;
use Drupal\Tests\markaspot_request_index\Kernel\RequestIndexKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Capped JSON:API counts of service requests (query parameter countCap).
 *
 * Every case runs the shipped collection controller. A capped count reports
 * the exact number up to the cap and cap + 1 with meta.countCapped above it,
 * for exactly the requests the account can read. All names and addresses are
 * fictional.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
final class CappedCountKernelTest extends RequestIndexKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['serialization', 'file', 'jsonapi'];

  /**
   * Prefix of the count cache IDs of service requests.
   */
  private const COUNT_CID_PREFIX = 'markaspot_nuxt:jsonapi_count:node--service_request:';

  /**
   * Account that sees only published requests of the child jurisdiction.
   */
  private UserInterface $districtStaff;

  /**
   * Account that bypasses node and group access: sees every request.
   */
  private UserInterface $editor;

  /**
   * The service request resource type with counts switched on.
   */
  private ResourceType $resourceType;

  /**
   * Uses the shipped collection controller.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->setParameter('jsonapi.base_path', '/jsonapi');
    if ($container->hasDefinition('jsonapi.entity_resource')) {
      $container->getDefinition('jsonapi.entity_resource')->setClass(CachedCountEntityResource::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['jsonapi']);
    $this->createAccounts();

    // Every third request lies in the child jurisdiction and every sixth of
    // those is unpublished. Requests 10 and 20 are routed to the child and
    // also reference the root, so a jurisdiction join returns two rows.
    for ($i = 0; $i < 30; $i++) {
      $jurisdictions = [$i % 3 === 0 ? $this->childJurisdiction->id() : $this->rootJurisdiction->id()];
      if ($i % 10 === 0 && $i > 0) {
        $jurisdictions = [$this->childJurisdiction->id(), $this->rootJurisdiction->id()];
      }
      $this->createRequest([
        'title' => 'Fictional report ' . $i,
        'status' => $i % 6 !== 3,
        'field_jurisdiction' => $jurisdictions,
      ]);
    }
    $this->container->get('markaspot_request_index.rebuilder')->drain();
    $this->reader()->resetParity();
    $this->assertTrue($this->reader()->isUsable());

    // Core never includes counts; jsonapi_extras switches them on per site.
    $base = $this->container->get('jsonapi.resource_type.repository')->get('node', 'service_request');
    // @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass (test double of the resource type the controller receives)
    $this->resourceType = new class('node', 'service_request', Node::class, FALSE, TRUE, TRUE, FALSE, $base->getFields()) extends ResourceType {

      /**
       * {@inheritdoc}
       */
      public function includeCount() {
        return TRUE;
      }

    };
  }

  /**
   * Capped counts are exact up to the cap and never exceed visibility.
   */
  public function testCappedCountMatchesVisibility(): void {
    $title_filter = [
      'prefix' => [
        'condition' => [
          'path' => 'title',
          'operator' => 'STARTS_WITH',
          'value' => 'Fictional report 1',
        ],
      ],
    ];
    $visible = [];
    $accounts = [
      'district' => $this->districtStaff,
      'editor' => $this->editor,
      'anonymous' => new AnonymousUserSession(),
    ];
    foreach ($accounts as $label => $account) {
      $this->container->get('current_user')->setAccount($account);
      foreach (['all' => [], 'title filter' => $title_filter] as $scope => $filter) {
        $expected = $this->directCount($scope === 'all' ? NULL : 'Fictional report 1');
        $visible[$label][$scope] = $expected;
        foreach (['usable index' => FALSE, 'unusable index' => TRUE] as $path => $block_index) {
          $queue = $this->container->get('queue')->get(RequestIndexRebuilder::QUEUE, TRUE);
          if ($block_index) {
            $queue->createItem(['op' => 'nids', 'nids' => []]);
          }
          $case = "$label / $scope / $path";
          $base_query = $filter === [] ? [] : ['filter' => $filter];

          $this->resetCountCache();
          $meta = $this->collection($base_query)->getMeta();
          $this->assertSame($expected, $meta['count'], "Uncapped: $case");
          $this->assertArrayNotHasKey('countCapped', $meta, "Uncapped: $case");

          foreach (array_unique(array_filter([1, $expected - 1, $expected, $expected + 1, 10000], static fn(int $cap): bool => $cap >= 1)) as $cap) {
            $this->resetCountCache();
            $document = $this->collection($base_query + ['countCap' => (string) $cap]);
            $meta = $document->getMeta();
            if ($expected > $cap) {
              $this->assertSame($cap + 1, $meta['count'], "Cap $cap: $case");
              $this->assertTrue($meta['countCapped'] ?? NULL, "Cap $cap: $case");
              $this->assertFalse($document->getLinks()->hasLinkWithKey('last'), "No last page for a capped count, cap $cap: $case");
            }
            else {
              $this->assertSame($expected, $meta['count'], "Cap $cap: $case");
              $this->assertArrayNotHasKey('countCapped', $meta, "Cap $cap: $case");
            }
          }
          $queue->deleteQueue();
        }
      }
    }

    // The fixture separates the accounts: the district sees requests 0, 6,
    // 12, 18 and 24 of the child plus 10 and 20 that are routed to it.
    $this->assertSame(['all' => 7, 'title filter' => 3], $visible['district']);
    $this->assertSame(['all' => 30, 'title filter' => 11], $visible['editor']);
    $this->assertGreaterThan(0, $visible['anonymous']['all']);
    $this->assertLessThan(30, $visible['anonymous']['all']);

    // An uncapped count still reports the last page.
    $this->container->get('current_user')->setAccount($this->editor);
    $this->resetCountCache();
    $this->assertTrue($this->collection([])->getLinks()->hasLinkWithKey('last'));
  }

  /**
   * The request index answers a capped count without the full inner query.
   */
  public function testCappedCountUsesIndexWindow(): void {
    $this->container->get('current_user')->setAccount($this->editor);
    $method = new \ReflectionMethod(CachedCountEntityResource::class, 'getCollectionCountQuery');
    $resource = $this->container->get('jsonapi.entity_resource');
    $run = function (string $cap) use ($method, $resource): array {
      $this->resetCountCache();
      $stack = $this->container->get('request_stack');
      $stack->push(Request::create('/jsonapi/node/service_request', 'GET', ['countCap' => $cap]));
      try {
        $query = $method->invoke($resource, $this->resourceType, [], new CacheableMetadata());
        $key = 'capped_count_' . bin2hex(random_bytes(4));
        Database::startLog($key);
        $count = $query->execute();
        $log = Database::getLog($key);
      }
      finally {
        $stack->pop();
      }
      $index_reads = count(array_filter($log, static fn(array $entry): bool => (bool) preg_match('/^SELECT "i"\."nid" AS "nid"\s+FROM\s+"[^"]*markaspot_request_index" "i".*ORDER BY/s', $entry['query'])));
      $node_queries = array_values(array_filter($log, static fn(array $entry): bool => (bool) preg_match('/FROM\s+"[^"]*node" "base_table"/', $entry['query'])));
      return [$count, $index_reads, $node_queries];
    };

    // Cap 5 of 30: one index window, then one Phase 2 narrowed to its IDs.
    [$count, $index_reads, $node_queries] = $run('5');
    $this->assertSame(6, $count);
    $this->assertSame(1, $index_reads);
    $this->assertCount(1, $node_queries, 'Phase 2 only, the inner query does not run.');
    $this->assertStringNotContainsString('COUNT(', $node_queries[0]['query']);
    $this->assertMatchesRegularExpression('/"nid" IN \(/', $node_queries[0]['query']);

    // A cap beyond the two-phase range runs the inner query once, capped.
    [$count, $index_reads, $node_queries] = $run('5000');
    $this->assertSame(30, $count);
    $this->assertSame(0, $index_reads);
    $this->assertCount(1, $node_queries);
    $this->assertStringNotContainsString('COUNT(', $node_queries[0]['query']);
    $this->assertStringContainsString('LIMIT 5001', $node_queries[0]['query']);

    // Without countCap the count stays Core's COUNT query.
    [$count, $index_reads, $node_queries] = $run('');
    $this->assertSame(30, $count);
    $this->assertSame(0, $index_reads);
    $this->assertCount(1, $node_queries);
    $this->assertStringContainsString('COUNT(', $node_queries[0]['query']);

    // A multi-valued condition joins two rows for requests 10 and 20: the
    // capped count still counts requests, on both Phase 1 sources.
    $field_manager = $this->container->get('entity_field.manager');
    foreach ([$this->districtStaff, $this->editor] as $account) {
      $this->container->get('current_user')->setAccount($account);
      $build = function (): SqlQuery {
        $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery();
        $this->assertInstanceOf(SqlQuery::class, $query);
        return $query->accessCheck(TRUE)
          ->condition('type', 'service_request')
          ->condition('field_jurisdiction', [$this->childJurisdiction->id(), $this->rootJurisdiction->id()], 'IN');
      };
      $expected = (int) $build()->count()->execute();
      foreach ([$expected - 2, $expected - 1, $expected, $expected + 1] as $cap) {
        foreach ([TRUE, FALSE] as $with_index) {
          $wrapper = new DeferredAccessQueryWrapper($build(), $field_manager, $with_index ? $this->reader() : NULL);
          $this->assertSame(min($expected, $cap + 1), $wrapper->capCount($cap)->execute(), sprintf('uid %d, cap %d, index %d', $account->id(), $cap, $with_index));
        }
      }
    }
  }

  /**
   * Capped and exact counts never share a cache entry; bad caps are ignored.
   */
  public function testCountCacheKeepsCappedAndExactApart(): void {
    $cache = new class($this->container->get('datetime.time')) extends MemoryBackend {

      /**
       * Every value written, keyed by cache ID.
       *
       * @var array<string, mixed>
       */
      public array $written = [];

      /**
       * {@inheritdoc}
       */
      public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
        $this->written[$cid] = $data;
        parent::set($cid, $data, $expire, $tags);
      }

    };
    $this->container->set('cache.default', $cache);
    $this->container->get('current_user')->setAccount($this->districtStaff);
    $new_entries = function (callable $request) use ($cache): array {
      $before = array_keys($cache->written);
      $request();
      return array_diff_key($cache->written, array_flip($before));
    };

    // A capped count first, then the exact one: separate entries.
    $capped_entries = $new_entries(fn() => $this->assertSame(
      ['count' => 3, 'countCapped' => TRUE],
      $this->collection(['countCap' => '2'])->getMeta(),
    ));
    $this->assertSame([3], array_values($capped_entries));
    $exact_entries = $new_entries(fn() => $this->assertSame(['count' => 7], $this->collection([])->getMeta()));
    $this->assertSame([7], array_values($exact_entries));
    $wide_entries = $new_entries(fn() => $this->assertSame(['count' => 7], $this->collection(['countCap' => '50'])->getMeta()));
    $this->assertSame([7], array_values($wide_entries));
    $capped_cid = (string) array_key_first($capped_entries);
    $exact_cid = (string) array_key_first($exact_entries);
    $wide_cid = (string) array_key_first($wide_entries);
    $this->assertCount(3, array_unique([$capped_cid, $exact_cid, $wide_cid]));
    foreach ([$capped_cid, $exact_cid, $wide_cid] as $cid) {
      $this->assertStringStartsWith(self::COUNT_CID_PREFIX . $this->districtStaff->id() . ':', $cid);
    }

    // Ignored caps behave like an uncapped request and add no entry.
    foreach (['0', '-3', '10001', '99999', 'abc', '5.0', ' 5', '05', '+5', '', ['5']] as $invalid) {
      $label = var_export($invalid, TRUE);
      $entries = $new_entries(fn() => $this->assertSame(['count' => 7], $this->collection(['countCap' => $invalid])->getMeta(), $label));
      $this->assertSame([], $entries, $label);
    }

    // Each kind reads only its own entry: a marked exact entry shows up only
    // in the uncapped response.
    $cache->set($exact_cid, 99, Cache::PERMANENT, ['node_list']);
    $this->assertSame(['count' => 7], $this->collection(['countCap' => '50'])->getMeta());
    $this->assertSame(['count' => 3, 'countCapped' => TRUE], $this->collection(['countCap' => '2'])->getMeta());
    $this->assertSame(['count' => 99], $this->collection([])->getMeta());
    $cache->set($wide_cid, 4, Cache::PERMANENT, ['node_list']);
    $this->assertSame(['count' => 99], $this->collection([])->getMeta());
    $this->assertSame(['count' => 4], $this->collection(['countCap' => '50'])->getMeta());

    // A skipped count ignores countCap and keeps today's behaviour: the exact
    // cached total on a hit.
    $skipped = ['countCap' => '2', 'skipCount' => 'true'];
    $entries = $new_entries(fn() => $this->assertSame(['count' => 99], $this->collection($skipped)->getMeta()));
    $this->assertSame([], $entries);

    // Anonymous counts are capped but never cached.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $entries = $new_entries(function (): void {
      $meta = $this->collection(['countCap' => '1'])->getMeta();
      $this->assertSame(2, $meta['count']);
      $this->assertTrue($meta['countCapped']);
    });
    $this->assertSame([], $entries);
  }

  /**
   * Runs the collection controller for the given query parameters.
   *
   * @param array<string, mixed> $query
   *   Query parameters; a page size of two is added.
   */
  private function collection(array $query): JsonApiDocumentTopLevel {
    $stack = $this->container->get('request_stack');
    $request = Request::create('/jsonapi/node/service_request', 'GET', $query + ['page' => ['limit' => '2']]);
    $request->setSession($stack->getCurrentRequest()->getSession());
    $stack->push($request);
    try {
      $response = $this->container->get('jsonapi.entity_resource')->getCollection($this->resourceType, $request);
    }
    finally {
      $stack->pop();
    }
    $document = $response->getResponseData();
    $this->assertInstanceOf(JsonApiDocumentTopLevel::class, $document);
    return $document;
  }

  /**
   * Counts readable requests with an access-checked entity query.
   */
  private function directCount(?string $title_prefix): int {
    $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'service_request');
    if ($title_prefix !== NULL) {
      $query->condition('title', $title_prefix, 'STARTS_WITH');
    }
    return (int) $query->count()->execute();
  }

  /**
   * Forgets cached counts so the next request runs its query.
   */
  private function resetCountCache(): void {
    $this->container->get('cache.default')->deleteAll();
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
   * Creates the district staff member, the editor and anonymous access.
   */
  private function createAccounts(): void {
    foreach (['anonymous' => 'Anonymous', 'authenticated' => 'Authenticated'] as $id => $label) {
      $role = Role::load($id) ?? Role::create(['id' => $id, 'label' => $label]);
      $role->grantPermission('access content')->save();
    }
    Role::create(['id' => 'editor', 'label' => 'Editor'])->grantPermission('bypass node access')->save();
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
    // Anonymous visitors see published requests of every jurisdiction.
    GroupRole::create([
      'id' => 'jur-anonymous',
      'label' => 'Anonymous',
      'group_type' => 'jur',
      'scope' => 'outsider',
      'global_role' => 'anonymous',
      'permissions' => ['view group_node:service_request entity'],
    ])->save();
  }

}
