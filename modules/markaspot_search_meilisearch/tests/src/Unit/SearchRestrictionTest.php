<?php

namespace Drupal\Tests\markaspot_search_meilisearch\Unit;

use Drupal\Core\State\StateInterface;
use Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService;
use Drupal\markaspot_search_meilisearch\IndexSettings;
use Drupal\markaspot_search_meilisearch\SearchRestriction;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_meilisearch\Client\MeilisearchClientFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;

/**
 * Tests that a query's restriction reaches Meilisearch, or the search fails.
 *
 * @group markaspot_search_meilisearch
 * @coversDefaultClass \Drupal\markaspot_search_meilisearch\SearchRestriction
 */
class SearchRestrictionTest extends UnitTestCase {

  /**
   * Index fields and their types, as on the service_requests index.
   */
  protected const FIELDS = [
    'title' => 'text',
    'body' => 'text',
    'request_id' => 'text',
    'field_e_mail' => 'text',
    'address_line1' => 'text',
    'postal_code' => 'text',
    'status' => 'boolean',
    'node_grants' => 'string',
  ];

  /**
   * Returns a query mock on an index.
   */
  protected function query(?array $fulltext_fields, string $keys = 'pothole', string $index_id = 'service_requests'): QueryInterface {
    $fields = [];
    foreach (self::FIELDS as $id => $type) {
      $field = $this->createMock(FieldInterface::class);
      $field->method('getType')->willReturn($type);
      $fields[$id] = $field;
    }
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn($index_id);
    $index->method('getFields')->willReturn($fields);

    $query = $this->createMock(QueryInterface::class);
    $query->method('getIndex')->willReturn($index);
    $query->method('getFulltextFields')->willReturn($fulltext_fields);
    $query->method('getOriginalKeys')->willReturn($keys);
    return $query;
  }

  /**
   * Returns the API service with a fixed Meilisearch index.
   */
  protected function api(SearchRestriction $restriction, Indexes $meili_index, bool $settings_applied = TRUE): RestrictedMeilisearchApiService {
    $api = new class($this->createMock(MeilisearchClientFactoryInterface::class), $meili_index) extends RestrictedMeilisearchApiService {

      /**
       * Constructs the service with a fixed Meilisearch index.
       */
      public function __construct(MeilisearchClientFactoryInterface $factory, protected Indexes $meiliIndex) {
        parent::__construct($factory);
      }

      /**
       * {@inheritdoc}
       */
      public function getIndex(string $indexName): Indexes {
        return $this->meiliIndex;
      }

    };
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with(IndexSettings::STATE_KEY)->willReturn(['service_requests' => $settings_applied]);
    $api->setRestriction($restriction);
    $api->setState($state);
    return $api;
  }

  /**
   * Options: requested text fields only, every word, item ID only.
   *
   * @covers ::buildOptions
   */
  public function testBuildOptions(): void {
    $options = SearchRestriction::buildOptions($this->query(['title', 'body', 'request_id', 'node_grants']));
    $this->assertSame([
      'matchingStrategy' => 'all',
      'attributesToSearchOn' => ['title', 'body', 'request_id'],
      'attributesToRetrieve' => ['search_api_id'],
    ], $options);
  }

  /**
   * Unresolved fields mean "every field" and must not reach Meilisearch.
   *
   * @covers ::buildOptions
   */
  public function testUnresolvedFieldsAbort(): void {
    $this->expectException(SearchApiException::class);
    SearchRestriction::buildOptions($this->query(NULL));
  }

  /**
   * No searchable field left must not turn into "search everything".
   *
   * @covers ::buildOptions
   */
  public function testNoSearchableFieldAborts(): void {
    $this->expectException(SearchApiException::class);
    SearchRestriction::buildOptions($this->query(['status']));
  }

