<?php

namespace Drupal\Tests\markaspot_search_meilisearch\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_search_meilisearch\EventSubscriber\SearchRestrictionSubscriber;
use Drupal\markaspot_search_meilisearch\SearchRestriction;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\ServerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that every query on a Meilisearch index is narrowed to the account.
 *
 * @group markaspot_search_meilisearch
 * @coversDefaultClass \Drupal\markaspot_search_meilisearch\EventSubscriber\SearchRestrictionSubscriber
 */
class SearchRestrictionSubscriberTest extends UnitTestCase {

  /**
   * Fields the query ends up with, captured from setFulltextFields().
   *
   * @var string[]|null
   */
  protected ?array $resolved = NULL;

  /**
   * Returns a query mock on the service_requests index.
   */
  protected function query(?array $requested, string $backend = 'search_api_meilisearch'): QueryInterface {
    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackendId')->willReturn($backend);
    $fields = [];
    foreach (['title', 'body', 'request_id', 'field_e_mail', 'address_line1', 'postal_code'] as $id) {
      $field = $this->createMock(FieldInterface::class);
      $field->method('getType')->willReturn('text');
      $fields[$id] = $field;
    }
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('service_requests');
    $index->method('getServerInstanceIfAvailable')->willReturn($server);
    $index->method('getFields')->willReturn($fields);

    $query = $this->createMock(QueryInterface::class);
    $query->method('getIndex')->willReturn($index);
    $query->method('getOriginalKeys')->willReturn('pothole');
    $query->method('getFulltextFields')->willReturnCallback(fn () => $this->resolved ?? $requested);
    $query->method('setFulltextFields')->willReturnCallback(function (?array $fields) use ($query) {
      $this->resolved = $fields;
      return $query;
    });
    return $query;
  }

  /**
   * Returns an account with the given permissions.
   */
  protected function account(array $permissions): AccountProxyInterface {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('hasPermission')->willReturnCallback(fn (string $permission) => in_array($permission, $permissions, TRUE));
    return $account;
  }

  /**
   * Runs the subscriber and returns the recorded search attributes.
   */
  protected function resolveFields(?array $requested, array $permissions): array {
    $this->resolved = NULL;
    $restriction = new SearchRestriction();
    $query = $this->query($requested);
    (new SearchRestrictionSubscriber($restriction, $this->account($permissions)))->onQueryPreExecute(new QueryPreExecuteEvent($query));
    return $restriction->getOptions('service_requests', 'pothole')['attributesToSearchOn'];
  }

  /**
   * All fields, the management view's default, become the account's set.
   *
   * @covers ::onQueryPreExecute
   */
  public function testAllFieldsBecomeTheAccountsFields(): void {
    $public = ['title', 'body', 'request_id'];
    $this->assertSame($public, $this->resolveFields(NULL, []));
    $this->assertSame([...$public, 'field_e_mail'], $this->resolveFields(NULL, ['view field_e_mail']));
    $this->assertSame(
      [...$public, 'field_e_mail', 'address_line1', 'postal_code'],
      $this->resolveFields(NULL, ['view field_e_mail', 'view field_address']),
    );
  }

  /**
   * A requested contact field is dropped for an account that may not see it.
   *
   * @covers ::onQueryPreExecute
   */
  public function testRequestedFieldsAreNarrowed(): void {
    $this->assertSame(['title'], $this->resolveFields(['title', 'field_e_mail'], []));
  }

  /**
   * A search on contact fields only is refused for such an account.
   *
   * @covers ::onQueryPreExecute
   */
  public function testContactOnlySearchIsRefused(): void {
    $this->expectException(SearchApiException::class);
    $this->resolveFields(['field_e_mail'], []);
  }

  /**
   * Indexes on other backends are left alone.
   *
   * @covers ::onQueryPreExecute
   */
  public function testOtherBackendsAreUntouched(): void {
    $this->resolved = NULL;
    $query = $this->query(NULL, 'search_api_db');
    (new SearchRestrictionSubscriber(new SearchRestriction(), $this->account([])))->onQueryPreExecute(new QueryPreExecuteEvent($query));
    $this->assertNull($this->resolved);
  }

}
