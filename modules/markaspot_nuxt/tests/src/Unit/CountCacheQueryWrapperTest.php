<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_nuxt\JsonApi\CountCacheQueryWrapper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\markaspot_nuxt\JsonApi\CountCacheQueryWrapper
 * @group markaspot_nuxt
 */
final class CountCacheQueryWrapperTest extends UnitTestCase {

  /**
   * Builds an account mock.
   */
  private function buildAccount(int $uid = 1, array $roles = ['authenticated']): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);
    return $account;
  }

  /**
   * Builds the wrapper under test.
   */
  private function buildWrapper(
    QueryInterface $inner,
    CacheBackendInterface $cache,
    AccountInterface $account,
    string $resource_type = 'node--service_request',
    string $filter_hash = 'testhash',
    bool $skip_on_cache_miss = FALSE,
  ): CountCacheQueryWrapper {
    return new CountCacheQueryWrapper(
      $inner,
      $cache,
      $account,
      $resource_type,
      $filter_hash,
      $skip_on_cache_miss,
    );
  }

  /**
   * @covers ::execute
   * Cache miss: executes the inner query and stores the result.
   */
  public function testCacheMissExecutesInnerAndCaches(): void {
    $inner = $this->createMock(QueryInterface::class);
    $inner->expects($this->once())
      ->method('execute')
      ->willReturn(42);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())
      ->method('get')
      ->willReturn(FALSE);
    $cache->expects($this->once())
      ->method('set')
      ->with(
        $this->isType('string'),
        42,
        Cache::PERMANENT,
        ['node_list', 'group_relationship_list'],
      );

    $wrapper = $this->buildWrapper($inner, $cache, $this->buildAccount());
    $result = $wrapper->execute();

    $this->assertSame(42, $result);
  }

  /**
   * @covers ::execute
   * Cache hit: returns the cached value and does NOT call inner->execute().
   */
  public function testCacheHitSkipsInnerQuery(): void {
    $inner = $this->createMock(QueryInterface::class);
    $inner->expects($this->never())->method('execute');

    $cached_item = new \stdClass();
    $cached_item->data = 99;

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())
      ->method('get')
      ->willReturn($cached_item);
    $cache->expects($this->never())->method('set');

    $wrapper = $this->buildWrapper($inner, $cache, $this->buildAccount());
    $result = $wrapper->execute();

    $this->assertSame(99, $result);
  }

  /**
   * @covers ::execute
   * Skip-count cache miss: returns the sentinel without running the count.
   */
  public function testSkipCountCacheMissReturnsSentinelWithoutExecutingOrCaching(): void {
    $inner = $this->createMock(QueryInterface::class);
    $inner->expects($this->never())->method('execute');

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())
      ->method('get')
      ->willReturn(FALSE);
    $cache->expects($this->never())->method('set');

    $wrapper = $this->buildWrapper(
      $inner,
      $cache,
      $this->buildAccount(),
      skip_on_cache_miss: TRUE,
    );
    $result = $wrapper->execute();

    $this->assertSame(CountCacheQueryWrapper::SKIPPED_COUNT_SENTINEL, $result);
  }

  /**
   * @covers ::execute
   * Skip-count cache hit: returns the exact cached count.
   */
  public function testSkipCountCacheHitReturnsCachedCount(): void {
    $inner = $this->createMock(QueryInterface::class);
    $inner->expects($this->never())->method('execute');

    $cached_item = new \stdClass();
    $cached_item->data = 123;

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())
      ->method('get')
      ->willReturn($cached_item);
    $cache->expects($this->never())->method('set');

    $wrapper = $this->buildWrapper(
      $inner,
      $cache,
      $this->buildAccount(),
      skip_on_cache_miss: TRUE,
    );
    $result = $wrapper->execute();

    $this->assertSame(123, $result);
  }

  /**
   * CachedCountEntityResource cacheability guard for skipped count responses.
   */
  public function testSkippedCountResponseCacheabilityIsGuarded(): void {
    $source = file_get_contents(__DIR__ . '/../../../src/JsonApi/CachedCountEntityResource.php');

    $this->assertIsString($source);
    $this->assertStringContainsString("'url.query_args:skipCount'", $source);
    $this->assertStringContainsString("'url.query_args:skip-count'", $source);
    $this->assertStringContainsString('$response->getCacheableMetadata()->setCacheMaxAge(0);', $source);
  }

  /**
   * @covers ::execute
   * The return value from execute() matches the inner query result exactly.
   */
  public function testExecuteReturnValueMatchesInner(): void {
    $inner = $this->createMock(QueryInterface::class);
    $inner->method('execute')->willReturn(7);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->method('set');

    $wrapper = $this->buildWrapper($inner, $cache, $this->buildAccount());

    $this->assertSame(7, $wrapper->execute());
  }

  /**
   * @covers ::execute
   * Different filter params produce different cache IDs.
   */
  public function testDifferentFiltersProduceDifferentCacheIds(): void {
    $inner_a = $this->createMock(QueryInterface::class);
    $inner_a->method('execute')->willReturn(10);
    $inner_b = $this->createMock(QueryInterface::class);
    $inner_b->method('execute')->willReturn(20);

    $cache_ids = [];
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(function (string $cid, mixed $data) use (&$cache_ids): void {
        $cache_ids[] = $cid;
      });

    $account = $this->buildAccount();
    $wrapper_a = $this->buildWrapper($inner_a, $cache, $account, 'node--service_request', 'hash-open');
    $wrapper_b = $this->buildWrapper($inner_b, $cache, $account, 'node--service_request', 'hash-closed');

    $wrapper_a->execute();
    $wrapper_b->execute();

    $this->assertCount(2, $cache_ids);
    $this->assertNotSame($cache_ids[0], $cache_ids[1], 'Cache IDs must differ when filter params differ.');
  }

  /**
   * @covers ::execute
   * Different users produce different cache IDs.
   */
  public function testDifferentUsersProduceDifferentCacheIds(): void {
    $inner = $this->createMock(QueryInterface::class);
    $inner->method('execute')->willReturn(5);

    $cache_ids = [];
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(function (string $cid, mixed $data) use (&$cache_ids): void {
        $cache_ids[] = $cid;
      });

    $wrapper_user1 = $this->buildWrapper($inner, $cache, $this->buildAccount(1));
    $wrapper_user2 = $this->buildWrapper($inner, $cache, $this->buildAccount(2));

    $wrapper_user1->execute();
    $wrapper_user2->execute();

    $this->assertNotSame($cache_ids[0], $cache_ids[1], 'Cache IDs must differ across users.');
  }

  /**
   * @covers ::getEntityTypeId
   * Delegation: getEntityTypeId() passes through to the inner query.
   */
  public function testGetEntityTypeIdDelegates(): void {
    $inner = $this->createMock(QueryInterface::class);
    $inner->method('getEntityTypeId')->willReturn('node');

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $wrapper = $this->buildWrapper($inner, $cache, $this->buildAccount());
    $this->assertSame('node', $wrapper->getEntityTypeId());
  }

}
