<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\markaspot_nuxt\Resource\ServiceRequestVersionHistory;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the service request version-history JSON:API resource.
 *
 * Unit-level: the revision enumeration, ordering, attribute mapping and
 * truncation are exercised through buildRevisionItems() with mocked entity
 * storage. Route-level concerns (staff-only permission gate, entity param) are
 * asserted against the shipped routing definition.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Resource\ServiceRequestVersionHistory
 */
class ServiceRequestVersionHistoryTest extends UnitTestCase {

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The node storage mock.
   *
   * @var \Drupal\node\NodeStorageInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * The date formatter mock.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $dateFormatter;

  /**
   * The logger mock.
   *
   * @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->nodeStorage = $this->createMock(NodeStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($this->nodeStorage);

    // The resource enumerates revisions through an allRevisions() entity query
    // and reads the node entity type's id / revision keys.
    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('getKey')->willReturnMap([
      ['id', 'nid'],
      ['revision', 'vid'],
    ]);
    $this->entityTypeManager->method('getDefinition')->with('node')->willReturn($entity_type);

    $this->dateFormatter = $this->createMock(DateFormatterInterface::class);
    // Render any timestamp deterministically as an ISO 8601 string so the test
    // can assert the value without depending on the real DateFormatter.
    $this->dateFormatter->method('format')->willReturnCallback(
      static fn(int $timestamp): string => gmdate(\DateTime::ATOM, $timestamp)
    );

    $this->logger = $this->createMock(LoggerInterface::class);
  }

  /**
   * Builds the resource under test with the mocked collaborators.
   */
  protected function resource(): TestableServiceRequestVersionHistory {
    return new TestableServiceRequestVersionHistory(
      $this->entityTypeManager,
      $this->createMock(AccountInterface::class),
      $this->dateFormatter,
      $this->logger
    );
  }

  /**
   * Creates a revision mock with the given metadata.
   */
  protected function revision(int $vid, ?UserInterface $author, int $author_uid, ?int $created, string $log): NodeInterface {
    $revision = $this->createMock(NodeInterface::class);
    $revision->method('getRevisionUser')->willReturn($author);
    $revision->method('getRevisionUserId')->willReturn($author_uid);
    $revision->method('getRevisionCreationTime')->willReturn($created);
    $revision->method('getRevisionLogMessage')->willReturn($log);
    return $revision;
  }

