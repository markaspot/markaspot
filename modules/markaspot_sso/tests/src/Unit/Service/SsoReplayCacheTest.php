<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\markaspot_sso\Service\SsoReplayCache;
use Drupal\Tests\UnitTestCase;

/**
 * Tests SSO replay cache fail-closed behavior.
 *
 * @group markaspot_sso
 */
final class SsoReplayCacheTest extends UnitTestCase {

  /**
   * A missing message or assertion ID fails closed before storage.
   */
  public function testRejectsPartialReplayIds(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->exactly(2))
      ->method('delete')
      ->with('markaspot_sso_replay')
      ->willReturn($this->deleteQuery());
    $database->expects($this->never())->method('select');
    $database->expects($this->never())->method('insert');

    $cache = new SsoReplayCache($database, $this->time());

    $this->assertFalse($cache->checkAndStore('keycloak', 'message-1', NULL, NULL));
    $this->assertFalse($cache->checkAndStore('keycloak', NULL, 'assertion-1', NULL));
  }

  /**
   * Duplicate insert races are treated as replay failures.
   */
  public function testDuplicateInsertRaceFailsClosed(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('countQuery')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $insert = $this->getMockBuilder(Insert::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['fields', 'execute'])
      ->getMock();
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')
      ->willThrowException(new IntegrityConstraintViolationException('duplicate'));

    $database = $this->createMock(Connection::class);
    $database->method('delete')->willReturn($this->deleteQuery());
    $database->method('select')->willReturn($select);
    $database->method('insert')->with('markaspot_sso_replay')->willReturn($insert);

    $cache = new SsoReplayCache($database, $this->time());

    $this->assertFalse($cache->checkAndStore('keycloak', 'message-1', 'assertion-1', NULL));
  }

  /**
   * Builds a delete query mock for expiry cleanup.
   */
  private function deleteQuery(): Delete {
    $delete = $this->getMockBuilder(Delete::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['condition', 'execute'])
      ->getMock();
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);

    return $delete;
  }

  /**
   * Builds a fixed request time service.
   */
  private function time(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);

    return $time;
  }

}
