<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_sso\Service\SsoRelayStateValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests RelayState sanitizing.
 *
 * @group markaspot_sso
 */
final class SsoRelayStateValidatorTest extends UnitTestCase {

  /**
   * Local relative paths are safe.
   */
  public function testAllowsLocalRelativePath(): void {
    $validator = $this->validator();

    $this->assertSame('/musterstadt/dashboard', $validator->sanitize('keycloak', '/musterstadt/dashboard'));
  }

  /**
   * Protocol-relative and control-character targets fall back.
   */
  public function testRejectsUnsafeRelativeTargets(): void {
    $validator = $this->validator();

    $this->assertSame('/fallback', $validator->sanitize('keycloak', '//evil.example/admin'));
    $this->assertSame('/fallback', $validator->sanitize('keycloak', "/safe\nLocation: https://evil.example"));
  }

  /**
   * Absolute URLs require an allowlisted host.
   */
  public function testRejectsUnlistedAbsoluteHost(): void {
    $validator = $this->validator();

    $this->assertSame('/fallback', $validator->sanitize('keycloak', 'https://evil.example/admin'));
  }

  /**
   * Configured host:port values are accepted.
   */
  public function testAllowsConfiguredHostWithPort(): void {
    $validator = $this->validator([
      'allowed_relay_hosts' => ['dev.ddev.site:3001'],
    ]);

    $this->assertSame(
          'https://dev.ddev.site:3001/musterstadt/dashboard',
          $validator->sanitize('keycloak', 'https://dev.ddev.site:3001/musterstadt/dashboard')
      );
  }

  /**
   * HTTP absolute URLs are rejected for non-local allowlisted hosts.
   */
  public function testRejectsHttpDowngradeForAllowedPublicHost(): void {
    $validator = $this->validator([
      'allowed_relay_hosts' => ['frontend.example.org'],
    ]);

    $this->assertSame(
          '/fallback',
          $validator->sanitize('keycloak', 'http://frontend.example.org/musterstadt/dashboard')
      );
  }

  /**
   * The current request host is accepted automatically.
   */
  public function testAllowsCurrentRequestHost(): void {
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('https://dev.ddev.site/current'));
    $validator = $this->validator([], $requestStack);

    $this->assertSame(
          'https://dev.ddev.site/dashboard',
          $validator->sanitize('keycloak', 'https://dev.ddev.site/dashboard')
      );
  }

  /**
   * Invalid defaults fall back to root.
   */
  public function testInvalidDefaultFallsBackToRoot(): void {
    $validator = $this->validator([
      'default_relay_path' => '//evil.example',
    ]);

    $this->assertSame('/', $validator->sanitize('keycloak', 'https://evil.example/admin'));
  }

  /**
   * Builds the validator with static provider config.
   *
   * @param array<string, mixed> $provider
   *   Provider config overrides.
   * @param \Symfony\Component\HttpFoundation\RequestStack|null $requestStack
   *   Request stack override.
   */
  private function validator(array $provider = [], ?RequestStack $requestStack = NULL): SsoRelayStateValidator {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('providers.keycloak')
      ->willReturn($provider + [
        'default_relay_path' => '/fallback',
        'allowed_relay_hosts' => [],
      ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('markaspot_sso.settings')
      ->willReturn($config);

    return new SsoRelayStateValidator($factory, $requestStack ?? new RequestStack());
  }

}
