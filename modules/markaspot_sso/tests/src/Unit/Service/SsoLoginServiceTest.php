<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_sso\Service\OidcClaims;
use Drupal\markaspot_sso\Service\OidcClient;
use Drupal\markaspot_sso\Service\SsoClientFactory;
use Drupal\markaspot_sso\Service\SsoGroupMembershipService;
use Drupal\markaspot_sso\Service\SsoIdentityLinker;
use Drupal\markaspot_sso\Service\SsoLoginService;
use Drupal\markaspot_sso\Service\SsoProviderManager;
use Drupal\markaspot_sso\Service\SsoReplayCache;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests SSO login coordination.
 *
 * @group markaspot_sso
 */
final class SsoLoginServiceTest extends UnitTestCase {

  use OidcTestIdpTrait;

  /**
   * Overrides merged into provider configs, keyed by provider id.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $providerOverrides = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('MARKASPOT_SSO_BROKER_CLIENT_SECRET');
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
   * Starting an OIDC login stores state, nonce and PKCE verifier together.
   */
  public function testOidcLoginStoresPkceMaterial(): void {
    $session = new Session(new MockArraySessionStorage());
    $url = $this->loginService()->startLogin('broker', '/dashboard', $session);

    $pending = $session->get('markaspot_sso.broker.oidc');
    $this->assertIsArray($pending);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->assertSame($pending['state'], $query['state']);
    $this->assertSame($pending['nonce'], $query['nonce']);
    $this->assertSame(
      rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], TRUE)), '+/', '-_'), '='),
      $query['code_challenge'],
    );
    $this->assertSame('S256', $query['code_challenge_method']);
    $this->assertGreaterThanOrEqual(43, strlen($pending['verifier']));
    $this->assertNotSame($pending['state'], $pending['nonce']);
    $this->assertSame('/dashboard', $session->get('markaspot_sso.broker.relay_state'));
  }

  /**
   * A callback without a started login is refused.
   */
  public function testOidcCallbackRequiresStartedLogin(): void {
    $request = Request::create('/auth/sso/broker/callback', 'GET', ['state' => 's', 'code' => 'c']);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('correlation');
    $this->loginService()->processCallback('broker', $request, new Session(new MockArraySessionStorage()));
  }

  /**
   * A wrong state is refused and the started login cannot be retried.
   */
  public function testOidcCallbackStateMismatchConsumesPendingLogin(): void {
    $session = $this->sessionWithPendingLogin(self::NOW);
    $request = Request::create('/auth/sso/broker/callback', 'GET', ['state' => 'forged', 'code' => 'c']);

    $exception = NULL;
    try {
      $this->loginService()->processCallback('broker', $request, $session);
    }
    catch (AccessDeniedHttpException $caught) {
      $exception = $caught;
    }

    $this->assertNotNull($exception);
    $this->assertStringContainsString('state', $exception->getMessage());
    $this->assertFalse($session->has('markaspot_sso.broker.oidc'));
  }

  /**
   * An error answer from the identity provider is refused without echoing it.
   */
  public function testOidcCallbackProviderErrorIsRefused(): void {
    $session = $this->sessionWithPendingLogin(self::NOW);
    $request = Request::create('/auth/sso/broker/callback', 'GET', [
      'state' => 'state-1',
      'error' => 'access_denied"><img>',
    ]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Identity provider returned "access_deniedimg"');
    $this->loginService()->processCallback('broker', $request, $session);
  }

  /**
   * A login started more than ten minutes ago is refused.
   */
  public function testOidcCallbackExpiredAttemptIsRefused(): void {
    $session = $this->sessionWithPendingLogin(self::NOW - 601);
    $request = Request::create('/auth/sso/broker/callback', 'GET', ['state' => 'state-1', 'code' => 'c']);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('expired');
    $this->loginService()->processCallback('broker', $request, $session);
  }

  /**
   * An error answer with a foreign state is refused as a state mismatch.
   */
  public function testOidcCallbackErrorWithForeignStateIsStateMismatch(): void {
    $session = $this->sessionWithPendingLogin(self::NOW);
    $request = Request::create('/auth/sso/broker/callback', 'GET', ['state' => 'forged', 'error' => 'access_denied']);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('state does not match');
    $this->loginService()->processCallback('broker', $request, $session);
  }

  /**
   * A valid callback links the identity and records the MFA decision.
   */
  public function testOidcCallbackLogsInAndRecordsMfa(): void {
    $idToken = $this->signToken($this->idTokenClaims([
      'amr' => [],
      'email_verified' => TRUE,
      'identity_provider' => 'bonn-adfs',
      'upstream_amr' => ['http://schemas.microsoft.com/claims/multipleauthn'],
    ]));
    $replay = $this->createMock(SsoReplayCache::class);
    $replay->expects($this->once())
      ->method('checkAndStore')
      ->with('broker', hash('sha256', $idToken), 'nonce-1', self::NOW + 300)
      ->willReturn(TRUE);
    $linker = $this->createMock(SsoIdentityLinker::class);
    $linker->expects($this->once())
      ->method('authenticate')
      ->with(
        'broker',
        $this->callback(static fn (array $provider): bool => $provider['attribute_map'] === OidcClaims::DEFAULT_ATTRIBUTE_MAP),
        'subject-1',
        $this->callback(static fn (array $attributes): bool => $attributes['email'] === ['staff@civicspot.example']),
        [],
      )
      ->willReturn(['uid' => 7, 'assurance_level' => NULL]);
    $session = $this->sessionWithPendingLogin(self::NOW);

    $user = $this->loginService($replay, $linker, $this->brokerClient($idToken))
      ->processCallback('broker', $this->callbackRequest(), $session);

    $this->assertSame(7, $user['uid']);
    $this->assertTrue($user['mfa']);
    $this->assertSame([
      'provider' => 'broker',
      'uid' => 7,
      'assurance_level' => NULL,
      'time' => self::NOW,
      'protocol' => 'oidc',
      'mfa' => TRUE,
      'amr' => [],
      'identity_provider' => 'bonn-adfs',
    ], $session->get('markaspot_sso.last_login'));
    $this->assertFalse($session->has('markaspot_sso.broker.oidc'));
  }

  /**
   * A local account cannot vouch for MFA, and its unverified email is dropped.
   */
  public function testOidcCallbackIgnoresSelfAssertedClaims(): void {
    $idToken = $this->signToken($this->idTokenClaims([
      'amr' => ['pwd'],
      'email_verified' => FALSE,
      'upstream_amr' => ['http://schemas.microsoft.com/claims/multipleauthn'],
    ]));
    $replay = $this->createMock(SsoReplayCache::class);
    $replay->method('checkAndStore')->willReturn(TRUE);
    $linker = $this->createMock(SsoIdentityLinker::class);
    $linker->expects($this->once())
      ->method('authenticate')
      ->with(
        'broker',
        $this->anything(),
        'subject-1',
        $this->callback(static fn (array $attributes): bool => !array_key_exists('email', $attributes)),
        [],
      )
      ->willReturn(['uid' => 8]);
    $session = $this->sessionWithPendingLogin(self::NOW);

    $user = $this->loginService($replay, $linker, $this->brokerClient($idToken))
      ->processCallback('broker', $this->callbackRequest(), $session);

    $this->assertFalse($user['mfa']);
    $this->assertFalse($session->get('markaspot_sso.last_login')['mfa']);
  }

  /**
   * A provider that requires MFA refuses a login without a second factor.
   */
  public function testOidcCallbackRequireMfaRefusesWeakLogin(): void {
    $this->providerOverrides['broker'] = ['require_mfa' => TRUE];
    $idToken = $this->signToken($this->idTokenClaims(['amr' => ['pwd']]));
    $replay = $this->createMock(SsoReplayCache::class);
    $replay->method('checkAndStore')->willReturn(TRUE);
    $linker = $this->createMock(SsoIdentityLinker::class);
    $linker->expects($this->never())->method('authenticate');
    $session = $this->sessionWithPendingLogin(self::NOW);

    try {
      $this->loginService($replay, $linker, $this->brokerClient($idToken))
        ->processCallback('broker', $this->callbackRequest(), $session);
      $this->fail('A login without a second factor was accepted.');
    }
    catch (AccessDeniedHttpException $exception) {
      $this->assertStringContainsString('requires a second factor', $exception->getMessage());
    }
    $this->assertFalse($session->has('markaspot_sso.last_login'));
  }

  /**
   * A provider that requires MFA accepts a passkey login.
   */
  public function testOidcCallbackRequireMfaAcceptsPasskey(): void {
    $this->providerOverrides['broker'] = ['require_mfa' => TRUE];
    $idToken = $this->signToken($this->idTokenClaims(['amr' => ['hwk']]));
    $replay = $this->createMock(SsoReplayCache::class);
    $replay->method('checkAndStore')->willReturn(TRUE);
    $linker = $this->createMock(SsoIdentityLinker::class);
    $linker->expects($this->once())->method('authenticate')->willReturn(['uid' => 8]);

    $user = $this->loginService($replay, $linker, $this->brokerClient($idToken))
      ->processCallback('broker', $this->callbackRequest(), $this->sessionWithPendingLogin(self::NOW));

    $this->assertTrue($user['mfa']);
  }

  /**
   * SAML cannot prove MFA, so a provider that requires it refuses SAML.
   */
  public function testRequireMfaRefusesSamlResponse(): void {
    $this->providerOverrides['keycloak'] = ['require_mfa' => TRUE];
    $request = Request::create('/auth/sso/keycloak/acs', 'POST', ['SAMLResponse' => 'dummy-response']);
    $session = new Session(new MockArraySessionStorage());
    $session->set('markaspot_sso.keycloak.request_id', 'request-123');

    try {
      $this->loginService()->processAcs('keycloak', $request, $session);
      $this->fail('A SAML login was accepted although MFA is required.');
    }
    catch (AccessDeniedHttpException $exception) {
      $this->assertStringContainsString('only OIDC logins can prove', $exception->getMessage());
    }
    $this->assertFalse($session->has('markaspot_sso.keycloak.request_id'));
  }

  /**
   * The dev mock cannot prove MFA either.
   */
  public function testRequireMfaRefusesMockLogin(): void {
    putenv('MARKASPOT_SSO_MOCK=true');
    putenv('IS_DDEV_PROJECT=true');
    $this->providerOverrides['local_mock'] = ['require_mfa' => TRUE];

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('only OIDC logins can prove');
    $this->loginService()->mockLogin('local_mock', new Session(new MockArraySessionStorage()));
  }

  /**
   * An unverified email in a custom-mapped claim never reaches the linker.
   */
  public function testOidcCallbackDropsUnverifiedMappedEmail(): void {
    $this->providerOverrides['broker'] = ['oidc_attribute_map' => ['email' => ['upn']]];
    $idToken = $this->signToken($this->idTokenClaims([
      'email' => NULL,
      'email_verified' => FALSE,
      'upn' => 'victim@example.test',
    ]));
    $replay = $this->createMock(SsoReplayCache::class);
    $replay->method('checkAndStore')->willReturn(TRUE);
    $linker = $this->createMock(SsoIdentityLinker::class);
    $linker->expects($this->once())
      ->method('authenticate')
      ->with(
        'broker',
        $this->callback(static fn (array $provider): bool => $provider['attribute_map'] === ['email' => ['upn']]),
        'subject-1',
        $this->callback(static fn (array $attributes): bool => !array_key_exists('upn', $attributes) && !array_key_exists('email', $attributes)),
        [],
      )
      ->willReturn(['uid' => 8]);

    $this->loginService($replay, $linker, $this->brokerClient($idToken))
      ->processCallback('broker', $this->callbackRequest(), $this->sessionWithPendingLogin(self::NOW));
  }

  /**
   * A response seen before never reaches the identity linker.
   */
  public function testOidcCallbackRejectsReplay(): void {
    $idToken = $this->signToken($this->idTokenClaims());
    $replay = $this->createMock(SsoReplayCache::class);
    $replay->method('checkAndStore')->willReturn(FALSE);
    $linker = $this->createMock(SsoIdentityLinker::class);
    $linker->expects($this->never())->method('authenticate');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('already processed');
    $this->loginService($replay, $linker, $this->brokerClient($idToken))
      ->processCallback('broker', $this->callbackRequest(), $this->sessionWithPendingLogin(self::NOW));
  }

  /**
   * Returns an OIDC client whose token endpoint answers with an ID token.
   */
  private function brokerClient(string $idToken): OidcClient {
    putenv('MARKASPOT_SSO_BROKER_CLIENT_SECRET=secret');
    return $this->oidcClient([], [], static fn (): Response => new Response(200, [], (string) json_encode([
      'id_token' => $idToken,
      'access_token' => 'at',
      'token_type' => 'Bearer',
    ])));
  }

  /**
   * Returns a callback request matching the pending login.
   */
  private function callbackRequest(): Request {
    return Request::create('/auth/sso/broker/callback', 'GET', ['state' => 'state-1', 'code' => 'code-1']);
  }

  /**
   * SAML providers do not accept OIDC callbacks.
   */
  public function testSamlProviderRejectsOidcCallback(): void {
    $request = Request::create('/auth/sso/keycloak/callback', 'GET', ['state' => 's', 'code' => 'c']);

    $this->expectException(BadRequestHttpException::class);
    $this->loginService()->processCallback('keycloak', $request, new Session(new MockArraySessionStorage()));
  }

  /**
   * Returns a session holding a started OIDC login for "broker".
   */
  private function sessionWithPendingLogin(int $created): Session {
    $session = new Session(new MockArraySessionStorage());
    $session->set('markaspot_sso.broker.oidc', [
      'state' => 'state-1',
      'nonce' => 'nonce-1',
      'verifier' => str_repeat('v', 43),
      'created' => $created,
    ]);
    return $session;
  }

  /**
   * Builds the service; collaborators not given are real but never reached.
   */
  private function loginService(?SsoReplayCache $replayCache = NULL, ?SsoIdentityLinker $identityLinker = NULL, ?OidcClient $oidcClient = NULL): SsoLoginService {
    $providerManager = new SsoProviderManager($this->configFactory());
    $clientFactory = new SsoClientFactory($providerManager, new RequestStack());
    $replayCache ??= new SsoReplayCache(
          $this->createMock(Connection::class),
          $this->createMock(TimeInterface::class),
      );
    $groupMembership = new SsoGroupMembershipService(
          $this->createMock(EntityTypeManagerInterface::class),
          $this->configFactory(),
          $this->createMock(LoggerInterface::class),
      );
    $identityLinker ??= new SsoIdentityLinker(
          $this->createMock(Connection::class),
          $this->createMock(EntityTypeManagerInterface::class),
          $groupMembership,
          $this->configFactory(),
          $this->createMock(LoggerInterface::class),
      );

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(self::NOW);

    return new SsoLoginService(
          $providerManager,
          $clientFactory,
          $replayCache,
          $identityLinker,
          $this->createMock(LoggerInterface::class),
          $oidcClient ?? $this->oidcClient(),
          $time,
      );
  }

  /**
   * Builds config for an enabled real SSO provider.
   */
  private function configFactory(): ConfigFactoryInterface {
    $broker = ($this->providerOverrides['broker'] ?? []) + $this->oidcProvider();
    $overrides = $this->providerOverrides;
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($broker, $overrides): mixed {
        if ($key === 'providers.broker') {
          return $broker;
        }
        if ($key === 'providers.keycloak') {
          return ($overrides['keycloak'] ?? []) + [
            'enabled' => TRUE,
            'profile' => 'generic',
          ];
        }
        if ($key === 'providers.local_mock') {
          return ($overrides['local_mock'] ?? []) + [
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
