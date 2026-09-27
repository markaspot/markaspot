<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the OIDC relying party: discovery, code exchange, ID token checks.
 */
#[Group('markaspot_sso')]
final class OidcClientTest extends UnitTestCase {

  use OidcTestIdpTrait;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('MARKASPOT_SSO_BROKER_CLIENT_SECRET');
    parent::tearDown();
  }

  /**
   * The authorization URL carries PKCE, nonce, scopes and the IdP hint.
   */
  public function testAuthorizationUrl(): void {
    $url = $this->oidcClient()->authorizationUrl($this->oidcProvider(), 'state-1', 'nonce-1', 'challenge-1');
    $this->assertStringStartsWith(self::ISSUER . '/protocol/openid-connect/auth?', $url);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->assertSame('markaspot', $query['client_id']);
    $this->assertSame('code', $query['response_type']);
    $this->assertSame('openid email profile', $query['scope']);
    $this->assertSame('https://dev.example.test/auth/sso/broker/callback', $query['redirect_uri']);
    $this->assertSame('state-1', $query['state']);
    $this->assertSame('nonce-1', $query['nonce']);
    $this->assertSame('challenge-1', $query['code_challenge']);
    $this->assertSame('S256', $query['code_challenge_method']);
    $this->assertSame('bonn-adfs', $query['kc_idp_hint']);
  }

  /**
   * Configured scopes always keep "openid"; no hint means no hint parameter.
   */
  public function testScopesKeepOpenidAndHintIsOptional(): void {
    $provider = $this->oidcProvider(['oidc_scopes' => 'email  profile', 'oidc_idp_hint' => '']);
    $url = $this->oidcClient()->authorizationUrl($provider, 's', 'n', 'c');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->assertSame('openid email profile', $query['scope']);
    $this->assertArrayNotHasKey('kc_idp_hint', $query);
  }

  /**
   * A discovery document for another issuer is refused.
   */
  public function testDiscoveryRejectsForeignIssuer(): void {
    $client = $this->oidcClient([], ['issuer' => 'https://evil.example.test/realms/civicpatches']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('issuer does not match');
    $client->discovery($this->oidcProvider());
  }

  /**
   * Plain http endpoints are refused.
   */
  public function testDiscoveryRejectsHttpEndpoints(): void {
    $client = $this->oidcClient([], ['token_endpoint' => 'http://idp.example.test/token']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('token_endpoint');
    $client->discovery($this->oidcProvider());
  }

  /**
   * Token and key endpoints on another origin are refused.
   */
  #[DataProvider('foreignEndpointProvider')]
  public function testDiscoveryRejectsForeignOriginEndpoints(string $key, string $url): void {
    $client = $this->oidcClient([], [$key => $url]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage($key . ' is not on the issuer origin');
    $client->discovery($this->oidcProvider());
  }

  /**
   * Endpoints outside the issuer origin.
   *
   * @return array<string, array{string, string}>
   *   Discovery key and URL.
   */
  public static function foreignEndpointProvider(): array {
    return [
      'token endpoint on another host' => ['token_endpoint', 'https://collector.example.test/token'],
      'key set on another host' => ['jwks_uri', 'https://collector.example.test/certs'],
      'token endpoint on another port' => ['token_endpoint', 'https://idp.example.test:8443/token'],
    ];
  }

  /**
   * An http issuer is refused before any request is made.
   */
  public function testHttpIssuerIsRefused(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('https URL');
    $this->oidcClient()->discovery($this->oidcProvider(['oidc_issuer' => 'http://idp.example.test/realms/x']));
  }

  /**
   * A correctly signed token with matching claims is accepted.
   */
  public function testValidIdTokenReturnsClaims(): void {
    $claims = $this->oidcClient()->validateIdToken($this->oidcProvider(), $this->signToken($this->idTokenClaims()), 'nonce-1');

    $this->assertSame('subject-1', $claims['sub']);
    $this->assertSame(['hwk'], $claims['amr']);
  }

  /**
   * Tokens with wrong registered claims are refused.
   *
   * @param array<string, mixed> $overrides
   *   Claim overrides.
   * @param string $message
   *   Expected message fragment.
   */
  #[DataProvider('invalidClaimsProvider')]
  public function testInvalidClaimsAreRefused(array $overrides, string $message): void {
    $client = $this->oidcClient();
    $token = $this->signToken($this->idTokenClaims($overrides));

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage($message);
    $client->validateIdToken($this->oidcProvider(), $token, 'nonce-1');
  }

  /**
   * Claim variations that must fail.
   *
   * @return array<string, array{0: array<string, mixed>, 1: string}>
   *   Overrides and expected message fragments.
   */
  public static function invalidClaimsProvider(): array {
    return [
      'foreign issuer' => [['iss' => 'https://evil.example.test/realms/civicpatches'], 'issuer'],
      'other audience' => [['aud' => 'someone-else'], 'audience'],
      'foreign azp' => [['aud' => [self::CLIENT_ID, 'other'], 'azp' => 'other'], 'authorized party'],
      'missing azp with several audiences' => [['aud' => [self::CLIENT_ID, 'other'], 'azp' => NULL], 'authorized party'],
      'expired' => [['exp' => self::NOW - 61], 'expired'],
      'issued in the future' => [['iat' => self::NOW + 61], 'future'],
      'not yet valid' => [['nbf' => self::NOW + 61], 'not valid yet'],
      'other nonce' => [['nonce' => 'nonce-2'], 'nonce'],
      'missing nonce' => [['nonce' => NULL], 'nonce'],
      'empty subject' => [['sub' => ' '], 'subject'],
    ];
  }

  /**
   * An HMAC token is refused even if the attacker knows a shared secret.
   */
  public function testHmacTokenIsRefused(): void {
    $secret = JWKFactory::createFromSecret('0123456789abcdef0123456789abcdef', ['kid' => 'k1']);
    $token = $this->signToken($this->idTokenClaims(), $secret, 'HS256');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('algorithm is not allowed');
    $this->oidcClient()->validateIdToken($this->oidcProvider(), $token, 'nonce-1');
  }

  /**
   * A token whose payload was changed after signing is refused.
   */
  public function testTamperedPayloadIsRefused(): void {
    [$header, , $signature] = explode('.', $this->signToken($this->idTokenClaims()));
    $payload = rtrim(strtr(base64_encode((string) json_encode($this->idTokenClaims(['sub' => 'admin']))), '+/', '-_'), '=');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('signature is invalid');
    $this->oidcClient()->validateIdToken($this->oidcProvider(), "$header.$payload.$signature", 'nonce-1');
  }

  /**
   * A token signed by an unknown key triggers exactly one key set refresh.
   */
  public function testUnknownKeyRefetchesOnceThenFails(): void {
    $client = $this->oidcClient([[self::rsaKey('k1')]]);
    $token = $this->signToken($this->idTokenClaims(), self::rsaKey('rogue'));

    $exception = NULL;
    try {
      $client->validateIdToken($this->oidcProvider(), $token, 'nonce-1');
    }
    catch (AccessDeniedHttpException $caught) {
      $exception = $caught;
    }

    $this->assertNotNull($exception);
    $this->assertStringContainsString('key is unknown', $exception->getMessage());
    $this->assertSame(2, $this->countRequests('/protocol/openid-connect/certs'));
  }

  /**
   * A rotated key is picked up by the refresh.
   */
  public function testRotatedKeyIsPickedUp(): void {
    $client = $this->oidcClient([[self::rsaKey('k1')], [self::rsaKey('k1'), self::rsaKey('k2')]]);
    $client->validateIdToken($this->oidcProvider(), $this->signToken($this->idTokenClaims()), 'nonce-1');

    $claims = $client->validateIdToken($this->oidcProvider(), $this->signToken($this->idTokenClaims(), self::rsaKey('k2')), 'nonce-1');

    $this->assertSame('subject-1', $claims['sub']);
    $this->assertSame(2, $this->countRequests('/protocol/openid-connect/certs'));
  }

  /**
   * The code exchange sends PKCE and form-encoded Basic credentials.
   */
  public function testExchangeCodeSendsPkceAndBasicAuth(): void {
    putenv('MARKASPOT_SSO_BROKER_CLIENT_SECRET=s3cret/+');
    $client = $this->oidcClient([], [], static fn (): Response => new Response(200, [], (string) json_encode([
      'id_token' => 'header.payload.signature',
      'access_token' => 'at',
      'token_type' => 'Bearer',
    ])));

    $tokens = $client->exchangeCode('broker', $this->oidcProvider(), 'code-1', 'verifier-1');

    $this->assertSame('header.payload.signature', $tokens['id_token']);
    $request = $this->lastRequest('/protocol/openid-connect/token');
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('Basic ' . base64_encode('markaspot:s3cret%2F%2B'), $request->getHeaderLine('Authorization'));
    parse_str((string) $request->getBody(), $form);
    $this->assertSame([
      'grant_type' => 'authorization_code',
      'code' => 'code-1',
      'redirect_uri' => 'https://dev.example.test/auth/sso/broker/callback',
      'code_verifier' => 'verifier-1',
    ], $form);
  }

  /**
   * Without a client secret in the environment no request is sent.
   */
  public function testExchangeCodeRequiresSecret(): void {
    $client = $this->oidcClient([], [], static fn (): Response => new Response(200));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('client secret');
    $client->exchangeCode('broker', $this->oidcProvider(), 'code-1', 'verifier-1');
  }

  /**
   * Provider errors surface as access denied with a sanitized error code.
   */
  public function testExchangeCodeReportsProviderError(): void {
    putenv('MARKASPOT_SSO_BROKER_CLIENT_SECRET=secret');
    $client = $this->oidcClient([], [], static fn (): Response => new Response(400, [], (string) json_encode([
      'error' => 'invalid_grant<script>',
    ])));

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('(400 invalid_grantscript)');
    $client->exchangeCode('broker', $this->oidcProvider(), 'code-1', 'verifier-1');
  }

  /**
   * Counts provider requests whose URL ends with a path.
   */
  private function countRequests(string $path): int {
    return count(array_filter(
      $this->idpRequests,
      static fn (RequestInterface $request): bool => str_ends_with($request->getUri()->getPath(), $path),
    ));
  }

  /**
   * Returns the last provider request whose URL ends with a path.
   */
  private function lastRequest(string $path): RequestInterface {
    $matches = array_values(array_filter(
      $this->idpRequests,
      static fn (RequestInterface $request): bool => str_ends_with($request->getUri()->getPath(), $path),
    ));
    $this->assertNotEmpty($matches);
    return $matches[count($matches) - 1];
  }

}
