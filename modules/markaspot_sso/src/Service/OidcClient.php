<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * OpenID Connect relying party for staff login.
 *
 * Drupal is a confidential client using the Authorization Code flow with PKCE.
 * Tokens never leave the server: the ID token is validated here and only its
 * claims reach the identity linker.
 */
final class OidcClient {

  /**
   * The only accepted signature algorithm.
   *
   * Registering nothing else keeps "none" and HMAC key confusion out.
   */
  private const ALGORITHM = 'RS256';

  /**
   * Tolerated clock skew in seconds for exp, iat and nbf.
   */
  private const LEEWAY = 60;

  /**
   * Lifetime of cached discovery documents and key sets in seconds.
   */
  private const CACHE_TTL = 3600;

  /**
   * Discovery keys kept from the provider document.
   */
  private const DISCOVERY_KEYS = [
    'issuer',
    'authorization_endpoint',
    'token_endpoint',
    'jwks_uri',
    'userinfo_endpoint',
    'end_session_endpoint',
  ];

  /**
   * Constructs the OIDC client.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Builds the authorization request URL.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $state
   *   Session-bound CSRF value, echoed back by the provider.
   * @param string $nonce
   *   Session-bound value the ID token must carry.
   * @param string $code_challenge
   *   PKCE S256 challenge of the stored verifier.
   */
  public function authorizationUrl(array $provider, string $state, string $nonce, string $code_challenge): string {
    $discovery = $this->discovery($provider);
    $params = [
      'client_id' => $this->clientId($provider),
      'response_type' => 'code',
      'scope' => $this->scopes($provider),
      'redirect_uri' => $this->redirectUri($provider),
      'state' => $state,
      'nonce' => $nonce,
      'code_challenge' => $code_challenge,
      'code_challenge_method' => 'S256',
    ];
    // Keycloak brokers: route straight to the tenant's identity provider.
    $idp_hint = is_scalar($provider['oidc_idp_hint'] ?? NULL) ? trim((string) $provider['oidc_idp_hint']) : '';
    if ($idp_hint !== '') {
      $params['kc_idp_hint'] = $idp_hint;
    }

    $endpoint = $discovery['authorization_endpoint'];
    $separator = str_contains($endpoint, '?') ? '&' : '?';
    return $endpoint . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * Exchanges an authorization code for tokens.
   *
   * @param string $provider_id
   *   Provider machine name, used to look up the client secret.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $code
   *   Authorization code from the callback.
   * @param string $code_verifier
   *   PKCE verifier stored when the login started.
   *
   * @return array<string, mixed>
   *   Token response containing at least a non-empty "id_token".
   */
  public function exchangeCode(string $provider_id, array $provider, string $code, string $code_verifier): array {
    $discovery = $this->discovery($provider);
    // RFC 6749 section 2.3.1: form-encode id and secret before Basic auth.
    $credentials = urlencode($this->clientId($provider)) . ':' . urlencode($this->clientSecret($provider_id));

    try {
      $response = $this->httpClient->request('POST', $discovery['token_endpoint'], [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($credentials),
          'Accept' => 'application/json',
        ],
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => $this->redirectUri($provider),
          'code_verifier' => $code_verifier,
        ],
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'connect_timeout' => 5,
        'timeout' => 10,
      ]);
    }
    catch (GuzzleException $exception) {
      throw new \RuntimeException('OIDC token endpoint is not reachable.', 0, $exception);
    }

