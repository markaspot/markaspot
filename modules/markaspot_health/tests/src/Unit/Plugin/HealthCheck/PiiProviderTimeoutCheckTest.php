<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\PiiProviderTimeoutCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pii-provider-timeout detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\PiiProviderTimeoutCheck
 */
class PiiProviderTimeoutCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'pii_provider_timeout',
    'label' => 'PII provider configured for timeout',
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
    $modules->method('moduleExists')->with('markaspot_pii')->willReturn(FALSE);

    $plugin = new PiiProviderTimeoutCheck([], 'pii_provider_timeout', $this->definition, $factory, $modules);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('not enabled', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsOnLocalNlpProvider(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('isNew')->willReturn(FALSE);
    $config->method('get')->with('provider')->willReturn('local_nlp');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = new PiiProviderTimeoutCheck([], 'pii_provider_timeout', $this->definition, $factory, $modules);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertStringContainsString('local_nlp', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesOnAzureProvider(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('isNew')->willReturn(FALSE);
    $config->method('get')->with('provider')->willReturn('azure');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = new PiiProviderTimeoutCheck([], 'pii_provider_timeout', $this->definition, $factory, $modules);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('azure', $result->message);
  }

}
