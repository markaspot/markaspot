<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_emergency\EventSubscriber\EmergencySubmissionGuardSubscriber;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\markaspot_emergency\Service\EmergencySubmissionIdempotencyLedgerInterface;
use Drupal\markaspot_emergency\Service\EmergencySubmissionReplayResponderInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the root-scoped JSON:API submission preflight.
 *
 * @group markaspot_emergency
 * @coversDefaultClass \Drupal\markaspot_emergency\EventSubscriber\EmergencySubmissionGuardSubscriber
 */
class EmergencySubmissionGuardSubscriberTest extends UnitTestCase {

  private const CATEGORY_UUID = '11111111-1111-4111-8111-111111111111';
  private const JURISDICTION_UUID = '22222222-2222-4222-8222-222222222222';

  /**
   * @covers ::getSubscribedEvents
   */
  public function testSubscribesAtPreparationAndExceptionBoundaries(): void {
    $events = EmergencySubmissionGuardSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertArrayNotHasKey(KernelEvents::RESPONSE, $events);
    $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
  }

  /**
   * @covers ::onRequest
   */
  public function testMarkedCreateRunsOneScopedPreflight(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('prepareSubmissionGuard')
      ->with(7, 12, self::CATEGORY_UUID, self::JURISDICTION_UUID, 'active')
      ->willReturn($this->preparedContext());
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());
    $kernel = $this->createMock(HttpKernelInterface::class);
    // Route semantics, not the public path, must drive the guard because
    // production can randomize the JSON:API prefix.
    $request = $this->createRequest('/private-json/node/service_request');

