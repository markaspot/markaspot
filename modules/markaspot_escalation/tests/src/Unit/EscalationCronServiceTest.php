<?php

namespace Drupal\Tests\markaspot_escalation\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\markaspot_escalation\Service\EscalationCronService;
use Drupal\markaspot_escalation\Service\EscalationServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the EscalationCronService.
 *
 * Covers processEscalations() including node filtering, time threshold
 * calculation, category escalation config checks, and escalation execution.
 *
 * @group markaspot_escalation
 * @coversDefaultClass \Drupal\markaspot_escalation\Service\EscalationCronService
 */
class EscalationCronServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_escalation\Service\EscalationCronService
   */
  protected $service;

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
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

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
   * Mocked entity query.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $query;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->escalationService = $this->createMock(EscalationServiceInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn($type) => $type === 'node' ? $this->nodeStorage : NULL);

    // Default open311 config: closed status TID = 6.
    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->willReturnMap([
        ['status_closed', [6 => '6']],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['markaspot_open311.settings', $open311Config],
      ]);

    // Build a fluent query mock.
    $this->query = $this->createMock(QueryInterface::class);
    $this->query->method('accessCheck')->willReturnSelf();
    $this->query->method('condition')->willReturnSelf();
    $this->query->method('exists')->willReturnSelf();
    $this->query->method('notExists')->willReturnSelf();

    $this->nodeStorage->method('getQuery')
      ->willReturn($this->query);

    // Fix: current time is 1000000 seconds.
    $this->time->method('getRequestTime')->willReturn(1000000);

    // Set up string translation for StringTranslationTrait::t().
    $translationManager = $this->getStringTranslationStub();

    $this->service = new EscalationCronService(
      $this->entityTypeManager,
      $this->escalationService,
      $this->configFactory,
      $this->time,
      $this->logger,
    );
    $this->service->setStringTranslation($translationManager);
  }

  /**
   * Tests that processEscalations returns 0 when no nodes match the query.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsNoMatchingNodes(): void {
    $this->query->method('execute')->willReturn([]);

    $result = $this->service->processEscalations();
    $this->assertEquals(0, $result);
  }

  /**
   * Tests that nodes without field_category are skipped.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsSkipsNodesWithoutCategory(): void {
    $this->query->method('execute')->willReturn([1 => 1]);

    $node = $this->createMockCronNode(1, NULL, NULL, NULL, 900000);
    $this->nodeStorage->method('load')
      ->willReturn($node);

    $this->escalationService->expects($this->never())
      ->method('escalateRequest');

    $result = $this->service->processEscalations();
    $this->assertEquals(0, $result);
  }

  /**
   * Tests that nodes with recent changes are not escalated.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsSkipsRecentlyUpdatedNodes(): void {
    $this->query->method('execute')->willReturn([1 => 1]);

    // Category has 7-day escalation. Node changed 1 day ago.
    // now = 1000000, 1 day = 86400, changed = 1000000 - 86400 = 913600.
    // Threshold = 1000000 - (7 * 86400) = 395200. 913600 > 395200, so skip.
    $categoryTerm = $this->createMockCategoryTerm(7, 20);
    $node = $this->createMockCronNode(1, $categoryTerm, NULL, NULL, 913600);
    $this->nodeStorage->method('load')
      ->willReturn($node);

    $this->escalationService->expects($this->never())
      ->method('escalateRequest');

    $result = $this->service->processEscalations();
    $this->assertEquals(0, $result);
  }

  /**
   * Tests that overdue nodes are escalated successfully.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsEscalatesOverdueNode(): void {
    $this->query->method('execute')->willReturn([1 => 1]);

    // Category: 3-day escalation. Node changed 5 days ago.
    // now = 1000000, 5 days = 432000, changed = 1000000 - 432000 = 568000.
    // Threshold = 1000000 - (3 * 86400) = 740800. 568000 < 740800, so escalate.
    $categoryTerm = $this->createMockCategoryTerm(3, 20);
    $node = $this->createMockCronNode(1, $categoryTerm, NULL, NULL, 568000);

    $this->nodeStorage->method('load')
      ->willReturn($node);

    $this->escalationService->method('resolveEscalationTarget')
      ->willReturn(20);

    $this->escalationService->expects($this->once())
      ->method('escalateRequest')
      ->with($node, 20, $this->anything());

    $result = $this->service->processEscalations();
    $this->assertEquals(1, $result);
  }

  /**
   * Tests that multiple overdue nodes are all escalated.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsMultipleNodes(): void {
    $this->query->method('execute')->willReturn([1 => 1, 2 => 2]);

    $categoryTerm = $this->createMockCategoryTerm(1, 20);
    $node1 = $this->createMockCronNode(1, $categoryTerm, NULL, NULL, 500000);
    $node2 = $this->createMockCronNode(2, $categoryTerm, NULL, NULL, 500000);
    $this->nodeStorage->method('load')
      ->willReturnCallback(fn($nid) => $nid == 1 ? $node1 : $node2);

    $this->escalationService->method('resolveEscalationTarget')
      ->willReturn(20);

    $this->escalationService->expects($this->exactly(2))
      ->method('escalateRequest');

    $result = $this->service->processEscalations();
    $this->assertEquals(2, $result);
  }

  /**
   * Tests that nodes without escalation target are skipped.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsSkipsNoTarget(): void {
    $this->query->method('execute')->willReturn([1 => 1]);

    $categoryTerm = $this->createMockCategoryTerm(1, 20);
    $node = $this->createMockCronNode(1, $categoryTerm, NULL, NULL, 500000);
    $this->nodeStorage->method('load')
      ->willReturn($node);

    $this->escalationService->method('resolveEscalationTarget')
      ->willReturn(NULL);

    $this->escalationService->expects($this->never())
      ->method('escalateRequest');

    $result = $this->service->processEscalations();
    $this->assertEquals(0, $result);
  }

  /**
   * Tests that escalation exceptions are caught and logged.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsLogsException(): void {
    $this->query->method('execute')->willReturn([1 => 1]);

    $categoryTerm = $this->createMockCategoryTerm(1, 20);
    $node = $this->createMockCronNode(1, $categoryTerm, NULL, NULL, 500000);
    $this->nodeStorage->method('load')
      ->willReturn($node);

    $this->escalationService->method('resolveEscalationTarget')
      ->willReturn(20);
    $this->escalationService->method('escalateRequest')
      ->willThrowException(new \RuntimeException('save failed'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to auto-escalate'),
        $this->anything()
      );

    $result = $this->service->processEscalations();
    $this->assertEquals(0, $result);
  }

  /**
   * Tests that categories with zero escalation days are skipped.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsSkipsZeroEscalationDays(): void {
    $this->query->method('execute')->willReturn([1 => 1]);

    $categoryTerm = $this->createMockCategoryTerm(0, 20);
    $node = $this->createMockCronNode(1, $categoryTerm, NULL, NULL, 500000);
    $this->nodeStorage->method('load')
      ->willReturn($node);

    $this->escalationService->expects($this->never())
      ->method('escalateRequest');

    $result = $this->service->processEscalations();
    $this->assertEquals(0, $result);
  }

  /**
   * Tests that categories without field_escalation_target are skipped.
   *
   * @covers ::processEscalations
   */
  public function testProcessEscalationsSkipsCategoryWithoutEscalationTarget(): void {
    $this->query->method('execute')->willReturn([1 => 1]);

    // Category without escalation target field: use anonymous class.
    $categoryTerm = new class() {

      /**
       * Checks whether the entity has a given field.
       */
      public function hasField(string $name): bool {
        // Has escalation_days but NOT escalation_target.
        return $name === 'field_escalation_days';
      }

      /**
       * Gets a field value.
       */
      public function get(string $name): mixed {
        return NULL;
      }

    };

    $node = $this->createMockCronNode(1, $categoryTerm, NULL, NULL, 500000);
    $this->nodeStorage->method('load')
      ->willReturn($node);

    $this->escalationService->expects($this->never())
      ->method('escalateRequest');

    $result = $this->service->processEscalations();
    $this->assertEquals(0, $result);
  }

  // ===========================================================================
  // Helper methods.
  // ===========================================================================

  /**
   * Creates a mock category term with escalation configuration.
   *
   * Uses anonymous class to avoid PHPUnit mock limitations with property
   * access on deeply inherited interfaces.
   *
   * @param int $escalationDays
   *   Number of days before auto-escalation.
   * @param int $escalationTarget
   *   Target jurisdiction group ID.
   *
   * @return object
   *   A category term stub with hasField() and get() methods.
   */
  protected function createMockCategoryTerm(int $escalationDays, int $escalationTarget): object {
    $fields = [];

    // field_escalation_target.
    $fields['field_escalation_target'] = new class($escalationTarget) {

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

    // field_escalation_days.
    $fields['field_escalation_days'] = new class($escalationDays) {

      /**
       * The field value.
       *
       * @var int
       */
      public int $value;

      /**
       * Constructs a field item stub.
       */
      public function __construct(int $val) {
        $this->value = $val;
      }

      /**
       * Checks whether the field item is empty.
       */
      public function isEmpty(): bool {
        return $this->value <= 0;
      }

    };

    return new class($fields) {

      /**
       * Field definitions.
       *
       * @var array
       */
      private array $fields;

      /**
       * Constructs a category term stub.
       */
      public function __construct(array $fields) {
        $this->fields = $fields;
      }

      /**
       * Checks whether the entity has a given field.
       */
      public function hasField(string $name): bool {
        return isset($this->fields[$name]);
      }

      /**
       * Gets a field value.
       */
      public function get(string $name): mixed {
        return $this->fields[$name] ?? NULL;
      }

    };
  }

  /**
   * Creates a mock node for cron processing.
   *
   * @param int $nid
   *   The node ID.
   * @param object|null $categoryTerm
   *   The category term entity, or NULL if no category.
   * @param int|null $organisationId
   *   The organisation group ID, or NULL.
   * @param int|null $escalationId
   *   The escalation jurisdiction ID, or NULL.
   * @param int $changedTime
   *   The last changed timestamp.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked node.
   */
  protected function createMockCronNode(
    int $nid,
    ?object $categoryTerm,
    ?int $organisationId,
    ?int $escalationId,
    int $changedTime,
  ): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('getChangedTime')->willReturn($changedTime);

    $fields = [];

    // field_category: use anonymous class so ->entity works as a property.
    if ($categoryTerm !== NULL) {
      $fields['field_category'] = new class($categoryTerm) {

        /**
         * The referenced entity.
         *
         * @var object
         */
        public object $entity;

        /**
         * Constructs a field item stub.
         */
        public function __construct(object $entity) {
          $this->entity = $entity;
        }

        /**
         * Checks whether the field item is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };
    }

    $node->method('hasField')
      ->willReturnCallback(fn($name) => isset($fields[$name]));
    $node->method('get')
      ->willReturnCallback(function ($name) use ($fields) {
        if (isset($fields[$name])) {
          return $fields[$name];
        }
        return new class() {

          /**
           * The referenced entity (always NULL for empty fields).
           *
           * @var object|null
           */
          public ?object $entity = NULL;

          /**
           * Checks whether the field item is empty.
           */
          public function isEmpty(): bool {
            return TRUE;
          }

        };
      });

    return $node;
  }

}
