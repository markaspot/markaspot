<?php

namespace Drupal\Tests\markaspot_search_meilisearch\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService;
use Drupal\markaspot_search_meilisearch\Client\TimedMeilisearchClientFactory;
use Drupal\markaspot_search_meilisearch\EventSubscriber\SearchRestrictionSubscriber;
use Drupal\markaspot_search_meilisearch\IndexSettings;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_meilisearch\Api\MeilisearchApiException;

/**
 * Proves the search hardening is wired into a real Search API query.
 *
 * The unit tests assemble the pieces by hand; the protection itself depends
 * on the container: the swapped API service class, the pre-execute
 * subscriber, the fail-closed condition parser and the index hooks. The
 * Meilisearch server points at a closed port. A search the hardening lets
 * through fails at the network: the contrib API wraps that error in a
 * MeilisearchApiException, which the backend reports through the messenger
 * and rethrows. A refusal is thrown by the hardening itself, before the
 * backend's error handling, so it has no previous exception and leaves the
 * messenger empty.
 *
 * @group markaspot_search_meilisearch
 */
class SearchRestrictionWiringKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'entity_test',
    'search_api',
    'search_api_test_example_content',
    'search_api_meilisearch',
    'markaspot_search_meilisearch',
  ];

  /**
   * The Meilisearch-backed test index.
   */
  protected IndexInterface $index;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The subscriber takes the field rule from markaspot_open311, which is not
    // installed here (its dependencies are heavy); its classes are enough. The
    // path comes from this file, not from module discovery, which drops
    // profile modules after a container rebuild in kernel tests.
    $this->classLoader->addPsr4('Drupal\\markaspot_open311\\', dirname(__DIR__, 4) . '/markaspot_open311/src');

    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('entity_test_mulrev_changed');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api', 'search_api_test_example_content']);

    Server::create([
      'id' => 'meilisearch',
      'name' => 'Meilisearch',
      'backend' => 'search_api_meilisearch',
      'backend_config' => [
        // A closed port on an IP literal: refused at once, no DNS.
        'meilisearch_host_address' => 'http://127.0.0.1',
        'meilisearch_host_port' => '1',
        'meilisearch_master_key' => 'kernel-test-key',
      ],
    ])->save();

    // Field IDs as on service_requests; the example entity supplies the text.
    $text = fn (string $property) => [
      'label' => $property,
      'datasource_id' => 'entity:entity_test_mulrev_changed',
      'property_path' => $property,
      'type' => 'text',
    ];
    $index = Index::create([
      'id' => 'service_requests',
      'name' => 'Service requests',
      'server' => 'meilisearch',
      'datasource_settings' => ['entity:entity_test_mulrev_changed' => []],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        'title' => $text('name'),
        'body' => $text('body'),
        'request_id' => $text('category'),
        'field_e_mail' => $text('keywords'),
      ],
      'options' => ['index_directly' => FALSE],
    ]);
    $index->save();
    $this->index = $index;

    // Index setup against the closed port reported errors; start clean.
    $this->container->get('messenger')->deleteAll();
  }

  /**
   * Marks the index settings as confirmed by Meilisearch.
   */
  protected function confirmSettings(bool $confirmed = TRUE): void {
    $this->container->get('state')->set(IndexSettings::STATE_KEY, ['service_requests' => $confirmed]);
  }

  /**
   * Runs a search and returns the exception it ends with.
   */
  protected function runSearch(QueryInterface $query): SearchApiException {
    try {
      $query->execute();
    }
    catch (SearchApiException $e) {
      return $e;
    }
    $this->fail('The search against a closed port did not fail.');
  }

  /**
   * Asserts the search was let through and failed at the network.
   */
  protected function assertReachedTheNetwork(SearchApiException $exception): void {
    $this->assertInstanceOf(MeilisearchApiException::class, $exception->getPrevious(), 'The search failed in the Meilisearch client.');
    $this->assertNotEmpty($this->container->get('messenger')->messagesByType('error'), 'The backend reported the network error.');
  }

  /**
   * Asserts the hardening refused the search before the backend saw an error.
   */
  protected function assertRefused(SearchApiException $exception, string $message): void {
    $this->assertStringContainsString($message, $exception->getMessage());
    $this->assertNull($exception->getPrevious(), 'The refusal did not come from the network.');
    // Refusals must not reach the messenger: for an anonymous caller that
    // would open a session.
    $this->assertSame([], $this->container->get('messenger')->all());
  }

  /**
   * Returns the restriction recorded for a search.
   *
   * Only readable because the search failed: after a successful search the
   * results event clears the entry.
   */
  protected function recordedAttributes(string $keys): array {
    return $this->container->get('markaspot_search_meilisearch.search_restriction')
      ->getOptions('service_requests', $keys)['attributesToSearchOn'];
  }

  /**
   * The container carries the hardened API service, factory and subscriber.
   */
  public function testContainerWiring(): void {
    $this->assertInstanceOf(RestrictedMeilisearchApiService::class, $this->container->get('search_api_meilisearch.api'));
    $this->assertInstanceOf(TimedMeilisearchClientFactory::class, $this->container->get('search_api_meilisearch.client_factory'));

    $listeners = $this->container->get('event_dispatcher')->getListeners(SearchApiEvents::QUERY_PRE_EXECUTE);
    $subscribed = array_filter($listeners, fn ($listener) => is_array($listener) && $listener[0] instanceof SearchRestrictionSubscriber);
    $this->assertCount(1, $subscribed);
  }

  /**
   * Saving the index runs the settings hook, which records the failure.
   */
  public function testIndexSaveRecordsUnconfirmedSettings(): void {
    $state = $this->container->get('state');
    // The insert hook ran against the closed port.
    $this->assertSame(['service_requests' => FALSE], $state->get(IndexSettings::STATE_KEY));

    // The update hook resets a confirmation it cannot renew.
    $this->confirmSettings();
    $this->index->save();
    $this->assertFalse(IndexSettings::isApplied($state, 'service_requests'));
  }

  /**
   * An anonymous query is narrowed to public fields and let through.
   */
  public function testAnonymousQueryIsNarrowedToPublicFields(): void {
    $this->confirmSettings();
    $query = $this->index->query()->keys('pothole');

    $this->assertReachedTheNetwork($this->runSearch($query));
    $this->assertSame(['title', 'body', 'request_id'], $query->getFulltextFields());
    $this->assertSame(['title', 'body', 'request_id'], $this->recordedAttributes('pothole'));
  }

  /**
   * Staff who may see e-mail addresses may search them.
   */
  public function testStaffWithEmailPermissionSearchesContactField(): void {
    $this->confirmSettings();
    $staff = $this->createMock(AccountInterface::class);
    $staff->method('hasPermission')->willReturnCallback(fn (string $permission) => $permission === 'view field_e_mail');
    $staff->method('id')->willReturn(7);
    $this->container->get('current_user')->setAccount($staff);
    $query = $this->index->query()->keys('pothole');

    $this->assertReachedTheNetwork($this->runSearch($query));
    $this->assertSame(['title', 'body', 'request_id', 'field_e_mail'], $query->getFulltextFields());
    $this->assertSame(['title', 'body', 'request_id', 'field_e_mail'], $this->recordedAttributes('pothole'));
  }

  /**
   * A contact-field-only search by an anonymous caller is refused.
   */
  public function testContactOnlySearchIsRefusedForAnonymous(): void {
    $this->confirmSettings();
    $query = $this->index->query()->keys('someone@example.org');
    $query->setFulltextFields(['field_e_mail']);

    $this->assertRefused($this->runSearch($query), 'The account may search none of the requested fields.');
    $this->expectException(SearchApiException::class);
    $this->recordedAttributes('someone@example.org');
  }

  /**
   * Without confirmed settings the search never leaves the API service.
   */
  public function testUnconfirmedSettingsRefuseTheSearch(): void {
    $this->confirmSettings(FALSE);

    $this->assertRefused($this->runSearch($this->index->query()->keys('pothole')), 'not confirmed');
  }

  /**
   * A query that skips the pre-execute event is refused, not run unrestricted.
   */
  public function testUnprocessedQueryIsRefused(): void {
    $this->confirmSettings();
    $query = $this->index->query()->keys('pothole');
    $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);

    $this->assertRefused($this->runSearch($query), 'No search restriction recorded');
  }

  /**
   * A condition no contributed parser translates aborts the search.
   *
   * The contributed parser would drop an empty IN list and widen the result.
   */
  public function testUntranslatableConditionIsRefused(): void {
    $this->confirmSettings();
    $query = $this->index->query()->keys('pothole');
    $query->addCondition('request_id', [], 'IN');

    $this->assertRefused($this->runSearch($query), 'cannot be translated for Meilisearch');
  }

  /**
   * Facet searches bypass the restriction and are disabled.
   */
  public function testFacetSearchIsRefused(): void {
    $this->expectException(SearchApiException::class);
    $this->expectExceptionMessage('facet search is disabled');
    $this->container->get('search_api_meilisearch.api')->searchFacets('service_requests', 'request_id', NULL, NULL, 'someone@example.org');
  }

}