    $body = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || !is_array($body)) {
      $error = is_array($body) && is_string($body['error'] ?? NULL)
        ? (preg_replace('/[^a-z_]/', '', strtolower($body['error'])) ?? '')
        : '';
      throw new AccessDeniedHttpException(sprintf('OIDC token exchange failed (%d %s).', $response->getStatusCode(), $error));
    }
    if (!is_string($body['id_token'] ?? NULL) || $body['id_token'] === '') {
      throw new AccessDeniedHttpException('OIDC token response has no ID token.');
    }
    if (strtolower(is_string($body['token_type'] ?? NULL) ? $body['token_type'] : '') !== 'bearer') {
      throw new AccessDeniedHttpException('OIDC token response has an unexpected token type.');
    }

    return $body;
  }

  /**
   * Validates an ID token and returns its claims.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $id_token
   *   Compact serialized ID token.
   * @param string $nonce
   *   Nonce stored when the login started.
   *
   * @return array<string, mixed>
   *   Verified claims.
   */
  public function validateIdToken(array $provider, string $id_token, string $nonce): array {
    try {
      $jws = (new CompactSerializer())->unserialize($id_token);
    }
    catch (\Throwable) {
      throw new AccessDeniedHttpException('ID token is malformed.');
    }
    if ($jws->countSignatures() !== 1) {
      throw new AccessDeniedHttpException('ID token must carry exactly one signature.');
    }

    $header = $jws->getSignature(0)->getProtectedHeader();
    if (($header['alg'] ?? NULL) !== self::ALGORITHM) {
      throw new AccessDeniedHttpException('ID token algorithm is not allowed.');
    }
    // RFC 7515 section 4.1.11: no critical extensions are understood here.
    if (array_key_exists('crit', $header)) {
      throw new AccessDeniedHttpException('ID token uses unsupported critical header parameters.');
    }
    $kid = $header['kid'] ?? NULL;
    if (!is_string($kid) || $kid === '') {
      throw new AccessDeniedHttpException('ID token has no key id.');
    }

    $verifier = new JWSVerifier(new AlgorithmManager([new RS256()]));
    if (!$verifier->verifyWithKey($jws, $this->signingKey($provider, $kid), 0)) {
      throw new AccessDeniedHttpException('ID token signature is invalid.');
    }

    $claims = json_decode((string) $jws->getPayload(), TRUE);
    if (!is_array($claims)) {
      throw new AccessDeniedHttpException('ID token payload is not a JSON object.');
    }
    $this->assertClaims($provider, $claims, $nonce);

    return $claims;
  }

  /**
   * Returns the provider discovery document, validated and cached.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   *
   * @return array<string, string>
   *   Discovery endpoints keyed by name.
   */
  public function discovery(array $provider): array {
    $issuer = $this->issuer($provider);
    $cid = 'markaspot_sso:oidc:discovery:' . hash('sha256', $issuer);
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    // The exact issuer string is compared below; only the fetch URL is
    // normalized, so a configured trailing slash does not produce "//".
    $data = $this->fetchJson(rtrim($issuer, '/') . '/.well-known/openid-configuration', 'discovery document');
    if (($data['issuer'] ?? NULL) !== $issuer) {
      throw new \RuntimeException('OIDC discovery issuer does not match the configured issuer.');
    }
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
      if (!is_string($data[$key] ?? NULL) || !$this->isHttpsUrl($data[$key])) {
        throw new \RuntimeException(sprintf('OIDC discovery document has no valid %s.', $key));
      }
    }
    // The client secret goes to the token endpoint and the signing keys come
    // from jwks_uri: both must live on the issuer's own origin.
    foreach (['token_endpoint', 'jwks_uri'] as $key) {
      if (!$this->isSameOrigin($data[$key], $issuer)) {
        throw new \RuntimeException(sprintf('OIDC discovery %s is not on the issuer origin.', $key));
      }
    }

    $document = array_filter(
      array_intersect_key($data, array_flip(self::DISCOVERY_KEYS)),
      'is_string',
    );
    $this->cache->set($cid, $document, $this->time->getRequestTime() + self::CACHE_TTL);
    return $document;
  }

  /**
   * Returns the RSA signing key for a key id, refetching the set once.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $kid
   *   Key id from the ID token header.
   */
  private function signingKey(array $provider, string $kid): JWK {
    foreach ([FALSE, TRUE] as $refresh) {
      $keys = $this->keySet($provider, $refresh);
      if (!$keys->has($kid)) {
        continue;
      }
      $jwk = $keys->get($kid);
      if ($jwk->get('kty') !== 'RSA' || ($jwk->has('use') && $jwk->get('use') !== 'sig')) {
        throw new AccessDeniedHttpException('ID token key is not an RSA signing key.');
      }
      return $jwk;
    }

    throw new AccessDeniedHttpException('ID token key is unknown.');
  }

  /**
   * Returns the provider key set.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param bool $refresh
   *   TRUE to bypass the cache, used once when a key id is unknown.
   */
  private function keySet(array $provider, bool $refresh): JWKSet {
    $jwks_uri = $this->discovery($provider)['jwks_uri'];
    $cid = 'markaspot_sso:oidc:jwks:' . hash('sha256', $jwks_uri);
    $cached = $refresh ? FALSE : $this->cache->get($cid);
    $data = $cached !== FALSE && is_array($cached->data)
      ? $cached->data
      : $this->fetchJson($jwks_uri, 'key set');

    if (!is_array($data['keys'] ?? NULL)) {
      throw new \RuntimeException('OIDC key set has no keys.');
    }
    try {
      $set = JWKSet::createFromKeyData($data);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException('OIDC key set is invalid.', 0, $exception);
    }

    if ($cached === FALSE) {
      $this->cache->set($cid, $data, $this->time->getRequestTime() + self::CACHE_TTL);
    }
    return $set;
  }

  /**
   * Checks the registered claims of a verified ID token.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param array<string, mixed> $claims
   *   Claims from the verified payload.
   * @param string $nonce
   *   Expected nonce.
   */
  private function assertClaims(array $provider, array $claims, string $nonce): void {
    $now = $this->time->getCurrentTime();
    $client_id = $this->clientId($provider);

    if (($claims['iss'] ?? NULL) !== $this->issuer($provider)) {
      throw new AccessDeniedHttpException('ID token issuer does not match.');
    }

    $audience = $claims['aud'] ?? NULL;
    $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
    if (!in_array($client_id, $audiences, TRUE)) {
      throw new AccessDeniedHttpException('ID token audience does not include this client.');
    }
    $azp = $claims['azp'] ?? NULL;
    if ((count($audiences) > 1 || $azp !== NULL) && $azp !== $client_id) {
      throw new AccessDeniedHttpException('ID token authorized party does not match.');
    }

    if (!is_int($claims['exp'] ?? NULL) || $claims['exp'] + self::LEEWAY < $now) {
      throw new AccessDeniedHttpException('ID token is expired.');
    }
    if (!is_int($claims['iat'] ?? NULL) || $claims['iat'] - self::LEEWAY > $now) {
      throw new AccessDeniedHttpException('ID token was issued in the future.');
    }
    if (array_key_exists('nbf', $claims) && (!is_int($claims['nbf']) || $claims['nbf'] - self::LEEWAY > $now)) {
      throw new AccessDeniedHttpException('ID token is not valid yet.');
    }

    if (!is_string($claims['nonce'] ?? NULL) || !hash_equals($nonce, $claims['nonce'])) {
      throw new AccessDeniedHttpException('ID token nonce does not match.');
    }
    if (!is_string($claims['sub'] ?? NULL) || trim($claims['sub']) === '') {
      throw new AccessDeniedHttpException('ID token has no subject.');
    }
  }

  /**
   * Fetches a JSON document over HTTPS.
   *
   * @return array<string, mixed>
   *   Decoded JSON object.
   */
  private function fetchJson(string $url, string $label): array {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Accept' => 'application/json'],
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'connect_timeout' => 5,
        'timeout' => 10,
      ]);
    }
    catch (GuzzleException $exception) {
      throw new \RuntimeException(sprintf('OIDC %s is not reachable.', $label), 0, $exception);
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || !is_array($data)) {
      throw new \RuntimeException(sprintf('OIDC %s could not be loaded (%d).', $label, $response->getStatusCode()));
    }
    return $data;
  }

  /**
   * Returns the configured issuer.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function issuer(array $provider): string {
    $issuer = is_scalar($provider['oidc_issuer'] ?? NULL) ? trim((string) $provider['oidc_issuer']) : '';
    if (!$this->isHttpsUrl($issuer)) {
      throw new \RuntimeException('OIDC issuer must be an https URL.');
    }
    return $issuer;
  }

  /**
   * Returns the configured client id.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function clientId(array $provider): string {
    $client_id = is_scalar($provider['oidc_client_id'] ?? NULL) ? trim((string) $provider['oidc_client_id']) : '';
    if ($client_id === '') {
      throw new \RuntimeException('OIDC client id is not configured.');
    }
    return $client_id;
  }

  /**
   * Returns the configured redirect URI.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function redirectUri(array $provider): string {
    $uri = is_scalar($provider['oidc_redirect_uri'] ?? NULL) ? trim((string) $provider['oidc_redirect_uri']) : '';
    if (!$this->isHttpsUrl($uri)) {
      throw new \RuntimeException('OIDC redirect URI must be an https URL.');
    }
    return $uri;
  }

  /**
   * Returns the requested scopes, always including "openid".
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function scopes(array $provider): string {
    $configured = is_scalar($provider['oidc_scopes'] ?? NULL) ? (string) $provider['oidc_scopes'] : '';
    $scopes = preg_split('/\s+/', trim($configured)) ?: [];
    $scopes = array_values(array_filter($scopes, static fn (string $scope): bool => $scope !== ''));
    if ($scopes === []) {
      $scopes = ['openid', 'email', 'profile'];
    }
    if (!in_array('openid', $scopes, TRUE)) {
      array_unshift($scopes, 'openid');
    }
    return implode(' ', array_unique($scopes));
  }

  /**
   * Reads the client secret from the environment, never from config.
   */
  private function clientSecret(string $provider_id): string {
    $suffix = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $provider_id) ?? $provider_id);
    $secret = getenv('MARKASPOT_SSO_' . $suffix . '_CLIENT_SECRET');
    if (!is_string($secret) || $secret === '') {
      throw new \RuntimeException('OIDC client secret is not configured.');
    }
    return $secret;
  }

  /**
   * Checks for an absolute https URL with a host.
   */
  private function isHttpsUrl(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts)
      && ($parts['scheme'] ?? NULL) === 'https'
      && !empty($parts['host']);
  }

  /**
   * Checks that two URLs share scheme, host and port.
   */
  private function isSameOrigin(string $url, string $reference): bool {
    $a = parse_url($url);
    $b = parse_url($reference);
    return is_array($a) && is_array($b)
      && strtolower($a['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
      && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
      && ($a['port'] ?? 443) === ($b['port'] ?? 443);
  }

}
