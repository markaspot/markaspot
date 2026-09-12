<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\markaspot_tenant_import\Drush\Commands\TenantImportCommands;
use Drupal\markaspot_tenant_import\Exception\TenantImportValidationException;
use Drupal\markaspot_tenant_import\Service\TenantImporter;
use Drupal\Tests\UnitTestCase;
use Drush\Config\DrushConfig;
use Drush\Log\DrushLoggerManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests tenant import command option handling and operator errors.
 */
#[CoversClass(TenantImportCommands::class)]
#[Group('markaspot_tenant_import')]
final class TenantImportCommandsTest extends UnitTestCase {

  /**
   * Provides the Drush compatibility alias normally registered by preflight.
   */
  protected function setUp(): void {
    parent::setUp();
    if (!class_exists('Drush\Style\DrushStyle')) {
      class_alias(SymfonyStyle::class, 'Drush\Style\DrushStyle');
    }
  }

  /**
   * Rejects missing and invalid jurisdiction IDs before reading the file.
   */
  #[DataProvider('invalidJurisdictionProvider')]
  public function testJurisdictionOptionMustBePositive(mixed $jurisdiction): void {
    $importer = $this->createMockImporter();
    $importer->expects($this->never())->method('decodeFile');
    $command = $this->createCommand($importer);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'The required --jurisdiction option must be a positive group ID.',
    );
    $command->import('/tmp/tenant.json', [
      'jurisdiction' => $jurisdiction,
    ]);
  }

  /**
   * Provides invalid jurisdiction option values.
   *
   * @return array<string, array{mixed}>
   *   Invalid values.
   */
  public static function invalidJurisdictionProvider(): array {
    return [
      'missing' => [NULL],
      'zero' => [0],
      'negative' => [-1],
      'text' => ['not-an-id'],
    ];
  }

  /**
   * Normalizes skip sections and forwards every safety option.
   */
  public function testSkipOptionIsNormalizedAndForwarded(): void {
    $configuration = ['version' => 1];
    $importer = $this->createMockImporter();
    $importer->expects($this->once())
      ->method('decodeFile')
      ->with('/tmp/tenant.json')
      ->willReturn($configuration);
    $importer->expects($this->once())
      ->method('import')
      ->with(
        $configuration,
        42,
        ['users', 'categories'],
        TRUE,
        TRUE,
        TRUE,
        TRUE,
      )
      ->willReturn([
        'rows' => [[
          'entity' => 'jurisdiction',
          'key' => '42',
          'action' => 'unchanged',
          'reason' => 'Target checked.',
        ]],
        'errors' => [],
        'created_terms' => FALSE,
      ]);
    $command = $this->createCommand($importer);

    $rows = iterator_to_array($command->import('tenant.json', [
      'jurisdiction' => '42',
      'apply' => TRUE,
      'skip' => ' users, categories,users, ,',
      'send-mails' => TRUE,
      'allow-cross-tenant-users' => TRUE,
      'allow-slug-mismatch' => TRUE,
    ]));
    $this->assertSame('jurisdiction', $rows[0]['entity']);
  }

  /**
   * Wraps importer validation failures with their total count.
   */
  public function testValidationExceptionIsWrappedWithCount(): void {
    $importer = $this->createMockImporter();
    $importer->method('decodeFile')->willThrowException(
      new TenantImportValidationException(['First error.', 'Second error.']),
    );
    $command = $this->createCommand($importer);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Tenant configuration validation failed with 2 error(s).',
    );
    $command->import('tenant.json', ['jurisdiction' => 1]);
  }

  /**
   * Includes apply-time row failures in the command exception.
   */
  public function testApplyErrorsAreReportedWithDetails(): void {
    $importer = $this->createMockImporter();
    $importer->method('decodeFile')->willReturn(['version' => 1]);
    $importer->method('import')->willReturn([
      'rows' => [[
        'entity' => 'user',
        'key' => 'person@example.com',
        'action' => 'skip',
        'reason' => 'Transaction rolled back.',
      ]],
      'errors' => ['user person@example.com: failure'],
      'created_terms' => FALSE,
    ]);
    $command = $this->createCommand($importer);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Tenant import finished with 1 error(s): user person@example.com: failure',
    );
    $command->import('tenant.json', [
      'jurisdiction' => 1,
      'apply' => TRUE,
    ]);
  }

  /**
   * Logs the cache guidance only when taxonomy terms were created.
   */
  public function testCreatedTermsLogsCacheNotice(): void {
    $importer = $this->createMockImporter();
    $importer->method('decodeFile')->willReturn(['version' => 1]);
    $importer->method('import')->willReturn([
      'rows' => [[
        'entity' => 'status',
        'key' => 'Open',
        'action' => 'create',
        'reason' => 'New status.',
      ]],
      'errors' => [],
      'created_terms' => TRUE,
    ]);
    $messages = [];
    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->exactly(2))
      ->method('notice')
      ->willReturnCallback(static function (string $message) use (&$messages): void {
        $messages[] = $message;
      });
    $command = $this->createCommand($importer, $logger);

    iterator_to_array($command->import('tenant.json', [
      'jurisdiction' => 1,
      'apply' => TRUE,
    ]));
    $this->assertStringContainsString('Apply summary: create=1.', $messages[0]);
    $this->assertSame(
      'No cache rebuild was run. If new taxonomy terms are not visible immediately, run drush cr.',
      $messages[1],
    );
  }

  /**
   * Creates a TenantImporter test double despite its dependency-heavy service.
   *
   * @return \Drupal\markaspot_tenant_import\Service\TenantImporter&\PHPUnit\Framework\MockObject\MockObject
   *   Importer mock.
   */
  private function createMockImporter(): TenantImporter&MockObject {
    return $this->getMockBuilder(TenantImporter::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['decodeFile', 'import'])
      ->getMock();
  }

  /**
   * Creates a command with deterministic cwd, IO, and logging collaborators.
   */
  private function createCommand(
    TenantImporter $importer,
    ?DrushLoggerManager $logger = NULL,
  ): TenantImportCommands {
    $command = new TenantImportCommands($importer);
    $config = $this->createMock(DrushConfig::class);
    $config->method('cwd')->willReturn('/tmp');
    $command->setConfig($config);
    $command->setInput(new ArrayInput([]));
    $command->setOutput(new BufferedOutput());
    $command->setLogger($logger ?? $this->createMock(DrushLoggerManager::class));
    return $command;
  }

}
