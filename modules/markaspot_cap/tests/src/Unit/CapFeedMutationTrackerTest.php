<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_cap\Service\CapFeedMutationTracker;
use Drupal\markaspot_cap\Service\CapFeedStateStoreInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests monotonic CAP feed mutation timestamps.
 *
 * @group markaspot_cap
 * @coversDefaultClass \Drupal\markaspot_cap\Service\CapFeedMutationTracker
 */
class CapFeedMutationTrackerTest extends UnitTestCase {

  /**
   * @covers ::markRootsChanged
   * @covers ::getChangedAt
   */
  public function testTimestampAdvancesForSharedRequestTime(): void {
    $values = [CapFeedMutationTracker::stateKey(7) => 1_700_000_000];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static function (string $key, mixed $default = NULL) use (&$values): mixed {
        return $values[$key] ?? $default;
      },
    );
    $state->method('set')->willReturnCallback(
      static function (string $key, mixed $value) use (&$values): void {
        $values[$key] = $value;
      },
    );
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);
    $tracker = new CapFeedMutationTracker(
      $state,
      $time,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->availableLock(),
      $this->feedStateStore($values),
      $this->nonTransactionalConnection(),
    );

    $tracker->markRootsChanged([7, 7]);

    $this->assertSame(1_700_000_001, $tracker->getChangedAt(7));
  }

  /**
   * Both old and new feed scopes advance when an approved alert moves roots.
   *
   * @covers ::markEntityChanged
   */
  public function testApprovedAlertMoveInvalidatesOriginalAndCurrentRoots(): void {
    $values = [];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static function (string $key, mixed $default = NULL) use (&$values): mixed {
        return $values[$key] ?? $default;
      },
    );
    $state->method('set')->willReturnCallback(
      static function (string $key, mixed $value) use (&$values): void {
        $values[$key] = $value;
      },
    );
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_100);
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getRootJurisdictionId')->willReturnMap([
      [9, 7],
      [19, 17],
    ]);
    $tracker = new CapFeedMutationTracker(
      $state,
      $time,
      $hierarchy,
      $this->availableLock(),
      $this->feedStateStore($values),
      $this->nonTransactionalConnection(),
    );

    $tracker->markEntityChanged(
      $this->serviceRequest(TRUE, [19]),
      $this->serviceRequest(TRUE, [9]),
    );

    $this->assertSame(1_700_000_100, $values[CapFeedMutationTracker::stateKey(7)]);
    $this->assertSame(1_700_000_100, $values[CapFeedMutationTracker::stateKey(17)]);
  }

  /**
   * Removing approval still advances the feed that loses the alert.
   *
   * @covers ::markEntityChanged
   */
  public function testRemovingApprovalInvalidatesOriginalFeed(): void {
    $values = [];
    $state = $this->createMock(StateInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_200);
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getRootJurisdictionId')->with(9)->willReturn(7);
    $tracker = new CapFeedMutationTracker(
      $state,
      $time,
      $hierarchy,
      $this->availableLock(),
      $this->feedStateStore($values),
      $this->nonTransactionalConnection(),
    );

    $tracker->markEntityChanged(
      $this->serviceRequest(FALSE, [9]),
      $this->serviceRequest(TRUE, [9]),
    );

    $this->assertSame(1_700_000_200, $values[CapFeedMutationTracker::stateKey(7)]);
  }

  /**
   * A contending hook waits and rereads State while holding the same root lock.
   *
   * @covers ::markRootsChanged
   */
  public function testTimestampUpdateRetriesThePerRootLock(): void {
    $values = [CapFeedMutationTracker::stateKey(7) => 10];
    $state = $this->createMock(StateInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(10);
    $lock = $this->createMock(LockBackendInterface::class);
    $attempts = 0;
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->with(CapFeedMutationTracker::stateKey(7) . '.transition', 30.0)
      ->willReturnCallback(static function () use (&$attempts): bool {
        $attempts++;
        return $attempts === 2;
      });
    $lock->expects($this->once())
      ->method('wait')
      ->with(CapFeedMutationTracker::stateKey(7) . '.transition', 2);
    $lock->expects($this->once())
      ->method('release')
      ->with(CapFeedMutationTracker::stateKey(7) . '.transition');
    $tracker = new CapFeedMutationTracker(
      $state,
      $time,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $lock,
      $this->feedStateStore($values),
      $this->nonTransactionalConnection(),
    );

    $tracker->markRootsChanged([7]);

    $this->assertSame(11, $values[CapFeedMutationTracker::stateKey(7)]);
  }

  /**
   * Repeated changes in one SQL transaction advance a root exactly once.
   *
   * @covers ::markRootsChanged
   */
  public function testTransactionCoalescesRootTimestampAndLock(): void {
    $state = $this->createMock(StateInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_400);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with(CapFeedMutationTracker::stateKey(7) . '.transition', 30.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with(CapFeedMutationTracker::stateKey(7) . '.transition');
    $store = $this->createMock(CapFeedStateStoreInterface::class);
    $store->expects($this->once())
      ->method('advance')
      ->with(CapFeedMutationTracker::stateKey(7), 1_700_000_400)
      ->willReturn(1_700_000_400);
    $callback = NULL;
    $manager = $this->createMock(TransactionManagerInterface::class);
    $manager->expects($this->once())
      ->method('addPostTransactionCallback')
      ->willReturnCallback(static function (callable $registered) use (&$callback): void {
        $callback = $registered;
      });
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(TRUE);
    $database->method('transactionManager')->willReturn($manager);
    $tracker = new CapFeedMutationTracker(
      $state,
      $time,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $lock,
      $store,
      $database,
    );

    $tracker->markRootsChanged([7]);
    $tracker->markRootsChanged([7]);
    $this->assertIsCallable($callback);

    $callback(TRUE);
  }

  /**
   * Builds a service-request entity double for feed membership tests.
   *
   * @param bool $approved
   *   Whether CAP publication is approved.
   * @param int[] $jurisdictionIds
   *   Referenced jurisdiction group IDs.
   */
  private function serviceRequest(bool $approved, array $jurisdictionIds): ContentEntityInterface {
    $approval = $this->createMock(FieldItemListInterface::class);
    $approval->method('getString')->willReturn($approved ? '1' : '0');
    $jurisdictions = $this->createMock(FieldItemListInterface::class);
    $jurisdictions->method('getValue')->willReturn(array_map(
      static fn(int $id): array => ['target_id' => $id],
      $jurisdictionIds,
    ));

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('service_request');
    $entity->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array(
        $field,
        ['field_cap_publish', 'field_jurisdiction'],
        TRUE,
      ),
    );
    $entity->method('get')->willReturnMap([
      ['field_cap_publish', $approval],
      ['field_jurisdiction', $jurisdictions],
    ]);
    return $entity;
  }

  /**
   * Returns an uncontended lock backend test double.
   */
  private function availableLock(): LockBackendInterface {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    return $lock;
  }

  /**
   * Returns a fresh State store test double.
   *
   * @param array<string, int> $values
   *   Backing timestamp values.
   */
  private function feedStateStore(array &$values): CapFeedStateStoreInterface {
    $store = $this->createMock(CapFeedStateStoreInterface::class);
    $store->method('advance')->willReturnCallback(
      static function (string $key, int $requestTime) use (&$values): int {
        $next = max($requestTime, ($values[$key] ?? 0) + 1);
        $values[$key] = $next;
        return $next;
      },
    );
    return $store;
  }

  /**
   * Returns a connection without an active entity transaction.
   */
  private function nonTransactionalConnection(): Connection {
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(FALSE);
    return $database;
  }

}
