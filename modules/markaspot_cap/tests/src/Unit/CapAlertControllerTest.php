<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_cap\Controller\CapAlertController;
use Drupal\markaspot_cap\Encoder\CapEncoder;
use Drupal\markaspot_cap\Service\CapProcessorService;
use Drupal\markaspot_cap\Service\CapFeedMutationTracker;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests jurisdiction and revision safety of the CAP controller.
 *
 * @group markaspot_cap
 * @coversDefaultClass \Drupal\markaspot_cap\Controller\CapAlertController
 */
class CapAlertControllerTest extends UnitTestCase {

  /**
   * @covers ::index
   */
  public function testIndexKeepsRequestedSubtreeAndActivationCutoff(): void {
    $fixture = $this->fixture($this->activeState(), [101, 102]);

    $response = $fixture->controller->index(Request::create(
      '/api/cap/v1/alerts?jurisdiction_id=child',
    ));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/atom+xml; charset=UTF-8', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertContains(['changed', 1_800_000_000, '<='], $fixture->nodeConditions);
    $this->assertContains(['nid', [101, 102], 'IN'], $fixture->nodeConditions);
    $this->assertContains(['created', 1_700_000_000, '>='], $fixture->nodeConditions);
    $this->assertContains(['field_cap_publish', 1, NULL], $fixture->nodeConditions);
    $this->assertContains(['field_category', [40], 'IN'], $fixture->nodeConditions);
  }

  /**
   * Feed stays empty until the approval-field update has completed safely.
   *
   * @covers ::index
   */
  public function testApprovalMigrationReadinessFailsClosed(): void {
    $fixture = $this->fixture(
      $this->activeState(),
      [101],
      approvalReady: FALSE,
    );

    $fixture->controller->index(Request::create(
      '/api/cap/v1/alerts?jurisdiction_id=child',
    ));

    $this->assertContains(['nid', [0], 'IN'], $fixture->nodeConditions);
    $this->assertNotContains(['field_cap_publish', 1, NULL], $fixture->nodeConditions);
  }

  /**
   * Atom feed identity is distinct for each tenant subtree.
   *
   * @covers ::index
   */
  public function testFeedIdentityIncludesRootAndRequestedJurisdiction(): void {
    $first = $this->fixture(
      $this->activeState(),
      requestedId: 9,
      rootId: 7,
    );
    $second = $this->fixture(
      $this->activeState(),
      requestedId: 10,
      rootId: 7,
    );

    $first->controller->index(Request::create('/api/cap/v1/alerts?jurisdiction_id=first'));
    $second->controller->index(Request::create('/api/cap/v1/alerts?jurisdiction_id=second'));

    $this->assertSame(
      'urn:markaspot:cap:feed:site-uuid:root-7:scope-9',
      $first->encodeContext['cap_feed_id'],
    );
    $this->assertSame(
      'urn:markaspot:cap:feed:site-uuid:root-7:scope-10',
      $second->encodeContext['cap_feed_id'],
    );
    $this->assertSame('Test site', $first->encodeContext['cap_feed_author']);
    $this->assertSame('2023-11-14T22:13:20Z', $first->encodeContext['cap_feed_updated']);
  }

  /**
   * Removed or unapproved entries cannot move Atom updated backwards.
   */
  public function testFeedMutationTimestampIsAnUpdatedFloor(): void {
    $fixture = $this->fixture(
      $this->activeState(),
      feedChangedAt: 1_750_000_000,
    );

    $fixture->controller->index(Request::create('/api/cap/v1/alerts?jurisdiction_id=child'));

    $this->assertSame('2025-06-15T15:06:40Z', $fixture->encodeContext['cap_feed_updated']);
  }

