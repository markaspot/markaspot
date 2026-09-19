<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\markaspot_ai\Drush\Commands\MarkaspotAiCommands;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests explicit embedding relabelling and visibility warnings without a DB.
 */
#[CoversClass(EmbeddingService::class)]
#[CoversClass(MarkaspotAiCommands::class)]
#[Group('markaspot_ai')]
class EmbeddingIdentityTest extends UnitTestCase {

  /**
   * Creates real policy methods with a mocked stored-model inventory.
   */
  private function service(array $rows, Connection $db, LoggerInterface $logger): EmbeddingService {
    $client = $this->createMock(AiClientService::class);
    $client->method('resolveEmbeddingModel')->willReturn('current');
    $client->expects($this->never())->method('embed');
    $client->expects($this->never())->method('chat');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    $service = $this->getMockBuilder(EmbeddingService::class)->setConstructorArgs([
      $client, $db, $this->createMock(EntityTypeManagerInterface::class), $factory,
    ])->onlyMethods(['getStoredModelSummary'])->getMock();
    $service->method('getStoredModelSummary')->willReturn($rows);
    return $service;
  }

  /**
   * Refusals and dry runs never update; success changes only the model.
   */
  #[DataProvider('relabels')]
  public function testRelabelCommand(array $options, array $rows, int $exit, bool $updates): void {
    $db = $this->createMock(Connection::class);
    if ($updates) {
      $update = $this->createMock(Update::class);
      $db->expects($this->once())->method('update')->with('markaspot_ai_embeddings')->willReturn($update);
      $update->expects($this->once())->method('fields')->with(['model' => 'current'])->willReturnSelf();
      $update->expects($this->once())->method('condition')->with('model', 'old')->willReturnSelf();
      $update->method('execute')->willReturn(3);
    }
    else {
      $db->expects($this->never())->method('update');
    }
    $service = $this->service($rows, $db, $this->createMock(LoggerInterface::class));
    $command = new MarkaspotAiCommands(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(QueueFactory::class),
      $this->createMock(QueueWorkerManagerInterface::class),
      $service, $this->createMock(SentimentService::class), $db,
    );
    $output = new BufferedOutput();
    $command->setOutput($output);
    self::assertSame($exit, $command->embeddingsRelabel($options));
    $text = $output->fetch();
    self::assertStringContainsString('model, dimensions, count', $text);
    if ($exit === 0) {
      self::assertStringContainsString($updates ? 'Affected rows: 3' : 'Would relabel rows: 3', $text);
    }
  }

  /**
   * Covers required identifiers, dimension conflicts and dry runs.
   */
  public static function relabels(): iterable {
    $rows = [['model' => 'old', 'dimensions' => 3, 'count' => 3]];
    yield [['to' => 'current'], $rows, 1, FALSE];
    yield [['from' => 'old'], $rows, 1, FALSE];
    yield [['from' => 'missing', 'to' => 'current'], $rows, 1, FALSE];
    yield [['from' => 'old', 'to' => 'unconfigured'], $rows, 1, FALSE];
    $conflict = [...$rows, ['model' => 'current', 'dimensions' => 4, 'count' => 1]];
    yield [['from' => 'old', 'to' => 'current'], $conflict, 1, FALSE];
    yield [['from' => 'old', 'to' => 'current', 'dry-run' => TRUE], $rows, 0, FALSE];
    yield [['from' => 'old', 'to' => 'current'], $rows, 0, TRUE];
  }

  /**
   * Empty model-filtered lookups warn once without exposing vector data.
   */
  public function testMissingModelWarningOnce(): void {
    $db = $this->createMock(Connection::class);
    $query = $this->createMock(SelectInterface::class);
    foreach (['fields', 'condition', 'range'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllAssoc')->willReturn([]);
    $query->method('execute')->willReturn($statement);
    $db->method('select')->willReturn($query);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning')->with(
      $this->stringContains('markaspot:ai:embeddings-relabel'),
      ['@model' => 'current', '@stored' => 'old'],
    );
    $service = $this->service([['model' => 'old', 'dimensions' => 3, 'count' => 3]], $db, $logger);
    self::assertSame([], $service->getAllEmbeddings());
    self::assertSame([], $service->getAllEmbeddings());
  }

}
