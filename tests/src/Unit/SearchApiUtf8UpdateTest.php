<?php

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Utility\UpdateException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Schema test double for the Search API UTF-8 update helper.
 */
class SearchApiUtf8SchemaDouble {

  /**
   * Constructs a schema test double.
   */
  public function __construct(
    private readonly bool $tableExists = TRUE,
    private readonly bool $fieldExists = TRUE,
  ) {}

  /**
   * Reports whether the table exists.
   */
  public function tableExists(string $table): bool {
    return $this->tableExists;
  }

  /**
   * Reports whether the field exists.
   */
  public function fieldExists(string $table, string $field): bool {
    return $this->fieldExists;
  }

}

/**
 * Query-result test double for the Search API UTF-8 update helper.
 */
class SearchApiUtf8ResultDouble {

  /**
   * Constructs a query-result test double.
   */
  public function __construct(private readonly array|false $row) {}

  /**
   * Returns the configured column metadata.
   */
  public function fetchAssoc(): array|false {
    return $this->row;
  }

}

/**
 * Database test double for the Search API UTF-8 update helper.
 */
class SearchApiUtf8ConnectionDouble {

  /**
   * Captured SQL statements and arguments.
   *
   * @var array<int, array{query: string, arguments: array}>
   */
  public array $queries = [];

  /**
   * Constructs a database test double.
   */
  public function __construct(
    private readonly SearchApiUtf8SchemaDouble $schema,
    private readonly string $databaseType = 'mysql',
    private string $type = 'varchar(30)',
    private string $collation = 'utf8mb3_general_ci',
    private readonly string $nullable = 'YES',
    private readonly mixed $default = NULL,
    private readonly string $key = 'MUL',
    private readonly string $extra = '',
    private readonly string $comment = "The field's value for this item",
    private readonly bool $applyAlter = TRUE,
    private readonly bool $metadataAvailable = TRUE,
  ) {}

  /**
   * Returns the configured database type.
   */
  public function databaseType(): string {
    return $this->databaseType;
  }

  /**
   * Returns the schema test double.
   */
  public function schema(): SearchApiUtf8SchemaDouble {
    return $this->schema;
  }

  /**
   * Captures SQL and simulates the collation change.
   */
  public function query(string $query, array $arguments = []): SearchApiUtf8ResultDouble {
    $this->queries[] = [
      'query' => $query,
      'arguments' => $arguments,
    ];
    if ($this->applyAlter && str_starts_with($query, 'ALTER TABLE')) {
      $this->collation = 'utf8mb4_bin';
    }
    if (!$this->metadataAvailable && str_starts_with($query, 'SHOW FULL COLUMNS')) {
      return new SearchApiUtf8ResultDouble(FALSE);
    }

    return new SearchApiUtf8ResultDouble([
      'Type' => $this->type,
      'Collation' => $this->collation,
      'Null' => $this->nullable,
      'Default' => $this->default,
      'Key' => $this->key,
      'Extra' => $this->extra,
      'Comment' => $this->comment,
    ]);
  }

}

/**
 * Tests the profile update for four-byte Search API content.
 */