  /**
   * Creates a user mock returning the given label.
   */
  protected function user(string $label): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('label')->willReturn($label);
    return $user;
  }

  /**
   * Stubs the allRevisions() entity query to return the given vids.
   *
   * The query result is keyed by revision id (vid) with the entity id as the
   * value, exactly like a real allRevisions() result.
   *
   * @param int[] $vids
   *   The revision ids, in the order the (sorted) query would return them.
   */
  protected function stubRevisionQuery(array $vids): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('allRevisions')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    // [vid => entity_id]; entity id is irrelevant to the resource.
    $query->method('execute')->willReturn(array_fill_keys($vids, 1));
    $this->nodeStorage->method('getQuery')->willReturn($query);
  }

  /**
   * Reads an attribute off a JSON:API resource object.
   *
   * @return mixed
   *   The attribute value.
   */
  protected function attr(ResourceObject $object, string $name) {
    return $object->getField($name);
  }

  /**
   * Tests revisions are returned oldest -> newest with documented attributes.
   *
   * @covers ::buildRevisionItems
   */
  public function testRevisionsSortedOldestToNewestWithAttributes(): void {
    $editor = $this->user('Editor Eve');

    // The allRevisions() query sorts ASC on the revision id, so it returns
    // oldest -> newest. The resource preserves that order.
    $this->stubRevisionQuery([1, 2, 3]);
    $this->nodeStorage->method('loadRevision')->willReturnMap([
      [1, $this->revision(1, NULL, 0, 1000, '')],
      [2, $this->revision(2, $editor, 7, 2000, 'Status changed to in progress')],
      [3, $this->revision(3, $editor, 7, 3000, 'Closed')],
    ]);

    $entity = $this->createMock(NodeInterface::class);
    $entity->method('bundle')->willReturn('service_request');
    $entity->method('getRevisionId')->willReturn(3);

    $built = $this->resource()->callBuildRevisionItems($entity);
    $items = $built['items'];

    $this->assertFalse($built['truncated']);
    $this->assertCount(3, $items);

    // Oldest first.
    $this->assertSame([1, 2, 3], array_map(fn(ResourceObject $o) => $this->attr($o, 'vid'), $items));

    // First (oldest) revision: anonymous/system author, empty log.
    $first = $items[0];
    $this->assertSame('1', $first->getId());
    $this->assertSame(1, $this->attr($first, 'vid'));
    $this->assertNull($this->attr($first, 'author'));
    $this->assertSame(0, $this->attr($first, 'author_uid'));
    $this->assertSame(gmdate(\DateTime::ATOM, 1000), $this->attr($first, 'timestamp'));
    $this->assertSame('', $this->attr($first, 'log_message'));
    $this->assertFalse($this->attr($first, 'is_current'));

    // Middle revision: named author, log message.
    $second = $items[1];
    $this->assertSame('Editor Eve', $this->attr($second, 'author'));
    $this->assertSame(7, $this->attr($second, 'author_uid'));
    $this->assertSame('Status changed to in progress', $this->attr($second, 'log_message'));
    $this->assertFalse($this->attr($second, 'is_current'));

    // Newest revision is flagged current.
    $third = $items[2];
    $this->assertTrue($this->attr($third, 'is_current'));
    $this->assertSame('Closed', $this->attr($third, 'log_message'));
  }

  /**
   * Tests a deleted revision author yields a null author label, not a crash.
   *
   * @covers ::buildRevisionItems
   */
  public function testDeletedAuthorYieldsNull(): void {
    $this->stubRevisionQuery([1]);
    $this->nodeStorage->method('loadRevision')->willReturnMap([
      // Revision user entity deleted (null) but the uid is retained.
      [1, $this->revision(1, NULL, 42, 1000, 'edit')],
    ]);

    $entity = $this->createMock(NodeInterface::class);
    $entity->method('bundle')->willReturn('service_request');
    $entity->method('getRevisionId')->willReturn(1);

    $items = $this->resource()->callBuildRevisionItems($entity)['items'];

    $this->assertNull($this->attr($items[0], 'author'));
    $this->assertSame(42, $this->attr($items[0], 'author_uid'));
    $this->assertTrue($this->attr($items[0], 'is_current'));
  }

  /**
   * Tests the collection caps at 50 most-recent revisions and flags truncation.
   *
   * @covers ::buildRevisionItems
   */
  public function testTruncationCapsAtFiftyMostRecent(): void {
    // 60 revisions: vids 1..60, oldest -> newest from the ASC-sorted query.
    $this->stubRevisionQuery(range(1, 60));
    $this->nodeStorage->method('loadRevision')->willReturnCallback(
      fn(int $vid) => $this->revision($vid, NULL, 0, 1000 + $vid, '')
    );

    $entity = $this->createMock(NodeInterface::class);
    $entity->method('bundle')->willReturn('service_request');
    $entity->method('getRevisionId')->willReturn(60);

    // The truncation event must be logged, never silent.
    $this->logger->expects($this->once())->method('info');

    $built = $this->resource()->callBuildRevisionItems($entity);
    $items = $built['items'];

    $this->assertTrue($built['truncated']);
    $this->assertCount(50, $items);
    // The 50 most-recent, still oldest -> newest: 11..60.
    $this->assertSame(11, $this->attr($items[0], 'vid'));
    $this->assertSame(60, $this->attr($items[49], 'vid'));
    $this->assertTrue($this->attr($items[49], 'is_current'));
  }

  /**
   * Tests a non-service_request node is rejected with 403, not served.
   *
   * @covers ::process
   */
  public function testNonServiceRequestBundleIsDenied(): void {
    $entity = $this->createMock(NodeInterface::class);
    $entity->method('bundle')->willReturn('page');

    $request = $this->createMock(Request::class);

    $this->expectException(AccessDeniedHttpException::class);
    $this->resource()->process($request, $entity);
  }

  /**
   * Tests the route is staff-only and resolves the node entity param.
   *
   * Anonymous and reporter accounts cannot pass the permission gate; this
   * asserts the declarative requirements that enforce it.
   */
  public function testRouteIsStaffGatedAndResolvesNode(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $routing = Yaml::decode(file_get_contents($moduleRoot . '/markaspot_nuxt.routing.yml'));

    $route = $routing['markaspot_nuxt.service_request_version_history'] ?? NULL;
    $this->assertIsArray($route, 'The version-history route is defined.');

    // Served by the custom jsonapi_resources plugin.
    $this->assertSame(
      'Drupal\markaspot_nuxt\Resource\ServiceRequestVersionHistory',
      $route['defaults']['_jsonapi_resource']
    );

    // JSON:API path with the entity (uuid) param.
    $this->assertSame(
      '/%jsonapi%/node/service_request/{entity}/version-history',
      $route['path']
    );
    $this->assertSame('entity:node', $route['options']['parameters']['entity']['type']);

    // resource_type makes core's JSON:API UUID param converter apply, so the
    // {entity} segment resolves by UUID (the canonical JSON:API identifier).
    $this->assertSame('node--service_request', $route['defaults']['resource_type']);

    // Staff-only: revision permission OR service_request editor permission
    // ('+' is OR in Drupal's _permission requirement). Author identity is never
    // exposed to anonymous / reporter accounts.
    $this->assertSame(
      'view all revisions+edit any service_request content',
      $route['requirements']['_permission']
    );
    // The caller must also be able to view the underlying node.
    $this->assertSame('entity.view', $route['requirements']['_entity_access']);
  }

  /**
   * Tests fresh installs enable jsonapi_resources and ship the backfill hook.
   */
  public function testJsonapiResourcesIsWiredForFreshAndExistingInstalls(): void {
    $moduleRoot = dirname(__DIR__, 3);

    // Module declares the contrib dependency.
    $info = Yaml::decode(file_get_contents($moduleRoot . '/markaspot_nuxt.info.yml'));
    $this->assertContains('jsonapi_resources:jsonapi_resources', $info['dependencies']);

    // Existing tenants get an idempotent enable hook.
    $install = file_get_contents($moduleRoot . '/markaspot_nuxt.install');
    $this->assertStringContainsString('function markaspot_nuxt_update_11905(): string', $install);
    $this->assertStringContainsString("moduleExists('jsonapi_resources')", $install);
    $this->assertStringContainsString("install(['jsonapi_resources'])", $install);
  }

}

/**
 * Test-only subclass exposing the protected revision builder.
 */
class TestableServiceRequestVersionHistory extends ServiceRequestVersionHistory {

  /**
   * Public proxy for buildRevisionItems().
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The node.
   *
   * @return array{items: \Drupal\jsonapi\JsonApiResource\ResourceObject[], truncated: bool}
   *   The built items and the truncation flag.
   */
  public function callBuildRevisionItems(NodeInterface $entity): array {
    return $this->buildRevisionItems($entity);
  }

}
