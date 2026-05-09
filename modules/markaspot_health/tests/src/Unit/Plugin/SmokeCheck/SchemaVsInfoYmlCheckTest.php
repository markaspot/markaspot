<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\SmokeCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Update\UpdateHookRegistry;
use Drupal\markaspot_health\Plugin\SmokeCheck\SchemaVsInfoYmlCheck;
use Drupal\markaspot_health\SmokeCheckResult;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the schema-vs-info.yml smoke check.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\SmokeCheck\SchemaVsInfoYmlCheck
 */
class SchemaVsInfoYmlCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'schema_vs_info_yml',
    'label' => 'Module schema vs .install file',
    'severity' => 'error',
    'category' => 'drupal_internal',
    'mutates' => FALSE,
    'fix_hint' => 'Run drush updatedb -y.',
  ];

  /**
   * @covers ::run
   */
  public function testPassesWhenInstalledMatchesAvailable(): void {
    $registry = $this->createMock(UpdateHookRegistry::class);
    $registry->method('getInstalledVersion')->willReturnMap([
      ['system', 9100],
      ['user', 9100],
    ]);
    $registry->method('getAvailableUpdates')->willReturnMap([
      ['system', [9100]],
      ['user', [9100]],
    ]);

    $plugin = new SchemaVsInfoYmlCheck(
      [],
      'schema_vs_info_yml',
      $this->definition,
      $this->mockConfigFactory(['system' => 0, 'user' => 0]),
      $registry,
      $this->createMock(ModuleHandlerInterface::class),
    );
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_PASS, $result->status);
    $this->assertStringContainsString('2 module(s)', $result->message);
  }

  /**
   * Catches the schema-0 trap (installed=0, available>0).
   *
   * @covers ::run
   */
  public function testFailsWhenInstalledIsZero(): void {
    $registry = $this->createMock(UpdateHookRegistry::class);
    $registry->method('getInstalledVersion')->willReturnMap([
      ['markaspot_passwordless', 0],
      ['system', 9100],
    ]);
    $registry->method('getAvailableUpdates')->willReturnMap([
      ['markaspot_passwordless', [9001]],
      ['system', [9100]],
    ]);

    $plugin = new SchemaVsInfoYmlCheck(
      [],
      'schema_vs_info_yml',
      $this->definition,
      $this->mockConfigFactory(['markaspot_passwordless' => 0, 'system' => 0]),
      $registry,
      $this->createMock(ModuleHandlerInterface::class),
    );
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_FAIL, $result->status);
    $this->assertSame(1, $result->count);
    $this->assertSame('markaspot_passwordless', $result->evidence['offenders'][0]['module']);
    $this->assertSame(0, $result->evidence['offenders'][0]['installed']);
    $this->assertSame(9001, $result->evidence['offenders'][0]['expected']);
  }

  /**
   * Catches the partial-update drift case (installed=8, available=10).
   *
   * @covers ::run
   */
  public function testFailsWhenInstalledLagsBehindAvailable(): void {
    $registry = $this->createMock(UpdateHookRegistry::class);
    $registry->method('getInstalledVersion')->willReturnMap([
      ['markaspot_open311', 8],
    ]);
    $registry->method('getAvailableUpdates')->willReturnMap([
      ['markaspot_open311', [1, 2, 3, 8, 9, 10]],
    ]);

    $plugin = new SchemaVsInfoYmlCheck(
      [],
      'schema_vs_info_yml',
      $this->definition,
      $this->mockConfigFactory(['markaspot_open311' => 0]),
      $registry,
      $this->createMock(ModuleHandlerInterface::class),
    );
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_FAIL, $result->status);
    $this->assertSame(8, $result->evidence['offenders'][0]['installed']);
    $this->assertSame(10, $result->evidence['offenders'][0]['expected']);
  }

  /**
   * Modules with no hook_update_N at all (0/0) must not be flagged.
   *
   * @covers ::run
   */
  public function testIgnoresZeroOverZeroModules(): void {
    $registry = $this->createMock(UpdateHookRegistry::class);
    $registry->method('getInstalledVersion')->willReturnMap([
      ['fresh_module', 0],
    ]);
    $registry->method('getAvailableUpdates')->willReturnMap([
      ['fresh_module', []],
    ]);

    $plugin = new SchemaVsInfoYmlCheck(
      [],
      'schema_vs_info_yml',
      $this->definition,
      $this->mockConfigFactory(['fresh_module' => 0]),
      $registry,
      $this->createMock(ModuleHandlerInterface::class),
    );
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_PASS, $result->status);
  }

  /**
   * Helper: mock the config factory chain returning the provided modules.
   */
  private function mockConfigFactory(array $modules): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('module')->willReturn($modules);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('core.extension')->willReturn($config);
    return $factory;
  }

}
