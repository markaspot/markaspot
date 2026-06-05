<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\markaspot_nuxt\Resource\ServiceRequestVersionHistory;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the service request version-history JSON:API resource.
 *
 * Unit-level: the revision enumeration, ordering, attribute mapping and
 * truncation are exercised through buildRevisionItems() with a mocked
 * node_revision metadata query and a mocked user batch load. Route-level
 * concerns (staff-only permission gate, entity param) are asserted against the
 * shipped routing definition.
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
   * The user storage mock (for author label batch loads).
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $userStorage;

  /**
   * The database connection mock.
   *
   * @var \Drupal\Core\Database\Connection&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

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

    // Author labels are resolved through one user-storage batch load.
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->with('user')->willReturn($this->userStorage);

    // Revision metadata is read with one node_revision select.
    $this->database = $this->createMock(Connection::class);

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
      $this->logger,
      $this->database
    );
  }

  /**
   * Builds a node_revision metadata row as fetchAll() (FETCH_OBJ) returns it.
   */
  protected function row(int $vid, int $uid, ?int $timestamp, string $log): \stdClass {
    return (object) [
      'vid' => $vid,
      'revision_uid' => $uid,
      'revision_timestamp' => $timestamp,
      'revision_log' => $log,
    ];
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
   * Stubs the node_revision select to return the given metadata rows.
   *
   * The rows are returned in the order the (ASC vid) query yields them
   * (oldest -> newest), exactly like the real node_revision read.
   *
   * @param \stdClass[] $rows
   *   The revision metadata rows.
   */
  protected function stubRevisionRows(array $rows): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($rows);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);
  }

  /**
   * Stubs the user batch load to resolve the given uid -> user map.
   *
   * @param array<int, \Drupal\user\UserInterface> $users
   *   Keyed by uid; uids not present resolve to a null author label.
   */
  protected function stubUsers(array $users): void {
    $this->userStorage->method('loadMultiple')->willReturn($users);
  }

  /**
   * Mocks the default-revision node passed to buildRevisionItems().
   */
  protected function node(int $nid, int $current_vid, string $bundle = 'service_request'): NodeInterface {
    $entity = $this->createMock(NodeInterface::class);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('id')->willReturn($nid);
    $entity->method('getRevisionId')->willReturn($current_vid);
    return $entity;
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

    // node_revision is read ASC on vid, so rows arrive oldest -> newest.
    $this->stubRevisionRows([
      $this->row(1, 0, 1000, ''),
      $this->row(2, 7, 2000, 'Status changed to in progress'),
      $this->row(3, 7, 3000, 'Closed'),
    ]);
    // Distinct non-zero author uids ([7]) resolve in one batch load.
    $this->stubUsers([7 => $editor]);

    $built = $this->resource()->callBuildRevisionItems($this->node(1, 3));
    $items = $built['items'];

    $this->assertFalse($built['truncated']);
    $this->assertCount(3, $items);

    // Oldest first.
    $this->assertSame([1, 2, 3], array_map(fn(ResourceObject $o) => $this->attr($o, 'vid'), $items));

    // First (oldest) revision: anonymous/system author (uid 0), empty log.
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
    // Revision user uid retained, but the account was deleted so the batch
    // load returns no user for it.
    $this->stubRevisionRows([$this->row(1, 42, 1000, 'edit')]);
    $this->stubUsers([]);

    $items = $this->resource()->callBuildRevisionItems($this->node(1, 1))['items'];

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
    // 60 revisions: vids 1..60, oldest -> newest, all system-authored.
    $rows = array_map(fn(int $vid) => $this->row($vid, 0, 1000 + $vid, ''), range(1, 60));
    $this->stubRevisionRows($rows);

    // The truncation event must be logged, never silent.
    $this->logger->expects($this->once())->method('info');

    $built = $this->resource()->callBuildRevisionItems($this->node(1, 60));
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
