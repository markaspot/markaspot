<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\VisionEnvDriftCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the vision-env-drift detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\VisionEnvDriftCheck
 */
class VisionEnvDriftCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'vision_env_drift',
    'label' => 'Vision ENV overrides missing',
    'severity' => 'warning',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenModuleDisabled(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('markaspot_vision')->willReturn(FALSE);

    $plugin = new VisionEnvDriftCheck([], 'vision_env_drift', $this->definition, $factory, $modules);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenApiKeyEmpty(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('isNew')->willReturn(FALSE);
    $config->method('get')->with('api_key')->willReturn('');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = new VisionEnvDriftCheck([], 'vision_env_drift', $this->definition, $factory, $modules);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertStringContainsString('ENV override is not active', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenApiKeyResolved(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('isNew')->willReturn(FALSE);
    $config->method('get')->with('api_key')->willReturn('sk-fake-runtime-secret');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = new VisionEnvDriftCheck([], 'vision_env_drift', $this->definition, $factory, $modules);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

}
