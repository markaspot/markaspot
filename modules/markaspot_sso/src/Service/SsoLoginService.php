<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use Drupal\Component\Datetime\TimeInterface;
use OneLogin\Saml2\Auth;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Coordinates SSO login starts, SAML ACS, OIDC callbacks, and dev mock login.
 */
final class SsoLoginService {

  /**
   * Seconds a started OIDC login stays valid.
   */
  private const OIDC_LOGIN_TTL = 600;

  /**
   * Constructs the SSO login service.
   */
  public function __construct(
    private readonly SsoProviderManager $providerManager,
    private readonly SsoClientFactory $clientFactory,
    private readonly SsoReplayCache $replayCache,
    private readonly SsoIdentityLinker $identityLinker,
    private readonly LoggerInterface $logger,
    private readonly OidcClient $oidcClient,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Builds an SP-initiated login redirect URL.
   */
  public function startLogin(string $provider_id, string $relay_state, SessionInterface $session): string {
    $provider = $this->providerManager->enabledProvider($provider_id);
    $session->set($this->relayStateKey($provider_id), $relay_state);
    if ($this->providerManager->isMockProvider($provider)) {
      $this->providerManager->assertMockAllowed($provider);
      return $this->mockLoginUrl($provider_id, $relay_state);
    }
    if ($this->providerManager->isOidcProvider($provider)) {
      return $this->startOidcLogin($provider_id, $provider, $session);
    }

    $auth = $this->clientFactory->auth($provider_id);
    $url = $auth->login($relay_state, [], FALSE, FALSE, TRUE);
    $session->set($this->requestIdKey($provider_id), $auth->getLastRequestID());
    return (string) $url;
  }

  /**
   * Processes a POSTed SSO response and logs in the mapped identity.
   *
   * @return array<string, mixed>
   *   Authenticated user payload.
   */
  public function processAcs(string $provider_id, Request $request, SessionInterface $session): array {
    $provider = $this->providerManager->enabledProvider($provider_id);
    $request_id_key = $this->requestIdKey($provider_id);

    try {
      if ($this->providerManager->isMockProvider($provider)) {
        throw new BadRequestHttpException('Mock providers do not accept SAMLResponse payloads.');
      }

      $sso_response = $request->request->get('SAMLResponse');
      if (!is_string($sso_response) || trim($sso_response) === '') {
        throw new BadRequestHttpException('Missing SAMLResponse.');
      }

      $request_id = $session->get($request_id_key);
      $request_id = is_string($request_id) && $request_id !== '' ? $request_id : NULL;
      if ($request_id === NULL) {
        throw new AccessDeniedHttpException('Missing SSO request correlation.');
      }

      $auth = $this->clientFactory->auth($provider_id);
      $this->processResponse($auth, $request, $request_id);

      if (!$auth->isAuthenticated()) {
        throw new AccessDeniedHttpException('SSO response is not authenticated.');
      }
      if (
            !$this->replayCache->checkAndStore(
                $provider_id,
                $auth->getLastMessageId(),
                $auth->getLastAssertionId(),
                $auth->getLastAssertionNotOnOrAfter(),
            )
        ) {
        throw new AccessDeniedHttpException('SSO response was already processed.');
      }

      $user = $this->identityLinker->authenticate(
            $provider_id,
            $provider,
            (string) $auth->getNameId(),
            $auth->getAttributes(),
            $auth->getAttributesWithFriendlyName(),
        );
    }
    catch (\Throwable $exception) {
      $session->remove($request_id_key);
      throw $exception;
    }

    $this->storeLastLogin($session, $provider_id, $user, ['protocol' => 'saml', 'mfa' => NULL]);
    $session->remove($request_id_key);
    return $user;
  }

  /**
   * Processes an OIDC authorization response and logs in the mapped identity.
   *
   * @return array<string, mixed>
   *   Authenticated user payload plus the "mfa" decision.
   */
  public function processCallback(string $provider_id, Request $request, SessionInterface $session): array {
    $provider = $this->providerManager->enabledProvider($provider_id);
    if (!$this->providerManager->isOidcProvider($provider) || $this->providerManager->isMockProvider($provider)) {
      throw new BadRequestHttpException('Provider does not use OpenID Connect.');
    }

    // Consume the pending login before anything else, so a failed callback
    // can never be retried with the same state, nonce and verifier.
    $key = $this->oidcKey($provider_id);
    $pending = $session->get($key);
    $session->remove($key);
    if (!$this->isPendingOidcLogin($pending)) {
      throw new AccessDeniedHttpException('Missing OIDC login correlation.');
    }
    if ($pending['created'] + self::OIDC_LOGIN_TTL < $this->time->getCurrentTime()) {
      throw new AccessDeniedHttpException('OIDC login attempt expired.');
    }

    // Error responses carry the state too (RFC 6749 section 4.1.2.1); check it
    // first so a foreign request cannot write provider errors into the log.
    $state = $request->query->get('state');
    if (!is_string($state) || !hash_equals($pending['state'], $state)) {
      throw new AccessDeniedHttpException('OIDC state does not match.');
    }
    $error = $request->query->get('error');
    if (is_string($error) && $error !== '') {
      $error = preg_replace('/[^a-z_]/', '', strtolower($error)) ?? '';
      throw new AccessDeniedHttpException(sprintf('Identity provider returned "%s".', $error));
    }
    $code = $request->query->get('code');
    if (!is_string($code) || $code === '') {
      throw new BadRequestHttpException('Missing authorization code.');
    }

    $tokens = $this->oidcClient->exchangeCode($provider_id, $provider, $code, $pending['verifier']);
    $claims = $this->oidcClient->validateIdToken($provider, $tokens['id_token'], $pending['nonce']);

    // The token hash and our own nonce are unique per login. The IdP session
    // id is not: it stays the same when the IdP reuses its SSO session.
    if (
      !$this->replayCache->checkAndStore(
        $provider_id,
        hash('sha256', $tokens['id_token']),
        $pending['nonce'],
        is_int($claims['exp'] ?? NULL) ? $claims['exp'] : NULL,
      )
    ) {
      throw new AccessDeniedHttpException('OIDC response was already processed.');
    }

    $attributes = OidcClaims::toAttributes($claims);
    $mfa = OidcClaims::hasMfa($attributes, $provider);
    // Decide before any account is created or linked.
    if (!empty($provider['require_mfa']) && !$mfa) {
      throw new AccessDeniedHttpException('This provider requires a second factor and the login did not prove one.');
    }
    $linking_provider = ['attribute_map' => OidcClaims::attributeMap($provider)] + $provider;
    $user = $this->identityLinker->authenticate(
      $provider_id,
      $linking_provider,
      (string) $claims['sub'],
      OidcClaims::withoutUnverifiedEmail($attributes, $linking_provider['attribute_map']),
      [],
    );
    $this->storeLastLogin($session, $provider_id, $user, [
      'protocol' => 'oidc',
      'mfa' => $mfa,
      'amr' => $attributes['amr'] ?? [],
      'identity_provider' => $attributes['identity_provider'][0] ?? NULL,
    ]);

    return $user + ['mfa' => $mfa];
  }

  /**
   * Consumes the RelayState that was stored when login started.
   */
  public function consumeRelayState(string $provider_id, SessionInterface $session): string {
    $key = $this->relayStateKey($provider_id);
    $relay_state = $session->get($key);
    $session->remove($key);

    return is_string($relay_state) && $relay_state !== '' ? $relay_state : '/';
  }

  /**
   * Performs a dev-only mock login with real Drupal session finalizing.
   *
   * @return array<string, mixed>
   *   Authenticated user payload.
   */
  public function mockLogin(string $provider_id, SessionInterface $session): array {
    $provider = $this->providerManager->enabledProvider($provider_id);
    $this->providerManager->assertMockAllowed($provider);
    if (!$this->providerManager->isMockProvider($provider)) {
      throw new BadRequestHttpException('Provider is not configured as a mock provider.');
    }

    $claims = is_array($provider['mock_claims'] ?? NULL) ? $provider['mock_claims'] : [];
    $name_id = is_scalar($claims['subject'] ?? NULL)
        ? (string) $claims['subject']
        : 'markaspot-demo-subject';
    unset($claims['subject']);

    $attributes = [];
    foreach ($claims as $key => $value) {
      if (!is_scalar($value) || trim((string) $value) === '') {
        continue;
      }
      $attributes[(string) $key] = [(string) $value];
    }

    $user = $this->identityLinker->authenticate($provider_id, $provider, $name_id, $attributes, $attributes);
    $this->storeLastLogin($session, $provider_id, $user, ['protocol' => 'mock', 'mfa' => NULL]);
    $this->logger->notice('Dev-only SSO mock login executed for @provider.', ['@provider' => $provider_id]);
    return $user;
  }

  /**
   * Stores the PKCE and replay material and returns the authorization URL.
   *
   * @param string $provider_id
   *   Provider machine name.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   Request session.
   */
  private function startOidcLogin(string $provider_id, array $provider, SessionInterface $session): string {
    $state = $this->randomToken();
    $nonce = $this->randomToken();
    $verifier = $this->randomToken();
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');

    // Build the URL first: a discovery failure then leaves no pending login.
    $url = $this->oidcClient->authorizationUrl($provider, $state, $nonce, $challenge);
    $session->set($this->oidcKey($provider_id), [
      'state' => $state,
      'nonce' => $nonce,
      'verifier' => $verifier,
      'created' => $this->time->getCurrentTime(),
    ]);

    return $url;
  }

  /**
   * Checks the shape of a pending OIDC login stored in the session.
   *
   * @phpstan-assert-if-true array{state: string, nonce: string, verifier: string, created: int} $pending
   */
  private function isPendingOidcLogin(mixed $pending): bool {
    if (!is_array($pending) || !is_int($pending['created'] ?? NULL)) {
      return FALSE;
    }
    foreach (['state', 'nonce', 'verifier'] as $key) {
      if (!is_string($pending[$key] ?? NULL) || $pending[$key] === '') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns 256 random bits as base64url without padding.
   */
  private function randomToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  /**
   * Processes the response through php-saml without trusting raw globals.
   */
  private function processResponse(Auth $auth, Request $request, ?string $request_id): void {
    // OneLogin php-saml reads the POST binding from $_POST. Keep the global
    // shim isolated and restore it immediately after processing.
      // phpcs:disable DrupalPractice.Variables.GetRequestData.SuperglobalAccessed,DrupalPractice.Variables.GetRequestData.SuperglobalAccessedWithVar
    $previous_post = $_POST;
    $_POST = [
      'SAMLResponse' => (string) $request->request->get('SAMLResponse'),
    ];
    $relay_state = $request->request->get('RelayState');
    if (is_string($relay_state) && $relay_state !== '') {
      $_POST['RelayState'] = $relay_state;
    }

    try {
      $auth->processResponse($request_id);
    }
    finally {
      $_POST = $previous_post;
    }
      // phpcs:enable DrupalPractice.Variables.GetRequestData.SuperglobalAccessed,DrupalPractice.Variables.GetRequestData.SuperglobalAccessedWithVar

    if ($auth->getErrors() !== []) {
      throw new AccessDeniedHttpException($auth->getLastErrorReason() ?: 'Invalid SSO response.');
    }
  }

  /**
   * Builds the local URL for dev mock login completion.
   *
   * @param string $provider_id
   *   Provider machine name.
   * @param string $relay_state
   *   Sanitized RelayState target.
   */
  private function mockLoginUrl(string $provider_id, string $relay_state): string {
    return '/auth/sso/' . rawurlencode($provider_id) . '/mock-idp?RelayState=' . rawurlencode($relay_state);
  }

  /**
   * Stores lightweight last-login metadata in the session.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   Request session.
   * @param string $provider_id
   *   Provider machine name.
   * @param array<string, mixed> $user
   *   Authenticated user payload.
   * @param array<string, mixed> $extra
   *   Protocol and MFA decision the login guard reads. "mfa" is NULL when the
   *   protocol cannot tell.
   */
  private function storeLastLogin(SessionInterface $session, string $provider_id, array $user, array $extra = []): void {
    $session->set('markaspot_sso.last_login', [
      'provider' => $provider_id,
      'uid' => $user['uid'],
      'assurance_level' => $user['assurance_level'] ?? NULL,
      'time' => $this->time->getCurrentTime(),
    ] + $extra);
  }

  /**
   * Builds the session key for AuthnRequest IDs.
   */
  private function requestIdKey(string $provider_id): string {
    return "markaspot_sso.$provider_id.request_id";
  }

  /**
   * Builds the session key for a pending OIDC login.
   */
  private function oidcKey(string $provider_id): string {
    return "markaspot_sso.$provider_id.oidc";
  }

  /**
   * Builds the session key for sanitized RelayState targets.
   */
  private function relayStateKey(string $provider_id): string {
    return "markaspot_sso.$provider_id.relay_state";
  }

}
