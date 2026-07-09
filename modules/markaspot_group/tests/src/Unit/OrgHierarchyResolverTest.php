<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\OrgHierarchyResolver;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 3) . '/src/Service/OrgHierarchyResolverInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/ParentTreeResolver.php';
require_once dirname(__DIR__, 3) . '/src/Service/OrgHierarchyResolver.php';

/**
 * Tests the OrgHierarchyResolver service.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Service\OrgHierarchyResolver
 */
class OrgHierarchyResolverTest extends UnitTestCase {

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
   * @var \Drupal\markaspot_group\Service\OrgHierarchyResolver
   */
  protected OrgHierarchyResolver $resolver;

  /**
   * Child group bundle overrides for loadMultiple() callbacks.
   *
   * @var array<int, string>
   */
  protected array $childBundleOverrides = [];

  /**
   * Child group jurisdiction overrides for loadMultiple() callbacks.
   *
   * @var array<int, int>
   */
  protected array $childJurisdictionOverrides = [];

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
    $this->groupStorage->method('loadMultiple')
      ->willReturnCallback(function (array $ids): array {
        $groups = [];
        foreach ($ids as $id) {
          $id = (int) $id;
          $groups[$id] = $this->createMockGroup(
            $id,
            $this->childBundleOverrides[$id] ?? 'org',
            NULL,
            $this->childJurisdictionOverrides[$id] ?? 1,
          );
        }
        return $groups;
      });

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_group')
      ->willReturn($this->logger);

