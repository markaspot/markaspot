<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\ModuleSchemaDriftCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the module-schema-drift detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\ModuleSchemaDriftCheck
 */
class ModuleSchemaDriftCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'module_schema_drift',
    'label' => 'Module schema drift',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunPassesWhenAllSchemasArePresent(): void {
    [$factory, $kv] = $this->mockFactories(
      ['system' => 0, 'user' => 0, 'node' => 0],
      ['system' => 8901, 'user' => 11003, 'node' => 8901],
    );

    $plugin = new ModuleSchemaDriftCheck([], 'module_schema_drift', $this->definition, $factory, $kv);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('3 module(s) tracked', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenSchemaMissingOrZero(): void {
    [$factory, $kv] = $this->mockFactories(
      ['system' => 0, 'mystery_module' => 0, 'broken_module' => 0],
      ['system' => 8901, 'broken_module' => 0],
    );

    $plugin = new ModuleSchemaDriftCheck([], 'module_schema_drift', $this->definition, $factory, $kv);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(2, $result->count);
    $this->assertStringContainsString('broken_module', $result->message);
    $this->assertStringContainsString('mystery_module', $result->message);
  }

  /**
   * Helper to mock both ConfigFactory + KeyValueFactory in one go.
   */
  private function mockFactories(array $modules, array $schemas): array {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('module')->willReturn($modules);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('core.extension')->willReturn($config);

    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->method('getMultiple')->willReturn($schemas);
    $kv = $this->createMock(KeyValueFactoryInterface::class);
    $kv->method('get')->with('system.schema')->willReturn($store);

    return [$factory, $kv];
  }

}
