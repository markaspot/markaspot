<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\SmokeCheckInterface;
use Drupal\markaspot_health\SmokeCheckPluginManager;
use Drupal\markaspot_health\SmokeCheckResult;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the SmokeCheckPluginManager.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\SmokeCheckPluginManager
 */
class SmokeCheckPluginManagerTest extends UnitTestCase {

  /**
   * @covers ::__construct
   */
  public function testConstruction(): void {
    $namespaces = new \ArrayObject([
      'Drupal\\markaspot_health' => __DIR__ . '/../../../src',
    ]);
    $cache = $this->createMock(CacheBackendInterface::class);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);

    $manager = new SmokeCheckPluginManager($namespaces, $cache, $moduleHandler);
    $this->assertInstanceOf(SmokeCheckPluginManager::class, $manager);
  }

  /**
   * @covers ::runAll
   */
  public function testRunAllCollectsResults(): void {
    $manager = $this->buildManager([
      'a' => ['id' => 'a', 'label' => 'A', 'severity' => 'info', 'category' => 'http_sanity'],
      'b' => ['id' => 'b', 'label' => 'B', 'severity' => 'error', 'category' => 'drupal_internal'],
    ]);

    $pluginA = $this->createMock(SmokeCheckInterface::class);
    $pluginA->method('run')->willReturn($this->resultPass('a', 'A'));
    $pluginB = $this->createMock(SmokeCheckInterface::class);
    $pluginB->method('run')->willReturn($this->resultFail('b', 'B'));

    $manager->method('createInstance')->willReturnMap([
      ['a', [], $pluginA],
      ['b', [], $pluginB],
    ]);

    $results = $manager->runAll();
    $this->assertCount(2, $results);
    $this->assertSame('a', $results[0]->pluginId);
    $this->assertSame('b', $results[1]->pluginId);
    $this->assertNotNull($results[0]->lastRunDurationMs);
    $this->assertNotNull($results[1]->lastRunDurationMs);
  }

  /**
   * Tests that mutating plugins are skipped without instantiation in read-only.
   *
   * Without this guard, side-effectful constructors of mutating plugins would
   * still run on prod-safe report invocations.
   *
   * @covers ::runAll
   */
  public function testMutatingPluginIsSkippedInReadOnly(): void {
    $manager = $this->buildManager([
      'mutator' => [
        'id' => 'mutator',
        'label' => 'Mutator',
        'severity' => 'error',
        'category' => 'auth',
        'mutates' => TRUE,
      ],
    ]);

    // createInstance must NOT be called for a mutating plugin in read-only.
    $manager->expects($this->never())->method('createInstance');

    $results = $manager->runAll(['mode' => SmokeCheckResult::MODE_READ_ONLY]);
    $this->assertCount(1, $results);
    $this->assertSame(SmokeCheckResult::STATUS_SKIP, $results[0]->status);
    $this->assertStringContainsString('read-only', $results[0]->message);
  }

  /**
   * @covers ::runAll
   */
  public function testMutatingPluginRunsInFullMode(): void {
    $manager = $this->buildManager([
      'mutator' => [
        'id' => 'mutator',
        'label' => 'Mutator',
        'severity' => 'error',
        'category' => 'auth',
        'mutates' => TRUE,
      ],
    ]);

    $plugin = $this->createMock(SmokeCheckInterface::class);
    $plugin->expects($this->once())->method('run')
      ->willReturn($this->resultPass('mutator', 'Mutator'));
    $manager->method('createInstance')->willReturn($plugin);

    $results = $manager->runAll(['mode' => SmokeCheckResult::MODE_FULL]);
    $this->assertCount(1, $results);
    $this->assertSame(SmokeCheckResult::STATUS_PASS, $results[0]->status);
  }

  /**
   * @covers ::runAll
   */
  public function testCategoryFilter(): void {
    $manager = $this->buildManager([
      'a' => ['id' => 'a', 'label' => 'A', 'severity' => 'info', 'category' => 'http_sanity'],
      'b' => ['id' => 'b', 'label' => 'B', 'severity' => 'info', 'category' => 'drupal_internal'],
    ]);

    $pluginA = $this->createMock(SmokeCheckInterface::class);
    $pluginA->method('run')->willReturn($this->resultPass('a', 'A'));
    $manager->method('createInstance')->willReturn($pluginA);

    $results = $manager->runAll(['category' => 'http_sanity']);
    $this->assertCount(1, $results);
    $this->assertSame('a', $results[0]->pluginId);
  }

  /**
   * @covers ::runAll
   */
  public function testFailedInstanceProducesSyntheticFailResult(): void {
    $manager = $this->buildManager([
      'broken' => ['id' => 'broken', 'label' => 'Broken', 'severity' => 'error', 'category' => 'drupal_internal'],
    ]);

    $manager->method('createInstance')->willThrowException(new \RuntimeException('boom'));

    $results = $manager->runAll();
    $this->assertCount(1, $results);
    $this->assertSame(SmokeCheckResult::STATUS_FAIL, $results[0]->status);
    $this->assertStringContainsString('boom', $results[0]->message);
    $this->assertSame('error', $results[0]->severity);
  }

  /**
   * Builds a partially-mocked manager that bypasses parent construction.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   Plugin definitions returned by getDefinitions().
   *
   * @return SmokeCheckPluginManager&\PHPUnit\Framework\MockObject\MockObject
   *   Manager mock.
   */
  private function buildManager(array $definitions): SmokeCheckPluginManager {
    $manager = $this->getMockBuilder(SmokeCheckPluginManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getDefinitions', 'createInstance'])
      ->getMock();

    $manager->method('getDefinitions')->willReturn($definitions);
    return $manager;
  }

  /**
   * Helper: build a passing SmokeCheckResult fixture.
   */
  private function resultPass(string $id, string $label): SmokeCheckResult {
    return new SmokeCheckResult(
      $id,
      $label,
      SmokeCheckResult::STATUS_PASS,
      'info',
      'http_sanity',
      FALSE,
      SmokeCheckResult::MODE_READ_ONLY,
      0,
      'ok',
    );
  }

  /**
   * Helper: build a failing SmokeCheckResult fixture.
   */
  private function resultFail(string $id, string $label): SmokeCheckResult {
    return new SmokeCheckResult(
      $id,
      $label,
      SmokeCheckResult::STATUS_FAIL,
      'error',
      'drupal_internal',
      FALSE,
      SmokeCheckResult::MODE_READ_ONLY,
      1,
      'fail',
    );
  }

}