  /**
   * @covers ::show
   */
  public function testShowAlsoUsesActivationCutoff(): void {
    $fixture = $this->fixture($this->activeState(), [101]);

    try {
      $fixture->controller->show('REQ-1.cap', Request::create(
        '/api/cap/v1/alerts/REQ-1.cap?jurisdiction_id=child',
      ));
      $this->fail('An empty scoped query must produce a not-found response.');
    }
    catch (NotFoundHttpException) {
      $this->assertContains(['created', 1_700_000_000, '>='], $fixture->nodeConditions);
      $this->assertContains(['nid', [101], 'IN'], $fixture->nodeConditions);
    }
  }

  /**
   * @covers ::index
   * @dataProvider inactiveStateProvider
   */
  public function testControllerRecheckRejectsInactiveOrInvalidState(array $state): void {
    $fixture = $this->fixture($state);

    $this->expectException(NotFoundHttpException::class);
    $fixture->controller->index(Request::create(
      '/api/cap/v1/alerts?jurisdiction_id=child',
    ));
  }

  /**
   * Provides non-exportable mode states.
   */
  public static function inactiveStateProvider(): array {
    return [
      'inactive' => [[
        'status' => 'off',
        'activated_at' => NULL,
      ]],
      'active without timestamp' => [[
        'status' => 'active',
        'activated_at' => NULL,
      ]],
    ];
  }

  /**
   * @covers ::index
   */
  public function testUnknownFiltersProduceEmptyConditions(): void {
    $fixture = $this->fixture($this->activeState(), [101], []);

    $fixture->controller->index(Request::create(
      '/api/cap/v1/alerts?jurisdiction_id=child&status=unknown&service_code=NOPE',
    ));

    $this->assertContains(['field_status', [0], 'IN'], $fixture->nodeConditions);
    $this->assertContains(['field_category', [0], 'IN'], $fixture->nodeConditions);
  }

  /**
   * @covers ::index
   */
  public function testArrayJurisdictionIsRejectedBeforeServiceCall(): void {
    $fixture = $this->fixture($this->activeState());
    $fixture->service->expects($this->never())->method('resolveJurisdictionContext');

    $this->expectException(BadRequestHttpException::class);
    $fixture->controller->index(Request::create(
      '/api/cap/v1/alerts?jurisdiction_id[]=child',
    ));
  }

  /**
   * @covers ::index
   * @dataProvider malformedFilterProvider
   */
  public function testMalformedPublicFiltersReturnBadRequest(string $query): void {
    $fixture = $this->fixture($this->activeState());
    $fixture->service->expects($this->never())->method('resolveJurisdictionContext');

    $this->expectException(BadRequestHttpException::class);
    $fixture->controller->index(Request::create('/api/cap/v1/alerts?' . $query));
  }

  /**
   * Provides public query values that previously reached typed operations.
   */
  public static function malformedFilterProvider(): array {
    return [
      'array status' => ['jurisdiction_id=child&status[]=open'],
      'array service code' => ['jurisdiction_id=child&service_code[]=EMG-ROADS'],
      'array start date' => ['jurisdiction_id=child&start_date[]=today'],
      'array page' => ['jurisdiction_id=child&page[]=1'],
      'excessive limit' => ['jurisdiction_id=child&limit=101'],
      'excessive offset' => ['jurisdiction_id=child&offset=1000001'],
    ];
  }

  /**
   * @covers ::index
   */
  public function testEmptyServiceCodeTokensDoNotCreateTaxonomyQueries(): void {
    $fixture = $this->fixture($this->activeState());

    $fixture->controller->index(Request::create(
      '/api/cap/v1/alerts?jurisdiction_id=child&service_code=,,,,,,,,',
    ));

    $this->assertSame([], $fixture->termConditions);
  }

