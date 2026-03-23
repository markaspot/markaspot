<?php

namespace Drupal\Tests\markaspot_escalation\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_escalation\Controller\EscalationController;
use Drupal\markaspot_escalation\Service\EscalationServiceInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the EscalationController.
 *
 * Covers escalate(), delegate(), and the protected helper methods
 * decodeJsonBody(), loadServiceRequest(), validateNotes(),
 * getEffectiveNodeJurisdictionId(), and validateDelegationScope().
 *
 * @group markaspot_escalation
 * @coversDefaultClass \Drupal\markaspot_escalation\Controller\EscalationController
 */
class EscalationControllerTest extends UnitTestCase {

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_escalation\Controller\EscalationController
   */
  protected $controller;

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked escalation service.
   *
   * @var \Drupal\markaspot_escalation\Service\EscalationServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $escalationService;

  /**
   * Mocked GeoReport processor.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $processor;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * Mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $groupStorage;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->escalationService = $this->createMock(EscalationServiceInterface::class);
    $this->processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['node', $this->nodeStorage],
        ['group', $this->groupStorage],
      ]);

    // Set up container for ControllerBase::currentUser() and
    // ControllerBase::entityTypeManager().
    $container = new ContainerBuilder();
    $container->set('current_user', $this->currentUser);
    $container->set('entity_type.manager', $this->entityTypeManager);
    \Drupal::setContainer($container);

    $this->controller = new EscalationController(
      $this->entityTypeManager,
      $this->escalationService,
      $this->processor,
      $this->logger,
    );
  }

  // ===========================================================================
  // Tests for decodeJsonBody (tested via escalate/delegate).
  // ===========================================================================

  /**
   * Tests that an empty request body throws BadRequestHttpException.
   *
   * @covers ::escalate
   */
  public function testEscalateWithEmptyBodyThrowsBadRequest(): void {
    $request = new Request([], [], [], [], [], [], '');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Request body is required.');

    $this->controller->escalate('123-2026', $request);
  }

  /**
   * Tests that invalid JSON in request body throws BadRequestHttpException.
   *
   * @covers ::escalate
   */
  public function testEscalateWithInvalidJsonThrowsBadRequest(): void {
    $request = new Request([], [], [], [], [], [], '{invalid json');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid JSON in request body.');

    $this->controller->escalate('123-2026', $request);
  }

  // ===========================================================================
  // Tests for loadServiceRequest (tested via escalate).
  // ===========================================================================

  /**
   * Tests that an invalid service request ID format throws BadRequestHttpException.
   *
   * @covers ::escalate
   */
  public function testEscalateWithInvalidIdFormatThrowsBadRequest(): void {
    $request = new Request([], [], [], [], [], [], '{"notes":"test"}');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid service request ID format.');

    $this->controller->escalate('123 <script>', $request);
  }

  /**
   * Tests that a non-existent service request throws NotFoundHttpException.
   *
   * @covers ::escalate
   */
  public function testEscalateWithNonExistentRequestThrowsNotFound(): void {
    $request = new Request([], [], [], [], [], [], '{"notes":"test"}');
    $this->nodeStorage->method('loadByProperties')->willReturn([]);

    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('Service request not found.');

    $this->controller->escalate('999-2026', $request);
  }

  // ===========================================================================
  // Tests for escalate().
  // ===========================================================================

  /**
   * Tests that escalation is denied when user lacks permission.
   *
   * @covers ::escalate
   */
  public function testEscalateAccessDenied(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(FALSE);

    $request = new Request([], [], [], [], [], [], '{"notes":"test note"}');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('You do not have permission to escalate this request.');

    $this->controller->escalate('123-2026', $request);
  }

  /**
   * Tests that notes are required for escalation.
   *
   * @covers ::escalate
   */
  public function testEscalateRequiresNotes(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);

    $request = new Request([], [], [], [], [], [], '{"notes":""}');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('The "notes" field is required.');

