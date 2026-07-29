<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_open311\Service\SearchApiQueryService;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Query\QueryInterface as SearchApiQueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the SearchApiQueryService.
 *
 * Covers isAvailable(), search() input validation and edge cases,
 * and getSearchableFields().
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Service\SearchApiQueryService
 */
class SearchApiQueryServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_open311\Service\SearchApiQueryService
   */
  protected $service;

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleHandler;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->service = new SearchApiQueryService(
      $this->entityTypeManager,
      $this->moduleHandler,
      $this->configFactory,
      $this->logger,
    );
  }

  /**
   * Tests isAvailable returns FALSE when search_api module is not installed.
   *
   * @covers ::isAvailable
   */
  public function testIsAvailableReturnsFalseWhenModuleNotInstalled(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('search_api')
      ->willReturn(FALSE);

    $this->assertFalse($this->service->isAvailable());
  }

  /**
   * Tests search returns empty array for query shorter than minimum length.
   *
   * @covers ::search
   */
  public function testSearchReturnEmptyForShortQuery(): void {
    $user = $this->createMock(AccountInterface::class);

    // Single character is below MIN_QUERY_LENGTH (2).
    $result = $this->service->search('a', $user);
    $this->assertSame([], $result);
  }

  /**
   * Tests search returns empty array for whitespace-only query.
   *
   * @covers ::search
   */
  public function testSearchReturnEmptyForWhitespaceQuery(): void {
    $user = $this->createMock(AccountInterface::class);

    $result = $this->service->search('  ', $user);
    $this->assertSame([], $result);
  }

  /**
   * Tests search returns empty when Search API is not available.
   *
   * @covers ::search
   */
  public function testSearchReturnsEmptyWhenNotAvailable(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('search_api')
      ->willReturn(FALSE);

    $user = $this->createMock(AccountInterface::class);

    $this->logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('not available'));

    $result = $this->service->search('test query', $user);
    $this->assertSame([], $result);
  }

  /**
   * Tests PII fields are excluded without any field view permission.
   *
   * @covers ::search
   */
  public function testSearchExcludesEmailWithoutFieldViewPermission(): void {
    $expected_fields = ['title', 'body', 'request_id'];
    $this->assertSearchUsesFulltextFields(FALSE, FALSE, $expected_fields);
  }

  /**
   * Tests email is included when the account can view the email field.
   *
   * @covers ::search
   */
  public function testSearchIncludesEmailWithFieldViewPermission(): void {
    $expected_fields = ['title', 'body', 'request_id', 'field_e_mail'];
    $this->assertSearchUsesFulltextFields(TRUE, FALSE, $expected_fields);
  }

  /**
   * Tests address fields are searchable with the address view permission.
   *
   * Staff address and postal code search must survive the PII whitelist.
   *
   * @covers ::search
   */
  public function testSearchIncludesAddressWithFieldViewPermission(): void {
    $expected_fields = ['title', 'body', 'request_id', 'address_line1', 'postal_code'];
    $this->assertSearchUsesFulltextFields(FALSE, TRUE, $expected_fields);
  }

  /**
   * Tests staff accounts search email and address fields together.
   *
   * @covers ::search
   */
  public function testSearchIncludesAllPiiFieldsForStaff(): void {
    $expected_fields = [
      'title',
      'body',
      'request_id',
      'field_e_mail',
      'address_line1',
      'postal_code',
    ];
    $this->assertSearchUsesFulltextFields(TRUE, TRUE, $expected_fields);
  }

  /**
   * Tests the shipped request ID field supports full-text queries.
   */
  public function testRequestIdIsFulltextInShippedIndex(): void {
    $path = dirname(__DIR__, 5)
      . '/config/optional/search_api.index.service_requests.yml';
    $contents = file_get_contents($path);
    $this->assertIsString($contents);
    $config = Yaml::decode($contents);

    $this->assertSame(
      'text',
      $config['field_settings']['request_id']['type'],
    );
  }

  /**
   * Tests getSearchableFields returns empty when index is not available.
   *
   * @covers ::getSearchableFields
   */
  public function testGetSearchableFieldsReturnsEmptyWhenNoIndex(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('search_api')
      ->willReturn(FALSE);

    $result = $this->service->getSearchableFields();
    $this->assertSame([], $result);
  }

  /**
   * Tests reindexNode does nothing when Search API is not available.
   *
   * @covers ::reindexNode
   */
  public function testReindexNodeDoesNothingWhenNotAvailable(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('search_api')
      ->willReturn(FALSE);

    // Should not throw any exception.
    $this->service->reindexNode(42);
    // If we reach here without exception, the test passes.
    $this->assertTrue(TRUE);
  }

  /**
   * Tests fallback search applies exact request ID lookup.
   *
   * @covers ::applySafeFallbackSearch
   */
  public function testApplySafeFallbackSearchExactRequestId(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('request_id', '123-2026', NULL)
      ->willReturnSelf();

    $this->assertTrue($this->service->applySafeFallbackSearch($query, '#123-2026'));
  }

  /**
   * Tests fallback search applies numeric request ID lookup as exact match.
   *
   * @covers ::applySafeFallbackSearch
   */
  public function testApplySafeFallbackSearchNumericRequestIdExact(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('request_id', '123', NULL)
      ->willReturnSelf();

    $this->assertTrue($this->service->applySafeFallbackSearch($query, '123'));
  }

  /**
   * Tests fallback search applies custom request ID formats as exact matches.
   *
   * @covers ::applySafeFallbackSearch
   */
  public function testApplySafeFallbackSearchCustomRequestIdExact(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('request_id', 'SR_2026:0001', NULL)
      ->willReturnSelf();

    $this->assertTrue($this->service->applySafeFallbackSearch($query, 'SR_2026:0001'));
  }

  /**
   * Tests fallback search refuses general full-text queries.
   *
   * @covers ::applySafeFallbackSearch
   */
  public function testApplySafeFallbackSearchRejectsGeneralText(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())
      ->method('condition');

    $this->assertFalse($this->service->applySafeFallbackSearch($query, 'graffiti wall'));
  }

  /**
   * Asserts the full-text fields selected for one account.
   *
   * @param bool $can_view_email
   *   Whether the account has the broad email field view permission.
   * @param bool $can_view_address
   *   Whether the account has the broad address field view permission.
   * @param string[] $expected_fields
   *   Expected Search API full-text field identifiers.
   */
  private function assertSearchUsesFulltextFields(
    bool $can_view_email,
    bool $can_view_address,
    array $expected_fields,
  ): void {
    $this->moduleHandler->method('moduleExists')
      ->with('search_api')
      ->willReturn(TRUE);

    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getResultItems')->willReturn([]);

    $query = $this->createMock(SearchApiQueryInterface::class);
    $query->expects($this->once())
      ->method('setFulltextFields')
      ->with($expected_fields)
      ->willReturnSelf();
    $query->method('keys')->willReturnSelf();
    $query->method('setOption')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($results);

    $index = $this->createMock(Index::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('query')->willReturn($query);

    $user = $this->createMock(AccountInterface::class);
    $user->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => match ($permission) {
        'view field_e_mail' => $can_view_email,
        'view field_address' => $can_view_address,
        default => FALSE,
      });
    $user->method('isAnonymous')->willReturn(FALSE);

    $service = new class(
      $this->entityTypeManager,
      $this->moduleHandler,
      $this->configFactory,
      $this->logger,
      $index,
    ) extends SearchApiQueryService {

      /**
       * Constructs a testable search service with a fixed index.
       */
      public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        ModuleHandlerInterface $module_handler,
        ConfigFactoryInterface $config_factory,
        LoggerInterface $logger,
        private readonly Index $index,
      ) {
        parent::__construct(
          $entity_type_manager,
          $module_handler,
          $config_factory,
          $logger,
        );
      }

      /**
       * {@inheritdoc}
       */
      protected function getIndex(): ?Index {
        return $this->index;
      }

    };

    $this->assertSame([], $service->search('reporter@example.test', $user));
  }

}
