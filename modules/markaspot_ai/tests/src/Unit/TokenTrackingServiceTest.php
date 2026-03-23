<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the token tracking service for usage metering and limits.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\TokenTrackingService
 */
class TokenTrackingServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_ai\Service\TokenTrackingService
   */
  protected TokenTrackingService $service;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * The mocked config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

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

    $this->database = $this->createMock(Connection::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($this->config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_ai')
      ->willReturn($this->logger);

    $this->service = new TokenTrackingService(
      $this->database,
      $this->configFactory,
      $loggerFactory,
    );
  }

  /**
   * Creates a new service instance with specific config values.
   *
   * @param bool $enabled
   *   Whether tracking is enabled.
   * @param int $dailyLimit
   *   The daily token limit (0 = unlimited).
   *
   * @return \Drupal\markaspot_ai\Service\TokenTrackingService
   *   A new service instance.
   */
  protected function createServiceWithConfig(bool $enabled, int $dailyLimit = 0): TokenTrackingService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) use ($enabled, $dailyLimit) {
        return match ($key) {
          'token_tracking.enabled' => $enabled,
          'token_tracking.daily_limit' => $dailyLimit,
          default => NULL,
        };
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);

    return new TokenTrackingService(
      $this->database,
      $configFactory,
      $loggerFactory,
    );
  }

  /**
   * Tests logging usage when tracking is enabled.
   *
   * @covers ::logUsage
   */
  public function testLogUsageWhenEnabled(): void {
    $this->config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'token_tracking.enabled' => TRUE,
          default => NULL,
        };
      });

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->expects($this->once())->method('execute');

    $this->database->expects($this->once())
      ->method('insert')
      ->with('markaspot_ai_token_usage')
      ->willReturn($insert);

    $this->service->logUsage('openai', 'gpt-4o', 'chat', 100, 50);
  }

  /**
   * Tests logging usage is skipped when tracking is disabled.
   *
   * @covers ::logUsage
   */
  public function testLogUsageSkippedWhenDisabled(): void {
    $this->config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'token_tracking.enabled' => FALSE,
          default => NULL,
        };
      });

    $this->database->expects($this->never())->method('insert');

    $this->service->logUsage('openai', 'gpt-4o', 'chat', 100, 50);
  }

  /**
   * Tests log usage handles database exception gracefully.
   *
   * @covers ::logUsage
   */
  public function testLogUsageDatabaseError(): void {
    $this->config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'token_tracking.enabled' => TRUE,
          default => NULL,
        };
      });

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willThrowException(new \Exception('DB error'));

    $this->database->method('insert')->willReturn($insert);

    // Should log error, not throw.
    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to log token usage'),
        $this->anything()
      );

    $this->service->logUsage('openai', 'gpt-4o', 'chat', 100, 50);
  }

  /**
   * Tests checkLimit returns TRUE when tracking is disabled.
   *
   * @covers ::checkLimit
   */
  public function testCheckLimitAlwaysAllowedWhenDisabled(): void {
    $service = $this->createServiceWithConfig(FALSE);
    $this->assertTrue($service->checkLimit());
  }

  /**
   * Tests checkLimit returns TRUE when no daily limit is set.
   *
   * @covers ::checkLimit
   */
  public function testCheckLimitAlwaysAllowedWhenNoLimit(): void {
    $service = $this->createServiceWithConfig(TRUE, 0);
    $this->assertTrue($service->checkLimit());
  }

  /**
   * Tests checkLimit returns TRUE when under the limit.
   *
   * @covers ::checkLimit
   */
  public function testCheckLimitUnderLimit(): void {
    $service = $this->createServiceWithConfig(TRUE, 100000);

    // Mock daily usage query returning low usage.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')
      ->willReturn([
        'total_input' => 1000,
        'total_output' => 500,
        'request_count' => 5,
      ]);
    $statement->method('fetchAllAssoc')
      ->willReturn([]);

    $select = $this->createMock(Select::class);
    $select->method('condition')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('fields')->willReturnSelf();
    $select->method('groupBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')
      ->willReturn($select);

    $this->assertTrue($service->checkLimit());
  }

  /**
   * Tests checkLimit returns FALSE when over the limit.
   *
   * @covers ::checkLimit
   */
  public function testCheckLimitOverLimit(): void {
    $service = $this->createServiceWithConfig(TRUE, 1000);

    // Mock daily usage query returning high usage.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')
      ->willReturn([
        'total_input' => 800,
        'total_output' => 300,
        'request_count' => 20,
      ]);
    $statement->method('fetchAllAssoc')
      ->willReturn([]);

    $select = $this->createMock(Select::class);
    $select->method('condition')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('fields')->willReturnSelf();
    $select->method('groupBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $this->assertFalse($service->checkLimit());
  }

  /**
   * Tests getRemainingTokens returns -1 when tracking is disabled.
   *
   * @covers ::getRemainingTokens
   */
  public function testRemainingTokensUnlimitedWhenDisabled(): void {
    $service = $this->createServiceWithConfig(FALSE);
    $this->assertEquals(-1, $service->getRemainingTokens());
  }

  /**
   * Tests getRemainingTokens returns -1 when no limit is set.
   *
   * @covers ::getRemainingTokens
   */
  public function testRemainingTokensUnlimitedWhenNoLimit(): void {
    $service = $this->createServiceWithConfig(TRUE, 0);
    $this->assertEquals(-1, $service->getRemainingTokens());
  }

  /**
   * Tests getRemainingTokens calculates correct remaining amount.
   *
   * @covers ::getRemainingTokens
   */
  public function testRemainingTokensCalculation(): void {
    $service = $this->createServiceWithConfig(TRUE, 10000);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')
      ->willReturn([
        'total_input' => 3000,
        'total_output' => 2000,
        'request_count' => 10,
      ]);
    $statement->method('fetchAllAssoc')
      ->willReturn([]);

    $select = $this->createMock(Select::class);
    $select->method('condition')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('fields')->willReturnSelf();
    $select->method('groupBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    // 10000 - 5000 = 5000.
    $this->assertEquals(5000, $service->getRemainingTokens());
  }

  /**
   * Tests getRemainingTokens returns 0 when over limit, not negative.
   *
   * @covers ::getRemainingTokens
   */
  public function testRemainingTokensNeverNegative(): void {
    $service = $this->createServiceWithConfig(TRUE, 1000);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')
      ->willReturn([
        'total_input' => 5000,
        'total_output' => 3000,
        'request_count' => 50,
      ]);
    $statement->method('fetchAllAssoc')
      ->willReturn([]);

    $select = $this->createMock(Select::class);
    $select->method('condition')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('fields')->willReturnSelf();
    $select->method('groupBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $this->assertEquals(0, $service->getRemainingTokens());
  }

  /**
   * Tests cleanup deletes old records.
   *
   * @covers ::cleanupOldRecords
   */
  public function testCleanupOldRecords(): void {
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(42);

    $this->database->method('delete')
      ->with('markaspot_ai_token_usage')
      ->willReturn($delete);

    $deleted = $this->service->cleanupOldRecords(90);
    $this->assertEquals(42, $deleted);
  }

  /**
   * Tests cleanup handles database exception gracefully.
   *
   * @covers ::cleanupOldRecords
   */
  public function testCleanupDatabaseError(): void {
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willThrowException(new \Exception('DB error'));

    $this->database->method('delete')->willReturn($delete);

    $deleted = $this->service->cleanupOldRecords(90);
    $this->assertEquals(0, $deleted);
  }

}
