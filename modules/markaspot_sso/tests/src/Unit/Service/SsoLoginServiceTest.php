<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_sso\Service\SsoClientFactory;
use Drupal\markaspot_sso\Service\SsoGroupMembershipService;
use Drupal\markaspot_sso\Service\SsoIdentityLinker;
use Drupal\markaspot_sso\Service\SsoLoginService;
use Drupal\markaspot_sso\Service\SsoProviderManager;
use Drupal\markaspot_sso\Service\SsoReplayCache;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests SSO login coordination.
 *
 * @group markaspot_sso
 */
final class SsoLoginServiceTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('MARKASPOT_SSO_MOCK');
    putenv('IS_DDEV_PROJECT');
    parent::tearDown();
  }

  /**
   * ACS processing rejects SP-initiated responses when the request id is gone.
   */
  public function testAcsRequiresStoredRequestId(): void {
    $service = $this->loginService();
    $request = Request::create('/auth/sso/keycloak/acs', 'POST', [
      'SAMLResponse' => 'dummy-response',
      'RelayState' => '/dashboard',
    ]);
    $session = new Session(new MockArraySessionStorage());

    $this->expectException(AccessDeniedHttpException::class);
    $service->processAcs('keycloak', $request, $session);
  }

  /**
   * Failed ACS attempts consume the stored request id.
   */
  public function testFailedAcsConsumesStoredRequestId(): void {
    $service = $this->loginService();
    $request = Request::create('/auth/sso/keycloak/acs', 'POST', [
      'SAMLResponse' => 'not-valid-saml',
      'RelayState' => '/dashboard',
    ]);
    $session = new Session(new MockArraySessionStorage());
    $session->set('markaspot_sso.keycloak.request_id', 'request-123');

    $exception = NULL;
    try {
      $service->processAcs('keycloak', $request, $session);
    }
    catch (\Throwable $caught) {
      $exception = $caught;
    }

    $this->assertInstanceOf(\Throwable::class, $exception);
    $this->assertFalse($session->has('markaspot_sso.keycloak.request_id'));
  }

  /**
   * Malformed ACS posts also consume the stored request id.
   */
  public function testMalformedAcsConsumesStoredRequestId(): void {
    $service = $this->loginService();
    $request = Request::create('/auth/sso/keycloak/acs', 'POST', [
      'RelayState' => '/dashboard',
    ]);
    $session = new Session(new MockArraySessionStorage());
    $session->set('markaspot_sso.keycloak.request_id', 'request-123');

    $exception = NULL;
    try {
      $service->processAcs('keycloak', $request, $session);
    }
    catch (\Throwable $caught) {
      $exception = $caught;
    }

    $this->assertInstanceOf(\Throwable::class, $exception);
    $this->assertFalse($session->has('markaspot_sso.keycloak.request_id'));
  }

  /**
   * Dev mock login starts at a visible mock identity provider page.
   */
  public function testMockLoginStartsAtVisibleMockIdp(): void {
    putenv('MARKASPOT_SSO_MOCK=true');
    putenv('IS_DDEV_PROJECT=true');

    $service = $this->loginService();
    $session = new Session(new MockArraySessionStorage());

    $this->assertSame(
          '/auth/sso/local_mock/mock-idp?RelayState=%2Fdashboard',
          $service->startLogin('local_mock', '/dashboard', $session),
      );
  }

  /**
   * ACS redirects use the RelayState captured at login start.
   */
  public function testRelayStateIsConsumedFromStartedLogin(): void {
    putenv('MARKASPOT_SSO_MOCK=true');
    putenv('IS_DDEV_PROJECT=true');

    $service = $this->loginService();
    $session = new Session(new MockArraySessionStorage());

    $service->startLogin('local_mock', '/dashboard', $session);

    $this->assertSame('/dashboard', $service->consumeRelayState('local_mock', $session));
    $this->assertSame('/', $service->consumeRelayState('local_mock', $session));
  }

  /**
   * Builds the service with real collaborators that are not reached here.
   */
  private function loginService(): SsoLoginService {
    $providerManager = new SsoProviderManager($this->configFactory());
    $clientFactory = new SsoClientFactory($providerManager, new RequestStack());
    $replayCache = new SsoReplayCache(
          $this->createMock(Connection::class),
          $this->createMock(TimeInterface::class),
      );
    $groupMembership = new SsoGroupMembershipService(
          $this->createMock(EntityTypeManagerInterface::class),
          $this->configFactory(),
          $this->createMock(LoggerInterface::class),
      );
    $identityLinker = new SsoIdentityLinker(
          $this->createMock(Connection::class),
          $this->createMock(EntityTypeManagerInterface::class),
          $groupMembership,
          $this->configFactory(),
          $this->createMock(LoggerInterface::class),
      );

    return new SsoLoginService(
          $providerManager,
          $clientFactory,
          $replayCache,
          $identityLinker,
          $this->createMock(LoggerInterface::class),
      );
  }

  /**
   * Builds config for an enabled real SSO provider.
   */
  private function configFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
        if ($key === 'providers.keycloak') {
          return [
            'enabled' => TRUE,
            'profile' => 'generic',
          ];
        }
        if ($key === 'providers.local_mock') {
          return [
            'enabled' => TRUE,
            'profile' => 'generic_mock',
            'mock' => TRUE,
          ];
        }
        if ($key === 'system.site') {
          return [
            'uuid' => 'test-site-uuid',
          ];
        }
            return NULL;
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->willReturn($config);

    return $factory;
  }

}
