<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_sso\Controller\SsoAuthController;
use Drupal\markaspot_sso\Service\SsoClientFactory;
use Drupal\markaspot_sso\Service\SsoGroupMembershipService;
use Drupal\markaspot_sso\Service\SsoIdentityLinker;
use Drupal\markaspot_sso\Service\SsoLoginService;
use Drupal\markaspot_sso\Service\SsoProviderManager;
use Drupal\markaspot_sso\Service\SsoRelayStateValidator;
use Drupal\markaspot_sso\Service\SsoReplayCache;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests SSO auth controller redirects.
 *
 * @group markaspot_sso
 */
final class SsoAuthControllerTest extends UnitTestCase {

  /**
   * Failed ACS responses redirect back to the SPA with a generic marker.
   */
  public function testAcsFailureRedirectsToRelayStateWithErrorMarker(): void {
    $controller = $this->controller();
    $request = Request::create('/auth/sso/keycloak/acs', 'POST');
    $session = new Session(new MockArraySessionStorage());
    $session->set(
      'markaspot_sso.keycloak.relay_state',
      'https://dev.ddev.site:3001/amsterdam/auth/sso-callback?redirect=/amsterdam/dashboard',
    );
    $request->setSession($session);

    $response = $controller->acs($request, 'keycloak');

    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame(
      'https://dev.ddev.site:3001/amsterdam/auth/sso-callback?redirect=/amsterdam/dashboard&sso_error=1',
      $response->headers->get('Location'),
    );
    $this->assertFalse($session->has('markaspot_sso.keycloak.relay_state'));
  }

  /**
   * Builds the controller with real services until ACS validation fails.
   */
  private function controller(): SsoAuthController {
    $provider_manager = new SsoProviderManager($this->configFactory());
    $request_stack = new RequestStack();
    $client_factory = new SsoClientFactory($provider_manager, $request_stack);
    $replay_cache = new SsoReplayCache(
      $this->createMock(Connection::class),
      $this->createMock(TimeInterface::class),
    );
    $group_membership = new SsoGroupMembershipService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->configFactory(),
      $this->createMock(LoggerInterface::class),
    );
    $identity_linker = new SsoIdentityLinker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $group_membership,
      $this->configFactory(),
      $this->createMock(LoggerInterface::class),
    );
    $login_service = new SsoLoginService(
      $provider_manager,
      $client_factory,
      $replay_cache,
      $identity_linker,
      $this->createMock(LoggerInterface::class),
    );

    return new SsoAuthController(
      $provider_manager,
      $client_factory,
      $login_service,
      new SsoRelayStateValidator($this->configFactory(), $request_stack),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds config for an enabled SSO provider.
   */
  private function configFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
        if ($key === 'providers.keycloak') {
          return [
            'enabled' => TRUE,
            'profile' => 'generic',
            'default_relay_path' => '/',
            'allowed_relay_hosts' => ['dev.ddev.site:3001'],
          ];
        }
        if ($key === 'jurisdiction_group_type') {
          return 'jur';
        }
        if ($key === 'organisation_group_type') {
          return 'org';
        }
        return NULL;
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    return $factory;
  }

}
