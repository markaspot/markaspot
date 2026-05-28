<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_id\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_request_id\Service\RequestIdGenerator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests request ID formatting for multi-jurisdiction installs.
 */
#[Group('markaspot_request_id')]
class RequestIdGeneratorTest extends UnitTestCase {

  /**
   * Legacy sites without prefixes keep the existing visible ID format.
   */
  public function testRequestIdWithoutPrefixKeepsLegacyFormat(): void {
    $generator = $this->createGenerator();

    $this->assertSame(
      '7-2026',
      $generator->buildForTest(7, '2026', '-', 1, 1)
    );
  }

  /**
   * Source jurisdiction prefixes distinguish child jurisdictions.
   */
  public function testSourceJurisdictionPrefixWinsOverRootPrefix(): void {
    $generator = $this->createGenerator([
      1 => 'BONN',
      20 => 'BEUEL',
    ]);

    $this->assertSame(
      'BEUEL-7-2026',
      $generator->buildForTest(7, '2026', '-', 1, 20)
    );
  }

  /**
   * Root prefixes remain usable when a child has no explicit prefix.
   */
  public function testRootPrefixIsFallbackForUnconfiguredSource(): void {
    $generator = $this->createGenerator([
      1 => 'BONN',
    ]);

    $this->assertSame(
      'BONN-7-2026',
      $generator->buildForTest(7, '2026', '-', 1, 20)
    );
  }

  /**
   * Prefixes are normalized for URLs and legacy Open311 path parameters.
   */
  public function testPrefixIsNormalized(): void {
    $generator = $this->createGenerator([
      1 => 'Bonn Werke!',
    ]);

    $this->assertSame(
      'BONNWERKE-7-2026',
      $generator->buildForTest(7, '2026', '-', 1, 1)
    );
  }

  /**
   * Prefixed IDs fit the expanded node request_id base field.
   */
  public function testPrefixedRequestIdFitsExpandedBaseField(): void {
    $generator = $this->createGenerator([
      1 => 'ABCDEFGHIJKLMNOP',
    ]);

    $requestId = $generator->buildForTest(1234567890, '2026', '-', 1, 1);

    $this->assertSame('ABCDEFGHIJKLMNOP-1234567890-2026', $requestId);
    $this->assertLessThanOrEqual(64, strlen($requestId));
  }

  /**
   * New installs expose the jurisdiction request ID prefix field.
   */
  public function testJurisdictionPrefixFieldConfigExists(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $profileRoot = dirname($moduleRoot, 2);

    $storage = $this->loadYaml($moduleRoot . '/config/optional/field.storage.group.field_request_id_prefix.yml');
    $this->assertSame('group.field_request_id_prefix', $storage['id']);
    $this->assertSame('string', $storage['type']);
    $this->assertSame(16, $storage['settings']['max_length']);
    $this->assertFalse($storage['translatable']);

    $field = $this->loadYaml($moduleRoot . '/config/optional/field.field.group.jur.field_request_id_prefix.yml');
    $this->assertSame('group.jur.field_request_id_prefix', $field['id']);
    $this->assertFalse($field['required']);
    $this->assertFalse($field['translatable']);

    $display = $this->loadYaml($profileRoot . '/config/optional/core.entity_form_display.group.jur.default.yml');
    $this->assertSame(
      'string_textfield',
      $display['content']['field_request_id_prefix']['type'] ?? NULL
    );
  }

  /**
   * The node base field is large enough for prefixed request IDs.
   */
  public function testRequestIdBaseFieldLengthIsExpanded(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/markaspot_request_id.module');
    $this->assertIsString($module);
    $this->assertStringContainsString("->setSetting('max_length', 64)", $module);

    $install = file_get_contents(dirname(__DIR__, 3) . '/markaspot_request_id.install');
    $this->assertIsString($install);
    $this->assertStringContainsString('updateFieldStorageDefinition', $install);
    $storageUpdatePosition = strpos($install, 'updateFieldStorageDefinition');
    $groupGuardPosition = strpos($install, "moduleExists('group')");
    $this->assertNotFalse($storageUpdatePosition);
    $this->assertNotFalse($groupGuardPosition);
    $this->assertLessThan(
      $groupGuardPosition,
      $storageUpdatePosition,
      'The base-field schema update must not depend on group/jur availability.'
    );
  }

  /**
   * Creates a generator exposing protected formatting.
   *
   * @param array<int, string> $prefixes
   *   Prefixes keyed by jurisdiction group ID.
   *
   * @return \Drupal\Tests\markaspot_request_id\Unit\TestableRequestIdGenerator
   *   Test generator.
   */
  private function createGenerator(array $prefixes = []): TestableRequestIdGenerator {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn(new NullLogger());

    return new TestableRequestIdGenerator(
      $this->createMock(Connection::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LockBackendInterface::class),
      $loggerFactory,
      $prefixes,
    );
  }

  /**
   * Loads a YAML config file.
   *
   * @return array<string, mixed>
   *   Parsed config.
   */
  private function loadYaml(string $path): array {
    $this->assertFileExists($path);
    $config = Yaml::decode(file_get_contents($path));
    $this->assertIsArray($config);

    return $config;
  }

}

/**
 * Test double exposing request ID formatting.
 */
final class TestableRequestIdGenerator extends RequestIdGenerator {

  /**
   * Prefixes keyed by jurisdiction group ID.
   *
   * @var array<int, string>
   */
  private array $prefixes;

  /**
   * Constructs the test generator.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection mock.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory mock.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service mock.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Lock backend mock.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Logger factory mock.
   * @param array<int, string> $prefixes
   *   Prefixes keyed by jurisdiction group ID.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
    array $prefixes,
  ) {
    parent::__construct($database, $configFactory, $time, $lock, $loggerFactory);
    $this->prefixes = $prefixes;
  }

  /**
   * Exposes the protected request ID formatter.
   */
  public function buildForTest(
    int $sequence,
    string $date,
    string $delimiter,
    ?int $jurisdictionId,
    ?int $sourceJurisdictionId,
  ): string {
    return parent::buildRequestId(
      $sequence,
      $date,
      $delimiter,
      $jurisdictionId,
      $sourceJurisdictionId
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function loadRequestIdPrefix(?int $jurisdictionId): string {
    if ($jurisdictionId === NULL || !isset($this->prefixes[$jurisdictionId])) {
      return '';
    }

    return $this->normalizeRequestIdPrefix($this->prefixes[$jurisdictionId]);
  }

}
