<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the duplicate detection service.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\DuplicateDetectionService
 */
class DuplicateDetectionServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_ai\Service\DuplicateDetectionService
   */
  protected DuplicateDetectionService $service;

  /**
   * The mocked embedding service.
   *
   * @var \Drupal\markaspot_ai\Service\EmbeddingService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $embeddingService;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up Drupal container for \Drupal::time() calls.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);
    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    \Drupal::setContainer($container);

    $this->embeddingService = $this->createMock(EmbeddingService::class);
    $this->database = $this->createMock(Connection::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'duplicate_detection.similarity_threshold' => 0.85,
          'duplicate_detection.radius_meters' => 500,
          'duplicate_detection.time_window_days' => 30,
          default => NULL,
        };
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_ai')
      ->willReturn($this->logger);

    $this->service = new DuplicateDetectionService(
      $this->embeddingService,
      $this->database,
      $entityTypeManager,
      $configFactory,
      $loggerFactory,
    );
  }

  /**
   * Tests distance between identical points is zero.
   *
   * @covers ::haversineDistance
   */
  public function testHaversineDistanceSamePoint(): void {
    $distance = $this->service->haversineDistance(50.9375, 6.9603, 50.9375, 6.9603);
    $this->assertEqualsWithDelta(0.0, $distance, 0.01);
  }

  /**
   * Tests known distance between two cities (Cologne to Bonn, roughly 28km).
   *
   * @covers ::haversineDistance
   */
  public function testHaversineDistanceKnownPair(): void {
    // Cologne to Bonn.
    $distance = $this->service->haversineDistance(
      50.9375, 6.9603,
      50.7374, 7.0982
    );
    // Actual distance is approximately 24-25 km.
    $this->assertGreaterThan(20000, $distance);
    $this->assertLessThan(30000, $distance);
  }

  /**
   * Tests Haversine distance is commutative.
   *
   * @covers ::haversineDistance
   */
  public function testHaversineDistanceCommutative(): void {
    $ab = $this->service->haversineDistance(50.9375, 6.9603, 50.7374, 7.0982);
    $ba = $this->service->haversineDistance(50.7374, 7.0982, 50.9375, 6.9603);

    $this->assertEqualsWithDelta($ab, $ba, 0.01);
  }

  /**
   * Tests short distance (within typical radius).
   *
   * @covers ::haversineDistance
   */
  public function testHaversineDistanceShort(): void {
    // Two points about 100 meters apart.
    $distance = $this->service->haversineDistance(
      50.9375, 6.9603,
      50.93838, 6.9603
    );
    $this->assertGreaterThan(50, $distance);
    $this->assertLessThan(200, $distance);
  }

  /**
   * Tests bounding box contains center point.
   *
   * @covers ::calculateBoundingBox
   */
  public function testBoundingBoxContainsCenter(): void {
    $bbox = $this->service->calculateBoundingBox(50.9375, 6.9603, 500);

    $this->assertLessThan(50.9375, $bbox['minLat']);
    $this->assertGreaterThan(50.9375, $bbox['maxLat']);
    $this->assertLessThan(6.9603, $bbox['minLon']);
    $this->assertGreaterThan(6.9603, $bbox['maxLon']);
  }

  /**
   * Tests bounding box size scales with radius.
   *
   * @covers ::calculateBoundingBox
   */
  public function testBoundingBoxScalesWithRadius(): void {
    $small = $this->service->calculateBoundingBox(50.0, 7.0, 100);
    $large = $this->service->calculateBoundingBox(50.0, 7.0, 1000);

    $smallLatRange = $small['maxLat'] - $small['minLat'];
    $largeLatRange = $large['maxLat'] - $large['minLat'];

    $this->assertGreaterThan($smallLatRange, $largeLatRange);
  }

  /**
   * Tests bounding box is symmetrical around center.
   *
   * @covers ::calculateBoundingBox
   */
  public function testBoundingBoxSymmetry(): void {
    $bbox = $this->service->calculateBoundingBox(50.0, 7.0, 500);

    $latCenter = ($bbox['minLat'] + $bbox['maxLat']) / 2;
    $lonCenter = ($bbox['minLon'] + $bbox['maxLon']) / 2;

    $this->assertEqualsWithDelta(50.0, $latCenter, 0.0001);
    $this->assertEqualsWithDelta(7.0, $lonCenter, 0.0001);
  }

  /**
   * Tests confirming a match.
   *
   * @covers ::reviewMatch
   */
  public function testReviewMatchConfirmed(): void {
    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);

    $this->database->method('update')
      ->with('markaspot_ai_duplicate_matches')
      ->willReturn($update);

    $result = $this->service->reviewMatch(1, 'confirmed', 42);
    $this->assertTrue($result);
  }

  /**
   * Tests rejecting a match.
   *
   * @covers ::reviewMatch
   */
  public function testReviewMatchRejected(): void {
    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);

    $this->database->method('update')
      ->with('markaspot_ai_duplicate_matches')
      ->willReturn($update);

    $result = $this->service->reviewMatch(1, 'rejected', 42);
    $this->assertTrue($result);
  }

  /**
   * Tests invalid status is rejected.
   *
   * @covers ::reviewMatch
   */
  public function testReviewMatchInvalidStatus(): void {
    $result = $this->service->reviewMatch(1, 'invalid_status', 42);
    $this->assertFalse($result);
  }

  /**
   * Tests reviewing a nonexistent match.
   *
   * @covers ::reviewMatch
   */
  public function testReviewMatchNotFound(): void {
    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(0);

    $this->database->method('update')
      ->with('markaspot_ai_duplicate_matches')
      ->willReturn($update);

    $result = $this->service->reviewMatch(999, 'confirmed', 42);
    $this->assertFalse($result);
  }

  /**
   * Tests review with database error.
   *
   * @covers ::reviewMatch
   */
  public function testReviewMatchDatabaseError(): void {
    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willThrowException(new \Exception('DB error'));

    $this->database->method('update')
      ->with('markaspot_ai_duplicate_matches')
      ->willReturn($update);

    $result = $this->service->reviewMatch(1, 'confirmed', 42);
    $this->assertFalse($result);
  }

  /**
   * Tests deleting matches for a node.
   *
   * @covers ::deleteMatchesForNode
   */
  public function testDeleteMatchesForNode(): void {
    $conditionGroup = $this->createMock(Condition::class);
    $conditionGroup->method('condition')->willReturnSelf();

    $this->database->method('condition')
      ->with('OR')
      ->willReturn($conditionGroup);

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(3);

    $this->database->method('delete')
      ->with('markaspot_ai_duplicate_matches')
      ->willReturn($delete);

    $deleted = $this->service->deleteMatchesForNode(42);
    $this->assertEquals(3, $deleted);
  }

  /**
   * Tests deleting matches handles database error.
   *
   * @covers ::deleteMatchesForNode
   */
  public function testDeleteMatchesForNodeDatabaseError(): void {
    $conditionGroup = $this->createMock(Condition::class);
    $conditionGroup->method('condition')->willReturnSelf();

    $this->database->method('condition')
      ->with('OR')
      ->willReturn($conditionGroup);

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willThrowException(new \Exception('DB error'));

    $this->database->method('delete')
      ->with('markaspot_ai_duplicate_matches')
      ->willReturn($delete);

    $deleted = $this->service->deleteMatchesForNode(42);
    $this->assertEquals(0, $deleted);
  }

  /**
   * Tests getting an existing match.
   *
   * @covers ::getMatch
   */
  public function testGetMatchExists(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')
      ->willReturn([
        'id' => 1,
        'source_nid' => 42,
        'match_nid' => 43,
        'similarity_score' => 0.92,
        'status' => 'pending',
      ]);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')
      ->with('markaspot_ai_duplicate_matches', 'd')
      ->willReturn($select);

    $match = $this->service->getMatch(1);
    $this->assertNotNull($match);
    $this->assertEquals(42, $match['source_nid']);
    $this->assertEquals(0.92, $match['similarity_score']);
  }

  /**
   * Tests getting a nonexistent match returns NULL.
   *
   * @covers ::getMatch
   */
  public function testGetMatchNotFound(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(FALSE);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $match = $this->service->getMatch(999);
    $this->assertNull($match);
  }

  /**
   * Tests getMatch handles database exception.
   *
   * @covers ::getMatch
   */
  public function testGetMatchDatabaseError(): void {
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willThrowException(new \Exception('DB error'));

    $this->database->method('select')->willReturn($select);

    $match = $this->service->getMatch(1);
    $this->assertNull($match);
  }

  /**
   * Tests getting match counts.
   *
   * @covers ::getMatchCounts
   */
  public function testGetMatchCounts(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllKeyed')
      ->willReturn([
        'pending' => '5',
        'confirmed' => '3',
        'rejected' => '2',
      ]);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('groupBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')
      ->with('markaspot_ai_duplicate_matches', 'd')
      ->willReturn($select);

    $counts = $this->service->getMatchCounts();

    $this->assertEquals(5, $counts['pending']);
    $this->assertEquals(3, $counts['confirmed']);
    $this->assertEquals(2, $counts['rejected']);
    $this->assertEquals(10, $counts['total']);
  }

  /**
   * Tests getMatchCounts returns zeros on error.
   *
   * @covers ::getMatchCounts
   */
  public function testGetMatchCountsDatabaseError(): void {
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('groupBy')->willReturnSelf();
    $select->method('execute')->willThrowException(new \Exception('DB error'));

    $this->database->method('select')->willReturn($select);

    $counts = $this->service->getMatchCounts();

    $this->assertEquals(0, $counts['pending']);
    $this->assertEquals(0, $counts['confirmed']);
    $this->assertEquals(0, $counts['rejected']);
    $this->assertEquals(0, $counts['total']);
  }

}