    $subscriber->onRequest(new RequestEvent(
      $kernel,
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    ));
    $this->assertSame(
      $this->preparedContext(),
      $request->attributes->get(EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE),
    );
  }

  /**
   * @covers ::onRequest
   */
  public function testMalformedMarkedDocumentFailsBeforeLocking(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('prepareSubmissionGuard');
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());
    $request = Request::create(
      '/jsonapi/node/service_request',
      'POST',
      [],
      [],
      [],
      [
        'CONTENT_TYPE' => 'application/vnd.api+json',
        'HTTP_X_MARKASPOT_EMERGENCY_ROOT' => '7',
        'HTTP_X_MARKASPOT_EMERGENCY_REVISION' => '12',
      ],
      '{"data":[]}',
    );
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');

    $this->expectException(BadRequestHttpException::class);
    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * Lite pins include the verified leaf jurisdiction used for boundaries.
   *
   * @covers ::onRequest
   */
  public function testMarkedCreateRequiresOneJurisdictionRelationship(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('prepareSubmissionGuard');
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());

    $this->expectException(BadRequestHttpException::class);
    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->createRequest(includeJurisdictionRelationship: FALSE),
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * Duplicate emergency pins are rejected before request preparation.
   *
   * @covers ::onRequest
   */
  public function testDuplicateRootHeaderIsRejected(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('prepareSubmissionGuard');
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());
    $request = $this->createRequest();
    $request->headers->set('X-Markaspot-Emergency-Root', ['7', '8']);

    $this->expectException(BadRequestHttpException::class);
    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * @covers ::onRequest
   */
  public function testRuntimeDriftReturnsConflict(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->method('prepareSubmissionGuard')
      ->willThrowException(new \LogicException('Revision changed.'));
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());

    $this->expectException(ConflictHttpException::class);
    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->createRequest(),
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * A valid active Lite key is carried only to the prepared save context.
   *
   * @covers ::onRequest
   */
  public function testActiveLiteIdempotencyKeyIsPreparedForTheSaveTransaction(): void {
    $key = '44444444-4444-4444-8444-444444444444';
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('prepareSubmissionGuard')
      ->with(7, 12, self::CATEGORY_UUID, self::JURISDICTION_UUID, 'active')
      ->willReturn($this->preparedContext());
    $ledger = $this->createMock(EmergencySubmissionIdempotencyLedgerInterface::class);
    $request = $this->createRequest(idempotencyKey: $key);
    $ledger->expects($this->once())
      ->method('findReplay')
      ->with(7, 12, $key, hash('sha256', $request->getContent()))
      ->willReturn(NULL);
    $responder = $this->createMock(EmergencySubmissionReplayResponderInterface::class);
    $responder->expects($this->never())->method('buildResponse');
    $subscriber = new EmergencySubmissionGuardSubscriber(
      $service,
      $this->account(),
      $ledger,
      $responder,
    );

    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    ));

    $context = $request->attributes->get(EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE);
    $this->assertIsArray($context);
    $this->assertSame($key, $context['idempotency_key']);
    $this->assertSame(hash('sha256', $request->getContent()), $context['idempotency_request_hash']);
  }

  /**
   * Idempotency is deliberately unavailable to normal and off-mode creates.
   *
   * @covers ::onRequest
   */
  public function testIdempotencyKeyRequiresAnActiveLitePin(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('prepareSubmissionGuard');
    $ledger = $this->createMock(EmergencySubmissionIdempotencyLedgerInterface::class);
    $ledger->expects($this->never())->method('findReplay');
    $subscriber = new EmergencySubmissionGuardSubscriber(
      $service,
      $this->account(),
      $ledger,
      $this->createMock(EmergencySubmissionReplayResponderInterface::class),
    );

    $this->expectException(BadRequestHttpException::class);
    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->createRequest(
        expectedStatus: 'off',
        revision: 0,
        idempotencyKey: '44444444-4444-4444-8444-444444444444',
      ),
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * A committed matching key short-circuits JSON:API before a second save.
   *
   * @covers ::onRequest
   */
  public function testMatchingIdempotencyKeyReturnsThePriorSuccessfulResponse(): void {
    $key = '44444444-4444-4444-8444-444444444444';
    $nodeUuid = '55555555-5555-4555-8555-555555555555';
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('prepareSubmissionGuard');
    $ledger = $this->createMock(EmergencySubmissionIdempotencyLedgerInterface::class);
    $request = $this->createRequest(idempotencyKey: $key);
    $ledger->expects($this->once())
      ->method('findReplay')
      ->with(7, 12, $key, hash('sha256', $request->getContent()))
      ->willReturn($nodeUuid);
    $response = new JsonResponse(['data' => ['id' => $nodeUuid]], 200);
    $responder = $this->createMock(EmergencySubmissionReplayResponderInterface::class);
    $responder->expects($this->once())
      ->method('buildResponse')
      ->with($request, $nodeUuid)
      ->willReturn($response);
    $subscriber = new EmergencySubmissionGuardSubscriber(
      $service,
      $this->account(),
      $ledger,
      $responder,
    );
    $event = new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    );

    $subscriber->onRequest($event);

    $this->assertSame($response, $event->getResponse());
    $this->assertFalse($request->attributes->has(
      EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE,
    ));
  }

  /**
   * A duplicate discovered inside presave becomes a successful response.
   *
   * @covers ::onException
   */
  public function testResolvedDuplicatePersistenceFailureReturnsPriorResponse(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $nodeUuid = '55555555-5555-4555-8555-555555555555';
    $response = new JsonResponse(['data' => ['id' => $nodeUuid]], 200);
    $responder = $this->createMock(EmergencySubmissionReplayResponderInterface::class);
    $request = $this->createRequest();
    $request->attributes->set(EmergencySubmissionGuardSubscriber::REPLAY_ATTRIBUTE, $nodeUuid);
    $responder->expects($this->once())
      ->method('buildResponse')
      ->with($request, $nodeUuid)
      ->willReturn($response);
    $subscriber = new EmergencySubmissionGuardSubscriber(
      $service,
      $this->account(),
      NULL,
      $responder,
    );
    $event = new ExceptionEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      new \RuntimeException('Wrapped entity storage failure.'),
    );

    $subscriber->onException($event);

    $this->assertSame($response, $event->getResponse());
    $this->assertFalse($request->attributes->has(EmergencySubmissionGuardSubscriber::REPLAY_ATTRIBUTE));
  }

  /**
   * SQL entity storage wraps presave exceptions; the subscriber restores 409.
   *
   * @covers ::onException
   */
  public function testPersistenceFailureIsRestoredToConflict(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());
    $request = $this->createRequest();
    $request->attributes->set(
      EmergencySubmissionGuardSubscriber::FAILURE_ATTRIBUTE,
      'The emergency profile changed before the report was saved.',
    );
    $event = new ExceptionEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      new \RuntimeException('Wrapped entity storage failure.'),
    );

    $subscriber->onException($event);

    $this->assertInstanceOf(ConflictHttpException::class, $event->getThrowable());
    $this->assertSame(409, $event->getThrowable()->getStatusCode());
  }

  /**
   * A normal offline replay may pin the legitimate initial off revision zero.
   *
   * @covers ::onRequest
   */
  public function testOffRevisionPinAllowsZeroWithoutJurisdiction(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('prepareSubmissionGuard')
      ->with(7, 0, self::CATEGORY_UUID, NULL, 'off')
      ->willReturn($this->preparedContext(
        expectedRevision: 0,
        expectedStatus: 'off',
        jurisdictionId: NULL,
        requireLiteUi: FALSE,
        requirePublishedCategory: TRUE,
      ));
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());

    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->createRequest(
        includeJurisdictionRelationship: FALSE,
        expectedStatus: 'off',
        revision: 0,
      ),
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * Client-supplied status pins accept only the two canonical runtime states.
   *
   * @covers ::onRequest
   */
  public function testInvalidExpectedStatusFailsBeforeLocking(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('prepareSubmissionGuard');
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());

    $this->expectException(BadRequestHttpException::class);
    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->createRequest(expectedStatus: 'normal'),
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * @covers ::onRequest
   */
  public function testOversizedMarkedDocumentIsRejectedBeforeDecoding(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('prepareSubmissionGuard');
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());
    $stream = fopen('php://temp', 'w+');
    $this->assertIsResource($stream);
    fwrite($stream, str_repeat('x', 65 * 1024));
    rewind($stream);
    $request = Request::create(
      '/private-json/node/service_request',
      'POST',
      [],
      [],
      [],
      [
        'CONTENT_TYPE' => 'application/vnd.api+json',
        'HTTP_X_MARKASPOT_EMERGENCY_ROOT' => '7',
        'HTTP_X_MARKASPOT_EMERGENCY_REVISION' => '12',
      ],
      $stream,
    );
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    $this->assertNull($request->headers->get('Content-Length'));

    try {
      $subscriber->onRequest(new RequestEvent(
        $this->createMock(HttpKernelInterface::class),
        $request,
        HttpKernelInterface::MAIN_REQUEST,
      ));
      $this->fail('Oversized marked documents must be rejected.');
    }
    catch (HttpException $exception) {
      $this->assertSame(413, $exception->getStatusCode());
    }
    finally {
      fclose($stream);
    }
  }

  /**
   * Removing client headers does not bypass citizen scope enforcement.
   *
   * @covers ::onRequest
   */
  public function testUnmarkedCitizenCreateIsStillGuarded(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('prepareSubmissionGuard')
      ->with(NULL, NULL, self::CATEGORY_UUID, NULL, NULL)
      ->willReturn($this->preparedContext(
        expectedRevision: 0,
        expectedStatus: 'off',
        jurisdictionId: NULL,
        requireLiteUi: FALSE,
        requirePublishedCategory: FALSE,
      ));
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());

    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->createRequest(
        includeRevisionHeaders: FALSE,
        includeJurisdictionRelationship: FALSE,
      ),
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * Existing dashboard creates use a to-one jurisdiction resource identifier.
   *
   * @covers ::onRequest
   */
  public function testDashboardJurisdictionObjectRunsTheSamePreflight(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->once())
      ->method('prepareSubmissionGuard')
      ->with(NULL, NULL, self::CATEGORY_UUID, self::JURISDICTION_UUID, NULL)
      ->willReturn($this->preparedContext(
        expectedRevision: 0,
        expectedStatus: 'off',
        requireLiteUi: FALSE,
        requirePublishedCategory: FALSE,
      ));
    $subscriber = new EmergencySubmissionGuardSubscriber($service, $this->account());

    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->createRequest(
        includeRevisionHeaders: FALSE,
        jurisdictionAsList: FALSE,
      ),
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * Restricted emergency operators may run an unmarked content import.
   *
   * @covers ::onRequest
   */
  public function testEmergencyOperatorMayBypassUnmarkedCreateGuard(): void {
    $service = $this->createMock(EmergencyModeService::class);
    $service->expects($this->never())->method('prepareSubmissionGuard');
    $subscriber = new EmergencySubmissionGuardSubscriber(
      $service,
      $this->account(TRUE),
    );

    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->createRequest(includeRevisionHeaders: FALSE),
      HttpKernelInterface::MAIN_REQUEST,
    ));
  }

  /**
   * Builds a valid marked Lite JSON:API request.
   */
  private function createRequest(
    string $path = '/jsonapi/node/service_request',
    bool $includeRevisionHeaders = TRUE,
    bool $includeJurisdictionRelationship = TRUE,
    bool $jurisdictionAsList = TRUE,
    ?string $expectedStatus = NULL,
    int $revision = 12,
    ?string $idempotencyKey = NULL,
  ): Request {
    $server = ['CONTENT_TYPE' => 'application/vnd.api+json'];
    if ($includeRevisionHeaders) {
      $server['HTTP_X_MARKASPOT_EMERGENCY_ROOT'] = '7';
      $server['HTTP_X_MARKASPOT_EMERGENCY_REVISION'] = (string) $revision;
      if ($expectedStatus !== NULL) {
        $server['HTTP_X_MARKASPOT_EMERGENCY_STATUS'] = $expectedStatus;
      }
    }
    if ($idempotencyKey !== NULL) {
      $server['HTTP_X_MARKASPOT_EMERGENCY_IDEMPOTENCY_KEY'] = $idempotencyKey;
    }
    $relationships = [
      'field_category' => [
        'data' => [
          'type' => 'taxonomy_term--service_category',
          'id' => self::CATEGORY_UUID,
        ],
      ],
    ];
    if ($includeJurisdictionRelationship) {
      $jurisdiction = [
        'type' => 'group--jur',
        'id' => self::JURISDICTION_UUID,
      ];
      $relationships['field_jurisdiction'] = [
        'data' => $jurisdictionAsList ? [$jurisdiction] : $jurisdiction,
      ];
    }
    $request = Request::create(
      $path,
      'POST',
      [],
      [],
      [],
      $server,
      json_encode([
        'data' => [
          'type' => 'node--service_request',
          'relationships' => $relationships,
        ],
      ], JSON_THROW_ON_ERROR),
    );
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    return $request;
  }

  /**
   * Builds the normalized context returned by request preparation.
   */
  private function preparedContext(
    int $expectedRevision = 12,
    string $expectedStatus = 'active',
    ?int $jurisdictionId = 9,
    bool $requireLiteUi = TRUE,
    bool $requirePublishedCategory = TRUE,
  ): array {
    return [
      'root_id' => 7,
      'expected_revision' => $expectedRevision,
      'expected_status' => $expectedStatus,
      'require_lite_ui' => $requireLiteUi,
      'require_published_category' => $requirePublishedCategory,
      'require_lite_compatible_category' => $requireLiteUi && $expectedStatus === 'active',
      'category_id' => 40,
      'category_jurisdiction_ids' => [7],
      'jurisdiction_id' => $jurisdictionId,
    ];
  }

  /**
   * Builds a current-user test double.
   */
  private function account(bool $emergencyOperator = FALSE): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $emergencyOperator
        && $permission === 'administer emergency mode',
    );
    return $account;
  }

}
