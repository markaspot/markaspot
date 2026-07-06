<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the GeoReport requests index id parameter filter.
 *
 * @group markaspot_open311
 *
 * @coversDefaultClass \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource
 */
class GeoreportRequestIndexResourceRequestIdFilterTest extends UnitTestCase {

  /**
   * Single id lookup keeps the existing equality condition.
   *
   * @covers ::applyRequestIdFilter
   */
  public function testSingleIdUsesEqualityCondition(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('request_id', '17')
      ->willReturnSelf();

    GeoreportRequestIndexResource::applyRequestIdFilter($query, '17');
  }

  /**
   * Multiple ids use one IN condition after trimming tokens.
   *
   * @covers ::applyRequestIdFilter
   */
  public function testMultipleIdsUseInConditionWithTrimmedTokens(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('request_id', ['17', '23', '42'], 'IN')
      ->willReturnSelf();

    GeoreportRequestIndexResource::applyRequestIdFilter($query, '17, 23 ,42');
  }

  /**
   * Empty tokens are dropped before choosing the condition shape.
   *
   * @covers ::applyRequestIdFilter
   */
  public function testEmptyTokensAreDropped(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('request_id', ['17', '23'], 'IN')
      ->willReturnSelf();

    GeoreportRequestIndexResource::applyRequestIdFilter($query, ',17,, 23, ,');
  }

  /**
   * All-empty input keeps today's empty-id equality lookup behavior.
   *
   * @covers ::applyRequestIdFilter
   */
  public function testAllEmptyTokensUseEmptyStringEqualityCondition(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('condition')
      ->with('request_id', '')
      ->willReturnSelf();

    GeoreportRequestIndexResource::applyRequestIdFilter($query, ',,  ,');
  }

  /**
   * Oversized id lists are rejected with the standard GeoReport 400.
   *
   * @covers ::applyRequestIdFilter
   */
  public function testMoreThanOneHundredIdsThrowsGeoreportException(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())
      ->method('condition');

    $ids = array_map('strval', range(1, 101));

    $this->expectException(GeoreportException::class);
    $this->expectExceptionCode(400);
    $this->expectExceptionMessage('Too many ids in request (max 100).');

    GeoreportRequestIndexResource::applyRequestIdFilter($query, implode(',', $ids));
  }

}
