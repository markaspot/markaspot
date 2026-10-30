<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Flood\DatabaseBackend;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_service_provider\Controller\ServiceProviderController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests provider verification and flood protection on both response endpoints.
 *
 * @group markaspot_service_provider
 */
#[RunTestsInSeparateProcesses]
final class ServiceProviderVerificationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy',
    'markaspot_service_provider',
  ];

  /**
   * The assigned provider.
   */
  private Term $provider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Exercise production flood storage, including its identifier length limit.
    $this->container->set('flood', new DatabaseBackend(
      $this->container->get('database'),
      $this->container->get('request_stack'),
      $this->container->get('datetime.time'),
    ));
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    NodeType::create(['type' => 'other', 'name' => 'Other'])->save();
    Vocabulary::create(['vid' => 'service_provider', 'name' => 'Providers'])->save();

    foreach ([
      ['taxonomy_term', 'service_provider', 'field_sp_email', 'email', []],
      ['node', 'service_request', 'field_service_provider', 'entity_reference', ['target_type' => 'taxonomy_term']],
      ['node', 'service_request', 'field_service_provider_notes', 'text_long', []],
      ['node', 'service_request', 'field_reassign_sp', 'boolean', []],
    ] as [$entity_type, $bundle, $name, $type, $settings]) {
      // Optional module configuration may already provide these fields.
      if (!FieldStorageConfig::loadByName($entity_type, $name)) {
        FieldStorageConfig::create([
          'field_name' => $name,
          'entity_type' => $entity_type,
          'type' => $type,
          'settings' => $settings,
          'cardinality' => -1,
        ])->save();
      }
      if (!FieldConfig::loadByName($entity_type, $bundle, $name)) {
        FieldConfig::create([
          'field_name' => $name,
          'entity_type' => $entity_type,
          'bundle' => $bundle,
          'label' => $name,
        ])->save();
      }
    }
    $this->provider = Term::create([
      'vid' => 'service_provider',
      'name' => 'Example provider',
      'field_sp_email' => 'provider@example.org',
    ]);
    $this->provider->save();
  }

  /**
   * Provides the handlers and their existing wrong-address status codes.
   */
  public static function endpoints(): array {
    return [
      'auth' => ['authenticateServiceProvider', 'POST', 401],
      'update POST' => ['updateResponse', 'POST', 403],
      'update PATCH' => ['updateResponse', 'PATCH', 403],
    ];
  }

  /**
   * The removed public form cannot expose completion notes or provider emails.
   */
  public function testPublicResponseFormReturnsNotFound(): void {
    $report = $this->report();
    $this->assertSame(200, $this->send('updateResponse', 'POST', $report->uuid(), 'provider@example.org')->getStatusCode());
    // Discover this checkout's routing YAML, including when run in a worktree.
    $module_path = Path::makeRelative(dirname(__DIR__, 3), $this->root);
    $module_handler = $this->container->get('module_handler');
    $modules = $module_handler->getModuleList();
    $modules['markaspot_service_provider'] = new Extension(
      $this->root, 'module', $module_path . '/markaspot_service_provider.info.yml', 'markaspot_service_provider.module',
    );
    $module_handler->setModuleList($modules);
    $this->container->get('router.builder')->rebuild();
    foreach ([$report->uuid(), '00000000-0000-4000-8000-000000000000'] as $uuid) {
      $request = Request::create('/service-response/' . $uuid, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
      $response = $this->container->get('http_kernel')->handle($request);
      $this->assertSame(404, $response->getStatusCode(), (string) $response->getContent());
      $this->assertStringNotContainsString('provider@example.org', (string) $response->getContent());
      $this->assertStringNotContainsString('Work completed.', (string) $response->getContent());
    }
  }

  /**
   * GET reveals neither existence, content type nor unpublished report titles.
   */
  public function testGetRequiresVerificationForEveryUuid(): void {
    $report = $this->report();
    $unpublished = $this->report();
    $unpublished->setUnpublished()->setTitle('Private example report')->save();
    $this->assertFalse($unpublished->access('view'));
    $other = Node::create(['type' => 'other', 'title' => 'Other content']);
    $other->save();
    $expected = json_encode([
      'message' => 'Authentication required',
      'error_code' => 'AUTH_REQUIRED',
    ]);
    foreach ([$report->uuid(), $unpublished->uuid(), $other->uuid(), '00000000-0000-4000-8000-000000000000'] as $uuid) {
      foreach (['', '?email=provider@example.org&email_verification=provider@example.org'] as $query) {
        $request = Request::create('/api/service-response/' . $uuid . $query, 'GET', [], [], [], [
          'CONTENT_TYPE' => 'application/json',
        ], '{"email":"provider@example.org","email_verification":"provider@example.org"}');
        $response = ServiceProviderController::create($this->container)->getServiceRequest($uuid, $request);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame($expected, $response->getContent());
      }
    }
    // Verified data still comes from POST /auth with its existing contract.
    $verified = $this->send('authenticateServiceProvider', 'POST', $report->uuid(), 'provider@example.org');
    $this->assertSame(200, $verified->getStatusCode());
    $data = json_decode((string) $verified->getContent(), TRUE);
    $this->assertTrue($data['authenticated']);
    $this->assertSame($report->uuid(), $data['uuid']);
    $this->assertSame($report->getTitle(), $data['title']);
    $this->assertSame(0, $this->floodCount());
  }

  /**
   * GET cannot acquire an existence-dependent response through storage errors.
   */
  public function testGetDoesNotLoadReports(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $this->container->set('entity_type.manager', $manager);
    $response = $this->send('getServiceRequest', 'GET', '00000000-0000-4000-8000-000000000000', NULL);
    $this->assertSame(401, $response->getStatusCode());
  }

  /**
   * Unknown reports and failed verifications all have the same answer.
   */
  #[DataProvider('endpoints')]
  public function testVerificationFailures(string $handler, string $method, int $status): void {
    $report = $this->report();
    $wrong = $this->send($handler, $method, $report->uuid(), 'wrong@example.org');
    $this->assertSame($status, $wrong->getStatusCode());
    $other = Node::create(['type' => 'other', 'title' => 'Other content']);
    $other->save();
    foreach ([
      $this->send($handler, $method, '00000000-0000-4000-8000-000000000000', 'provider@example.org'),
      $this->send($handler, $method, $other->uuid(), 'provider@example.org'),
      $this->send($handler, $method, $report->uuid(), NULL),
      $this->send($handler, $method, $report->uuid(), ['provider@example.org']),
    ] as $response) {
      $this->assertSame($status, $response->getStatusCode());
      $this->assertSame($wrong->getContent(), $response->getContent());
    }
    $this->provider->set('field_sp_email', NULL)->save();
    // A new HTTP request reloads the report and its referenced provider.
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $response = $this->send($handler, $method, $report->uuid(), 'provider@example.org');
    $this->assertSame($wrong->getContent(), $response->getContent());
    $this->assertUnchanged($report);
  }

  /**
   * A query address cannot authorize an empty or malformed update body.
   */
  public function testInvalidUpdateBodyIsRefused(): void {
    $report = $this->report();
    $wrong = $this->send('updateResponse', 'POST', $report->uuid(), 'wrong@example.org');
    foreach (['', '{', '{}', 'null', '"text"'] as $body) {
      $request = Request::create('/api/service-response/' . $report->uuid() . '?email_verification=provider@example.org', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '192.0.2.1',
      ], $body);
      $response = ServiceProviderController::create($this->container)->updateResponse($report->uuid(), $request);
      $this->assertSame(403, $response->getStatusCode());
      $this->assertSame($wrong->getContent(), $response->getContent());
    }
    $this->assertUnchanged($report);
  }

  /**
   * Invalid UUIDs fail without loading a report or writing a flood event.
   */
  #[DataProvider('endpoints')]
  public function testInvalidUuidsAreRefusedBeforeLoading(string $handler, string $method, int $status): void {
    $report = $this->report();
    $failure = $this->send($handler, $method, $report->uuid(), 'wrong@example.org');
    $before = $this->floodCount();
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $this->container->set('entity_type.manager', $manager);

    foreach ([
      $report->uuid() . ' ',
      $report->uuid() . '  ',
      'malformed',
      'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeeg',
      str_repeat('a', 129),
      $report->uuid() . "\n",
    ] as $uuid) {
      $response = $this->send($handler, $method, $uuid, 'provider@example.org');
      $this->assertSame($status, $response->getStatusCode());
      $this->assertSame($failure->getContent(), $response->getContent());
      $this->assertSame($before, $this->floodCount());
    }
  }

  /**
   * Body credentials are required even when a query address would match.
   */
  #[DataProvider('endpoints')]
  public function testQueryStringEmailIsRefused(string $handler, string $method, int $status): void {
    $report = $this->report();
    $failure = $this->send($handler, $method, $report->uuid(), 'wrong@example.org');
    $request = Request::create('/api/service-response/' . $report->uuid() . '?email=provider@example.org&email_verification=provider@example.org', $method, [], [], [], [
      'CONTENT_TYPE' => 'application/json',
      'REMOTE_ADDR' => '192.0.2.1',
    ], '{"completion_notes":"Work completed."}');
    $response = ServiceProviderController::create($this->container)->$handler($report->uuid(), $request);
    $this->assertSame($status, $response->getStatusCode());
    $this->assertSame($failure->getContent(), $response->getContent());
    $this->assertSame(2, $this->floodCount());
    $this->assertUnchanged($report);
  }

  /**
   * Upper-case input is canonicalized for the actual entity lookup too.
   */
  #[DataProvider('endpoints')]
  public function testCanonicalUuidLookup(string $handler, string $method, int $status): void {
    $report = $this->report();
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('loadByProperties')
      ->with(['uuid' => $report->uuid()])->willReturn([$report->id() => $report]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->once())->method('getStorage')
      ->with('node')->willReturn($storage);
    $real_manager = $this->container->get('entity_type.manager');
    $this->container->set('entity_type.manager', $manager);
    $controller = ServiceProviderController::create($this->container);
    $this->container->set('entity_type.manager', $real_manager);
    $response = $this->send($handler, $method, strtoupper($report->uuid()), 'provider@example.org', '192.0.2.1', $controller);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(0, $this->floodCount());
  }

  /**
   * Every UUID route accepts both cases and rejects malformed identifiers.
   */
  public function testUuidRouteRequirements(): void {
    $definitions = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/markaspot_service_provider.routing.yml'));
    $uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    foreach ($definitions as $definition) {
      if (!str_contains($definition['path'], '{uuid}')) {
        continue;
      }
      $route = new Route($definition['path'], [], $definition['requirements']);
      $pattern = $route->compile()->getRegex();
      foreach ([$uuid, strtoupper($uuid)] as $valid) {
        $this->assertMatchesRegularExpression($pattern, str_replace('{uuid}', $valid, $definition['path']));
      }
      foreach ([
        $uuid . ' ',
        $uuid . '  ',
        'malformed',
        'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeeg',
        str_repeat('a', 129),
        $uuid . "\n",
      ] as $invalid) {
        $this->assertDoesNotMatchRegularExpression($pattern, str_replace('{uuid}', $invalid, $definition['path']));
      }
    }
  }

  /**
   * Correct addresses work before the threshold, including after failures.
   */
  #[DataProvider('endpoints')]
  public function testSuccessfulVerification(string $handler, string $method, int $status): void {
    $report = $this->report();
    for ($i = 0; $i < ServiceProviderController::FLOOD_THRESHOLD - 1; $i++) {
      $this->assertSame($status, $this->send($handler, $method, $report->uuid(), 'wrong@example.org')->getStatusCode());
    }
    $response = $this->send($handler, $method, $report->uuid(), ' Provider@Example.org ');
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame($report->id(), $data['nid']);
    if ($handler === 'authenticateServiceProvider') {
      $this->assertTrue($data['authenticated']);
      $this->assertSame($report->uuid(), $data['uuid']);
      $this->assertSame([], $data['completions']);
      $this->assertUnchanged($report);
    }
    else {
      $this->assertSame(['message', 'nid', 'success'], array_keys($data));
      $this->assertTrue($data['success']);
      $saved = Node::load($report->id());
      $this->assertStringContainsString('Work completed.', $saved->get('field_service_provider_notes')->value);
    }
  }

  /**
   * Both handlers share a limit scoped to client IP and case-normalized UUID.
   */
  #[DataProvider('endpoints')]
  public function testVerificationIsRateLimited(string $handler, string $method, int $status): void {
    $report = $this->report();
    for ($i = 0; $i < ServiceProviderController::FLOOD_THRESHOLD; $i++) {
      $failure = $this->send($handler, $method, $report->uuid(), "guess$i@example.org");
      $this->assertSame($status, $failure->getStatusCode());
    }
    foreach (self::endpoints() as [$blocked_handler, $blocked_method]) {
      $blocked = $this->send($blocked_handler, $blocked_method, strtoupper($report->uuid()), 'provider@example.org');
      $this->assertSame(429, $blocked->getStatusCode());
      $this->assertSame((string) ServiceProviderController::FLOOD_WINDOW, $blocked->headers->get('Retry-After'));
    }
    $before = $this->floodCount();
    $padded = $this->send($handler, $method, $report->uuid() . ' ', 'provider@example.org');
    $this->assertSame($status, $padded->getStatusCode());
    $this->assertSame($failure->getContent(), $padded->getContent());
    $this->assertSame($before, $this->floodCount());
    $this->assertUnchanged($report);
    $this->assertSame(200, $this->send($handler, $method, $report->uuid(), 'provider@example.org', '192.0.2.2')->getStatusCode());
    $this->assertSame(200, $this->send($handler, $method, $this->report()->uuid(), 'provider@example.org')->getStatusCode());
  }

  /**
   * Unknown UUID failures count, and blocking happens before any entity load.
   */
  #[DataProvider('endpoints')]
  public function testUnknownUuidIsRateLimitedBeforeLoading(string $handler, string $method, int $status): void {
    $uuid = '00000000-0000-4000-8000-000000000000';
    for ($i = 0; $i < ServiceProviderController::FLOOD_THRESHOLD; $i++) {
      $this->assertSame($status, $this->send($handler, $method, $uuid, 'wrong@example.org')->getStatusCode());
    }
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $this->container->set('entity_type.manager', $manager);
    $this->assertSame(429, $this->send($handler, $method, $uuid, 'provider@example.org')->getStatusCode());
  }

  /**
   * Failures across reports from a shared proxy IP do not block another report.
   */
  #[DataProvider('endpoints')]
  public function testFailuresAcrossReportsDoNotBlockAnotherReport(string $handler, string $method, int $status): void {
    for ($i = 0; $i < 50; $i++) {
      $report = $this->report();
      $response = $this->send($handler, $method, $report->uuid(), 'wrong@example.org');
      $this->assertSame($status, $response->getStatusCode());
      $this->assertUnchanged($report);
    }
    $response = $this->send($handler, $method, $this->report()->uuid(), 'provider@example.org');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(50, $this->floodCount());
  }

  /**
   * A request blocked by the per-report limit logs one warning.
   */
  #[DataProvider('endpoints')]
  public function testBlockedRequestLogsOneWarning(string $handler, string $method, int $status): void {
    $report = $this->report();
    $flood = $this->container->get('flood');
    for ($i = 0; $i < ServiceProviderController::FLOOD_THRESHOLD; $i++) {
      $flood->register(ServiceProviderController::FLOOD_EVENT, 3600, '192.0.2.1:' . $report->uuid());
    }
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('warning')->with(
      'Service provider verification rate limit exceeded for client IP @ip and report @uuid',
      ['@ip' => '192.0.2.1', '@uuid' => $report->uuid()],
    );
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('markaspot_service_provider')->willReturn($logger);
    $this->container->set('logger.factory', $factory);
    $response = $this->send($handler, $method, strtoupper($report->uuid()), 'provider@example.org');
    $this->assertSame(429, $response->getStatusCode());
  }

  /**
   * Already-completed warnings do not contain the provider's address.
   */
  public function testAlreadyCompletedWarningOmitsEmail(): void {
    $report = $this->report();
    $this->assertSame(200, $this->send('updateResponse', 'POST', $report->uuid(), 'provider@example.org')->getStatusCode());
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->exactly(2))->method('warning')->with(
      'Service provider attempted completion without reassignment flag for node @nid',
      ['@nid' => $report->id()],
    );
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('markaspot_service_provider')->willReturn($logger);
    $this->container->set('logger.factory', $factory);
    foreach (['POST', 'PATCH'] as $method) {
      $response = $this->send('updateResponse', $method, $report->uuid(), 'provider@example.org');
      $this->assertSame(403, $response->getStatusCode());
      $this->assertSame('ALREADY_COMPLETED', json_decode((string) $response->getContent(), TRUE)['error_code']);
    }
    $this->assertSame(0, $this->floodCount());
  }

  /**
   * Counts real database flood rows, including the initially absent table.
   */
  private function floodCount(?string $event = NULL): int {
    $database = $this->container->get('database');
    if (!$database->schema()->tableExists('flood')) {
      return 0;
    }
    $query = $database->select('flood', 'f');
    if ($event !== NULL) {
      $query->condition('event', $event);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Creates a report assigned to the provider.
   */
  private function report(): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Example report',
      'uid' => 0,
      'status' => 1,
      'field_service_provider' => $this->provider->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Calls a response handler as an anonymous client.
   */
  private function send(string $handler, string $method, string $uuid, mixed $email, string $ip = '192.0.2.1', ?ServiceProviderController $controller = NULL): JsonResponse {
    $body = [
      $handler === 'authenticateServiceProvider' ? 'email' : 'email_verification' => $email,
      'completion_notes' => 'Work completed.',
    ];
    $path = '/api/service-response/' . rawurlencode($uuid);
    if ($handler === 'authenticateServiceProvider') {
      $path .= '/auth';
    }
    $request = Request::create($path, $method, [], [], [], [
      'CONTENT_TYPE' => 'application/json',
      'REMOTE_ADDR' => $ip,
    ], (string) json_encode($body));
    $controller ??= ServiceProviderController::create($this->container);
    return $controller->$handler($uuid, $request);
  }

  /**
   * Checks that a rejected request did not write completion notes.
   */
  private function assertUnchanged(NodeInterface $report): void {
    $this->assertTrue(Node::load($report->id())->get('field_service_provider_notes')->isEmpty());
  }

}
