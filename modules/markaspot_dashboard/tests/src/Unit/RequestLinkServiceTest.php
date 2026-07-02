<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\markaspot_dashboard\Service\RequestLinkService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests RequestLinkService storage and retrieval.
 *
 * @group markaspot_dashboard
 * @coversDefaultClass \Drupal\markaspot_dashboard\Service\RequestLinkService
 */
class RequestLinkServiceTest extends UnitTestCase {

  /**
   * Mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

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
   * The service under test.
   *
   * @var \Drupal\markaspot_dashboard\Service\RequestLinkService
   */
  protected $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1751462400);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->service = new RequestLinkService($this->database, $this->time, $this->logger);
  }

  /**
   * Tests that storeLink() inserts the expected row shape.
   *
   * @covers ::storeLink
   */
  public function testStoreLinkInsertsRow(): void {
    $insert = $this->getMockBuilder(Insert::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['fields', 'execute'])
      ->getMock();
    $insert->expects($this->once())
      ->method('fields')
      ->with([
        'source_nid' => 88,
        'target_nid' => 89,
        'link_type' => 'split',
        'uid' => 12,
        'created' => 1751462400,
      ])
      ->willReturnSelf();
    $insert->expects($this->once())->method('execute');

    $this->database->expects($this->once())
      ->method('insert')
      ->with('markaspot_request_links')
      ->willReturn($insert);

    $this->service->storeLink(88, 89, 12);
  }

  /**
   * Tests that getLinksForNode() queries with an OR(source, target) group.
   *
   * @covers ::getLinksForNode
   */
  public function testGetLinksForNodeQueriesBothDirections(): void {
    $conditionGroup = $this->createMock(Condition::class);
    $conditionGroup->expects($this->exactly(2))
      ->method('condition')
      ->willReturnSelf();

    $this->database->expects($this->once())
      ->method('condition')
      ->with('OR')
      ->willReturn($conditionGroup);

    $rows = [
      (object) [
        'source_nid' => '88',
        'target_nid' => '89',
        'link_type' => 'split',
        'uid' => '12',
        'created' => '1751462400',
      ],
    ];
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($rows);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->with('markaspot_request_links', 'l')
      ->willReturn($select);

    // Node 89 is the TARGET side of the row: read-both-directions means the
    // lookup must find the link whether nid is source_nid or target_nid.
    $result = $this->service->getLinksForNode(89);

    $this->assertCount(1, $result);
    $this->assertSame(88, $result[0]['source_nid']);
    $this->assertSame(89, $result[0]['target_nid']);
    $this->assertSame('split', $result[0]['link_type']);
    $this->assertSame(12, $result[0]['uid']);
    $this->assertSame(1751462400, $result[0]['created']);
  }

  /**
   * Tests that getLinksForNode() returns an empty array when nothing found.
   *
   * @covers ::getLinksForNode
   */
  public function testGetLinksForNodeEmpty(): void {
    $conditionGroup = $this->createMock(Condition::class);
    $conditionGroup->method('condition')->willReturnSelf();
    $this->database->method('condition')->with('OR')->willReturn($conditionGroup);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([]);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $this->assertSame([], $this->service->getLinksForNode(999));
  }

  /**
   * Tests that deleteForNode() deletes with an OR(source, target) group.
   *
   * @covers ::deleteForNode
   */
  public function testDeleteForNode(): void {
    $conditionGroup = $this->createMock(Condition::class);
    $conditionGroup->method('condition')->willReturnSelf();
    $this->database->method('condition')->with('OR')->willReturn($conditionGroup);

    $delete = $this->getMockBuilder(Delete::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['condition', 'execute'])
      ->getMock();
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(2);

    $this->database->expects($this->once())
      ->method('delete')
      ->with('markaspot_request_links')
      ->willReturn($delete);

    $this->assertSame(2, $this->service->deleteForNode(88));
  }

  /**
   * Tests that deleteForNode() fails closed (returns 0) on a DB error.
   *
   * @covers ::deleteForNode
   */
  public function testDeleteForNodeHandlesDatabaseError(): void {
    $conditionGroup = $this->createMock(Condition::class);
    $conditionGroup->method('condition')->willReturnSelf();
    $this->database->method('condition')->with('OR')->willReturn($conditionGroup);

    $delete = $this->getMockBuilder(Delete::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['condition', 'execute'])
      ->getMock();
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willThrowException(new \Exception('DB error'));

    $this->database->method('delete')->willReturn($delete);

    $this->assertSame(0, $this->service->deleteForNode(88));
  }

}