#[Group('markaspot')]
class SearchApiUtf8UpdateTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot.install';
  }

  /**
   * Confirms the database repair is shipped as a profile update hook.
   */
  public function testProfileUpdateHookExists(): void {
    $this->assertTrue(function_exists('markaspot_update_11934'));
  }

  /**
   * Confirms non-MySQL databases are left untouched.
   */
  public function testNonMysqlDatabaseIsSkipped(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(),
      'sqlite',
    );

    $this->assertSame(
      'Search API UTF-8 repair is only required on MySQL.',
      _markaspot_ensure_search_api_body_utf8mb4($database),
    );
    $this->assertSame([], $database->queries);
  }

  /**
   * Confirms installations without the denormalized table are skipped.
   */
  public function testMissingTableIsSkipped(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(FALSE),
    );

    $this->assertSame(
      'Skipped Search API UTF-8 repair: table search_api_db_service_requests is absent.',
      _markaspot_ensure_search_api_body_utf8mb4($database),
    );
    $this->assertSame([], $database->queries);
  }

  /**
   * Confirms an existing index table without the body column fails closed.
   */
  public function testMissingColumnFailsClosed(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(TRUE, FALSE),
    );

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Expected Search API column search_api_db_service_requests.body is absent.');
    _markaspot_ensure_search_api_body_utf8mb4($database);
  }

  /**
   * Confirms a foreign four-byte collation fails closed.
   */
  public function testDifferentUtf8mb4CollationFailsClosed(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(),
      collation: 'utf8mb4_unicode_ci',
    );

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Unexpected collation for search_api_db_service_requests.body: utf8mb4_unicode_ci.');
    _markaspot_ensure_search_api_body_utf8mb4($database);
  }

  /**
   * Confirms an existing target definition is preserved.
   */
  public function testExistingTargetDefinitionIsPreserved(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(),
      collation: 'utf8mb4_bin',
    );

    $this->assertSame(
      'Search API column search_api_db_service_requests.body already uses utf8mb4_bin.',
      _markaspot_ensure_search_api_body_utf8mb4($database),
    );
    $this->assertCount(1, $database->queries);
  }

  /**
   * Confirms only the body prefix column is converted.
   */
  public function testLegacyBodyColumnIsConverted(): void {
    $database = new SearchApiUtf8ConnectionDouble(new SearchApiUtf8SchemaDouble());

    $this->assertSame(
      'Converted Search API column search_api_db_service_requests.body to utf8mb4_bin.',
      _markaspot_ensure_search_api_body_utf8mb4($database),
    );
    $this->assertCount(3, $database->queries);
    $this->assertSame(
      [':column' => 'body'],
      $database->queries[0]['arguments'],
    );

    $alter = $database->queries[1]['query'];
    $this->assertStringContainsString('MODIFY [body] VARCHAR(30)', $alter);
    $this->assertStringContainsString('CHARACTER SET utf8mb4', $alter);
    $this->assertStringContainsString('COLLATE utf8mb4_bin', $alter);
    $this->assertStringContainsString("COMMENT 'The field''s value for this item'", $alter);
    $this->assertStringNotContainsString('CONVERT TO CHARACTER SET', $alter);
    $this->assertStringNotContainsString('item_id', $alter);
  }

  /**
   * Confirms an unexpected column shape fails closed.
   */
  public function testUnexpectedColumnTypeFailsClosed(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(),
      type: 'text',
      collation: 'utf8mb4_bin',
    );

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Unexpected definition for search_api_db_service_requests.body: type=text, null=YES, default=NULL, key=MUL');
    _markaspot_ensure_search_api_body_utf8mb4($database);
  }

  /**
   * Confirms an unexpected key definition fails closed before any alteration.
   */
  public function testUnexpectedKeyFailsClosed(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(),
      key: 'PRI',
    );

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Unexpected definition for search_api_db_service_requests.body: type=varchar(30), null=YES, default=NULL, key=PRI');
    _markaspot_ensure_search_api_body_utf8mb4($database);
  }

  /**
   * Confirms missing column metadata is reported.
   */
  public function testMissingColumnMetadataIsReported(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(),
      metadataAvailable: FALSE,
    );

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Could not inspect search_api_db_service_requests.body.');
    _markaspot_ensure_search_api_body_utf8mb4($database);
  }

  /**
   * Confirms an unsuccessful conversion is detected.
   */
  public function testFailedConversionIsDetected(): void {
    $database = new SearchApiUtf8ConnectionDouble(
      new SearchApiUtf8SchemaDouble(),
      applyAlter: FALSE,
    );

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Failed to convert search_api_db_service_requests.body to utf8mb4_bin.');
    _markaspot_ensure_search_api_body_utf8mb4($database);
  }

}
