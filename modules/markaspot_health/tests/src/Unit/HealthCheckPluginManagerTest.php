<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\HealthCheckInterface;
use Drupal\markaspot_health\HealthCheckPluginManager;
use Drupal\markaspot_health\HealthCheckResult;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the HealthCheckPluginManager.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\HealthCheckPluginManager
 */
class HealthCheckPluginManagerTest extends UnitTestCase {

  /**
   * Tests that the manager can be constructed.
   *
   * @covers ::__construct
   */
  public function testConstruction(): void {
    $namespaces = new \ArrayObject([
      'Drupal\\markaspot_health' => __DIR__ . '/../../../src',
    ]);
    $cache = $this->createMock(CacheBackendInterface::class);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);

    $manager = new HealthCheckPluginManager($namespaces, $cache, $moduleHandler);
    $this->assertInstanceOf(HealthCheckPluginManager::class, $manager);
  }

  /**
   * Tests runAll() iterates plugins and collects results.
   *
   * @covers ::runAll
   */
  public function testRunAllCollectsResults(): void {
    $manager = $this->getMockBuilder(HealthCheckPluginManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getDefinitions', 'createInstance'])
      ->getMock();

    $manager->method('getDefinitions')->willReturn([
      'a' => ['id' => 'a'],
      'b' => ['id' => 'b'],
    ]);

    $pluginA = $this->createMock(HealthCheckInterface::class);
    $resultA = new HealthCheckResult('a', 'A', TRUE, 'info', 0, 'ok');
    $pluginA->method('run')->willReturn($resultA);

    $pluginB = $this->createMock(HealthCheckInterface::class);
    $resultB = new HealthCheckResult('b', 'B', FALSE, 'error', 2, 'fail');
    $pluginB->method('run')->willReturn($resultB);

    $manager->method('createInstance')->willReturnMap([
      ['a', [], $pluginA],
      ['b', [], $pluginB],
    ]);

    $results = $manager->runAll();
    $this->assertCount(2, $results);
    // runAll() wraps each plugin's result with withDuration(), so the
    // returned objects are copies. Compare identity-bearing fields and
    // assert the duration was filled in.
    $this->assertSame($resultA->pluginId, $results[0]->pluginId);
    $this->assertSame($resultB->pluginId, $results[1]->pluginId);
    $this->assertNotNull($results[0]->lastRunDurationMs);
    $this->assertNotNull($results[1]->lastRunDurationMs);
  }

  /**
   * Tests runAll() forwards context to plugins.
   *
   * @covers ::runAll
   */
  public function testRunAllForwardsContext(): void {
    $manager = $this->getMockBuilder(HealthCheckPluginManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getDefinitions', 'createInstance'])
      ->getMock();

    $manager->method('getDefinitions')->willReturn(['a' => ['id' => 'a']]);

    $plugin = $this->createMock(HealthCheckInterface::class);
    $plugin->expects($this->once())
      ->method('run')
      ->with(['jurisdiction' => '5'])
      ->willReturn(new HealthCheckResult('a', 'A', TRUE, 'info', 0, 'ok'));

    $manager->method('createInstance')->willReturn($plugin);

    $manager->runAll(['jurisdiction' => '5']);
  }

}
