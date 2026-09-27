<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource;
use Drupal\markaspot_open311\Service\SearchApiQueryService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests the full-text search part of the GeoReport request index.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource
 */
class GeoreportRequestIndexSearchTest extends UnitTestCase {

  /**
   * Tests a missing q means no search.
   *
   * @covers ::normaliseSearchParameter
   */
  public function testMissingSearchParameterIsEmpty(): void {
    $this->assertSame('', GeoreportRequestIndexResource::normaliseSearchParameter(NULL));
  }

  /**
   * Tests q[]= is a client error, not a TypeError.
   *
   * @covers ::normaliseSearchParameter
   */
  public function testArraySearchParameterIsRejected(): void {
    try {
      GeoreportRequestIndexResource::normaliseSearchParameter(['Schlagloch']);
      $this->fail('An array q must be rejected.');
    }
    catch (GeoreportException $e) {
      $this->assertSame(400, $e->getCode());
    }
  }

  /**
   * Tests invalid UTF-8 is a client error, not an unfiltered list.
   *
   * @covers ::normaliseSearchParameter
   */
  public function testInvalidUtf8SearchParameterIsRejected(): void {
    try {
      GeoreportRequestIndexResource::normaliseSearchParameter("\xff\xfeabc");
      $this->fail('Invalid UTF-8 must be rejected.');
    }
    catch (GeoreportException $e) {
      $this->assertSame(400, $e->getCode());
    }
  }

  /**
   * Tests q is collapsed and capped like the UI proxy does.
   *
   * @covers ::normaliseSearchParameter
   */
  public function testSearchParameterIsNormalized(): void {
    $this->assertSame('Müll am Rhein', GeoreportRequestIndexResource::normaliseSearchParameter("  Müll \t am\nRhein  "));
    $this->assertSame(
      SearchApiQueryService::MAX_QUERY_LENGTH,
      mb_strlen(GeoreportRequestIndexResource::normaliseSearchParameter(str_repeat('ä', 150))),
    );
  }

  /**
   * Tests an exact request ID returns that request only.
   *
   * @covers ::applySearchQuery
   */
  public function testVisibleExactRequestIdSkipsFullText(): void {
    $service = $this->createSearchService();
    $service->expects($this->never())->method('search');

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('request_id', '78-2026')
      ->willReturnSelf();

    $resource = $this->createResource($service, 1);
    $this->applySearch($resource, $query, '#78-2026');
  }

  /**
   * Tests an ID the caller cannot list falls back to the full-text search.
   *
   * @covers ::applySearchQuery
   */
  public function testInvisibleRequestIdUsesFullText(): void {
    $service = $this->createSearchService();
    $service->method('isAvailable')->willReturn(TRUE);
    $service->expects($this->once())
      ->method('search')
      ->with('78-2026')
      ->willReturn([178]);

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('nid', [178], 'IN')
      ->willReturnSelf();

    $resource = $this->createResource($service, 0);
    $this->applySearch($resource, $query, '78-2026');
  }

  /**
   * Tests input without three letters or digits in a row matches nothing.
   *
   * @covers ::applySearchQuery
   * @dataProvider providerUnsearchableInput
   */
  public function testUnsearchableInputMatchesNothing(string $input): void {
    $service = $this->createSearchService();
    $service->expects($this->never())->method('search');
    $service->expects($this->never())->method('applySafeFallbackSearch');

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('nid', [0], 'IN')
      ->willReturnSelf();

    $resource = $this->createResource($service, 0);
    $this->applySearch($resource, $query, $input);
  }

  /**
   * Data provider for unsearchable input.
   *
   * @return array<string, array{string}>
   *   Inputs the database backend cannot match.
   */
  public static function providerUnsearchableInput(): array {
    return [
      'one letter' => ['a'],
      'two letters' => ['ab'],
      'wildcard' => ['%'],
      'underscore' => ['_ _'],
      'split letters' => ['a b c d'],
    ];
  }

  /**
   * Tests an empty Search API result keeps the list empty.
   *
   * @covers ::applySearchQuery
   */
  public function testNoSearchHitsMatchNothing(): void {
    $service = $this->createSearchService();
    $service->method('isAvailable')->willReturn(TRUE);
    $service->method('search')->willReturn([]);
    $service->method('didLastSearchFail')->willReturn(FALSE);

    // Same response shape as hits the caller cannot list: no bare [].
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('nid', [0], 'IN')
      ->willReturnSelf();

    $resource = $this->createResource($service, 0);
    $this->applySearch($resource, $query, 'Schlagloch');
  }

  /**
   * Tests a failed search never widens to an unfiltered list.
   *
   * @covers ::applySearchQuery
   */
  public function testFailedSearchWithoutRequestIdMatchesNothing(): void {
    $service = $this->createSearchService();
    $service->method('isAvailable')->willReturn(TRUE);
    $service->method('search')->willReturn([]);
    $service->method('didLastSearchFail')->willReturn(TRUE);
    $service->method('applySafeFallbackSearch')->willReturn(FALSE);

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('nid', [0], 'IN')
      ->willReturnSelf();

    $resource = $this->createResource($service, 0);
    $this->applySearch($resource, $query, 'Schlagloch');
  }

  /**
   * Creates a search service with the real input rules and mocked backend.
   *
   * @return \Drupal\markaspot_open311\Service\SearchApiQueryService&\PHPUnit\Framework\MockObject\MockObject
   *   The search service.
   */
  private function createSearchService(): SearchApiQueryService {
    return $this->getMockBuilder(SearchApiQueryService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['isAvailable', 'search', 'didLastSearchFail', 'applySafeFallbackSearch', 'countVisibleRequestId'])
      ->getMock();
  }

  /**
   * Creates the resource with a fixed number of visible exact ID matches.
   */
  private function createResource(SearchApiQueryService $service, int $visibleRequestIds): GeoreportRequestIndexResource {
    $service->method('countVisibleRequestId')->willReturn($visibleRequestIds);
    $reflection = new \ReflectionClass(GeoreportRequestIndexResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $this->setProperty($resource, 'searchApiQueryService', $service);
    $this->setProperty($resource, 'currentUser', $this->createMock(AccountProxyInterface::class));
    $this->setProperty($resource, 'logger', new NullLogger());
    return $resource;
  }

  /**
   * Invokes the protected search method.
   */
  private function applySearch(GeoreportRequestIndexResource $resource, QueryInterface $query, string $input): void {
    $method = new \ReflectionMethod($resource, 'applySearchQuery');
    $method->invoke($resource, $query, GeoreportRequestIndexResource::normaliseSearchParameter($input), 'de');
  }

  /**
   * Sets a protected resource property for focused unit testing.
   */
  private function setProperty(GeoreportRequestIndexResource $resource, string $name, object $value): void {
    $property = new \ReflectionProperty(GeoreportRequestIndexResource::class, $name);
    $property->setValue($resource, $value);
  }

}
