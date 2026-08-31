<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_dashboard\Controller\StatusNoteController;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the lower-trust contractor status-note contract.
 *
 * @group markaspot_dashboard
 *
 * @coversDefaultClass \Drupal\markaspot_dashboard\Controller\StatusNoteController
 */
final class ContractorStatusNoteAccessTest extends UnitTestCase {

  /**
   * Restricted status-note controller under test.
   */
  private StatusNoteController $controller;

  /**
   * Entity type manager mock.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Current user mock.
   */
  private AccountProxyInterface $currentUser;

  /**
   * Status term scope mock.
   */
  private StatusTermScope $statusTermScope;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentUser->method('hasPermission')
      ->with('manage dashboard notes')
      ->willReturn(FALSE);
    $this->statusTermScope = $this->createMock(StatusTermScope::class);

    $this->controller = new StatusNoteController(
      $this->entityTypeManager,
      $this->createMock(GeoreportProcessorServiceInterface::class),
      $this->currentUser,
      $this->createMock(FeatureFlagChecker::class),
      $this->statusTermScope,
    );
  }

  /**
   * Contractors cannot inject internal status attributes.
   *
   * @covers ::add
   */
  public function testStatusAttributesAreRejected(): void {
    $this->entityTypeManager->expects($this->never())->method('getStorage');

    $response = $this->controller->add($this->request([
      'request_uuid' => 'request-uuid',
      'status_attributes' => ['internal' => 'value'],
    ]));

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * A null status-attributes key remains compatible with the frontend.
   *
   * @covers ::add
   */
  public function testNullStatusAttributesAreAccepted(): void {
    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn([]);
    $this->entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($node_storage);

    $response = $this->controller->add($this->request([
      'request_uuid' => 'missing-request',
      'status_attributes' => NULL,
    ]));

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Terms outside the jurisdiction scope are rejected before any save.
   *
   * Cross-jurisdiction validation (including the child-to-root walk) lives
   * in StatusTermScope and is covered by StatusTermScopeTest; this test pins
   * the controller contract on top of it.
   *
   * @covers ::add
   */
  public function testScopedOutStatusTermIsRejected(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('access')->with('update')->willReturn(TRUE);

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('loadByProperties')->willReturn([$node]);
    $this->entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($node_storage);

    $this->statusTermScope->method('loadByProperties')->willReturn([]);
    $this->statusTermScope->method('canScope')->willReturn(TRUE);

    $response = $this->controller->add($this->request([
      'request_uuid' => 'request-uuid',
      'status_term_uuid' => 'foreign-term-uuid',
    ]));

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Contractors may not use status terms they cannot view.
   *
   * @covers ::add
   */
  public function testStatusTermWithoutViewAccessIsRejected(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('access')->with('update')->willReturn(TRUE);

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('loadByProperties')->willReturn([$node]);
    $this->entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($node_storage);

    $term = $this->createMock(TermInterface::class);
    $term->method('access')->with('view', $this->currentUser)->willReturn(FALSE);
    $this->statusTermScope->method('loadByProperties')->willReturn([$term]);

    $response = $this->controller->add($this->request([
      'request_uuid' => 'request-uuid',
      'status_term_uuid' => 'hidden-term-uuid',
    ]));

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Managers persist a validated status in the status-note request.
   *
   * @covers ::add
   */
  public function testManagerStatusNotePersistsValidatedStatus(): void {
    $manager = $this->createMock(AccountProxyInterface::class);
    $manager->method('hasPermission')
      ->with('manage dashboard notes')
      ->willReturn(TRUE);
    $manager->method('id')->willReturn(42);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');

    $node = $this->createMock(NodeInterface::class);
    $node->method('access')->with('update')->willReturn(TRUE);
    $node->method('language')->willReturn($language);
    $node->expects($this->once())
      ->method('set')
      ->with('field_status', 17)
      ->willReturnSelf();

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('loadByProperties')->willReturn([$node]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('node')
      ->willReturn($node_storage);

    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn(17);
    $status_term_scope = $this->createMock(StatusTermScope::class);
    $status_term_scope->method('loadByProperties')->willReturn([$term]);

    $georeport_processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $georeport_processor->expects($this->once())
      ->method('createStatusNoteParagraph')
      ->with([
        'status_term_id' => 17,
        'note' => 'Status update',
        'boilerplate_id' => NULL,
        'author_id' => 42,
      ], 'de')
      ->willThrowException(new \RuntimeException('Stop after status synchronization.'));

    $controller = new StatusNoteController(
      $entity_type_manager,
      $georeport_processor,
      $manager,
      $this->createMock(FeatureFlagChecker::class),
      $status_term_scope,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Stop after status synchronization.');
    $controller->add($this->request([
      'request_uuid' => 'request-uuid',
      'status_term_uuid' => 'status-term-uuid',
      'note' => 'Status update',
    ]));
  }

  /**
   * The newly exposed mutation route requires a request-header CSRF token.
   */
  public function testCreateRouteRequiresCsrfToken(): void {
    $routing = Yaml::decode(file_get_contents(
      dirname(__DIR__, 3) . '/markaspot_dashboard.routing.yml',
    ));
    $requirements = $routing['markaspot_dashboard.status_note_create']['requirements'] ?? [];

    $this->assertSame('TRUE', $requirements['_csrf_request_header_token'] ?? NULL);
  }

  /**
   * Builds a JSON request.
   */
  private function request(array $data): Request {
    return Request::create(
      '/api/dashboard/status-notes',
      'POST',
      content: json_encode($data, JSON_THROW_ON_ERROR),
    );
  }

}
