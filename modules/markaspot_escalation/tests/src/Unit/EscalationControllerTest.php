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

  /**
   * Tests invalid service request ID format throws BadRequestHttpException.
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
   * Tests that escalation notes are optional when the flag is absent or FALSE.
   *
   * @dataProvider optionalDelegationNoteConfigProvider
   *
   * @covers ::escalate
   */
  public function testEscalateAllowsEmptyNotesWhenNoteFlagMissingOrFalse(?string $nuxtConfig): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveRequestJurisdictionId')->willReturn(9);
    $this->escalationService->method('resolveEscalationTarget')->willReturn(10);

    $jurGroup = $this->createMockJurisdictionWithNuxtConfig($nuxtConfig, 9, 'Current Jurisdiction');
    $targetGroup = $this->createMockJurisdictionWithNuxtConfig(NULL, 10, 'Parent Jurisdiction');
    $this->groupStorage->method('load')->willReturnMap([
      [9, $jurGroup],
      [10, $targetGroup],
    ]);

    $this->escalationService->expects($this->once())
      ->method('escalateRequest')
      ->with($node, 10, '');

    $request = new Request([], [], [], [], [], [], '{"notes":""}');

    $response = $this->controller->escalate('123-2026', $request);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that delegation notes are required when the config flag is TRUE.
   *
   * @covers ::delegate
   */
  public function testDelegateRequiresNotesWhenJurisdictionFlagTrue(): void {
    $node = $this->createMockNodeWithFields([]);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canDelegate')->willReturn(TRUE);
    $this->escalationService->method('resolveRequestJurisdictionId')->willReturn(10);

    $orgGroup = $this->createMockOrgInJurisdiction(5, 10);
    $jurGroup = $this->createMockJurisdictionWithNuxtConfig('{"features":{"delegationNoteRequired":true}}', 10);
    $this->groupStorage->method('load')->willReturnMap([
      [5, $orgGroup],
      [10, $jurGroup],
    ]);
    $this->processor->method('getJurisdictionIdFromNode')->willReturn(10);

    $this->escalationService->expects($this->never())->method('delegateRequest');

    $request = new Request([], [], [], [], [], [], '{"target_organisation":5,"notes":""}');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('The "notes" field is required.');

    $this->controller->delegate('123-2026', $request);
  }

  /**
   * Tests that escalation notes are required when the config flag is TRUE.
   *
   * @covers ::escalate
   */
  public function testEscalateRequiresNotesWhenJurisdictionFlagTrue(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveRequestJurisdictionId')->willReturn(9);

    $jurGroup = $this->createMockJurisdictionWithNuxtConfig('{"features":{"delegationNoteRequired":true}}', 9, 'Current Jurisdiction');
    $this->groupStorage->method('load')->willReturnMap([
      [9, $jurGroup],
    ]);

    $this->escalationService->expects($this->never())->method('escalateRequest');

    $request = new Request([], [], [], [], [], [], '{"notes":""}');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('The "notes" field is required.');

    $this->controller->escalate('123-2026', $request);
  }

  /**
   * Tests that notes pass when the config flag is TRUE and notes are filled.
   *
   * @covers ::escalate
   */
  public function testEscalateAllowsFilledNotesWhenJurisdictionFlagTrue(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveRequestJurisdictionId')->willReturn(9);
    $this->escalationService->method('resolveEscalationTarget')->willReturn(10);

    $jurGroup = $this->createMockJurisdictionWithNuxtConfig('{"features":{"delegationNoteRequired":true}}', 9, 'Current Jurisdiction');
    $targetGroup = $this->createMockJurisdictionWithNuxtConfig(NULL, 10, 'Parent Jurisdiction');
    $this->groupStorage->method('load')->willReturnMap([
      [9, $jurGroup],
      [10, $targetGroup],
    ]);

    $this->escalationService->expects($this->once())
      ->method('escalateRequest')
      ->with($node, 10, 'configured reason');

    $request = new Request([], [], [], [], [], [], '{"notes":"configured reason"}');

    $response = $this->controller->escalate('123-2026', $request);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that invalid config JSON leaves delegation notes optional.
   *
   * @covers ::delegate
   */
  public function testDelegateAllowsEmptyNotesWhenNuxtConfigJsonIsInvalid(): void {
    $node = $this->createMockNodeWithFields([]);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canDelegate')->willReturn(TRUE);
    $this->escalationService->method('resolveRequestJurisdictionId')->willReturn(10);

    $orgGroup = $this->createMockOrgInJurisdiction(5, 10);
    $jurGroup = $this->createMockJurisdictionWithNuxtConfig('{invalid json', 10);
    $this->groupStorage->method('load')->willReturnMap([
      [5, $orgGroup],
      [10, $jurGroup],
    ]);
    $this->processor->method('getJurisdictionIdFromNode')->willReturn(10);

    $this->escalationService->expects($this->once())
      ->method('delegateRequest')
      ->with($node, 5, '');

    $request = new Request([], [], [], [], [], [], '{"target_organisation":5}');

    $response = $this->controller->delegate('123-2026', $request);

    $this->assertEquals(200, $response->getStatusCode());
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
    $targetGroup->method('bundle')->willReturn('jur');
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
   * Tests successful org escalation returns a delegation response shape.
   *
   * @covers ::escalate
   */
  public function testEscalateParentOrgResponseDoesNotExposeEscalationTarget(): void {
    $node = $this->createMock(NodeInterface::class);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canEscalate')->willReturn(TRUE);
    $this->escalationService->method('resolveEscalationTarget')->willReturn(20);

    $targetGroup = $this->createMock(GroupInterface::class);
    $targetGroup->method('bundle')->willReturn('org');
    $targetGroup->method('label')->willReturn('Parent Org');
    $this->groupStorage->method('load')->with(20)->willReturn($targetGroup);

    $this->escalationService->expects($this->once())
      ->method('escalateRequest')
      ->with($node, 20, 'escalation reason');

    $request = new Request([], [], [], [], [], [], '{"notes":"escalation reason"}');

    $response = $this->controller->escalate('123-2026', $request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['service_requests']['request']['escalated']);
    $this->assertTrue($data['service_requests']['request']['delegated']);
    $this->assertArrayNotHasKey('escalation_target', $data['service_requests']['request']);
    // The delegated branch echoes the moved-to org label (same exposure as
    // the delegate endpoint's own response) so clients can confirm the
    // target even when the server-resolved step changed since page load.
    $this->assertSame('Parent Org', $data['service_requests']['request']['target_organisation']);
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
   * Tests that delegation to an org outside the node's jur hierarchy is 422.
   *
   * ValidateDelegationScope() is the sole target-side cross-tenant boundary
   * for lateral delegation: the target org's jurisdiction must be the node's
   * jurisdiction or reach it via the field_parent_jurisdiction walk-up.
   *
   * @covers ::delegate
   */
  public function testDelegateRejectsOrgOutsideJurisdictionHierarchy(): void {
    $node = $this->createMockNodeWithFields([]);
    $this->nodeStorage->method('loadByProperties')->willReturn([$node]);
    $this->escalationService->method('canDelegate')->willReturn(TRUE);

    // Target org 5 belongs to foreign jurisdiction 99; the node's effective
    // jurisdiction is 10. Jur 99 has no parent, so the walk-up never
    // reaches 10.
    $orgGroup = $this->createMockOrgInJurisdiction(5, 99);
    $foreignJur = $this->createMock(GroupInterface::class);
    $foreignJur->method('id')->willReturn(99);
    $foreignJur->method('bundle')->willReturn('jur');
    $foreignJur->method('hasField')->with('field_parent_jurisdiction')->willReturn(FALSE);
    $nodeJur = $this->createMock(GroupInterface::class);
    $nodeJur->method('id')->willReturn(10);
    $nodeJur->method('bundle')->willReturn('jur');

    $this->groupStorage->method('load')->willReturnMap([
      [5, $orgGroup],
      [99, $foreignJur],
      [10, $nodeJur],
    ]);
    $this->processor->method('getJurisdictionIdFromNode')->willReturn(10);

    $this->escalationService->expects($this->never())->method('delegateRequest');

    $request = new Request([], [], [], [], [], [], json_encode(['target_organisation' => 5]));

    try {
      $this->controller->delegate('123-2026', $request);
      $this->fail('Expected 422 for a cross-jurisdiction delegation target.');
    }
    catch (HttpException $e) {
      $this->assertSame(422, $e->getStatusCode());
    }
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

  /**
   * Data provider for configs that keep delegation notes optional.
   *
   * @return array
   *   Nuxt config payloads without a TRUE delegationNoteRequired flag.
   */
  public static function optionalDelegationNoteConfigProvider(): array {
    return [
      'absent field' => [NULL],
      'empty field' => [''],
      'missing flag' => ['{"features":{}}'],
      'false flag' => ['{"features":{"delegationNoteRequired":false}}'],
      'scalar JSON' => ['true'],
      'null JSON' => ['null'],
    ];
  }

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
   * Creates a mock jurisdiction group with optional field_nuxt_config JSON.
   *
   * @param string|null $nuxtConfig
   *   The raw field_nuxt_config value, or NULL when the field is absent.
   * @param int $id
   *   The jurisdiction group ID.
   * @param string $label
   *   The jurisdiction label.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked jurisdiction group.
   */
  protected function createMockJurisdictionWithNuxtConfig(?string $nuxtConfig, int $id = 10, string $label = 'Jurisdiction'): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('jur');
    $group->method('label')->willReturn($label);

    $configField = NULL;
    if ($nuxtConfig !== NULL) {
      $configField = new class($nuxtConfig) {

        /**
         * The raw Nuxt config JSON value.
         *
         * @var string
         */
        public string $value;

        /**
         * Constructs a field item stub.
         */
        public function __construct(string $value) {
          $this->value = $value;
        }

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return trim($this->value) === '';
        }

      };
    }

    $group->method('hasField')
      ->willReturnCallback(static fn($name) => $name === 'field_nuxt_config' && $nuxtConfig !== NULL);
    $group->method('get')
      ->willReturnCallback(function ($name) use ($configField) {
        if ($name === 'field_nuxt_config' && $configField !== NULL) {
          return $configField;
        }
        $empty = $this->createMock(FieldItemListInterface::class);
        $empty->method('isEmpty')->willReturn(TRUE);
        return $empty;
      });

    return $group;
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
