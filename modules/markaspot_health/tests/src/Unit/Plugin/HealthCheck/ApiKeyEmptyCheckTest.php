<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\markaspot_health\Plugin\HealthCheck\ApiKeyEmptyCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the api-key-empty detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\ApiKeyEmptyCheck
 */
class ApiKeyEmptyCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'api_key_empty',
    'label' => 'API keys empty at runtime',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenNoCredentialsConfigured(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('listAll')->with('services_api_key_auth.')->willReturn([]);

    $plugin = new ApiKeyEmptyCheck([], 'api_key_empty', $this->definition, $factory);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('not configured', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenKeyEmptyAtRuntime(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('listAll')->willReturn([
      'services_api_key_auth.settings',
      'services_api_key_auth.api_key_auth.georeport',
    ]);

    $emptyKey = $this->createMock(ImmutableConfig::class);
    $emptyKey->method('get')->with('key')->willReturn('');
    $factory->method('get')->willReturnCallback(static function (string $name) use ($emptyKey) {
      return $emptyKey;
    });

    $plugin = new ApiKeyEmptyCheck([], 'api_key_empty', $this->definition, $factory);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertStringContainsString('api_key_auth.georeport', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenAllKeysResolveToValues(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('listAll')->willReturn([
      'services_api_key_auth.api_key_auth.georeport',
      'services_api_key_auth.api_key_auth.dashboard',
    ]);
    $populated = $this->createMock(ImmutableConfig::class);
    $populated->method('get')->with('key')->willReturn('sk-runtime-value');
    $factory->method('get')->willReturn($populated);

    $plugin = new ApiKeyEmptyCheck([], 'api_key_empty', $this->definition, $factory);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

}
