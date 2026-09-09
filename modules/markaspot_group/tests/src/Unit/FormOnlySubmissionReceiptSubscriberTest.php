<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\EventSubscriber\FormOnlySubmissionReceiptSubscriber;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Tests that form-only creation returns only a receipt to non-readers.
 *
 * @group markaspot_group
 */
class FormOnlySubmissionReceiptSubscriberTest extends UnitTestCase {

  /**
   * Includes and internal fields must not leave the creation response.
   */
  public function testRestrictedCreationReturnsMinimalPrivateReceipt(): void {
    $event = $this->responseEvent();
    $subscriber = $this->subscriber('form_only', FALSE);
    $subscriber->onResponse($event);
    $response = $event->getResponse();
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame([
      'data' => [
        'id' => 'created-uuid',
        'type' => 'node--service_request',
        'attributes' => ['request_id' => 'R-123', 'field_add_data' => TRUE],
      ],
    ], json_decode((string) $response->getContent(), TRUE));
    $this->assertFalse($response->headers->has('Location'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
  }

  /**
   * Existing modes and authorized staff retain their normal response.
   */
  public function testPublicAndAuthorizedResponsesAreUnchanged(): void {
    foreach ([['public', FALSE], ['submission_only', FALSE], ['form_only', TRUE]] as [$mode, $allowed]) {
      $event = $this->responseEvent();
      $original = $event->getResponse()->getContent();
      $this->subscriber($mode, $allowed)->onResponse($event);
      $this->assertSame($original, $event->getResponse()->getContent());
    }
  }

  /**
   * Successful reads and failed creates are not rewritten into receipts.
   */
  public function testOtherResponsesAreNotRewritten(): void {
    $subscriber = $this->subscriber('form_only', FALSE);
    foreach ([['GET', 200], ['POST', 422], ['PATCH', 200]] as [$method, $status]) {
      $event = $this->responseEvent();
      $event->getRequest()->setMethod($method);
      $event->getResponse()->setStatusCode($status);
      $original = $event->getResponse()->getContent();
      $subscriber->onResponse($event);
      $this->assertSame($original, $event->getResponse()->getContent());
    }
  }

  /**
   * Serializer or entity lookup failures cannot retain an unverified report.
   */
  public function testUnverifiableCreationFailsClosed(): void {
    foreach ([
      '{invalid private response',
      '{"data":{"attributes":{"description":"private report"}}}',
      '{"data":{"id":"created-uuid","attributes":{"description":"private report"}}}',
    ] as $content) {
      $event = $this->responseEvent();
      $event->getResponse()->setContent($content);
      $this->subscriber('form_only', FALSE, FALSE)->onResponse($event);
      $response = $event->getResponse();
      $this->assertSame(500, $response->getStatusCode());
      $this->assertStringNotContainsString('private report', (string) $response->getContent());
      $this->assertFalse($response->headers->has('Location'));
      $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
      $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    }
  }

  /**
   * Creates a realistic serialized creation response with private includes.
   */
  private function responseEvent(): ResponseEvent {
    $request = Request::create('/jsonapi/node/service_request?include=field_organisation', 'POST');
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    $response = new CacheableJsonResponse([
      'data' => [
        'id' => 'created-uuid', 'type' => 'node--service_request',
        'attributes' => ['description' => 'private report', 'status' => 'internal'],
        'relationships' => ['field_organisation' => ['data' => ['id' => 'org-uuid']]],
        'links' => ['self' => '/jsonapi/node/service_request/created-uuid'],
      ],
      'included' => [['id' => 'org-uuid', 'attributes' => ['mail' => 'internal@example.test']]],
    ], 201, ['Location' => '/jsonapi/node/service_request/created-uuid']);
    return new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
  }

  /**
   * Creates the receipt subscriber with a persisted report and visibility rule.
   */
  private function subscriber(string $mode, bool $allowed, bool $resolved = TRUE): FormOnlySubmissionReceiptSubscriber {
    $jurisdiction = $this->createMock(FieldItemListInterface::class);
    $jurisdiction->method('isEmpty')->willReturn(FALSE);
    $jurisdiction->method('getValue')->willReturn([['target_id' => 5]]);
    $request_id = $this->createMock(FieldItemListInterface::class);
    $request_id->method('__get')->with('value')->willReturn('R-123');
    $competition = $this->createMock(FieldItemListInterface::class);
    $competition->method('__get')->with('value')->willReturn(1);
    $fields = ['field_jurisdiction' => $jurisdiction, 'request_id' => $request_id, 'field_add_data' => $competition];
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')->willReturnCallback(static fn(string $name) => $fields[$name]);
    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('loadByProperties')->with(['uuid' => 'created-uuid'])->willReturn($resolved ? [$node] : []);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('loadMultiple')->willReturn([5 => $group]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([['node', $node_storage], ['group', $group_storage]]);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    $container->set('config.factory', $this->getConfigFactoryStub(['markaspot_open311.settings' => ['jurisdiction_group_type' => 'jur']]));
    \Drupal::setContainer($container);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('getVisibility')->willReturn($mode);
    $visibility->method('allowsReportReadFor')->willReturn($allowed);
    return new FormOnlySubmissionReceiptSubscriber($manager, new AnonymousUserSession(), $visibility);
  }

}
