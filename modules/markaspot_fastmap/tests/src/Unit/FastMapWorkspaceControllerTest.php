<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Controller\FastMapWorkspaceController;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
    $this->provisioning = $this->createMock(WorkspaceProvisioningServiceInterface::class);
    $this->mailManager = $this->createMock(MailManagerInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    // Build FastMap config.
    $fastmapConfig = $this->createMock(ImmutableConfig::class);
    $fastmapConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'service_key' => 'test-service-key-123',
        'verify_base_url' => 'https://example.com',
        'cleanup_days' => 7,
        'mail_from' => 'noreply@example.com',
        'workspace_base_url' => NULL,
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

    // Group storage for slug uniqueness check.
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('loadByProperties')->willReturn([]);
    $this->entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    // Set up the Drupal container so ControllerBase::config() works.
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('database', $this->database);
    $container->set('markaspot_fastmap.workspace_provisioning', $this->provisioning);
    $container->set('plugin.manager.mail', $this->mailManager);
    $container->set('logger.channel.markaspot_fastmap', $this->logger);
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
   * Tests that service key from query parameter is accepted.
   *
   * @covers ::createWorkspace
   */
  public function testCreateWorkspaceServiceKeyFromQuery(): void {
    $this->setupSuccessfulPending();
    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

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
    $this->assertEquals(202, $response->getStatusCode());
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

    $this->database->method('select')->willReturn($select);
    $this->database->method('delete')->willReturn($delete);

    $this->provisioning->method('provisionWorkspace')
      ->with($workspaceData)
      ->willReturn([
        'group_id' => 42,
        'slug' => 'test-ws',
        'name' => 'Test Workspace',
        'url' => '/test-ws',
        'categories' => 1,
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

}
