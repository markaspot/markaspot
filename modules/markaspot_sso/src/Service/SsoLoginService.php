<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use OneLogin\Saml2\Auth;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Coordinates SSO login starts, ACS processing, and dev mock login.
 */
final class SsoLoginService {

  /**
   * Constructs the SSO login service.
   */
  public function __construct(
    private readonly SsoProviderManager $providerManager,
    private readonly SsoClientFactory $clientFactory,
    private readonly SsoReplayCache $replayCache,
    private readonly SsoIdentityLinker $identityLinker,
    private readonly LoggerInterface $logger,
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

    $this->storeLastLogin($session, $provider_id, $user);
    $session->remove($request_id_key);
    return $user;
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
    $this->storeLastLogin($session, $provider_id, $user);
    $this->logger->notice('Dev-only SSO mock login executed for @provider.', ['@provider' => $provider_id]);
    return $user;
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
   */
  private function storeLastLogin(SessionInterface $session, string $provider_id, array $user): void {
    $session->set('markaspot_sso.last_login', [
      'provider' => $provider_id,
      'uid' => $user['uid'],
      'assurance_level' => $user['assurance_level'] ?? NULL,
      'time' => time(),
    ]);
  }

  /**
   * Builds the session key for AuthnRequest IDs.
   */
  private function requestIdKey(string $provider_id): string {
    return "markaspot_sso.$provider_id.request_id";
  }

  /**
   * Builds the session key for sanitized RelayState targets.
   */
  private function relayStateKey(string $provider_id): string {
    return "markaspot_sso.$provider_id.relay_state";
  }

}