  /**
   * Builds a controller with observable entity-query conditions.
   */
  private function fixture(
    array $modeState,
    array $nodeIds = [101],
    array $termIds = [],
    bool $approvalReady = TRUE,
    int $requestedId = 9,
    int $rootId = 7,
    int $feedChangedAt = 0,
  ): object {
    $fixture = (object) [
      'nodeConditions' => [],
      'termConditions' => [],
      'encodeContext' => [],
    ];

    $nodeQuery = $this->createMock(QueryInterface::class);
    $nodeQuery->method('condition')->willReturnCallback(
      function (string $field, mixed $value = NULL, ?string $operator = NULL) use ($fixture, $nodeQuery): QueryInterface {
        $fixture->nodeConditions[] = [$field, $value, $operator];
        return $nodeQuery;
      },
    );
    $nodeQuery->method('accessCheck')->willReturnSelf();
    $nodeQuery->method('range')->willReturnSelf();
    $nodeQuery->method('sort')->willReturnSelf();
    $nodeQuery->method('execute')->willReturn([]);
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('getQuery')->willReturn($nodeQuery);

    $termQuery = $this->createMock(QueryInterface::class);
    $termQuery->method('condition')->willReturnCallback(
      function (string $field, mixed $value = NULL, ?string $operator = NULL) use ($fixture, $termQuery): QueryInterface {
        $fixture->termConditions[] = [$field, $value, $operator];
        return $termQuery;
      },
    );
    $termQuery->method('accessCheck')->willReturnSelf();
    $termQuery->method('execute')->willReturn($termIds);
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('getQuery')->willReturn($termQuery);
    $termStorage->method('loadByProperties')->willReturn([]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(
      static fn(string $entityType): EntityStorageInterface => $entityType === 'node'
        ? $nodeStorage
        : $termStorage,
    );

    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldDefinitions')->willReturnCallback(
      static fn(string $entityType): array => $entityType === 'node'
        ? [
          'field_jurisdiction' => new \stdClass(),
          'field_cap_publish' => new \stdClass(),
          'field_category' => new \stdClass(),
        ]
        : [
          'field_service_code' => new \stdClass(),
          'field_jurisdiction' => new \stdClass(),
        ],
    );

    $capConfig = $this->createMock(ImmutableConfig::class);
    $capConfig->method('get')->with('bundle')->willReturn('service_request');
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->willReturnMap([
      ['uuid', 'site-uuid'],
      ['name', 'Test site'],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['markaspot_cap.settings', $capConfig],
      ['system.site', $siteConfig],
    ]);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_800_000_000);
    $processor = $this->createMock(CapProcessorService::class);
    $encoder = $this->createMock(CapEncoder::class);
    $encoder->method('encode')->willReturnCallback(
      static function (
        mixed $data,
        string $format,
        array $context = [],
      ) use ($fixture): string {
        $fixture->encodeContext = $context;
        return '<?xml version="1.0"?><alert/>';
      },
    );

    $service = $this->createMock(EmergencyModeService::class);
    $service->method('resolveJurisdictionContext')->willReturn([
      'requested_id' => $requestedId,
      'root_id' => $rootId,
    ]);
    $service->method('getModeState')->with($rootId)->willReturn($modeState);
    $category = $this->createMock(TermInterface::class);
    $category->method('id')->willReturn(40);
    $service->method('getAvailableCategoryTerms')->with($rootId)->willReturn([$category]);
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getNodeIdsInJurisdiction')->with($requestedId)->willReturn($nodeIds);
    $hierarchy->method('getTermJurisdictionIds')->with($rootId)->willReturn([$rootId, $requestedId]);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static fn(string $key, mixed $default = NULL): mixed => match ($key) {
        'markaspot_cap.approval_field_ready' => $approvalReady,
        CapFeedMutationTracker::stateKey($rootId) => $feedChangedAt,
        default => $default,
      },
    );

    $fixture->service = $service;
    $fixture->controller = new CapAlertController(
      $this->createMock(AccountProxyInterface::class),
      $configFactory,
      $time,
      $entityTypeManager,
      $fieldManager,
      $processor,
      $encoder,
      $service,
      $hierarchy,
      $state,
    );
    return $fixture;
  }

  /**
   * Returns a valid active mode state snapshot.
   */
  private function activeState(): array {
    return [
      'status' => 'active',
      'activated_at' => 1_700_000_000,
    ];
  }

}
