<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\markaspot_health\Plugin\HealthCheck\PasswordlessFeatureMissingCheck;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the central passwordless configuration check.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\PasswordlessFeatureMissingCheck
 */
class PasswordlessFeatureMissingCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'passwordless_feature_missing',
    'label' => 'Passwordless platform configuration',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  #[DataProvider('validConfigurationProvider')]
  public function testValidPlatformModesPass(mixed $features, bool $effective): void {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->expects($this->once())->method('isPlatformFeatureEnabled')->with('passwordless')->willReturn($effective);
    $plugin = new PasswordlessFeatureMissingCheck(
      [], 'passwordless_feature_missing', $this->definition,
      $this->getConfigFactoryStub(['markaspot_nuxt.settings' => ['platform_features' => $features]]),
      $resolver,
    );
    $result = $plugin->run(['jurisdiction' => 1]);
    $this->assertTrue($result->passed);
    $this->assertStringContainsString($effective ? 'enabled' : 'disabled', $result->message);
  }

  /**
   * Covers explicit modes and automatic defaults without tenant JSON.
   */
  public static function validConfigurationProvider(): array {
    return [
      'enabled' => [['passwordless' => TRUE], TRUE],
      'disabled' => [['passwordless' => FALSE], FALSE],
      'automatic enabled' => [['passwordless' => NULL], TRUE],
      'automatic disabled' => [['passwordless' => NULL], FALSE],
      'missing flag' => [[], FALSE],
      'missing platform map' => [NULL, FALSE],
    ];
  }

  /**
   * @covers ::run
   */
  #[DataProvider('invalidConfigurationProvider')]
  public function testMalformedPlatformConfigurationFails(mixed $features): void {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->expects($this->never())->method('isPlatformFeatureEnabled');
    $plugin = new PasswordlessFeatureMissingCheck(
      [], 'passwordless_feature_missing', $this->definition,
      $this->getConfigFactoryStub(['markaspot_nuxt.settings' => ['platform_features' => $features]]),
      $resolver,
    );
    $result = $plugin->run();
    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
  }

  /**
   * Provides malformed values rather than legitimate disabled modes.
   */
  public static function invalidConfigurationProvider(): array {
    return [
      'string flag' => [['passwordless' => 'false']],
      'nested flag' => [['passwordless' => ['enabled' => TRUE]]],
      'numeric flag' => [['passwordless' => 1]],
      'malformed map' => ['false'],
      'list instead of mapping' => [[TRUE]],
    ];
  }

  /**
   * @covers ::run
   */
  public function testRunSkipsWithoutNuxtResolver(): void {
    $plugin = new PasswordlessFeatureMissingCheck(
      [], 'passwordless_feature_missing', $this->definition,
      $this->getConfigFactoryStub(),
    );
    $this->assertTrue($plugin->run()->passed);
  }

}
