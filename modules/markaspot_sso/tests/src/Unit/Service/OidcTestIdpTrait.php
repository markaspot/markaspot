<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\markaspot_sso\Service\OidcClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Http\Message\RequestInterface;

/**
 * An in-memory OpenID provider for OIDC unit tests.
 */
trait OidcTestIdpTrait {

  /**
   * Fixed "now" for all time checks.
   */
  protected const NOW = 1790000000;

  /**
   * Issuer of the test provider.
   */
  protected const ISSUER = 'https://idp.example.test/realms/civicpatches';

  /**
   * Client id registered at the test provider.
   */
  protected const CLIENT_ID = 'markaspot';

  /**
   * RSA keys shared by all tests, generated once because it is slow.
   *
   * @var array<string, \Jose\Component\Core\JWK>
   */
  private static array $rsaKeys = [];

  /**
   * Requests the provider received, in order.
   *
   * @var array<int, \Psr\Http\Message\RequestInterface>
   */
  protected array $idpRequests = [];

  /**
   * Returns an RSA signing key by kid.
   */
  protected static function rsaKey(string $kid): JWK {
    return self::$rsaKeys[$kid] ??= JWKFactory::createRSAKey(2048, ['kid' => $kid, 'alg' => 'RS256', 'use' => 'sig']);
  }

  /**
   * Returns a provider configuration for the test provider.
   *
   * @param array<string, mixed> $overrides
   *   Values replacing the defaults.
   *
   * @return array<string, mixed>
   *   Provider configuration.
   */
  protected function oidcProvider(array $overrides = []): array {
    return $overrides + [
      'enabled' => TRUE,
      'protocol' => 'oidc',
      'oidc_issuer' => self::ISSUER,
      'oidc_client_id' => self::CLIENT_ID,
      'oidc_redirect_uri' => 'https://dev.example.test/auth/sso/broker/callback',
      'oidc_idp_hint' => 'bonn-adfs',
    ];
  }

  /**
   * Returns ID token claims that pass every check.
   *
   * @param array<string, mixed> $overrides
   *   Claims replacing the defaults. A NULL value removes the claim.
   *
   * @return array<string, mixed>
   *   Claims.
   */
  protected function idTokenClaims(array $overrides = []): array {
    $claims = $overrides + [
      'iss' => self::ISSUER,
      'aud' => self::CLIENT_ID,
      'azp' => self::CLIENT_ID,
      'sub' => 'subject-1',
      'exp' => self::NOW + 300,
      'iat' => self::NOW,
      'nonce' => 'nonce-1',
      'email' => 'staff@civicspot.example',
      'amr' => ['hwk'],
    ];
    return array_filter($claims, static fn (mixed $value): bool => $value !== NULL);
  }

  /**
   * Signs claims as a compact JWS.
   *
   * @param array<string, mixed> $claims
   *   Payload claims.
   * @param \Jose\Component\Core\JWK|null $key
   *   Signing key, defaults to the RSA key "k1".
   * @param string $alg
   *   Signature algorithm.
   */
  protected function signToken(array $claims, ?JWK $key = NULL, string $alg = 'RS256'): string {
    $key ??= self::rsaKey('k1');
    $builder = new JWSBuilder(new AlgorithmManager([new RS256(), new HS256()]));
    $jws = $builder->create()
      ->withPayload((string) json_encode($claims))
      ->addSignature($key, ['alg' => $alg, 'kid' => $key->has('kid') ? $key->get('kid') : 'none', 'typ' => 'JWT'])
      ->build();
    return (new CompactSerializer())->serialize($jws, 0);
  }

  /**
   * Builds an OIDC client wired to the in-memory provider.
   *
   * @param array<int, array<int, \Jose\Component\Core\JWK>> $jwksResponses
   *   Public key lists returned by successive JWKS fetches. The last list
   *   repeats.
   * @param array<string, mixed> $discoveryOverrides
   *   Values replacing the discovery document defaults.
   * @param \Closure|null $tokenResponder
   *   Returns the token endpoint response for a request.
   */
  protected function oidcClient(array $jwksResponses = [], array $discoveryOverrides = [], ?\Closure $tokenResponder = NULL): OidcClient {
    $jwksResponses = $jwksResponses ?: [[self::rsaKey('k1')]];
    $discovery = $discoveryOverrides + [
      'issuer' => self::ISSUER,
      'authorization_endpoint' => self::ISSUER . '/protocol/openid-connect/auth',
      'token_endpoint' => self::ISSUER . '/protocol/openid-connect/token',
      'jwks_uri' => self::ISSUER . '/protocol/openid-connect/certs',
    ];
    $jwksFetches = 0;

    $handler = function (RequestInterface $request) use ($discovery, $jwksResponses, &$jwksFetches, $tokenResponder) {
      $this->idpRequests[] = $request;
      $url = (string) $request->getUri();
      if (str_ends_with($url, '/.well-known/openid-configuration')) {
        return Create::promiseFor(new Response(200, [], (string) json_encode($discovery)));
      }
      if ($url === $discovery['jwks_uri']) {
        $keys = $jwksResponses[min($jwksFetches, count($jwksResponses) - 1)];
        $jwksFetches++;
        $public = array_map(static fn (JWK $key): array => $key->toPublic()->all(), $keys);
        return Create::promiseFor(new Response(200, [], (string) json_encode(['keys' => $public])));
      }
      if ($url === $discovery['token_endpoint'] && $tokenResponder !== NULL) {
        return Create::promiseFor($tokenResponder($request));
      }
      return Create::promiseFor(new Response(404));
    };

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(self::NOW);
    $time->method('getRequestTime')->willReturn(self::NOW);

    return new OidcClient(
      new Client(['handler' => HandlerStack::create($handler)]),
      new MemoryBackend($time),
      $time,
    );
  }

}
