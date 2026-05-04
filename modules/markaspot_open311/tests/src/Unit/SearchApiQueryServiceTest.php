<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_open311\Service\SearchApiQueryService;
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

}
