<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Schema;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_dashboard\Service\MetricsCalculatorService;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_open311\Service\StatusClassifier;
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
  public function testStatusDistributionSqlUsesTreePoolForStatusFilter(): void {
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
      ->method('loadTreePoolByProperties')
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
   * Proves distribution semantics do not depend on labels or zero counts.
   */
  public function testStatusDistributionIncludesCanonicalSemantics(): void {
    foreach ([[], [1, 2]] as $node_ids) {
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchAll')->willReturn([
        (object) ['tid' => 101, 'status' => 'Geschlossen', 'count' => 2, 'color' => '#ff0000'],
        (object) ['tid' => 202, 'status' => 'Externe Zuständigkeit', 'count' => 3, 'color' => '#00ff00'],
      ]);
      $database = $this->createMock(Connection::class);
      $database->method('query')->willReturn($statement);
      $scope = $this->createMock(StatusTermScope::class);
      $scope->method('canScope')->willReturn(FALSE);
      $classifier = $this->createMock(StatusClassifier::class);
      $classifier->expects($this->exactly(2))->method('isClosed')
        ->willReturnMap([[101, FALSE], [202, TRUE]]);
      $service = $this->createService($database, $scope, $classifier);
      $distribution = $service->getStatusDistribution($node_ids);
      $this->assertSame(['open', 'closed'], array_column($distribution, 'open311'));
      $this->assertSame(['Geschlossen', 'Externe Zuständigkeit'], array_column($distribution, 'status'));
      $this->assertSame($node_ids === [] ? [0, 0] : [2, 3], array_column($distribution, 'count'));
    }
  }

  /**
   * FCR uses provisioned closed terms from the shared classifier.
   */
  public function testFcrUsesClassifiedClosedTerms(): void {
    $classifier = $this->createMock(StatusClassifier::class);
    $classifier->expects($this->once())->method('closedTids')->willReturn([401, 402]);
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn([1]);
    $statement->method('fetchField')->willReturn(2);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $database->expects($this->exactly(2))->method('query')
      ->willReturnCallback(function (string $sql, array $args) use ($statement): StatementInterface {
        $this->assertContains(401, $args);
        $this->assertContains(402, $args);
        $this->assertStringContainsString(' IN (:closed_tid_0,:closed_tid_1)', str_replace('eligible_closed_tid', 'closed_tid', $sql));
        return $statement;
      });
    $service = $this->createService($database, $this->createMock(StatusTermScope::class), $classifier);
    $this->assertSame(['fcr_count' => 1, 'eligible_count' => 2, 'rate' => 50.0], $service->calculateFcrRate([1, 2]));
  }

  /**
   * Processing time uses the same classifier as status distribution and FCR.
   */
  public function testProcessingTimeUsesClassifiedClosedTerms(): void {
    $classifier = $this->createMock(StatusClassifier::class);
    $classifier->expects($this->once())->method('closedTids')->willReturn([401, 402]);
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([
      (object) ['first_created' => 1000, 'closed_created' => 8200],
    ]);
    $database = $this->createMock(Connection::class);
    $database->expects($this->once())->method('query')
      ->with($this->stringContains('pst.field_status_term_target_id IN (:closed_tid_0,:closed_tid_1)'), [
        ':nid_0' => 1, ':closed_tid_0' => 401, ':closed_tid_1' => 402,
      ])->willReturn($statement);
    $service = $this->createService($database, $this->createMock(StatusTermScope::class), $classifier);
    $result = $service->calculateAvgProcessingTime([1]);
    $this->assertSame(1, $result['closed_count']);
    $this->assertSame(7200, $result['avg_seconds']);
    $this->assertSame(2.0, $result['avg_hours']);
  }

  /**
   * Creates the service with the supplied database and scope doubles.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection double.
   * @param \Drupal\markaspot_group\Service\StatusTermScope $statusTermScope
   *   Status scope double.
   * @param \Drupal\markaspot_open311\Service\StatusClassifier|null $statusClassifier
   *   Status classifier double.
   *
   * @return \Drupal\markaspot_dashboard\Service\MetricsCalculatorService
   *   Service under test.
   */
  protected function createService(
    Connection $database,
    StatusTermScope $statusTermScope,
    ?StatusClassifier $statusClassifier = NULL,
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
      $statusClassifier ?? $this->createMock(StatusClassifier::class),
    );
  }

}
