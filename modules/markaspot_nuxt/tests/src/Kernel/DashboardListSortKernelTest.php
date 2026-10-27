<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Http\Exception\CacheableBadRequestHttpException;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\jsonapi\Query\OffsetPage;
use Drupal\jsonapi\Query\Sort;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_nuxt\JsonApi\CachedCountEntityResource;
use Drupal\markaspot_nuxt\JsonApi\DeferredAccessQueryWrapper;
use Drupal\markaspot_nuxt\JsonApi\SingleValueSortPath;
use Drupal\markaspot_nuxt\JsonApi\LocalizedSortQuery;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Compares dashboard collection sorts with real access-checked Core queries.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
final class DashboardListSortKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy', 'file',
    'serialization', 'jsonapi', 'language', 'content_translation',
  ];

  /**
   * Uses the shipped collection controller without unrelated profile features.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->setParameter('jsonapi.base_path', '/jsonapi');
    $container->getDefinition('jsonapi.entity_resource')->setClass(CachedCountEntityResource::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term', 'file'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'language', 'jsonapi']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Report'])->save();
    Vocabulary::create(['vid' => 'categories', 'name' => 'Categories'])->save();
    $this->container->get('content_translation.manager')->setEnabled('taxonomy_term', 'categories', TRUE);
    foreach ([
      'request_id' => ['string', []],
      'field_category' => ['entity_reference', ['target_type' => 'taxonomy_term']],
      'field_multi' => ['string', []],
    ] as $name => [$type, $settings]) {
      FieldStorageConfig::create([
        'field_name' => $name, 'entity_type' => 'node', 'type' => $type,
        'settings' => $settings, 'cardinality' => $name === 'field_multi' ? -1 : 1,
      ])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'service_request'])->save();
    }
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::load('anonymous')->grantPermission('access content')->save();
    $this->container->get('node.grant_storage')->writeDefault();
    $terms = [];
    foreach (['Zebra' => 'Apfel', 'Apple' => 'Zug'] as $en => $de) {
      $term = Term::create(['vid' => 'categories', 'langcode' => 'en', 'name' => $en]);
      $term->addTranslation('de', ['name' => $de]);
      $term->save();
      $terms[] = $term->id();
    }
    // Lexical IDs deliberately disagree with insertion order, including years.
    foreach (['9999-2025', '9-2026', '10-2026', '11-2026', '12-2026', '13-2026', '14-2026', '15-2026'] as $i => $id) {
      Node::create([
        'type' => 'service_request', 'title' => $id, 'request_id' => $id,
        'status' => $i % 3 !== 0, 'uid' => 1, 'field_category' => $terms[$i % 2],
        'langcode' => $i % 2 ? 'und' : 'en',
      ])->save();
    }
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  /**
   * One reference/language fast paths preserve access, ties and page offsets.
   */
  public function testSingleValueSortsAndGuards(): void {
    $manager = $this->container->get('entity_field.manager');
    $sort = ['field' => 'field_category.entity.name', 'direction' => 'ASC', 'langcode' => 'de'];
    $this->assertTrue(SingleValueSortPath::isSafe('node', $sort, $manager));
    // JSON:API's field resolver names the target type in the hop.
    $resolved = $this->container->get('jsonapi.field_resolver')->resolveInternalEntityQueryPath(
      $this->container->get('jsonapi.resource_type.repository')->get('node', 'service_request'),
      'field_category.name',
    );
    $this->assertSame('field_category.entity:taxonomy_term.name', $resolved);
    $this->assertTrue(SingleValueSortPath::isSafe('node', ['field' => $resolved] + $sort, $manager));
    foreach (['field_multi.value', 'field_category.entity.missing', 'uid.target_id', 'field_category.entity.name.extra', 'field_category.entity:node.title'] as $field) {
      $this->assertFalse(SingleValueSortPath::isSafe('node', ['field' => $field, 'langcode' => 'de'], $manager));
    }
    $this->assertFalse(SingleValueSortPath::isSafe('node', ['field' => 'field_category.entity.name'], $manager));
    foreach ([new AnonymousUserSession(), User::load(1)] as $account) {
      $this->container->get('current_user')->setAccount($account);
      foreach (['en', 'de'] as $language) {
        foreach (['ASC', 'DESC'] as $direction) {
          foreach ([0, 2, 6, 2049] as $offset) {
            $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
              ->accessCheck(TRUE)->condition('type', 'service_request')
              ->sort('field_category.entity.name', $direction, $language)->sort('nid', $direction)->range($offset, 3);
            $query = LocalizedSortQuery::wrap($query, [0]);
            $direct = clone $query;
            $expected = array_values($direct->execute());
            $actual = array_values((new DeferredAccessQueryWrapper($query, $manager))->execute());
            $this->assertSame($expected, $actual, "$language $direction $offset uid=" . $account->id());
          }
        }
      }
      // Verify actual values as well as SQL-equivalence: Core's ordinary
      // langcode sort makes und references NULL, so equality alone misses it.
      foreach (['en', 'de'] as $language) {
        $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
          ->accessCheck(TRUE)->condition('type', 'service_request')
          ->sort('field_category.entity.name', 'ASC', $language)->sort('nid', 'ASC')->range(0, 20);
        $query = LocalizedSortQuery::wrap($query, [0]);
        $ids = array_values((new DeferredAccessQueryWrapper($query, $manager))->execute());
        $labels = [];
        foreach ($ids as $id) {
          $term = Node::load($id)->get('field_category')->entity;
          $labels[] = $term->getTranslation($language)->label();
        }
        $sorted_labels = $labels;
        sort($sorted_labels);
        $this->assertSame($sorted_labels, $labels, 'Visible category labels, including und reports, are really ordered.');
        $this->assertCount(2, array_unique($labels));
      }
    }
  }

  /**
   * The virtual sort works while nid stays disabled and request_id stays text.
   */
  public function testRecordOrderWithHiddenInternalId(): void {
    $resource = $this->container->get('jsonapi.resource_type.repository')->get('node', 'service_request');
    $fields = $resource->getFields();
    $fields['nid'] = $fields['nid']->disabled();
    $resource = new ResourceType('node', 'service_request', Node::class, FALSE, TRUE, TRUE, TRUE, $fields);
    $this->assertFalse($resource->isFieldEnabled('nid'));
    $method = new \ReflectionMethod(CachedCountEntityResource::class, 'getCollectionQuery');
    $controller = $this->container->get('jsonapi.entity_resource');
    foreach (['ASC', 'DESC'] as $direction) {
      $params = [
        'sort' => new Sort([['path' => 'request_order', 'direction' => $direction]]),
        'page' => new OffsetPage(0, 3),
      ];
      $query = $method->invoke($controller, $resource, $params, new CacheableMetadata());
      $expected = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE)
        ->condition('type', 'service_request')->sort('nid', $direction)->range(0, 4)->execute();
      $this->assertSame(array_values($expected), array_values($query->execute()));
    }
    // Create a large tie group using SQL fixtures: these queries only read IDs,
    // so no entity loading or revision records are needed. The dedicated kernel
    // database owns all fixture rows.
    $database = $this->container->get('database');
    foreach (['node', 'node_field_data'] as $table) {
      $template = $database->select($table, 'n')->fields('n')->range(0, 1)->execute()->fetchAssoc();
      $insert = $database->insert($table)->fields(array_keys($template));
      for ($i = 1000; $i < 3100; $i++) {
        $row = $template;
        $row['nid'] = $row['vid'] = $i;
        if (isset($row['uuid'])) {
          $row['uuid'] = sprintf('ffffffff-ffff-4fff-8fff-%012d', $i);
        }
        if (isset($row['status'])) {
          $row['status'] = 1;
        }
        $insert->values($row);
      }
      $insert->execute();
    }
    $params = [
      'sort' => new Sort([['path' => 'category_order', 'direction' => 'ASC', 'langcode' => 'de']]),
      'page' => new OffsetPage(1990, 50),
    ];
    $collected = [];
    foreach ([1990, 2040] as $offset) {
      $params['page'] = new OffsetPage($offset, 50);
      $query = $method->invoke($controller, $resource, $params, new CacheableMetadata());
      $collected = array_merge($collected, array_slice(array_values($query->execute()), 0, 50));
    }
    $expected = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE)
      ->condition('type', 'service_request')->sort('field_category.entity.name', 'ASC', 'de')
      ->sort('nid', 'ASC')->range(1990, 100);
    $expected = LocalizedSortQuery::wrap($expected, [0])->execute();
    $this->assertCount(100, $collected);
    $this->assertSame(array_values($expected), $collected, 'No gaps or duplicates across the fast-path/fallback boundary.');
    $this->assertCount(100, array_unique($collected));
    // Also assert that the fallback actually carries the tie sort in its SQL.
    $params['page'] = new OffsetPage(2049, 3);
    $query = $method->invoke($controller, $resource, $params, new CacheableMetadata());
    Database::startLog('dashboard_tie_sort');
    $query->execute();
    $sql = implode("\n", array_column(Database::getLog('dashboard_tie_sort'), 'query'));
    $this->assertMatchesRegularExpression('/ORDER BY.+expression.+nid/s', $sql);
    $params['page'] = new OffsetPage(0, 3);
    $params['sort'] = new Sort([['path' => 'request_id', 'direction' => 'ASC']]);
    $query = $method->invoke($controller, $resource, $params, new CacheableMetadata());
    $expected = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE)
      ->condition('type', 'service_request')->sort('request_id', 'ASC')->sort('nid', 'ASC')->range(0, 4)->execute();
    $this->assertSame(array_values($expected), array_values($query->execute()));
    $params['sort'] = new Sort([['path' => 'drupal_internal__nid', 'direction' => 'ASC']]);
    $this->expectException(BadRequestHttpException::class);
    $method->invoke($controller, $resource, $params, new CacheableMetadata());
  }

  /**
   * Bounds duplicate joins and isolates language-specific sort helpers.
   */
  public function testVirtualSortJoinBounds(): void {
    $resource = $this->container->get('jsonapi.resource_type.repository')->get('node', 'service_request');
    $method = new \ReflectionMethod(CachedCountEntityResource::class, 'getCollectionQuery');
    $controller = $this->container->get('jsonapi.entity_resource');
    foreach ([1, 4] as $count) {
      $params = [
        'sort' => new Sort(array_fill(0, $count, ['path' => 'category_order', 'direction' => 'ASC', 'langcode' => 'de'])),
        'page' => new OffsetPage(0, 20),
      ];
      $query = $method->invoke($controller, $resource, $params, new CacheableMetadata());
      Database::startLog('duplicate_sort_' . $count);
      $this->assertNotEmpty($query->execute());
      $sql = implode("\n", array_column(Database::getLog('duplicate_sort_' . $count), 'query'));
      preg_match_all('/JOIN "[^"]*taxonomy_term_field_data"/', $sql, $matches);
      $join_counts[$count] = count($matches[0]);
    }
    $this->assertGreaterThan(0, $join_counts[1]);
    $this->assertSame($join_counts[1], $join_counts[4], 'Identical keys do not multiply joins.');
    $params['sort'] = new Sort([
      ['path' => 'category_order', 'direction' => 'ASC', 'langcode' => 'de'],
      ['path' => 'category_order', 'direction' => 'ASC', 'langcode' => 'en'],
    ]);
    $query = $method->invoke($controller, $resource, $params, new CacheableMetadata());
    Database::startLog('separate_sort_languages');
    $this->assertNotEmpty($query->execute(), 'Different languages use separate, non-conflicting aliases.');
    $checked = 0;
    foreach (Database::getLog('separate_sort_languages') as $entry) {
      if (!str_contains($entry['query'], 'ORDER BY')) {
        continue;
      }
      preg_match_all('/JOIN "[^"]*taxonomy_term_field_data" "([^"]+)"/', $entry['query'], $aliases);
      $this->assertCount(2, array_unique($aliases[1]));
      $this->assertContains('de', array_values($entry['args']));
      $this->assertContains('en', array_values($entry['args']));
      $checked++;
    }
    $this->assertGreaterThan(0, $checked);
    $params['sort'] = new Sort(array_fill(0, 5, ['path' => 'category_order', 'langcode' => 'de']));
    Database::startLog('too_many_virtual_sorts');
    try {
      $method->invoke($controller, $resource, $params, new CacheableMetadata());
      $this->fail('Five virtual keys must be rejected.');
    }
    catch (CacheableBadRequestHttpException $error) {
      $this->assertSame(400, $error->getStatusCode());
      // A cached 400 must never apply to requests with another sort.
      $this->assertContains('url.query_args:sort', $error->getCacheContexts());
    }
    $this->assertSame([], Database::getLog('too_many_virtual_sorts'));
  }

}
