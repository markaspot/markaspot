<?php

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolver;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the JurisdictionHierarchyResolver service.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Service\JurisdictionHierarchyResolver
 */
class JurisdictionHierarchyResolverTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $groupStorage;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolver
   */
  protected $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($this->groupStorage);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_group')
      ->willReturn($this->logger);

    $this->resolver = new JurisdictionHierarchyResolver(
      $this->entityTypeManager,
      $this->database,
      $loggerFactory,
    );
  }

  /**
   * Creates a mock jurisdiction group entity.
   *
   * @param int $id
   *   The group ID.
   * @param string $bundle
   *   The group type (default: 'jur').
   * @param int|null $parentId
   *   The parent jurisdiction ID, or NULL if root.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockGroup(int $id, string $bundle = 'jur', ?int $parentId = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);

    if ($parentId !== NULL) {
      // Use an anonymous class to provide target_id as a real property.
      // PHPUnit mocks of interfaces don't support dynamic properties in PHP 8.2+.
      $fieldItem = new class($parentId) implements \Iterator, \Countable {
        public int $target_id;
        private bool $valid = TRUE;

        public function __construct(int $parentId) {
          $this->target_id = $parentId;
        }

        public function isEmpty(): bool {
          return FALSE;
        }

        public function current(): mixed {
          return $this;
        }

        public function key(): int {
          return 0;
        }

        public function next(): void {
          $this->valid = FALSE;
        }

        public function rewind(): void {
          $this->valid = TRUE;
        }

        public function valid(): bool {
          return $this->valid;
        }

        public function count(): int {
          return 1;
        }
      };

      $group->method('hasField')
        ->willReturnCallback(fn($name) => $name === 'field_parent_jurisdiction');
      $group->method('get')
        ->willReturnCallback(function ($name) use ($fieldItem) {
          if ($name === 'field_parent_jurisdiction') {
            return $fieldItem;
          }
          return $this->createMock(FieldItemListInterface::class);
        });
    }
    else {
      // Root jurisdiction: field exists but is empty.
      $fieldItem = new class {
        public function isEmpty(): bool {
          return TRUE;
        }
      };

      $group->method('hasField')
        ->willReturnCallback(fn($name) => $name === 'field_parent_jurisdiction');
      $group->method('get')
        ->willReturnCallback(function ($name) use ($fieldItem) {
          if ($name === 'field_parent_jurisdiction') {
            return $fieldItem;
          }
          return $this->createMock(FieldItemListInterface::class);
        });
    }

    return $group;
  }

  /**
   * Sets up a mock DB query for group__field_parent_jurisdiction.
   *
   * @param int $parentId
   *   The parent group ID to query for.
   * @param array $childIds
   *   The child IDs to return.
   */
  protected function mockChildQuery(int $parentId, array $childIds): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(array_map('strval', $childIds));

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')
      ->with('group__field_parent_jurisdiction', 'p')
      ->willReturn($select);
  }

  // =========================================================================
  // getRootJurisdictionId() tests
  // =========================================================================

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testRootJurisdictionReturnsItself(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);

    $this->assertEquals(10, $this->resolver->getRootJurisdictionId(10));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testChildResolvesToParent(): void {
    $child = $this->createMockGroup(20, 'jur', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $root],
      ]);

    $this->assertEquals(10, $this->resolver->getRootJurisdictionId(20));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testDeepHierarchyResolvesToRoot(): void {
    $grandchild = $this->createMockGroup(30, 'jur', 20);
    $child = $this->createMockGroup(20, 'jur', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [30, $grandchild],
        [20, $child],
        [10, $root],
      ]);

    $this->assertEquals(10, $this->resolver->getRootJurisdictionId(30));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testCircularReferenceDetection(): void {
    // A -> B -> A (circular).
    $groupA = $this->createMockGroup(1, 'jur', 2);
    $groupB = $this->createMockGroup(2, 'jur', 1);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [1, $groupA],
        [2, $groupB],
      ]);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Circular parent reference'),
        $this->anything()
      );

    // Should not loop forever. Returns the node where cycle was detected.
    $result = $this->resolver->getRootJurisdictionId(1);
    $this->assertIsInt($result);
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testNonExistentGroupReturnsInputId(): void {
    $this->groupStorage->method('load')
      ->with(999)
      ->willReturn(NULL);

    $this->assertEquals(999, $this->resolver->getRootJurisdictionId(999));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testNonJurGroupTypeReturnsInputId(): void {
    $org = $this->createMockGroup(5, 'org');
    $this->groupStorage->method('load')
      ->with(5)
      ->willReturn($org);

    $this->assertEquals(5, $this->resolver->getRootJurisdictionId(5));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testParentOfWrongBundleStopsTraversal(): void {
    // Child points to parent that is an 'org' group, not 'jur'.
    $child = $this->createMockGroup(20, 'jur', 10);
    $orgParent = $this->createMockGroup(10, 'org');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $orgParent],
      ]);

    // Should stop at child because parent is wrong bundle.
    $this->assertEquals(20, $this->resolver->getRootJurisdictionId(20));
  }

  // =========================================================================
  // isChildJurisdiction() tests
  // =========================================================================

  /**
   * @covers ::isChildJurisdiction
   */
  public function testIsChildReturnsTrueForChild(): void {
    $child = $this->createMockGroup(20, 'jur', 10);
    $this->groupStorage->method('load')
      ->with(20)
      ->willReturn($child);

    $this->assertTrue($this->resolver->isChildJurisdiction(20));
  }

  /**
   * @covers ::isChildJurisdiction
   */
  public function testIsChildReturnsFalseForRoot(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);

    $this->assertFalse($this->resolver->isChildJurisdiction(10));
  }

  /**
   * @covers ::isChildJurisdiction
   */
  public function testIsChildReturnsFalseForNonExistent(): void {
    $this->groupStorage->method('load')
      ->with(999)
      ->willReturn(NULL);

    $this->assertFalse($this->resolver->isChildJurisdiction(999));
  }

  /**
   * @covers ::isChildJurisdiction
   */
  public function testIsChildReturnsFalseForNonJurGroup(): void {
    $org = $this->createMockGroup(5, 'org');
    $this->groupStorage->method('load')
      ->with(5)
      ->willReturn($org);

    $this->assertFalse($this->resolver->isChildJurisdiction(5));
  }

  // =========================================================================
  // getDescendantIds() tests
  // =========================================================================

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsLeafNode(): void {
    $this->mockChildQuery(10, []);

    $result = $this->resolver->getDescendantIds(10);
    $this->assertEquals([10], $result);
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsWithChildren(): void {
    // Root 10 has children 20 and 30. We need to set up the mock
    // so that the first call returns children and subsequent calls return none.
    $statement1 = $this->createMock(StatementInterface::class);
    $statement1->method('fetchCol')->willReturn(['20', '30']);

    $statementEmpty = $this->createMock(StatementInterface::class);
    $statementEmpty->method('fetchCol')->willReturn([]);

    $callCount = 0;
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();

    // Track condition calls to differentiate parent IDs.
    $conditionParentId = NULL;
    $select->method('condition')
      ->willReturnCallback(function ($field, $value) use ($select, &$conditionParentId) {
        if ($field === 'field_parent_jurisdiction_target_id') {
          $conditionParentId = $value;
        }
        return $select;
      });

    $select->method('execute')
      ->willReturnCallback(function () use (&$conditionParentId, $statement1, $statementEmpty) {
        if ($conditionParentId == 10) {
          return $statement1;
        }
        return $statementEmpty;
      });

    $this->database->method('select')
      ->with('group__field_parent_jurisdiction', 'p')
      ->willReturn($select);

    $result = $this->resolver->getDescendantIds(10);
    sort($result);
    $this->assertEquals([10, 20, 30], $result);
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsCircularChildDetection(): void {
    // 10 -> [20], 20 -> [10] (circular).
    $conditionParentId = NULL;

    $statement10 = $this->createMock(StatementInterface::class);
    $statement10->method('fetchCol')->willReturn(['20']);

    $statement20 = $this->createMock(StatementInterface::class);
    $statement20->method('fetchCol')->willReturn(['10']);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')
      ->willReturnCallback(function ($field, $value) use ($select, &$conditionParentId) {
        if ($field === 'field_parent_jurisdiction_target_id') {
          $conditionParentId = $value;
        }
        return $select;
      });
    $select->method('execute')
      ->willReturnCallback(function () use (&$conditionParentId, $statement10, $statement20) {
        return $conditionParentId == 10 ? $statement10 : $statement20;
      });

    $this->database->method('select')
      ->with('group__field_parent_jurisdiction', 'p')
      ->willReturn($select);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Circular child reference'),
        $this->anything()
      );

    $result = $this->resolver->getDescendantIds(10);
    sort($result);
    // Should contain both but not loop infinitely.
    $this->assertEquals([10, 20], $result);
  }

  // =========================================================================
  // getTermJurisdictionIds() tests
  // =========================================================================

  /**
   * @covers ::getTermJurisdictionIds
   */
  public function testGetTermJurisdictionIdsFromRoot(): void {
    // Root 10, no children.
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->mockChildQuery(10, []);

    $result = $this->resolver->getTermJurisdictionIds(10);
    $this->assertEquals([10], $result);
  }

  /**
   * @covers ::getTermJurisdictionIds
   */
  public function testGetTermJurisdictionIdsFromChildResolvesRoot(): void {
    // Child 20 -> Root 10, and root 10 has descendants [10, 20].
    $child = $this->createMockGroup(20, 'jur', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $root],
      ]);

    // Set up descendant query for root 10.
    $conditionParentId = NULL;

    $statement10 = $this->createMock(StatementInterface::class);
    $statement10->method('fetchCol')->willReturn(['20']);

    $statementEmpty = $this->createMock(StatementInterface::class);
    $statementEmpty->method('fetchCol')->willReturn([]);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')
      ->willReturnCallback(function ($field, $value) use ($select, &$conditionParentId) {
        if ($field === 'field_parent_jurisdiction_target_id') {
          $conditionParentId = $value;
        }
        return $select;
      });
    $select->method('execute')
      ->willReturnCallback(function () use (&$conditionParentId, $statement10, $statementEmpty) {
        return $conditionParentId == 10 ? $statement10 : $statementEmpty;
      });

    $this->database->method('select')
      ->with('group__field_parent_jurisdiction', 'p')
      ->willReturn($select);

    $result = $this->resolver->getTermJurisdictionIds(20);
    sort($result);
    // Should return full tree: root + child.
    $this->assertEquals([10, 20], $result);
  }

  // =========================================================================
  // getNodeIdsInJurisdiction() tests
  // =========================================================================

  /**
   * @covers ::getNodeIdsInJurisdiction
   */
  public function testGetNodeIdsInJurisdiction(): void {
    // Group 10 has no children, and has 3 service request nodes.
    $childStatement = $this->createMock(StatementInterface::class);
    $childStatement->method('fetchCol')->willReturn([]);

    $childSelect = $this->createMock(Select::class);
    $childSelect->method('fields')->willReturnSelf();
    $childSelect->method('condition')->willReturnSelf();
    $childSelect->method('execute')->willReturn($childStatement);

    $nodeStatement = $this->createMock(StatementInterface::class);
    $nodeStatement->method('fetchCol')->willReturn(['100', '101', '102']);

    $nodeSelect = $this->createMock(Select::class);
    $nodeSelect->method('fields')->willReturnSelf();
    $nodeSelect->method('condition')->willReturnSelf();
    $nodeSelect->method('execute')->willReturn($nodeStatement);

    $callIndex = 0;
    $this->database->method('select')
      ->willReturnCallback(function ($table) use ($childSelect, $nodeSelect, &$callIndex) {
        if ($table === 'group__field_parent_jurisdiction') {
          return $childSelect;
        }
        if ($table === 'group_relationship_field_data') {
          return $nodeSelect;
        }
        return $childSelect;
      });

    $result = $this->resolver->getNodeIdsInJurisdiction(10);
    $this->assertEquals(['100', '101', '102'], $result);
  }

  /**
   * @covers ::getNodeIdsInJurisdiction
   */
  public function testGetNodeIdsInJurisdictionEmpty(): void {
    $childStatement = $this->createMock(StatementInterface::class);
    $childStatement->method('fetchCol')->willReturn([]);

    $childSelect = $this->createMock(Select::class);
    $childSelect->method('fields')->willReturnSelf();
    $childSelect->method('condition')->willReturnSelf();
    $childSelect->method('execute')->willReturn($childStatement);

    $nodeStatement = $this->createMock(StatementInterface::class);
    $nodeStatement->method('fetchCol')->willReturn([]);

    $nodeSelect = $this->createMock(Select::class);
    $nodeSelect->method('fields')->willReturnSelf();
    $nodeSelect->method('condition')->willReturnSelf();
    $nodeSelect->method('execute')->willReturn($nodeStatement);

    $this->database->method('select')
      ->willReturnCallback(function ($table) use ($childSelect, $nodeSelect) {
        return $table === 'group_relationship_field_data' ? $nodeSelect : $childSelect;
      });

    $result = $this->resolver->getNodeIdsInJurisdiction(10);
    $this->assertEquals([], $result);
  }

}
