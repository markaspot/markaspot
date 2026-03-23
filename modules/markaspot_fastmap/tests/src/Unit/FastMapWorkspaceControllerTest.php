<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the FastMapWorkspaceController.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController
 * @group markaspot_fastmap
 */
class FastMapWorkspaceControllerTest extends UnitTestCase {

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection $database;

  /**
   * The mocked provisioning service.
   *
   * @var \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected WorkspaceProvisioningServiceInterface $provisioning;

  /**
   * The mocked mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected MailManagerInterface $mailManager;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface $logger;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked key-value expirable factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected KeyValueExpirableFactoryInterface $keyValueExpirable;

  /**
   * The request stack used by verifyWorkspace().
   */
  protected RequestStack $requestStack;

  /**
   * Mutable workspace base URL for config callbacks.
   */
  protected ?string $workspaceBaseUrl = NULL;

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController
   */
  protected FastMapWorkspaceController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);
    // startTransaction() must return an object with rollBack() for the
    // transaction wrapper in verifyWorkspace().
    $transactionStub = new class {

      public function rollBack(): void {}

    };
    $this->database->method('startTransaction')->willReturn($transactionStub);
    $this->provisioning = $this->createMock(WorkspaceProvisioningServiceInterface::class);
    $this->mailManager = $this->createMock(MailManagerInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->requestStack = new RequestStack();

    // Build FastMap config.
    $fastmapConfig = $this->createMock(ImmutableConfig::class);
    $fastmapConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'service_key' => 'test-service-key-123',
        'verify_base_url' => 'https://example.com',
        'cleanup_days' => 7,
        'mail_from' => 'noreply@example.com',
        'workspace_base_url' => $this->workspaceBaseUrl,
        default => NULL,
      });

    // Build system.site config.
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'name' => 'FastMap Test',
        default => NULL,
      });

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_fastmap.settings' => $fastmapConfig,
        'system.site' => $siteConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    // Storage mocks for group (slug uniqueness) and user (claim token).
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('loadByProperties')->willReturn([]);
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $groupStorage,
        'user' => $userStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    // Mock keyvalue.expirable: returns a store that silently ignores writes.
    $kvStore = $this->createMock(KeyValueStoreExpirableInterface::class);
    $kvStore->method('setWithExpire')->willReturn(NULL);
    $this->keyValueExpirable = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $this->keyValueExpirable->method('get')->willReturn($kvStore);

    // Set up the Drupal container so ControllerBase::config() works.
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('database', $this->database);
    $container->set('markaspot_fastmap.workspace_provisioning', $this->provisioning);
    $container->set('plugin.manager.mail', $this->mailManager);
    $container->set('logger.channel.markaspot_fastmap', $this->logger);
    $container->set('keyvalue.expirable', $this->keyValueExpirable);
    $container->set('request_stack', $this->requestStack);
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    $container->set('flood', $flood);
    \Drupal::setContainer($container);

    $this->controller = FastMapWorkspaceController::create($container);
  }

  /**
   * Creates a request with JSON body.
   *
   * @param array $data
   *   The request body data.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request object.
   */
  protected function createJsonRequest(array $data): Request {
    return Request::create(
      '/api/fastmap/create-workspace',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($data)
    );
  }

  /**
   * Pushes the current request for controller methods that inspect headers.
   */
  protected function pushCurrentRequest(array $server = []): void {
    while ($this->requestStack->getCurrentRequest()) {
      $this->requestStack->pop();
    }

    $this->requestStack->push(Request::create('/api/fastmap/verify/valid-token', 'GET', [], [], [], $server));
  }

  /**
   * Returns valid workspace creation data.
   *
   * @param array $overrides
   *   Optional overrides.
   *
   * @return array
   *   Valid request data.
   */
  protected function validRequestData(array $overrides = []): array {
    return array_merge([
      'service_key' => 'test-service-key-123',
      'name' => 'Test Workspace',
      'slug' => 'test-ws',
      'email' => 'user@example.com',
      'categories' => ['Road Damage', 'Flood'],
      'lat' => 50.9,
      'lng' => 6.9,
      'zoom' => 14,
      'template' => 'civic-report',
    ], $overrides);
  }

  /**
   * Sets up database mocks for successful pending row insertion.
   */
  protected function setupSuccessfulPending(): void {
    // No existing pending slug.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willReturn('1');

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $this->database->method('select')
      ->willReturn($select);
    $this->database->method('insert')
      ->willReturn($insert);
    $this->database->method('delete')
      ->willReturn($delete);
  }

  /**
   * Tests that invalid JSON body returns 400.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceInvalidJson(): void {
    $request = Request::create(
      '/api/fastmap/create-workspace',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      'not-json'
    );

    $response = $this->controller->createWorkspace($request);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(400, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid JSON body', $data['error']);
  }

  /**
   * Tests that missing service key returns 403.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceMissingServiceKey(): void {
    $request = $this->createJsonRequest($this->validRequestData([
      'service_key' => NULL,
    ]));

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid API key', $data['error']);
  }

  /**
   * Tests that wrong service key returns 403.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceWrongServiceKey(): void {
    $request = $this->createJsonRequest($this->validRequestData([
      'service_key' => 'wrong-key',
    ]));

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid API key', $data['error']);
  }

  /**
   * Tests that missing name returns 400.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceMissingName(): void {
    $request = $this->createJsonRequest($this->validRequestData([
      'name' => '',
    ]));

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('name and slug are required', $data['error']);
  }

  /**
   * Tests that missing slug returns 400.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceMissingSlug(): void {
    $request = $this->createJsonRequest($this->validRequestData([
      'slug' => '',
    ]));

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('name and slug are required', $data['error']);
  }

  /**
   * Tests that invalid email returns 400.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceInvalidEmail(): void {
    $request = $this->createJsonRequest($this->validRequestData([
      'email' => 'not-an-email',
    ]));

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('A valid email address is required', $data['error']);
  }

  /**
   * Tests that missing email returns 400.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceMissingEmail(): void {
    $request = $this->createJsonRequest($this->validRequestData([
      'email' => '',
    ]));

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('A valid email address is required', $data['error']);
  }

  /**
   * Tests that invalid slug format returns 400.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceInvalidSlugFormat(): void {
    $request = $this->createJsonRequest($this->validRequestData([
      'slug' => 'UPPERCASE',
    ]));

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('slug must be', $data['error']);
  }

  /**
   * Tests that empty categories returns 400.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceEmptyCategories(): void {
    $request = $this->createJsonRequest($this->validRequestData([
      'categories' => [],
    ]));

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('categories must be provided', $data['error']);
  }

  /**
   * Tests that duplicate slug returns 409.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceDuplicateSlug(): void {
    // Override group storage to return an existing group.
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('loadByProperties')
      ->willReturn([$this->createMock(GroupInterface::class)]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $container = \Drupal::getContainer();
    $container->set('entity_type.manager', $entityTypeManager);
    $this->controller = FastMapWorkspaceController::create($container);

    $request = $this->createJsonRequest($this->validRequestData());

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Slug already taken', $data['error']);
  }

  /**
   * Tests that pending slug returns 409.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspacePendingSlug(): void {
    // Pending slug exists.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn('1');

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $request = $this->createJsonRequest($this->validRequestData());

    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('pending request', $data['error']);
  }

  /**
   * Tests successful workspace creation returns 202 pending.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceSuccess(): void {
    $this->setupSuccessfulPending();

    // Mail sends successfully.
    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

    $request = $this->createJsonRequest($this->validRequestData());
    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(202, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('test-ws', $data['slug']);
    $this->assertEquals('Test Workspace', $data['name']);
    $this->assertEquals('pending', $data['status']);
    $this->assertArrayHasKey('message', $data);
  }

  /**
   * Tests that failed email send returns 503 and cleans up pending row.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceEmailFailure(): void {
    $this->setupSuccessfulPending();

    // Mail fails.
    $this->mailManager->method('mail')->willReturn(['result' => FALSE]);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Verification email failed'),
        $this->anything()
      );

    $request = $this->createJsonRequest($this->validRequestData());
    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(503, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('verification email', $data['error']);
  }

  /**
   * Tests that database insert failure returns 500.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceDbInsertFailure(): void {
    // No pending slug.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    // Insert throws.
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')
      ->willThrowException(new \Exception('DB write failed'));
    $this->database->method('insert')->willReturn($insert);

    $request = $this->createJsonRequest($this->validRequestData());
    $response = $this->controller->createWorkspace($request);

    $this->assertEquals(500, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('Failed to create pending', $data['error']);
  }

  /**
   * Tests that service key from query parameter is rejected.
   *
   * Query parameter authentication was removed for security reasons.
   * Only X-Service-Key header and JSON body are accepted.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceServiceKeyFromQueryRejected(): void {
    $bodyData = $this->validRequestData();
    unset($bodyData['service_key']);

    $request = Request::create(
      '/api/fastmap/create-workspace?service_key=test-service-key-123',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($bodyData)
    );

    $response = $this->controller->createWorkspace($request);
    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests verify with invalid token returns 404.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyWorkspaceInvalidToken(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $response = $this->controller->verifyWorkspace('invalid-token');

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(404, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('Invalid or expired', $data['error']);
  }

  /**
   * Tests verify with expired token returns 410.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyWorkspaceExpiredToken(): void {
    // Token created 8 days ago (beyond 7-day default).
    $record = [
      'id' => 1,
      'token' => 'valid-token',
      'email' => 'user@example.com',
      'workspace_data' => json_encode(['name' => 'Test']),
      'created' => time() - (8 * 86400),
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($record);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);

    $this->database->method('select')->willReturn($select);
    $this->database->method('delete')->willReturn($delete);

    $response = $this->controller->verifyWorkspace('valid-token');

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(410, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('expired', $data['error']);
  }

  /**
   * Tests successful verification returns 201 with provisioned data.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyWorkspaceSuccess(): void {
    $workspaceData = [
      'name' => 'Test Workspace',
      'slug' => 'test-ws',
      'email' => 'user@example.com',
      'categories' => ['Cat A'],
    ];

    $record = [
      'id' => 1,
      'token' => 'valid-token',
      'email' => 'user@example.com',
      'workspace_data' => json_encode($workspaceData),
      'created' => time() - 3600,
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($record);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);

    // Mock the merge query for the verified record.
    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $merge->method('execute')->willReturn(Merge::STATUS_INSERT);

    $this->database->method('select')->willReturn($select);
    $this->database->method('delete')->willReturn($delete);
    $this->database->method('merge')->willReturn($merge);

    $this->provisioning->method('provisionWorkspace')
      ->with($workspaceData)
      ->willReturn([
        'group_id' => 42,
        'slug' => 'test-ws',
        'name' => 'Test Workspace',
        'url' => '/test-ws',
        'categories' => 1,
        'user_id' => 7,
      ]);

    $response = $this->controller->verifyWorkspace('valid-token');

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(201, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(42, $data['id']);
    $this->assertEquals('test-ws', $data['slug']);
    $this->assertEquals('Test Workspace', $data['name']);
    $this->assertEquals('/test-ws', $data['url']);
    $this->assertEquals('provisioned', $data['status']);
    // A login_token should be present when provisioning succeeds.
    $this->assertArrayHasKey('login_token', $data);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['login_token']);
  }

  /**
   * Tests JSON verify mode when workspace_base_url is configured.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyWorkspacePrefersJsonWhenRequested(): void {
    $this->workspaceBaseUrl = 'https://frontend.example/{slug}/dashboard';
    $this->pushCurrentRequest([
      'HTTP_ACCEPT' => 'application/json',
      'HTTP_X_FASTMAP_RESPONSE_MODE' => 'json',
    ]);

    $workspaceData = [
      'name' => 'JSON Workspace',
      'slug' => 'json-ws',
      'email' => 'user@example.com',
      'categories' => ['Cat A'],
    ];

    $record = [
      'id' => 1,
      'token' => 'valid-token',
      'email' => 'user@example.com',
      'workspace_data' => json_encode($workspaceData),
      'created' => time() - 3600,
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($record);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);

    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $merge->method('execute')->willReturn(Merge::STATUS_INSERT);

    $this->database->method('select')->willReturn($select);
    $this->database->method('delete')->willReturn($delete);
    $this->database->method('merge')->willReturn($merge);

    $this->provisioning->method('provisionWorkspace')
      ->willReturn([
        'group_id' => 42,
        'slug' => 'json-ws',
        'name' => 'JSON Workspace',
        'url' => '/json-ws',
        'categories' => 1,
        'user_id' => 7,
      ]);

    $response = $this->controller->verifyWorkspace('valid-token');

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(201, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('json-ws', $data['slug']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['login_token']);
  }

  /**
   * Tests the legacy redirect path still exposes the login token header.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyWorkspaceRedirectIncludesLoginTokenHeader(): void {
    $this->workspaceBaseUrl = 'https://frontend.example/{slug}/dashboard';
    $this->pushCurrentRequest(['HTTP_ACCEPT' => 'text/html']);

    $workspaceData = [
      'name' => 'Redirect Workspace',
      'slug' => 'redirect-ws',
      'email' => 'user@example.com',
      'categories' => ['Cat A'],
    ];

    $record = [
      'id' => 1,
      'token' => 'valid-token',
      'email' => 'user@example.com',
      'workspace_data' => json_encode($workspaceData),
      'created' => time() - 3600,
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($record);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);

    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $merge->method('execute')->willReturn(Merge::STATUS_INSERT);

    $this->database->method('select')->willReturn($select);
    $this->database->method('delete')->willReturn($delete);
    $this->database->method('merge')->willReturn($merge);

    $this->provisioning->method('provisionWorkspace')
      ->willReturn([
        'group_id' => 42,
        'slug' => 'redirect-ws',
        'name' => 'Redirect Workspace',
        'url' => '/redirect-ws',
        'categories' => 1,
        'user_id' => 7,
      ]);

    $response = $this->controller->verifyWorkspace('valid-token');

    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertStringContainsString('/redirect-ws/dashboard', $response->headers->get('location'));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $response->headers->get('X-Login-Token'));
  }

  /**
   * Tests that a verified token can return a fresh login token in JSON mode.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyWorkspaceAlreadyVerifiedJsonResponseIncludesLoginToken(): void {
    $this->workspaceBaseUrl = 'https://frontend.example/{slug}/dashboard';
    $this->pushCurrentRequest([
      'HTTP_ACCEPT' => 'application/json',
      'HTTP_X_FASTMAP_RESPONSE_MODE' => 'json',
    ]);

    $pendingStatement = $this->createMock(StatementInterface::class);
    $pendingStatement->method('fetchAssoc')->willReturn(FALSE);

    $verifiedStatement = $this->createMock(StatementInterface::class);
    $verifiedStatement->method('fetchField')->willReturn('verified-ws');

    $pendingSelect = $this->createMock(SelectInterface::class);
    $pendingSelect->method('fields')->willReturnSelf();
    $pendingSelect->method('condition')->willReturnSelf();
    $pendingSelect->method('range')->willReturnSelf();
    $pendingSelect->method('execute')->willReturn($pendingStatement);

    $verifiedSelect = $this->createMock(SelectInterface::class);
    $verifiedSelect->method('fields')->willReturnSelf();
    $verifiedSelect->method('condition')->willReturnSelf();
    $verifiedSelect->method('range')->willReturnSelf();
    $verifiedSelect->method('execute')->willReturn($verifiedStatement);

    $this->database->method('select')
      ->willReturnOnConsecutiveCalls($pendingSelect, $verifiedSelect);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(99);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('loadByProperties')
      ->with(['field_slug' => 'verified-ws'])
      ->willReturn([$group]);

    $membership = new class {
      public function hasField(string $fieldName): bool {
        return in_array($fieldName, ['group_roles', 'entity_id'], TRUE);
      }
      public function get(string $fieldName): object {
        return match ($fieldName) {
          'group_roles' => new class {
            public function getValue(): array {
              return [['target_id' => 'jur-tenant_admin']];
            }
          },
          'entity_id' => new class {
            public int $target_id = 123;
          },
        };
      }
    };

    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->method('loadByProperties')
      ->with([
        'gid' => 99,
        'plugin_id' => 'group_membership',
      ])
      ->willReturn([$membership]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $groupStorage,
        'group_relationship' => $relationshipStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $container = \Drupal::getContainer();
    $container->set('entity_type.manager', $entityTypeManager);

    $response = $this->controller->verifyWorkspace('valid-token');

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('verified-ws', $data['slug']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['login_token']);
  }

  /**
   * Tests verify with corrupted workspace data returns 500.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyWorkspaceCorruptedData(): void {
    $record = [
      'id' => 1,
      'token' => 'valid-token',
      'email' => 'user@example.com',
      'workspace_data' => 'not-valid-json{{{',
      'created' => time() - 3600,
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($record);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $response = $this->controller->verifyWorkspace('valid-token');

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(500, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('Corrupted', $data['error']);
  }

  /**
   * Tests verify with provisioning failure returns 409.
   *
   * @covers ::verifyWorkspace
   */
  public function testVerifyWorkspaceProvisioningFailure(): void {
    $workspaceData = [
      'name' => 'Test',
      'slug' => 'test-ws',
      'email' => 'user@example.com',
      'categories' => ['Cat A'],
    ];

    $record = [
      'id' => 1,
      'token' => 'valid-token',
      'email' => 'user@example.com',
      'workspace_data' => json_encode($workspaceData),
      'created' => time() - 3600,
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($record);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $this->provisioning->method('provisionWorkspace')
      ->willThrowException(new \RuntimeException('Slug already taken'));

    $response = $this->controller->verifyWorkspace('valid-token');

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(409, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Slug already taken', $data['error']);
  }

  // =========================================================================
  // claimLoginToken() tests
  // =========================================================================

  /**
   * Creates a POST request for the claim endpoint.
   */
  protected function createClaimRequest(array $body): Request {
    return Request::create(
      '/api/fastmap/claim-login-token',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($body)
    );
  }

  /**
   * Tests that an invalid token format returns 400.
   *
   * @covers ::claimLoginToken
   */
  public function testClaimLoginTokenInvalidFormat(): void {
    $request = $this->createClaimRequest(['token' => 'too-short']);
    $response = $this->controller->claimLoginToken($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid token format', $data['error']);
  }

  /**
   * Tests that an empty token returns 400.
   *
   * @covers ::claimLoginToken
   */
  public function testClaimLoginTokenEmpty(): void {
    $request = $this->createClaimRequest(['token' => '']);
    $response = $this->controller->claimLoginToken($request);

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests that an expired or missing token returns 401.
   *
   * @covers ::claimLoginToken
   */
  public function testClaimLoginTokenExpired(): void {
    // KV store returns NULL (token not found or expired).
    $kvStore = $this->createMock(KeyValueStoreExpirableInterface::class);
    $kvStore->method('get')->willReturn(NULL);

    $this->keyValueExpirable = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $this->keyValueExpirable->method('get')
      ->with('markaspot_fastmap_login_tokens')
      ->willReturn($kvStore);

    $container = \Drupal::getContainer();
    $container->set('keyvalue.expirable', $this->keyValueExpirable);
    $this->controller = FastMapWorkspaceController::create($container);

    $validToken = str_repeat('ab', 32);
    $request = $this->createClaimRequest(['token' => $validToken]);
    $response = $this->controller->claimLoginToken($request);

    $this->assertEquals(401, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('Invalid or expired', $data['error']);
  }

  /**
   * Tests single-use: second claim with same token fails.
   *
   * @covers ::claimLoginToken
   */
  public function testClaimLoginTokenSingleUse(): void {
    $validToken = str_repeat('cd', 32);
    $callCount = 0;

    // First call returns token data, second returns NULL (consumed).
    $kvStore = $this->createMock(KeyValueStoreExpirableInterface::class);
    $kvStore->method('get')
      ->with($validToken)
      ->willReturnCallback(function () use (&$callCount) {
        return $callCount++ === 0
          ? ['uid' => 99, 'slug' => 'test']
          : NULL;
      });
    $kvStore->expects($this->once())->method('delete')->with($validToken);

    $this->keyValueExpirable = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $this->keyValueExpirable->method('get')
      ->with('markaspot_fastmap_login_tokens')
      ->willReturn($kvStore);

    // User storage returns NULL (user not found) to stop early.
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(99)->willReturn(NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'user' => $userStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $container = \Drupal::getContainer();
    $container->set('keyvalue.expirable', $this->keyValueExpirable);
    $container->set('entity_type.manager', $entityTypeManager);
    $this->controller = FastMapWorkspaceController::create($container);

    // First call: token consumed, user not found -> 403.
    $request = $this->createClaimRequest(['token' => $validToken]);
    $response = $this->controller->claimLoginToken($request);
    $this->assertEquals(403, $response->getStatusCode());

    // Recreate controller for second call (simulates fresh request).
    $this->controller = FastMapWorkspaceController::create($container);
    $response2 = $this->controller->claimLoginToken($request);
    $this->assertEquals(401, $response2->getStatusCode());
  }

  // =========================================================================
  // Input validation tests (XSS, language allowlist, status_translations)
  // =========================================================================

  /**
   * Tests that Xss::filter() is applied to start_page.body.
   *
   * Script tags and other dangerous HTML should be stripped from the
   * start_page body before it is stored in the pending record.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceXssFilterOnStartPageBody(): void {
    $this->setupSuccessfulPending();
    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

    // Capture the inserted workspace_data JSON.
    $insertedData = NULL;
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$insertedData, $insert) {
        $insertedData = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    // We need to re-mock the database to capture insert data.
    // The setupSuccessfulPending() already set up select/delete, so we
    // override insert specifically.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $transactionStub = new class { public function rollBack(): void {} };
    $database->method('startTransaction')->willReturn($transactionStub);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturn($delete);

    $container = \Drupal::getContainer();
    $container->set('database', $database);
    $controller = FastMapWorkspaceController::create($container);

    $request = $this->createJsonRequest($this->validRequestData([
      'start_page' => [
        'title' => 'Welcome',
        'body' => '<p>Safe content</p><script>alert("xss")</script><img onerror="evil()">',
      ],
    ]));

    $response = $controller->createWorkspace($request);
    $this->assertEquals(202, $response->getStatusCode());

    // Verify the stored body has XSS stripped.
    $this->assertNotNull($insertedData);
    $stored = json_decode($insertedData['workspace_data'], TRUE);
    $this->assertArrayHasKey('start_page', $stored);
    $body = $stored['start_page']['body'];
    $this->assertStringNotContainsString('<script>', $body);
    $this->assertStringNotContainsString('onerror', $body);
    $this->assertStringContainsString('Safe content', $body);
  }

  /**
   * Tests that language allowlist rejects invalid language codes.
   *
   * The controller should silently drop status_translations and
   * start_page_translations for language codes not in ALLOWED_LANGS.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceLanguageAllowlistRejectsInvalidCodes(): void {
    $this->setupSuccessfulPending();
    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

    $insertedData = NULL;
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$insertedData, $insert) {
        $insertedData = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $transactionStub = new class { public function rollBack(): void {} };
    $database->method('startTransaction')->willReturn($transactionStub);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturn($delete);

    $container = \Drupal::getContainer();
    $container->set('database', $database);
    $controller = FastMapWorkspaceController::create($container);

    $request = $this->createJsonRequest($this->validRequestData([
      'status_translations' => [
        'de' => ['Offen', 'Geschlossen'],
        'xx-evil' => ['Hack', 'Attack'],
        'zh' => ['Open', 'Closed'],
      ],
      'start_page_translations' => [
        'de' => ['title' => 'Willkommen', 'body' => '<p>Hallo</p>'],
        'xx-evil' => ['title' => 'Hack', 'body' => '<p>Attack</p>'],
        'zh' => ['title' => 'Test', 'body' => '<p>Test</p>'],
      ],
    ]));

    $response = $controller->createWorkspace($request);
    $this->assertEquals(202, $response->getStatusCode());

    $stored = json_decode($insertedData['workspace_data'], TRUE);

    // status_translations: 'de' is valid, 'xx-evil' and 'zh' are not in allowlist.
    $statusTransLangs = array_keys($stored['status_translations'] ?? []);
    $this->assertContains('de', $statusTransLangs);
    $this->assertNotContains('xx-evil', $statusTransLangs);
    $this->assertNotContains('zh', $statusTransLangs);

    // start_page_translations: same filter.
    $pageTransLangs = array_keys($stored['start_page_translations'] ?? []);
    $this->assertContains('de', $pageTransLangs);
    $this->assertNotContains('xx-evil', $pageTransLangs);
    $this->assertNotContains('zh', $pageTransLangs);
  }

  /**
   * Tests that status_translations with non-array values are filtered.
   *
   * If a language key maps to a non-array value (e.g. a string or number),
   * it should be silently dropped during validation.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceStatusTranslationsNonArrayFiltered(): void {
    $this->setupSuccessfulPending();
    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

    $insertedData = NULL;
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$insertedData, $insert) {
        $insertedData = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $transactionStub = new class { public function rollBack(): void {} };
    $database->method('startTransaction')->willReturn($transactionStub);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturn($delete);

    $container = \Drupal::getContainer();
    $container->set('database', $database);
    $controller = FastMapWorkspaceController::create($container);

    $request = $this->createJsonRequest($this->validRequestData([
      'status_translations' => [
        'de' => ['Offen', 'Geschlossen'],
        'fr' => 'not-an-array',
        'es' => 42,
        'nl' => NULL,
      ],
    ]));

    $response = $controller->createWorkspace($request);
    $this->assertEquals(202, $response->getStatusCode());

    $stored = json_decode($insertedData['workspace_data'], TRUE);
    $statusTransLangs = array_keys($stored['status_translations'] ?? []);

    // Only 'de' should survive (the rest are non-array values).
    $this->assertContains('de', $statusTransLangs);
    $this->assertNotContains('fr', $statusTransLangs);
    $this->assertNotContains('es', $statusTransLangs);
    $this->assertNotContains('nl', $statusTransLangs);
  }

  /**
   * Tests that a blocked user returns 403.
   *
   * @covers ::claimLoginToken
   */
  public function testClaimLoginTokenBlockedUser(): void {
    $validToken = str_repeat('ef', 32);

    $kvStore = $this->createMock(KeyValueStoreExpirableInterface::class);
    $kvStore->method('get')->with($validToken)->willReturn(['uid' => 5, 'slug' => 'ws']);
    $kvStore->method('delete');

    $this->keyValueExpirable = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $this->keyValueExpirable->method('get')
      ->with('markaspot_fastmap_login_tokens')
      ->willReturn($kvStore);

    // User is blocked.
    $user = $this->createMock(\Drupal\user\UserInterface::class);
    $user->method('isBlocked')->willReturn(TRUE);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(5)->willReturn($user);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'user' => $userStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $container = \Drupal::getContainer();
    $container->set('keyvalue.expirable', $this->keyValueExpirable);
    $container->set('entity_type.manager', $entityTypeManager);
    $this->controller = FastMapWorkspaceController::create($container);

    $request = $this->createClaimRequest(['token' => $validToken]);
    $response = $this->controller->claimLoginToken($request);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('not available', $data['error']);
  }

  /**
   * Tests that ai_system_prompt is stored in workspace data when provided.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceAiSystemPromptStored(): void {
    $this->setupSuccessfulPending();
    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

    // Capture the inserted workspace_data JSON.
    $insertedData = NULL;
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$insertedData, $insert) {
        $insertedData = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $transactionStub = new class {

      public function rollBack(): void {}

    };
    $database->method('startTransaction')->willReturn($transactionStub);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturn($delete);

    $container = \Drupal::getContainer();
    $container->set('database', $database);
    $controller = FastMapWorkspaceController::create($container);

    $prompt = 'You analyze photos of trail conditions. Focus on erosion and fallen trees.';
    $request = $this->createJsonRequest($this->validRequestData([
      'ai_system_prompt' => $prompt,
    ]));

    $response = $controller->createWorkspace($request);
    $this->assertEquals(202, $response->getStatusCode());

    $this->assertNotNull($insertedData);
    $stored = json_decode($insertedData['workspace_data'], TRUE);
    $this->assertArrayHasKey('ai_system_prompt', $stored);
    $this->assertEquals($prompt, $stored['ai_system_prompt']);
  }

  /**
   * Tests that ai_system_prompt defaults to empty when not provided.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceAiSystemPromptDefaultEmpty(): void {
    $this->setupSuccessfulPending();
    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

    $insertedData = NULL;
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$insertedData, $insert) {
        $insertedData = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $transactionStub = new class {

      public function rollBack(): void {}

    };
    $database->method('startTransaction')->willReturn($transactionStub);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturn($delete);

    $container = \Drupal::getContainer();
    $container->set('database', $database);
    $controller = FastMapWorkspaceController::create($container);

    // No ai_system_prompt in request.
    $request = $this->createJsonRequest($this->validRequestData());

    $response = $controller->createWorkspace($request);
    $this->assertEquals(202, $response->getStatusCode());

    $this->assertNotNull($insertedData);
    $stored = json_decode($insertedData['workspace_data'], TRUE);
    $this->assertArrayHasKey('ai_system_prompt', $stored);
    $this->assertEmpty($stored['ai_system_prompt']);
  }

  /**
   * Tests that ai_system_prompt is truncated at 2000 characters.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceAiSystemPromptTruncated(): void {
    $this->setupSuccessfulPending();
    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

    $insertedData = NULL;
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$insertedData, $insert) {
        $insertedData = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('where')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $transactionStub = new class {

      public function rollBack(): void {}

    };
    $database->method('startTransaction')->willReturn($transactionStub);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('delete')->willReturn($delete);

    $container = \Drupal::getContainer();
    $container->set('database', $database);
    $controller = FastMapWorkspaceController::create($container);

    $longPrompt = str_repeat('A', 2500);
    $request = $this->createJsonRequest($this->validRequestData([
      'ai_system_prompt' => $longPrompt,
    ]));

    $response = $controller->createWorkspace($request);
    $this->assertEquals(202, $response->getStatusCode());

    $this->assertNotNull($insertedData);
    $stored = json_decode($insertedData['workspace_data'], TRUE);
    $this->assertEquals(2000, mb_strlen($stored['ai_system_prompt']));
  }

}
