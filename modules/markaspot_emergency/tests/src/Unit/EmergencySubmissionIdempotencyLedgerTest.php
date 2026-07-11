<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\markaspot_emergency\Service\EmergencySubmissionIdempotencyLedger;
use Drupal\Tests\UnitTestCase;

/**
 * Tests transactional emergency Lite idempotency storage decisions.
 *
 * @group markaspot_emergency
 * @coversDefaultClass \Drupal\markaspot_emergency\Service\EmergencySubmissionIdempotencyLedger
 */
class EmergencySubmissionIdempotencyLedgerTest extends UnitTestCase {

  private const KEY = '44444444-4444-4444-8444-444444444444';
  private const NODE_UUID = '55555555-5555-4555-8555-555555555555';
  private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

  /**
   * @covers ::findReplay
   */
  public function testFindReplayReturnsAnExactCommittedResult(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('select')
      ->with(EmergencySubmissionIdempotencyLedger::TABLE, 'i')
      ->willReturn($this->selectForRecord($this->record()));
    $service = new EmergencySubmissionIdempotencyLedger($database, $this->time());

    $this->assertSame(
      self::NODE_UUID,
      $service->findReplay(7, 12, self::KEY, self::HASH),
    );
  }

  /**
   * A repeated key cannot silently select a different report or scope.
   *
   * @covers ::findReplay
   */
  public function testFindReplayRejectsConflictingReuse(): void {
    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($this->selectForRecord($this->record([
      'request_hash' => str_repeat('b', 64),
    ])));
    $service = new EmergencySubmissionIdempotencyLedger($database, $this->time());

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('conflicts with a different submission');
    $service->findReplay(7, 12, self::KEY, self::HASH);
  }

  /**
   * A new reservation writes no independent transaction of its own.
   *
   * @covers ::reserve
   */
  public function testReserveWritesInsideTheCallerTransaction(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('startTransaction');
    $insert = $this->createMock(Insert::class);
    $insert->expects($this->once())
      ->method('fields')
      ->with([
        'idempotency_key' => self::KEY,
        'root_id' => 7,
        'revision' => 12,
        'request_hash' => self::HASH,
        'node_uuid' => self::NODE_UUID,
        'created' => 1700000000,
      ])
      ->willReturnSelf();
    $insert->expects($this->once())->method('execute')->willReturn(NULL);
    $database->expects($this->once())
      ->method('insert')
      ->with(EmergencySubmissionIdempotencyLedger::TABLE)
      ->willReturn($insert);
    $service = new EmergencySubmissionIdempotencyLedger($database, $this->time());

    $this->assertNull($service->reserve(7, 12, self::KEY, self::HASH, self::NODE_UUID));
  }

  /**
   * A concurrent matching reservation resolves to the original node instead.
   *
   * @covers ::reserve
   */
  public function testReserveReturnsCommittedConcurrentResult(): void {
    $database = $this->createMock(Connection::class);
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willThrowException(
      new IntegrityConstraintViolationException('Duplicate key.'),
    );
    $database->method('insert')->willReturn($insert);
    $wasLocked = FALSE;
    $database->method('select')->willReturn($this->selectForRecord(
      $this->record(),
      $wasLocked,
    ));
    $service = new EmergencySubmissionIdempotencyLedger($database, $this->time());

    $this->assertSame(
      self::NODE_UUID,
      $service->reserve(7, 12, self::KEY, self::HASH, self::NODE_UUID),
    );
    $this->assertTrue($wasLocked);
  }

  /**
   * Retention is bounded to the documented offline retry window.
   *
   * @covers ::purgeExpired
   */
  public function testPurgeExpiredRemovesOnlyOutOfWindowRecords(): void {
    $database = $this->createMock(Connection::class);
    $delete = $this->createMock(Delete::class);
    $delete->expects($this->once())
      ->method('condition')
      ->with('created', 1697408000, '<')
      ->willReturnSelf();
    $delete->expects($this->once())->method('execute')->willReturn(3);
    $database->expects($this->once())
      ->method('delete')
      ->with(EmergencySubmissionIdempotencyLedger::TABLE)
      ->willReturn($delete);
    $service = new EmergencySubmissionIdempotencyLedger($database, $this->time());

    $this->assertSame(3, $service->purgeExpired());
  }

  /**
   * Creates a reusable clock double.
   */
  private function time(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);
    return $time;
  }

  /**
   * Builds a ledger record returned by the DB read double.
   *
   * @param array<string, mixed> $replace
   *   Fields replacing the default valid record.
   *
   * @return array<string, mixed>
   *   One valid ledger record.
   */
  private function record(array $replace = []): array {
    return array_replace([
      'idempotency_key' => self::KEY,
      'root_id' => 7,
      'revision' => 12,
      'request_hash' => self::HASH,
      'node_uuid' => self::NODE_UUID,
    ], $replace);
  }

  /**
   * Builds a small current-read DB query double.
   *
   * @param array<string, mixed>|null $record
   *   Row to expose from fetchAssoc(), if any.
   * @param bool|null $wasLocked
   *   Receives whether the caller requested a row lock.
   */
  private function selectForRecord(?array $record, ?bool &$wasLocked = NULL): SelectInterface {
    $query = $this->createMock(SelectInterface::class);
    $query->method('fields')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('forUpdate')->willReturnCallback(
      static function (bool $set = TRUE) use (&$wasLocked, $query): SelectInterface {
        if ($set && $wasLocked !== NULL) {
          $wasLocked = TRUE;
        }
        return $query;
      },
    );
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($record ?? FALSE);
    $query->method('execute')->willReturn($statement);
    return $query;
  }

}
