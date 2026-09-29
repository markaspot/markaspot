<?php

namespace Drupal\Tests\markaspot_search_meilisearch\Unit;

use Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService;
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
 * Tests that a query's field restriction reaches Meilisearch.
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
   * Returns a query mock on the service_requests index.
   */
  protected function query(?array $fulltext_fields): QueryInterface {
    $fields = [];
    foreach (self::FIELDS as $id => $type) {
      $field = $this->createMock(FieldInterface::class);
      $field->method('getType')->willReturn($type);
      $fields[$id] = $field;
    }
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('service_requests');
    $index->method('getFields')->willReturn($fields);

    $query = $this->createMock(QueryInterface::class);
    $query->method('getIndex')->willReturn($index);
    $query->method('getFulltextFields')->willReturn($fulltext_fields);
    return $query;
  }

  /**
   * Every word must match, as with the database backend.
   *
   * @covers ::buildOptions
   */
  public function testAllWordsMustMatch(): void {
    $this->assertSame(['matchingStrategy' => 'all'], SearchRestriction::buildOptions($this->query(NULL)));
  }

  /**
   * An anonymous search stays on title, body and request ID.
   *
   * @covers ::buildOptions
   */
  public function testFulltextFieldsBecomeAttributesToSearchOn(): void {
    $options = SearchRestriction::buildOptions($this->query(['title', 'body', 'request_id']));
    $this->assertSame(['title', 'body', 'request_id'], $options['attributesToSearchOn']);
  }

  /**
   * Fields that are not text fields are not searchable and are left out.
   *
   * @covers ::buildOptions
   */
  public function testNonTextFieldsAreDropped(): void {
    $options = SearchRestriction::buildOptions($this->query(['title', 'node_grants', 'unknown']));
    $this->assertSame(['title'], $options['attributesToSearchOn']);
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
   * The recorded restriction reaches the Meilisearch request and wins.
   *
   * @covers ::record
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
        $this->assertSame(20, $options['limit']);
        return TRUE;
      }))
      ->willReturn($this->createMock(SearchResult::class));

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
    $api->setRestriction($restriction);

    // The backend sets no matching strategy today; should a later version pass
    // one, it must not be able to widen the restriction.
    $api->search('service_requests', 'pothole', ['limit' => 20, 'matchingStrategy' => 'last']);
  }

  /**
   * Another index is not affected by a recorded restriction.
   *
   * @covers ::getOptions
   */
  public function testRestrictionIsPerIndex(): void {
    $restriction = new SearchRestriction();
    $restriction->record($this->query(['title']));
    $this->assertSame([], $restriction->getOptions('other_index'));
  }

}