    $this->controller->escalate('123-2026', $request);
  }

  /**
   * Tests that missing escalation target returns 422.
   *
   * @covers ::escalate
   */
  public function testEscalateNoTargetThrows422(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveEscalationTarget')->willReturn(NULL);

    $request = new Request([], [], [], [], [], [], '{"notes":"valid note"}');

    $this->expectException(HttpException::class);
    $this->expectExceptionMessage('No escalation target could be determined');

    $this->controller->escalate('123-2026', $request);
  }

  /**
   * Tests successful escalation returns proper JSON response.
   *
   * @covers ::escalate
   */
  public function testEscalateSuccess(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveEscalationTarget')->willReturn(10);

    $targetGroup = $this->createMock(GroupInterface::class);
    $targetGroup->method('label')->willReturn('Parent Jurisdiction');
    $this->groupStorage->method('load')->with(10)->willReturn($targetGroup);

    $request = new Request([], [], [], [], [], [], '{"notes":"escalation reason"}');

    $response = $this->controller->escalate('123-2026', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['service_requests']['request']['escalated']);
    $this->assertEquals('123-2026', $data['service_requests']['request']['service_request_id']);
    $this->assertEquals('Parent Jurisdiction', $data['service_requests']['request']['escalation_target']);
  }

  /**
   * Tests that InvalidArgumentException during escalation returns 422.
   *
   * @covers ::escalate
   */
  public function testEscalateInvalidArgumentReturns422(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveEscalationTarget')->willReturn(10);
    $this->escalationService->method('escalateRequest')
      ->willThrowException(new \InvalidArgumentException('bad target'));

    $request = new Request([], [], [], [], [], [], '{"notes":"escalation reason"}');

    $this->expectException(HttpException::class);
    $this->expectExceptionMessage('Invalid escalation target.');

    $this->controller->escalate('123-2026', $request);
  }

  // ===========================================================================
  // Tests for delegate().
  // ===========================================================================

  /**
   * Tests that delegation is denied when user lacks permission.
   *
   * @covers ::delegate
   */
  public function testDelegateAccessDenied(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canDelegate')->willReturn(FALSE);

    $request = new Request([], [], [], [], [], [], '{"target_organisation":5}');

    $this->expectException(AccessDeniedHttpException::class);

    $this->controller->delegate('123-2026', $request);
  }

  /**
   * Tests that missing target_organisation field throws BadRequest.
   *
   * @covers ::delegate
   */
  public function testDelegateMissingTargetOrgThrowsBadRequest(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canDelegate')->willReturn(TRUE);

    $request = new Request([], [], [], [], [], [], '{"notes":"test"}');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('The "target_organisation" field is required');

    $this->controller->delegate('123-2026', $request);
  }

  /**
   * Tests that an invalid target org (wrong bundle) throws 422.
   *
   * @covers ::delegate
   */
  public function testDelegateInvalidOrgBundleThrows422(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canDelegate')->willReturn(TRUE);

    $orgGroup = $this->createMock(GroupInterface::class);
    $orgGroup->method('bundle')->willReturn('jur');
    $this->groupStorage->method('load')->with(5)->willReturn($orgGroup);

    $request = new Request([], [], [], [], [], [], '{"target_organisation":5}');

    $this->expectException(HttpException::class);
    $this->expectExceptionMessage('Invalid target organisation.');

    $this->controller->delegate('123-2026', $request);
  }

  /**
   * Tests that a non-existent target org throws 422.
   *
   * @covers ::delegate
   */
  public function testDelegateNonExistentOrgThrows422(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canDelegate')->willReturn(TRUE);

    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $request = new Request([], [], [], [], [], [], '{"target_organisation":999}');

    $this->expectException(HttpException::class);
    $this->expectExceptionMessage('Invalid target organisation.');

    $this->controller->delegate('123-2026', $request);
  }

  // ===========================================================================
  // Tests for validateNotes().
  // ===========================================================================

  /**
   * Tests that notes are stripped of HTML tags.
   *
   * @covers ::escalate
   */
  public function testNotesStripsHtmlTags(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveEscalationTarget')->willReturn(10);

    $targetGroup = $this->createMock(GroupInterface::class);
    $targetGroup->method('label')->willReturn('Target');
    $this->groupStorage->method('load')->with(10)->willReturn($targetGroup);

    // The escalation service should receive notes with tags stripped.
    $this->escalationService->expects($this->once())
      ->method('escalateRequest')
      ->with(
        $this->anything(),
        10,
        'clean note'
      );

    $request = new Request([], [], [], [], [], [], '{"notes":"<script>clean note</script>"}');
    $this->controller->escalate('123-2026', $request);
  }

  /**
   * Tests that notes are truncated to the maximum length.
   *
   * @covers ::escalate
   */
  public function testNotesTruncatedToMaxLength(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveEscalationTarget')->willReturn(10);

    $targetGroup = $this->createMock(GroupInterface::class);
    $targetGroup->method('label')->willReturn('Target');
    $this->groupStorage->method('load')->with(10)->willReturn($targetGroup);

    // Notes should be truncated at NOTES_MAX_LENGTH (2000).
    $this->escalationService->expects($this->once())
      ->method('escalateRequest')
      ->with(
        $this->anything(),
        10,
        $this->callback(fn($notes) => mb_strlen($notes) === EscalationController::NOTES_MAX_LENGTH)
      );

    $longNotes = str_repeat('a', 3000);
    $request = new Request([], [], [], [], [], [], json_encode(['notes' => $longNotes]));
    $this->controller->escalate('123-2026', $request);
  }

  /**
   * Tests that notes are not required for delegation.
   *
   * @covers ::delegate
   */
  public function testDelegateNotesNotRequired(): void {
    $node = $this->createMockNodeWithFields([]);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canDelegate')->willReturn(TRUE);

    $orgGroup = $this->createMockOrgInJurisdiction(5, 10);
    $this->groupStorage->method('load')->willReturnMap([
      [5, $orgGroup],
    ]);

    // Node has field_escalation pointing to jur 10.
    $this->processor->method('getJurisdictionIdFromNode')->willReturn(10);

    $this->escalationService->expects($this->once())
      ->method('delegateRequest');

    $request = new Request([], [], [], [], [], [], '{"target_organisation":5}');
    // Should not throw even without notes.
    $this->controller->delegate('123-2026', $request);
  }

  // ===========================================================================
  // Helper methods.
  // ===========================================================================

  /**
   * Creates a mock node with configurable field values.
   *
   * @param array $fields
   *   Associative array of field_name => value pairs.
   *   Supported: 'field_escalation' (int target_id or NULL),
   *   'field_jurisdiction' (int target_id or NULL).
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked node.
   */
  protected function createMockNodeWithFields(array $fields): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(100);

    $fieldMap = [];
    foreach ($fields as $fieldName => $value) {
      if ($value !== NULL) {
        $fieldMap[$fieldName] = new class($value) {

          /**
           * The referenced entity target ID.
           *
           * @var int
           */
          // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
          public int $target_id;

          /**
           * Constructs a field item stub.
           */
          public function __construct(int $targetId) {
            $this->target_id = $targetId;
          }

          /**
           * Checks whether the field item is empty.
           */
          public function isEmpty(): bool {
            return FALSE;
          }

        };
      }
      else {
        $fieldMap[$fieldName] = new class() {

          /**
           * Checks whether the field item is empty.
           */
          public function isEmpty(): bool {
            return TRUE;
          }

        };
      }
    }

    $node->method('hasField')
      ->willReturnCallback(fn($name) => isset($fieldMap[$name]));
    $node->method('get')
      ->willReturnCallback(function ($name) use ($fieldMap) {
        if (isset($fieldMap[$name])) {
          return $fieldMap[$name];
        }
        $empty = $this->createMock(FieldItemListInterface::class);
        $empty->method('isEmpty')->willReturn(TRUE);
        return $empty;
      });

    return $node;
  }

  /**
   * Creates a mock org group within a jurisdiction.
   *
   * @param int $id
   *   The org group ID.
   * @param int $jurId
   *   The jurisdiction group ID.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked org group.
   */
  protected function createMockOrgInJurisdiction(int $id, int $jurId): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('org');
    $group->method('label')->willReturn("Org $id");

    $jurField = new class($jurId) {

      /**
       * The referenced entity target ID.
       *
       * @var int
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public int $target_id;

      /**
       * Constructs a field item stub.
       */
      public function __construct(int $targetId) {
        $this->target_id = $targetId;
      }

      /**
       * Checks whether the field item is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $group->method('hasField')
      ->willReturnCallback(fn($name) => $name === 'field_jurisdiction');
    $group->method('get')
      ->willReturnCallback(function ($name) use ($jurField) {
        if ($name === 'field_jurisdiction') {
          return $jurField;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    return $group;
  }

}