    $this->resolver = new OrgHierarchyResolver(
      $this->entityTypeManager,
      $this->database,
      $loggerFactory,
    );
  }

  /**
   * Creates a mock org group entity.
   *
   * @param int $id
   *   The group ID.
   * @param string $bundle
   *   The group type.
   * @param int|null $parentId
   *   The parent org ID, or NULL if root.
   * @param int $jurisdictionId
   *   The org jurisdiction ID.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockGroup(
    int $id,
    string $bundle = 'org',
    ?int $parentId = NULL,
    int $jurisdictionId = 1,
  ): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);

    $parentField = $this->targetField($parentId);
    $jurisdictionField = $this->targetField($jurisdictionId);
    $group->method('hasField')
      ->willReturnCallback(fn($name) => in_array($name, [
        'field_parent_org',
        'field_jurisdiction',
      ], TRUE));
    $group->method('get')
      ->willReturnCallback(function ($name) use ($parentField, $jurisdictionField) {
        if ($name === 'field_parent_org') {
          return $parentField;
        }
        if ($name === 'field_jurisdiction') {
          return $jurisdictionField;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    return $group;
  }

  /**
   * Creates a target ID field item list stub.
   *
   * @param int|null $targetId
   *   The target ID, or NULL for an empty field.
   *
   * @return object
   *   The field item list stub.
   */
  protected function targetField(?int $targetId): object {
    return new class($targetId) implements \Iterator, \Countable {

      /**
       * The target entity ID.
       *
       * @var int|null
       */
      public ?int $targetId;

      /**
       * Whether the iterator is still valid.
       */
      private bool $valid = TRUE;

      /**
       * Constructs the field item stub.
       */
      public function __construct(?int $targetId) {
        $this->targetId = $targetId;
      }

      /**
       * Provides Drupal field item property access.
       */
      public function __get(string $name): mixed {
        return $name === 'target_id' ? $this->targetId : NULL;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return $this->targetId === NULL;
      }

      /**
       * Returns the current element.
       */
      public function current(): mixed {
        return $this;
      }

      /**
       * Returns the key of the current element.
       */
      public function key(): int {
        return 0;
      }

      /**
       * Moves to the next element.
       */
      public function next(): void {
        $this->valid = FALSE;
      }

      /**
       * Rewinds the iterator.
       */
      public function rewind(): void {
        $this->valid = TRUE;
      }

      /**
       * Checks if the current position is valid.
       */
      public function valid(): bool {
        return $this->valid && $this->targetId !== NULL;
      }

      /**
       * Returns the number of elements.
       */
      public function count(): int {
        return $this->targetId === NULL ? 0 : 1;
      }

    };
  }

  /**
   * Sets up a mock DB query for group__field_parent_org.
   *
   * @param array<int, int[]> $childrenByParent
   *   Child IDs keyed by parent org ID.
   */
  protected function mockChildQuery(array $childrenByParent): void {
    $conditionParentId = NULL;

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')
      ->willReturnCallback(function ($field, $value) use ($select, &$conditionParentId) {
        if ($field === 'field_parent_org_target_id') {
          $conditionParentId = (int) $value;
        }
        return $select;
      });
    $select->method('execute')
      ->willReturnCallback(function () use (&$conditionParentId, $childrenByParent) {
        $statement = $this->createMock(StatementInterface::class);
        $children = $childrenByParent[$conditionParentId] ?? [];
        $statement->method('fetchCol')->willReturn(array_map('strval', $children));
        return $statement;
      });

    $this->database->method('select')
      ->with('group__field_parent_org', 'p')
      ->willReturn($select);
  }

  /**
   * @covers ::getRootOrgId
   */
  public function testRootOfChainResolvesToTopOrg(): void {
    $leaf = $this->createMockGroup(30, 'org', 20);
    $middle = $this->createMockGroup(20, 'org', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [30, $leaf],
        [20, $middle],
        [10, $root],
      ]);

    $this->assertSame(10, $this->resolver->getRootOrgId(30));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testDescendantsIncludeSelf(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->mockChildQuery([
      10 => [20, 30],
      20 => [],
      30 => [],
    ]);

    $this->assertSame([10, 20, 30], $this->resolver->getDescendantIds(10));
  }

  /**
   * @covers ::getAncestorIds
   */
  public function testAncestorsReturnNearestParentFirst(): void {
    $leaf = $this->createMockGroup(30, 'org', 20);
    $middle = $this->createMockGroup(20, 'org', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [30, $leaf],
        [20, $middle],
        [10, $root],
      ]);

    $this->assertSame([20, 10], $this->resolver->getAncestorIds(30));
  }

  /**
   * @covers ::getChildIds
   */
  public function testChildIdsReturnDirectValidChildren(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->mockChildQuery([
      10 => [20, 30],
      20 => [40],
    ]);

    $this->assertSame([20, 30], $this->resolver->getChildIds(10));
  }

  /**
   * @covers ::getChildIds
   */
  public function testChildIdsAreMemoizedPerParent(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['20', '30']);
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->expects($this->once())
      ->method('select')
      ->with('group__field_parent_org', 'p')
      ->willReturn($select);

    $this->assertSame([20, 30], $this->resolver->getChildIds(10));
    $this->assertSame([20, 30], $this->resolver->getChildIds(10));
  }

  /**
   * @covers ::isChildOrg
   */
  public function testIsChildOrgReflectsParentReference(): void {
    $child = $this->createMockGroup(20, 'org', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $root],
      ]);

    $this->assertTrue($this->resolver->isChildOrg(20));
    $this->assertFalse($this->resolver->isChildOrg(10));
  }

  /**
   * @covers ::isChildOrg
   */
  public function testIsChildOrgFailsClosedWhenParentIsMissing(): void {
    $child = $this->createMockGroup(20, 'org', 99);
    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [99, NULL],
      ]);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('organisation group @id does not exist'),
        $this->callback(fn(array $context): bool => $context['@id'] === 99)
      );

    $this->assertFalse($this->resolver->isChildOrg(20));
  }

  /**
   * @covers ::getRootOrgId
   * @covers ::getDescendantIds
   */
  public function testUnknownGroupFailsClosedWithWarning(): void {
    $this->groupStorage->method('load')
      ->with(999)
      ->willReturn(NULL);

    $this->logger->expects($this->exactly(2))
      ->method('warning')
      ->with(
        $this->stringContains('organisation group @id does not exist'),
        $this->callback(fn(array $context): bool => $context['@id'] === 999)
      );

    $this->assertNull($this->resolver->getRootOrgId(999));
    $this->assertSame([], $this->resolver->getDescendantIds(999));
  }

  /**
   * @covers ::getRootOrgId
   * @covers ::getDescendantIds
   */
  public function testNonOrgBundleFailsClosedWithWarning(): void {
    $jurisdiction = $this->createMockGroup(5, 'jur');
    $this->groupStorage->method('load')
      ->with(5)
      ->willReturn($jurisdiction);

    $this->database->expects($this->never())
      ->method('select');
    $this->logger->expects($this->exactly(2))
      ->method('warning')
      ->with(
        $this->stringContains('Expected organisation group'),
        $this->callback(fn(array $context): bool => $context['@id'] === 5 && $context['@bundle'] === 'jur')
      );

    $this->assertNull($this->resolver->getRootOrgId(5));
    $this->assertSame([], $this->resolver->getDescendantIds(5));
  }

  /**
   * @covers ::getRootOrgId
   */
  public function testCrossJurisdictionParentEdgeFailsClosed(): void {
    $child = $this->createMockGroup(20, 'org', 10, 1);
    $parent = $this->createMockGroup(10, 'org', NULL, 2);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $parent],
      ]);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Rejected organisation hierarchy edge'),
        $this->callback(fn(array $context): bool => $context['@parent_id'] === 10 && $context['@child_id'] === 20)
      );

    $this->assertNull($this->resolver->getRootOrgId(20));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testCrossJurisdictionChildEdgeIsSkipped(): void {
    $root = $this->createMockGroup(10, 'org', NULL, 1);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->childJurisdictionOverrides[20] = 2;
    $this->mockChildQuery([
      10 => [20],
    ]);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Rejected organisation hierarchy edge'),
        $this->callback(fn(array $context): bool => $context['@parent_id'] === 10 && $context['@child_id'] === 20)
      );

    $this->assertSame([10], $this->resolver->getDescendantIds(10));
  }

  /**
   * @covers ::getRootOrgId
   */
  public function testParentCycleFailsClosed(): void {
    $groupA = $this->createMockGroup(1, 'org', 2);
    $groupB = $this->createMockGroup(2, 'org', 1);

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

    $this->assertNull($this->resolver->getRootOrgId(1));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testDescendantCycleSkipsVisitedChild(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->mockChildQuery([
      10 => [20],
      20 => [10],
    ]);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Circular child reference'),
        $this->anything()
      );

    $result = $this->resolver->getDescendantIds(10);
    sort($result);
    $this->assertSame([10, 20], $result);
  }

  /**
   * @covers ::getRootOrgId
   */
  public function testParentDepthGuardFailsClosed(): void {
    $map = [];
    for ($id = 1; $id <= 51; $id++) {
      $map[] = [$id, $this->createMockGroup($id, 'org', $id + 1)];
    }

    $this->groupStorage->method('load')
      ->willReturnMap($map);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Maximum parent hierarchy depth exceeded'),
        $this->anything()
      );

    $this->assertNull($this->resolver->getRootOrgId(1));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testChildDepthGuardSkipsTooDeepBranch(): void {
    $root = $this->createMockGroup(1);
    $this->groupStorage->method('load')
      ->with(1)
      ->willReturn($root);

    $conditionParentId = NULL;
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')
      ->willReturnCallback(function ($field, $value) use ($select, &$conditionParentId) {
        if ($field === 'field_parent_org_target_id') {
          $conditionParentId = (int) $value;
        }
        return $select;
      });
    $select->method('execute')
      ->willReturnCallback(function () use (&$conditionParentId) {
        $statement = $this->createMock(StatementInterface::class);
        $children = $conditionParentId < 53 ? [(string) ($conditionParentId + 1)] : [];
        $statement->method('fetchCol')->willReturn($children);
        return $statement;
      });

    $this->database->method('select')
      ->with('group__field_parent_org', 'p')
      ->willReturn($select);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Maximum child hierarchy depth exceeded'),
        $this->anything()
      );

    $this->assertSame(range(1, 51), $this->resolver->getDescendantIds(1));
  }

}
