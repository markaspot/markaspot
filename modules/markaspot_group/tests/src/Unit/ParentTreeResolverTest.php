<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\ParentTreeResolver;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the generic ParentTreeResolver service.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Service\ParentTreeResolver
 */
class ParentTreeResolverTest extends UnitTestCase {

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
   * Whether the mocked schema reports the parent field table as present.
   */
  protected bool $parentTableExists = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->database = $this->createMock(Connection::class);
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturnCallback(fn(): bool => $this->parentTableExists);
    $this->database->method('schema')->willReturn($schema);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($this->groupStorage);
  }

  /**
   * Creates the resolver under test.
   *
   * @param string $rootFailMode
   *   The root fail mode.
   * @param bool $warnMissingGroups
   *   TRUE to log missing input groups.
   *
   * @return \Drupal\markaspot_group\Service\ParentTreeResolver
   *   The resolver under test.
   */
  protected function createResolver(
    string $rootFailMode = ParentTreeResolver::ROOT_FAIL_CLOSED,
    bool $warnMissingGroups = TRUE,
  ): ParentTreeResolver {
    return new ParentTreeResolver(
      $this->entityTypeManager,
      $this->database,
      $this->logger,
      'field_parent_custom',
      static fn(mixed $group): bool => TRUE,
      'custom',
      $rootFailMode,
      $warnMissingGroups,
    );
  }

  /**
   * @covers ::getRootId
   */
  public function testLegacySelfFailModeReturnsInputForMissingGroup(): void {
    $this->groupStorage->method('load')
      ->with(99)
      ->willReturn(NULL);
    $this->logger->expects($this->never())
      ->method('warning');

    $resolver = $this->createResolver(
      ParentTreeResolver::ROOT_FAIL_LEGACY_SELF,
      FALSE,
    );

    $this->assertSame(99, $resolver->getRootId(
      99,
      'resolve root custom',
      'resolve parent custom',
    ));
  }

  /**
   * @covers ::getAllRootIds
   */
  public function testAllRootIdsUsesDerivedParentTable(): void {
    $seenTables = [];

    $subSelect = $this->createMock(Select::class);
    $subSelect->method('fields')->willReturnSelf();
    $subSelect->method('where')->willReturnSelf();
    $subSelect->method('condition')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['3', '7']);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())->method('distinct')->willReturnSelf();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('notExists')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')
      ->willReturnCallback(function ($table, $alias) use ($select, $subSelect, &$seenTables) {
        $seenTables[] = [$table, $alias];
        return $table === 'groups_field_data' ? $select : $subSelect;
      });

    $this->assertSame([3, 7], $this->createResolver()->getAllRootIds('custom'));
    $this->assertSame([
      ['groups_field_data', 'g'],
      ['group__field_parent_custom', 'p'],
    ], $seenTables);
  }

  /**
   * Without the parent field table there is no hierarchy.
   *
   * @covers ::getAllRootIds
   * @covers ::getDescendantIds
   */
  public function testMissingParentTableMeansNoHierarchy(): void {
    $this->parentTableExists = FALSE;
    $seenTables = [];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['3', '7']);
    $select = $this->createMock(Select::class);
    $select->method('distinct')->willReturnSelf();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->expects($this->never())->method('notExists');
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')
      ->willReturnCallback(function ($table, $alias) use ($select, &$seenTables) {
        $seenTables[] = $table;
        return $select;
      });

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(3);
    $group->method('bundle')->willReturn('custom');
    $this->groupStorage->method('load')->willReturn($group);
    $this->groupStorage->method('loadMultiple')->willReturn([]);

    $resolver = $this->createResolver();
    $this->assertSame([3, 7], $resolver->getAllRootIds('custom'), 'Every group is a root.');
    $this->assertSame([3], array_map('intval', $resolver->getDescendantIds(3, 'load descendants custom', 'load child custom')));
    $this->assertNotContains('group__field_parent_custom', $seenTables, 'The missing table is never queried.');
  }

}