  /**
   * A recorded restriction is bound to its index and keys.
   *
   * @covers ::record
   * @covers ::getOptions
   */
  public function testRestrictionIsBoundToIndexAndKeys(): void {
    $restriction = new SearchRestriction();
    $restriction->record($this->query(['title'], 'pothole'));
    $this->assertSame(['title'], $restriction->getOptions('service_requests', 'pothole')['attributesToSearchOn']);

    foreach ([['service_requests', 'other words'], ['other_index', 'pothole']] as [$index_id, $keys]) {
      try {
        $restriction->getOptions($index_id, $keys);
        $this->fail("Options returned for $index_id / $keys.");
      }
      catch (SearchApiException) {
        // Expected: no restriction recorded for this search.
      }
    }
  }

  /**
   * A failed record leaves no earlier, possibly wider restriction behind.
   *
   * @covers ::record
   */
  public function testFailedRecordDropsTheEarlierRestriction(): void {
    $restriction = new SearchRestriction();
    $restriction->record($this->query(['title', 'field_e_mail'], 'pothole'));
    try {
      $restriction->record($this->query(NULL, 'pothole'));
    }
    catch (SearchApiException) {
      // Expected.
    }
    $this->expectException(SearchApiException::class);
    $restriction->getOptions('service_requests', 'pothole');
  }

  /**
   * After the results are processed the restriction is gone.
   *
   * @covers ::clear
   */
  public function testClear(): void {
    $restriction = new SearchRestriction();
    $restriction->record($this->query(['title']));
    $restriction->clear('service_requests');
    $this->expectException(SearchApiException::class);
    $restriction->getOptions('service_requests', 'pothole');
  }

  /**
   * The recorded restriction reaches the Meilisearch request and wins.
   *
   * @covers \Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService::search
   */
  public function testRecordedRestrictionReachesTheRequest(): void {
    $restriction = new SearchRestriction();
    $restriction->record($this->query(['title', 'body', 'request_id']));

    $meili_index = $this->createMock(Indexes::class);
    $meili_index->expects($this->once())
      ->method('search')
      ->with('pothole', $this->callback(function (array $options): bool {
        $this->assertSame(['title', 'body', 'request_id'], $options['attributesToSearchOn']);
        $this->assertSame('all', $options['matchingStrategy']);
        $this->assertSame(['search_api_id'], $options['attributesToRetrieve']);
        $this->assertSame(20, $options['limit']);
        return TRUE;
      }))
      ->willReturn($this->createMock(SearchResult::class));

    // Should a later backend pass its own matching strategy, it must not be
    // able to widen the restriction.
    $backend_options = ['limit' => 20, 'matchingStrategy' => 'last'];
    $this->api($restriction, $meili_index)->search('service_requests', 'pothole', $backend_options);
  }

  /**
   * A search that bypassed the pre-execute event never reaches Meilisearch.
   *
   * @covers \Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService::search
   */
  public function testUnrecordedSearchIsRefused(): void {
    $meili_index = $this->createMock(Indexes::class);
    $meili_index->expects($this->never())->method('search');
    $this->expectException(SearchApiException::class);
    $this->api(new SearchRestriction(), $meili_index)->search('service_requests', 'pothole', ['limit' => 20]);
  }

  /**
   * Without confirmed index settings the search is refused.
   *
   * @covers \Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService::search
   */
  public function testUnconfirmedSettingsRefuseTheSearch(): void {
    $restriction = new SearchRestriction();
    $restriction->record($this->query(['title']));
    $meili_index = $this->createMock(Indexes::class);
    $meili_index->expects($this->never())->method('search');
    $this->expectException(SearchApiException::class);
    $this->api($restriction, $meili_index, FALSE)->search('service_requests', 'pothole');
  }

  /**
   * Facet searches bypass the restriction and are disabled.
   *
   * @covers \Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService::searchFacets
   */
  public function testFacetSearchIsDisabled(): void {
    $this->expectException(SearchApiException::class);
    $this->api(new SearchRestriction(), $this->createMock(Indexes::class))->searchFacets('service_requests', 'field_category', NULL, NULL, 'someone@example.org');
  }

}
