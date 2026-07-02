<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_dashboard\Controller\SplitController;
use Drupal\markaspot_dashboard\Service\RequestLinkServiceInterface;
use Drupal\markaspot_dashboard\Service\SplitRequestServiceInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the SplitController REST endpoints.
 *
 * @group markaspot_dashboard
 * @coversDefaultClass \Drupal\markaspot_dashboard\Controller\SplitController
 */
class SplitControllerTest extends UnitTestCase {

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mocked split-request service.
   *
   * @var \Drupal\markaspot_dashboard\Service\SplitRequestServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $splitRequestService;

  /**
   * Mocked request-link service.
   *
   * @var \Drupal\markaspot_dashboard\Service\RequestLinkServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestLinkService;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * A stub jurisdiction scope validator (duck-typed, like the controller).
   *
   * @var object
   */
  protected $scopeValidator;

  /**
   * Mocked jurisdiction hierarchy resolver.
   *
   * NULL by default (matches the controller's own default), so existing
   * direct-membership tests are unaffected. Tests covering the ancestor
   * expansion set this to a mock before calling buildController().
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|\PHPUnit\Framework\MockObject\MockObject|null
   */
  protected $hierarchyResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($this->nodeStorage);

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentUser->method('id')->willReturn(12);
    $this->currentUser->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $this->splitRequestService = $this->createMock(SplitRequestServiceInterface::class);
    $this->requestLinkService = $this->createMock(RequestLinkServiceInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    // Duck-typed scope validator: allows jurisdiction 10 by default.
    $this->scopeValidator = new class() {

      /**
       * The jurisdiction group IDs the stub reports as allowed.
       *
       * @var int[]
       */
      public array $allowed = [10];

      /**
       * Returns the allowed jurisdiction IDs for any account.
       */
      public function getAllowedJurisdictionIds($account): array {
        return $this->allowed;
      }

    };
  }

  /**
   * Builds the controller with the current mocked collaborators.
   */
  protected function buildController(): SplitController {
    return new SplitController(
      $this->entityTypeManager,
      $this->currentUser,
      $this->splitRequestService,
      $this->requestLinkService,
      $this->logger,
      $this->scopeValidator,
      $this->hierarchyResolver,
    );
  }

