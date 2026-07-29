<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_dashboard\Service\MetricsCalculatorService;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests status distribution SQL compatibility.
 *
 * @group markaspot_dashboard
 * @coversDefaultClass \Drupal\markaspot_dashboard\Service\MetricsCalculatorService
 */
class MetricsCalculatorServiceTest extends UnitTestCase {

  /**
   * Tests that missing jurisdiction context keeps the legacy SQL unfiltered.
   *
   * @covers ::getStatusDistribution
   */
  public function testStatusDistributionSqlIsUnfilteredWithoutJurisdiction(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([]);

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('query')
      ->with($this->callback(function (string $sql): bool {
        $this->assertStringContainsString(
          "WHERE t.vid = 'service_status' AND t.default_langcode = 1",
          $sql,
        );
        $this->assertStringNotContainsString(
          'taxonomy_term__field_jurisdiction',
          $sql,
        );
        $this->assertStringNotContainsString(
          't.tid IN',
          $sql,
        );
        return TRUE;
      }))
      ->willReturn($statement);

    $statusTermScope = $this->createMock(StatusTermScope::class);
    $statusTermScope->expects($this->once())
      ->method('canScope')
      ->with(NULL)
      ->willReturn(FALSE);

    $service = $this->createService($database, $statusTermScope);

    $this->assertSame([], $service->getStatusDistribution([], []));
  }

  /**
   * Tests that the resolver's effective status IDs scope the SQL.
   *
   * @covers ::getStatusDistribution
   */
  public function testStatusDistributionSqlUsesEffectiveStatusSelection(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([]);

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('query')
      ->with(
        $this->callback(function (string $sql): bool {
          $this->assertStringContainsString(
            't.tid IN (:status_tid_0,:status_tid_1)',
            $sql,
          );
          $this->assertStringNotContainsString(
            'taxonomy_term__field_jurisdiction',
            $sql,
          );
          return TRUE;
        }),
        [
          ':status_tid_0' => 101,
          ':status_tid_1' => 102,
        ],
      )
      ->willReturn($statement);

    $statusTermScope = $this->createMock(StatusTermScope::class);
    $statusTermScope->expects($this->once())
      ->method('canScope')
      ->with(12)
      ->willReturn(TRUE);
    $first = $this->createMock(TermInterface::class);
    $first->method('id')->willReturn(101);
    $second = $this->createMock(TermInterface::class);
    $second->method('id')->willReturn(102);
    $statusTermScope->expects($this->exactly(2))
      ->method('loadByProperties')
      ->willReturnCallback(function (array $properties, int $jurisdictionId) use ($first, $second): array {
        $this->assertSame(['vid' => 'service_status'], $properties);
        return match ($jurisdictionId) {
          12 => [$first],
          13 => [$second],
        };
      });

    $service = $this->createService($database, $statusTermScope);

    $this->assertSame([], $service->getStatusDistribution([], [12, 13]));
  }

  /**
   * Creates the service with the supplied database and scope doubles.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection double.
   * @param \Drupal\markaspot_group\Service\StatusTermScope $statusTermScope
   *   Status scope double.
   *
   * @return \Drupal\markaspot_dashboard\Service\MetricsCalculatorService
   *   Service under test.
   */
  protected function createService(
    Connection $database,
    StatusTermScope $statusTermScope,
  ): MetricsCalculatorService {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_dashboard')
      ->willReturn($this->createMock(LoggerInterface::class));

    return new MetricsCalculatorService(
      $database,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $loggerFactory,
      $statusTermScope,
    );
  }

}
