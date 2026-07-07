<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_id\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
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
    $this->assertStringContainsString("changeField(\$table, 'request_id'", $install);
    $this->assertStringContainsString('CHARACTER_MAXIMUM_LENGTH', $install);
    $this->assertStringContainsString('$current_length > 64', $install);
    $this->assertStringContainsString('$seen === count($tables)', $install);
    $this->assertStringContainsString('expected storage columns are incomplete', $install);
    $this->assertStringContainsString('setLastInstalledFieldStorageDefinition', $install);
    $this->assertStringNotContainsString('updateFieldStorageDefinition', $install);
    $storageUpdatePosition = strpos($install, '_markaspot_request_id_expand_node_request_id_columns($messages)');
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
   * Generation keeps the sequence-table candidate when no node collides.
   */
  public function testGenerateRequestIdWithoutCollisionRecordsCandidate(): void {
    $insertedFields = [];
    $queries = [];
    $generator = $this->createGeneratorForGeneration(
      (object) [
        'seq' => 2,
        'timestamp' => strtotime('2026-05-01 12:00:00 UTC'),
      ],
      [1 => []],
      $insertedFields,
      $queries
    );

    $requestId = $generator->generateRequestId(1, 1);

    $this->assertSame('3-2026', $requestId);
    $this->assertSame(1, $insertedFields['jurisdiction_id']);
    $this->assertSame(3, $insertedFields['seq']);
    $this->assertSame('3-2026', $insertedFields['request_id']);
    $this->assertSame(1, $this->countQueriesContaining($queries, 'SELECT 1'));
    $this->assertSame(0, $this->countQueriesContaining($queries, 'SELECT DISTINCT nfd.request_id'));
  }

  /**
   * Existing node request IDs are skipped and the final seq is recorded.
   */
  public function testGenerateRequestIdSkipsExistingNodeRequestIds(): void {
    $insertedFields = [];
    $queries = [];
    $generator = $this->createGeneratorForGeneration(
      FALSE,
      [1 => ['1-2026', '2-2026']],
      $insertedFields,
      $queries
    );

    $requestId = $generator->generateRequestId(1, 1);

    $this->assertSame('3-2026', $requestId);
    $this->assertSame(3, $insertedFields['seq']);
    $this->assertSame('3-2026', $insertedFields['request_id']);
    $this->assertSame(2, $this->countQueriesContaining($queries, 'SELECT 1'));
    $this->assertSame(1, $this->countQueriesContaining($queries, 'SELECT DISTINCT nfd.request_id'));
  }

  /**
   * Dense imported ranges jump to the highest matching prefixed sequence.
   */
  public function testGenerateRequestIdJumpsToMaxExistingNodeSequence(): void {
    $insertedFields = [];
    $queries = [];
    $existingRequestIds = array_map(
      static fn(int $seq): string => 'BER-' . $seq . '-2026',
      range(1, 409)
    );
    $generator = $this->createGeneratorForGeneration(
      FALSE,
      [1 => $existingRequestIds],
      $insertedFields,
      $queries,
      [1 => 'BER']
    );

    $requestId = $generator->generateRequestId(1, 1);

    $this->assertSame('BER-410-2026', $requestId);
    $this->assertSame(410, $insertedFields['seq']);
    $this->assertSame('BER-410-2026', $insertedFields['request_id']);
    $this->assertSame(2, $this->countQueriesContaining($queries, 'SELECT 1'));
    $this->assertSame(1, $this->countQueriesContaining($queries, 'SELECT DISTINCT nfd.request_id'));
  }

  /**
   * Jurisdictionless generation only checks nodes without field_jurisdiction.
   */
  public function testJurisdictionlessGenerationUsesMissingJurisdictionScope(): void {
    $insertedFields = [];
    $queries = [];
    $generator = $this->createGeneratorForGeneration(
      FALSE,
      [0 => ['1-2026']],
      $insertedFields,
      $queries
    );

    $requestId = $generator->generateRequestId(NULL, NULL);

    $this->assertSame('2-2026', $requestId);
    $this->assertSame(0, $insertedFields['jurisdiction_id']);
    $this->assertSame(2, $insertedFields['seq']);
    $this->assertSame(0, $this->countNodeScopeQueriesWithArgument($queries, ':jid'));
    $this->assertGreaterThanOrEqual(
      1,
      $this->countQueriesContaining($queries, 'nfj.entity_id IS NULL')
    );
  }

  /**
   * Jurisdiction scopes include all descendants, not only direct children.
   */
  public function testJurisdictionScopeQueryTraversesAllDescendants(): void {
    $insertedFields = [];
    $queries = [];
    $generator = $this->createGeneratorForGeneration(
      FALSE,
      [1 => []],
      $insertedFields,
      $queries
    );

    $generator->generateRequestId(1, 1);
    $nodeScopeSql = $this->firstNodeScopeQuerySql($queries);

    $this->assertStringContainsString('WITH RECURSIVE jurisdiction_scope', $nodeScopeSql);
    $this->assertStringContainsString('UNION', $nodeScopeSql);
    $this->assertStringContainsString('FROM {group__field_parent_jurisdiction} gpj', $nodeScopeSql);
    $this->assertStringContainsString('INNER JOIN jurisdiction_scope scope', $nodeScopeSql);
    $this->assertStringContainsString('scope.id = nfj.field_jurisdiction_target_id', $nodeScopeSql);
    $this->assertStringNotContainsString('COALESCE(gpj', $nodeScopeSql);
  }

  /**
   * Max-jump LIKE scans escape wildcard characters from configured delimiters.
   */
  public function testMaxJumpLikePatternEscapesConfiguredDelimiterWildcards(): void {
    $insertedFields = [];
    $queries = [];
    $generator = $this->createGeneratorForGeneration(
      FALSE,
      [1 => ['1%_2026']],
      $insertedFields,
      $queries,
      [],
      '%_'
    );

    $requestId = $generator->generateRequestId(1, 1);
    $maxQuery = $this->firstNodeScopeQueryWithArgument($queries, ':request_id_pattern');

    $this->assertSame('2%_2026', $requestId);
    $this->assertSame('%\%\_2026', $maxQuery['args'][':request_id_pattern']);
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
   * Creates a generator with database mocks for generateRequestId().
   *
   * @param object|false $last
   *   Last sequence-table row returned by FOR UPDATE.
   * @param array<int, list<string>> $existingRequestIds
   *   Existing node request IDs keyed by root jurisdiction ID.
   * @param array<string, mixed> $insertedFields
   *   Captures the generated sequence-table insert.
   * @param list<array{sql: string, args: array<string, mixed>}> $queries
   *   Captures executed SQL queries.
   * @param array<int, string> $prefixes
   *   Prefixes keyed by jurisdiction group ID.
   * @param string $delimiter
   *   Configured request ID delimiter.
   *
   * @return \Drupal\Tests\markaspot_request_id\Unit\TestableRequestIdGenerator
   *   Test generator.
   */
  private function createGeneratorForGeneration(
    object|false $last,
    array $existingRequestIds,
    array &$insertedFields,
    array &$queries,
    array $prefixes = [],
    string $delimiter = '-',
  ): TestableRequestIdGenerator {
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnCallback(
      function (array $fields) use ($insert, &$insertedFields): Insert {
        $insertedFields = $fields;
        return $insert;
      }
    );
    $insert->method('execute')->willReturn(1);

    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($this->fakeTransaction());
    $database->method('escapeLike')
      ->willReturnCallback(static fn(string $string): string => addcslashes($string, '\%_'));
    $database->expects($this->once())
      ->method('insert')
      ->with('markaspot_request_id')
      ->willReturn($insert);
    $database->method('query')->willReturnCallback(
      function (string $sql, array $args = []) use ($last, $existingRequestIds, &$queries): StatementInterface {
        $queries[] = ['sql' => $sql, 'args' => $args];

        if (str_contains($sql, 'FROM {markaspot_request_id}')) {
          return $this->statementWithFetchObject($last);
        }

        if (str_contains($sql, 'SELECT DISTINCT nfd.request_id')) {
          $jid = (int) ($args[':jid'] ?? 0);
          return $this->statementWithFetchCol($existingRequestIds[$jid] ?? []);
        }

        if (str_contains($sql, 'SELECT 1')) {
          $jid = (int) ($args[':jid'] ?? 0);
          $requestId = (string) ($args[':request_id'] ?? '');
          $exists = in_array($requestId, $existingRequestIds[$jid] ?? [], TRUE);
          return $this->statementWithFetchField($exists ? '1' : FALSE);
        }

        $this->fail('Unexpected SQL query: ' . $sql);
      }
    );

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn(string $key): mixed => match ($key) {
        'delimiter' => $delimiter,
        'format' => 'Y',
        'rollover' => TRUE,
        'start' => NULL,
        default => NULL,
      }
    );

    $nodeType = $this->createMock(ImmutableConfig::class);
    $nodeType->method('get')
      ->with('type')
      ->willReturn('service_request');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      static fn(string $name): ImmutableConfig => match ($name) {
        'markaspot_request_id.settings' => $settings,
        'node.type.service_request' => $nodeType,
        default => throw new \InvalidArgumentException('Unexpected config: ' . $name),
      }
    );

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')
      ->willReturn((int) strtotime('2026-05-17 12:00:00 UTC'));

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn(new NullLogger());

    return new TestableRequestIdGenerator(
      $database,
      $configFactory,
      $time,
      $lock,
      $loggerFactory,
      $prefixes,
    );
  }

  /**
   * Builds a Transaction stand-in without entering the DB lifecycle.
   */
  private function fakeTransaction(): Transaction {
    return new class() extends Transaction {

      /**
       * Skips the parent constructor.
       */
      public function __construct() {
      }

      /**
       * Skips the parent destructor.
       */
      public function __destruct() {
      }

    };
  }

  /**
   * Creates a statement whose fetchObject() returns the supplied row.
   */
  private function statementWithFetchObject(object|false $row): StatementInterface {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn($row);

    return $statement;
  }

  /**
   * Creates a statement whose fetchField() returns the supplied value.
   */
  private function statementWithFetchField(mixed $value): StatementInterface {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($value);

    return $statement;
  }

  /**
   * Creates a statement whose fetchCol() returns the supplied values.
   *
   * @param list<string> $values
   *   Column values.
   */
  private function statementWithFetchCol(array $values): StatementInterface {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn($values);

    return $statement;
  }

  /**
   * Counts captured queries containing a SQL fragment.
   *
   * @param list<array{sql: string, args: array<string, mixed>}> $queries
   *   Captured queries.
   * @param string $needle
   *   SQL fragment to find.
   */
  private function countQueriesContaining(array $queries, string $needle): int {
    return count(array_filter(
      $queries,
      static fn(array $query): bool => str_contains($query['sql'], $needle)
    ));
  }

  /**
   * Counts captured node-scope queries carrying a named argument.
   *
   * @param list<array{sql: string, args: array<string, mixed>}> $queries
   *   Captured queries.
   * @param string $argument
   *   Query argument name to find.
   */
  private function countNodeScopeQueriesWithArgument(array $queries, string $argument): int {
    return count(array_filter(
      $queries,
      static fn(array $query): bool => str_contains($query['sql'], 'FROM {node_field_data}')
        && array_key_exists($argument, $query['args'])
    ));
  }

  /**
   * Returns the first captured node-scope query SQL.
   *
   * @param list<array{sql: string, args: array<string, mixed>}> $queries
   *   Captured queries.
   */
  private function firstNodeScopeQuerySql(array $queries): string {
    foreach ($queries as $query) {
      if (str_contains($query['sql'], 'FROM {node_field_data}')) {
        return $query['sql'];
      }
    }

    $this->fail('No node-scope SQL query was captured.');
  }

  /**
   * Returns the first captured node-scope query carrying a named argument.
   *
   * @param list<array{sql: string, args: array<string, mixed>}> $queries
   *   Captured queries.
   * @param string $argument
   *   Query argument name to find.
   *
   * @return array{sql: string, args: array<string, mixed>}
   *   Captured query.
   */
  private function firstNodeScopeQueryWithArgument(array $queries, string $argument): array {
    foreach ($queries as $query) {
      if (str_contains($query['sql'], 'FROM {node_field_data}')
        && array_key_exists($argument, $query['args'])) {
        return $query;
      }
    }

    $this->fail('No matching node-scope SQL query was captured.');
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