  /**
   * Builds a mock service_request node.
   */
  protected function createServiceRequestNode(int $nid = 88, string $title = 'Broken bench'): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('bundle')->willReturn('service_request');
    $node->method('getTitle')->willReturn($title);
    $node->method('access')->willReturn(TRUE);
    $node->method('hasField')->willReturn(FALSE);
    return $node;
  }

  /**
   * Builds a duck-typed request_id field stub.
   *
   * FieldItemListInterface does not declare __get(), so a PHPUnit mock of
   * the interface cannot support the ->value magic-property access the
   * controller uses. A plain stub object with a public $value property
   * mirrors the real FieldItemList's delegated property access.
   */
  private function createRequestIdFieldStub(string $value): object {
    return new class($value) {

      /**
       * Constructs a field item stub.
       */
      public function __construct(public readonly string $value) {
      }

      /**
       * Checks whether the field item is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };
  }

  // ===========================================================================
  // split() — node resolution / access gates.
  // ===========================================================================

  /**
   * @covers ::split
   */
  public function testSplitNotFoundReturns404(): void {
    $this->nodeStorage->method('load')->with(88)->willReturn(NULL);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{}'));

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('Service request not found.', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * @covers ::split
   */
  public function testSplitWrongBundleReturns404(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');
    $this->nodeStorage->method('load')->with(88)->willReturn($node);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{}'));

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * @covers ::split
   */
  public function testSplitAccessDeniedReturns403(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(88);
    $node->method('bundle')->willReturn('service_request');
    $node->method('access')->with('update')->willReturn(FALSE);
    $this->nodeStorage->method('load')->with(88)->willReturn($node);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{}'));

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * @covers ::split
   */
  public function testSplitUnresolvableJurisdictionReturns403(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(NULL);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{}'));

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * A non-global user outside the node's jurisdiction is refused.
   *
   * Fail-closed pattern (#482): the scope validator does not list
   * jurisdiction 99.
   *
   * @covers ::split
   */
  public function testSplitJurisdictionGateRefusesForeignJurisdiction(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(99);
    // scopeValidator only allows jurisdiction 10 (see setUp()).
    $this->currentUser->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{"category_tid":1,"description":"x"}'));

    $this->assertSame(403, $response->getStatusCode());
    $this->splitRequestService->expects($this->never())->method('split');
  }

  /**
   * A root member is allowed to act on a node in a child jurisdiction.
   *
   * Boundary-matched on insert, mirroring DuplicateController's hierarchy
   * walk: a direct member of a ROOT jurisdiction is not a direct member of
   * every CHILD jurisdiction under it.
   *
   * @covers ::split
   */
  public function testSplitAllowsRootMemberActingOnChildJurisdictionNode(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    // The node lives in child jurisdiction 20; the user is only a direct
    // member of root jurisdiction 10 (see setUp()'s scopeValidator).
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(20);
    $this->splitRequestService->method('isCategoryInJurisdiction')->willReturn(TRUE);

    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->hierarchyResolver->method('getDescendantIds')->with(10)->willReturn([10, 20]);

    $child = $this->createServiceRequestNode(456);
    $this->splitRequestService->method('split')->willReturn(['child' => $child, 'original' => $node]);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{"category_tid":1,"description":"x"}'));

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * An unrelated jurisdiction stays refused even with a hierarchy resolver.
   *
   * The user's only membership is an unrelated jurisdiction: the target
   * jurisdiction is not among its descendants either.
   *
   * @covers ::split
   */
  public function testSplitRefusesUnrelatedJurisdictionEvenWithHierarchyResolver(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(99);

    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    // Root jurisdiction 10's subtree does not contain 99.
    $this->hierarchyResolver->method('getDescendantIds')->with(10)->willReturn([10, 20]);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{"category_tid":1,"description":"x"}'));

    $this->assertSame(403, $response->getStatusCode());
    $this->splitRequestService->expects($this->never())->method('split');
  }

  /**
   * Uid 1 bypasses the jurisdiction gate entirely.
   *
   * Mirrors InboundMailAccessControlHandler::hasGlobalBypass().
   *
   * @covers ::split
   */
  public function testSplitGlobalBypassIgnoresScopeValidator(): void {
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentUser->method('id')->willReturn(1);
    $this->currentUser->method('getRoles')->willReturn([]);

    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(99);
    $this->splitRequestService->method('isCategoryInJurisdiction')->willReturn(TRUE);
    $this->splitRequestService->method('split')->willReturn([
      'child' => $this->createServiceRequestNode(456, 'Broken bench (split)'),
      'original' => $node,
    ]);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{"category_tid":1,"description":"x"}'));

    $this->assertSame(200, $response->getStatusCode());
  }

  // ===========================================================================
  // split() — payload validation (400 shape).
  // ===========================================================================

  /**
   * @covers ::split
   */
  public function testSplitInvalidJsonReturns400(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{invalid'));

    $this->assertSame(400, $response->getStatusCode());
    $this->assertArrayHasKey('error', json_decode($response->getContent(), TRUE));
  }

  /**
   * @covers ::split
   */
  public function testSplitMissingCategoryReturns400(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{"description":"broken bench needs repair"}'));

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('category_tid is required.', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * @covers ::split
   */
  public function testSplitMissingDescriptionReturns400(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{"category_tid":5}'));

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('description is required.', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * @covers ::split
   */
  public function testSplitBlankDescriptionReturns400(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], '{"category_tid":5,"description":"   "}'));

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::split
   */
  public function testSplitDescriptionTooLongReturns400(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);

    $longDescription = str_repeat('a', 10001);
    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], json_encode([
      'category_tid' => 5,
      'description' => $longDescription,
    ])));

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::split
   */
  public function testSplitMediaIdsNotArrayReturns400(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], json_encode([
      'category_tid' => 5,
      'description' => 'valid text',
      'media_ids' => 'not-an-array',
    ])));

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Media subset validation rejects a foreign media ID.
   *
   * A media ID not present on the source's field_request_media is rejected.
   *
   * @covers ::split
   */
  public function testSplitMediaIdsNotSubsetReturns400(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(88);
    $node->method('bundle')->willReturn('service_request');
    $node->method('getTitle')->willReturn('Broken bench');
    $node->method('access')->willReturn(TRUE);
    $node->method('hasField')->with('field_request_media')->willReturn(TRUE);
    $mediaField = $this->createMock(FieldItemListInterface::class);
    $mediaField->method('getValue')->willReturn([['target_id' => 45]]);
    $node->method('get')->with('field_request_media')->willReturn($mediaField);

    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], json_encode([
      'category_tid' => 5,
      'description' => 'valid text',
      // 99 is not among the source's media (only 45 is).
      'media_ids' => [45, 99],
    ])));

    $this->assertSame(400, $response->getStatusCode());
    $this->splitRequestService->expects($this->never())->method('split');
  }

  /**
   * @covers ::split
   */
  public function testSplitForeignCategoryReturns422(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);
    $this->splitRequestService->method('isCategoryInJurisdiction')->with(5, 10)->willReturn(FALSE);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], json_encode([
      'category_tid' => 5,
      'description' => 'valid text',
    ])));

    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('The category is not valid for this jurisdiction.', json_decode($response->getContent(), TRUE)['error']);
  }

  // ===========================================================================
  // split() — success + failure orchestration.
  // ===========================================================================

  /**
   * @covers ::split
   */
  public function testSplitSuccessReturnsExpectedShape(): void {
    $node = $this->createServiceRequestNode(88, 'Broken bench');
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);
    $this->splitRequestService->method('isCategoryInJurisdiction')->willReturn(TRUE);

    $child = $this->createMock(NodeInterface::class);
    $child->method('id')->willReturn(456);
    $child->method('uuid')->willReturn('child-uuid');
    $child->method('getTitle')->willReturn('Broken bench');
    $child->method('hasField')->with('request_id')->willReturn(TRUE);
    $child->method('get')->with('request_id')->willReturn($this->createRequestIdFieldStub('89-2026'));

    $this->splitRequestService->expects($this->once())
      ->method('split')
      ->with($node, $this->callback(function (array $payload) {
        return $payload['category_tid'] === 5
          && $payload['description'] === 'valid text'
          && !array_key_exists('title', $payload)
          && $payload['copy_reporter'] === TRUE
          && $payload['notify_citizen'] === TRUE
          && $payload['media_ids'] === [];
      }), $this->currentUser)
      ->willReturn(['child' => $child, 'original' => $node]);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], json_encode([
      'category_tid' => 5,
      'description' => 'valid text',
    ])));

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(456, $data['child']['nid']);
    $this->assertSame('child-uuid', $data['child']['uuid']);
    $this->assertSame('89-2026', $data['child']['request_id']);
    $this->assertSame(88, $data['original']['nid']);
    $this->assertSame('Request split successfully.', $data['message']);
  }

  /**
   * Request_id falls back to the nid as a string, never NULL.
   *
   * After a successful save request_id is guaranteed by
   * markaspot_request_id_node_presave(); the fallback only covers nodes
   * loaded outside that guarantee. The wire contract has no NULL branch.
   *
   * @covers ::split
   * @covers ::nodeRequestId
   */
  public function testSplitFallsBackRequestIdToNidStringWhenFieldMissing(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);
    $this->splitRequestService->method('isCategoryInJurisdiction')->willReturn(TRUE);

    // createServiceRequestNode() stubs hasField() to FALSE for everything,
    // including 'request_id'.
    $child = $this->createServiceRequestNode(456);

    $this->splitRequestService->method('split')->willReturn(['child' => $child, 'original' => $node]);

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], json_encode([
      'category_tid' => 5,
      'description' => 'valid text',
    ])));

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('456', $data['child']['request_id']);
    $this->assertSame('88', $data['original']['request_id']);
  }

  /**
   * Copy_reporter=false and notify_citizen=false are honored (not defaulted).
   *
   * @covers ::split
   */
  public function testSplitHonorsExplicitFalseFlags(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);
    $this->splitRequestService->method('isCategoryInJurisdiction')->willReturn(TRUE);

    $child = $this->createServiceRequestNode(456);

    $this->splitRequestService->expects($this->once())
      ->method('split')
      ->with($node, $this->callback(function (array $payload) {
        return $payload['copy_reporter'] === FALSE && $payload['notify_citizen'] === FALSE;
      }), $this->currentUser)
      ->willReturn(['child' => $child, 'original' => $node]);

    $this->buildController()->split(88, new Request([], [], [], [], [], [], json_encode([
      'category_tid' => 5,
      'description' => 'valid text',
      'copy_reporter' => FALSE,
      'notify_citizen' => FALSE,
    ])));
  }

  /**
   * @covers ::split
   */
  public function testSplitServiceThrowsReturns500(): void {
    $node = $this->createServiceRequestNode();
    $this->nodeStorage->method('load')->with(88)->willReturn($node);
    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);
    $this->splitRequestService->method('isCategoryInJurisdiction')->willReturn(TRUE);
    $this->splitRequestService->method('split')->willThrowException(new \RuntimeException('boom'));

    $response = $this->buildController()->split(88, new Request([], [], [], [], [], [], json_encode([
      'category_tid' => 5,
      'description' => 'valid text',
    ])));

    $this->assertSame(500, $response->getStatusCode());
    $this->assertSame('Split failed. The error has been logged.', json_decode($response->getContent(), TRUE)['error']);
  }

  // ===========================================================================
  // links().
  // ===========================================================================

  /**
   * @covers ::links
   */
  public function testLinksNotFoundReturns404(): void {
    $this->nodeStorage->method('load')->with(88)->willReturn(NULL);

    $response = $this->buildController()->links(88);

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * @covers ::links
   */
  public function testLinksAccessDeniedReturns403(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(88);
    $node->method('bundle')->willReturn('service_request');
    $node->method('access')->with('view')->willReturn(FALSE);
    $this->nodeStorage->method('load')->with(88)->willReturn($node);

    $response = $this->buildController()->links(88);

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Builds the JSON shape with both relationship directions.
   *
   * Also filters out linked nodes the current user cannot view.
   *
   * @covers ::links
   */
  public function testLinksBuildsBothDirectionsAndFiltersInaccessible(): void {
    $node = $this->createServiceRequestNode(88, 'Original');
    $node->method('hasField')->with('request_id')->willReturn(TRUE);
    $node->method('get')->with('request_id')->willReturn($this->createRequestIdFieldStub('88-2026'));

    $this->nodeStorage->method('load')->willReturnMap([
      [88, $node],
      [456, $this->createVisibleLinkedNode(456, 'Child', '89-2026')],
      [457, $this->createInvisibleLinkedNode(457)],
    ]);

    $this->splitRequestService->method('resolveJurisdictionForNode')->willReturn(10);
    $this->requestLinkService->method('getLinksForNode')->with(88)->willReturn([
      // Node 88 is the SOURCE of this row -> split_from_this.
      ['source_nid' => 88, 'target_nid' => 456, 'link_type' => 'split', 'uid' => 12, 'created' => 1751462400],
      // Node 88 is the TARGET of this row -> this_was_split_from; filtered
      // out because the linked node is not viewable.
      ['source_nid' => 457, 'target_nid' => 88, 'link_type' => 'split', 'uid' => 12, 'created' => 1751462300],
    ]);

    $response = $this->buildController()->links(88);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(1, $data['count']);
    $this->assertSame('split_from_this', $data['links'][0]['relationship']);
    $this->assertSame(456, $data['links'][0]['nid']);
    $this->assertSame('89-2026', $data['links'][0]['request_id']);
  }

  /**
   * Builds a linked node the current user can view.
   */
  private function createVisibleLinkedNode(int $nid, string $title, string $requestId): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('getTitle')->willReturn($title);
    $node->method('access')->with('view')->willReturn(TRUE);
    $node->method('hasField')->with('request_id')->willReturn(TRUE);
    $node->method('get')->with('request_id')->willReturn($this->createRequestIdFieldStub($requestId));
    return $node;
  }

  /**
   * Builds a linked node the current user cannot view.
   */
  private function createInvisibleLinkedNode(int $nid): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('access')->with('view')->willReturn(FALSE);
    return $node;
  }

}
