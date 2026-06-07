<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_sso\Service\SsoProviderManager;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests SSO provider gating and mock enablement.
 *
 * @group markaspot_sso
 */
final class SsoProviderManagerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('MARKASPOT_SSO_MOCK');
    putenv('MARKASPOT_DEMO_MODE');
    putenv('MARKASPOT_ENV');
    putenv('IS_DDEV_PROJECT');
    parent::tearDown();
  }

  /**
   * Admin-saved checkbox values such as 1 still count as enabled.
   */
  public function testEnabledProviderAcceptsTruthyConfigValue(): void {
    $manager = $this->manager([
      'keycloak' => [
        'enabled' => 1,
        'profile' => 'generic',
      ],
    ]);

    $this->assertSame('generic', $manager->enabledProvider('keycloak')['profile']);
  }

  /**
   * Disabled providers deny login starts.
   */
  public function testDisabledProviderDeniesAccess(): void {
    $manager = $this->manager([
      'keycloak' => [
        'enabled' => FALSE,
        'profile' => 'generic',
      ],
    ]);

    $this->expectException(AccessDeniedHttpException::class);
    $manager->enabledProvider('keycloak');
  }

  /**
   * Invalid provider ids never reach config lookup.
   */
  public function testInvalidProviderIdThrowsNotFound(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->manager([])->provider('../keycloak');
  }

  /**
   * Frontend listings expose only enabled id and label per jurisdiction.
   */
  public function testProvidersForJurisdictionExposeSafeSubset(): void {
    $manager = $this->manager([
      'keycloak' => [
        'enabled' => TRUE,
        'label' => 'Stadt-Login',
        'jurisdiction_id' => 1,
        'idp_x509_cert' => 'secret-cert',
      ],
      'other' => [
        'enabled' => TRUE,
        'label' => 'Other Login',
        'jurisdiction_id' => 2,
      ],
      'disabled' => [
        'enabled' => FALSE,
        'label' => 'Hidden Login',
        'jurisdiction_id' => 1,
      ],
    ]);

    $this->assertSame([
      [
        'id' => 'keycloak',
        'label' => 'Stadt-Login',
      ],
    ], $manager->providersForJurisdiction(1));
  }

  /**
   * Mock providers are hidden from frontend listings when the gate is closed.
   */
  public function testProvidersForJurisdictionHidesDisallowedMocks(): void {
    $manager = $this->manager([
      'keycloak' => [
        'enabled' => TRUE,
        'label' => 'Stadt-Login',
        'profile' => 'generic',
        'jurisdiction_id' => 1,
      ],
      'local_mock' => [
        'enabled' => TRUE,
        'label' => 'SSO Demo',
        'profile' => 'generic_mock',
        'mock' => TRUE,
        'jurisdiction_id' => 1,
      ],
    ]);

    $this->assertSame([
      [
        'id' => 'keycloak',
        'label' => 'Stadt-Login',
      ],
    ], $manager->providersForJurisdiction(1));
  }

  /**
   * Mock providers fail closed without an explicit SSO mock flag.
   */
  public function testMockLoginRequiresExplicitMockFlag(): void {
    putenv('MARKASPOT_DEMO_MODE=true');
    putenv('MARKASPOT_ENV=dev');

    $this->assertFalse($this->manager([])->mockAllowed());
  }

  /**
   * Production disables mock login without explicit public demo mode.
   */
  public function testMockLoginDeniedInProduction(): void {
    putenv('MARKASPOT_SSO_MOCK=true');
    putenv('MARKASPOT_ENV=production');

    $this->assertFalse($this->manager([])->mockAllowed());
  }

  /**
   * Public demo mode is not enough to run the mock provider.
   */
  public function testMockLoginDeniedForExplicitPublicDemo(): void {
    putenv('MARKASPOT_SSO_MOCK=true');
    putenv('MARKASPOT_DEMO_MODE=true');
    putenv('MARKASPOT_ENV=production');

    $this->assertFalse($this->manager([])->mockAllowed());
  }

  /**
   * Dev environments can opt into the mock explicitly.
   */
  public function testMockLoginAllowedForExplicitDevOptIn(): void {
    putenv('MARKASPOT_SSO_MOCK=true');
    putenv('MARKASPOT_ENV=dev');

    $this->assertTrue($this->manager([])->mockAllowed());
  }

  /**
   * DDEV can opt into the mock explicitly.
   */
  public function testMockLoginAllowedForExplicitDdevOptIn(): void {
    putenv('MARKASPOT_SSO_MOCK=true');
    putenv('IS_DDEV_PROJECT=true');

    $this->assertTrue($this->manager([])->mockAllowed());
  }

  /**
   * AssertMockAllowed throws for mock providers when the gate is closed.
   */
  public function testAssertMockAllowedDeniesClosedGate(): void {
    $manager = $this->manager([]);

    $this->expectException(AccessDeniedHttpException::class);
    $manager->assertMockAllowed([
      'profile' => 'generic_mock',
      'mock' => TRUE,
    ]);
  }

  /**
   * Builds a provider manager with static provider config.
   *
   * @param array<string, array<string, mixed>> $providers
   *   Providers keyed by machine name.
   */
  private function manager(array $providers): SsoProviderManager {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($providers): mixed {
        if ($key === 'providers') {
          return $providers;
        }
        if (!str_starts_with($key, 'providers.')) {
          return NULL;
        }
            $provider_id = substr($key, strlen('providers.'));
            return $providers[$provider_id] ?? NULL;
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('markaspot_sso.settings')
      ->willReturn($config);

    return new SsoProviderManager($factory);
  }

}
