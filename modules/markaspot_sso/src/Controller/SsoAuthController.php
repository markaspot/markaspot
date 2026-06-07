<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\markaspot_sso\Service\SsoClientFactory;
use Drupal\markaspot_sso\Service\SsoLoginService;
use Drupal\markaspot_sso\Service\SsoProviderManager;
use Drupal\markaspot_sso\Service\SsoRelayStateValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Handles generic SSO service provider endpoints.
 */
final class SsoAuthController extends ControllerBase {

  /**
   * Constructs a SSO auth controller.
   */
  public function __construct(
    private readonly SsoProviderManager $providerManager,
    private readonly SsoClientFactory $clientFactory,
    private readonly SsoLoginService $loginService,
    private readonly SsoRelayStateValidator $relayState,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
          $container->get('markaspot_sso.provider_manager'),
          $container->get('markaspot_sso.client_factory'),
          $container->get('markaspot_sso.login_service'),
          $container->get('markaspot_sso.relay_state'),
          $container->get('logger.channel.markaspot_sso'),
      );
  }

  /**
   * Returns SP metadata XML for a provider.
   */
  public function metadata(string $provider): Response {
    $this->providerManager->provider($provider);
    try {
      $settings = $this->clientFactory->settings($provider, TRUE);
      $metadata = $settings->getSPMetadata();
      $errors = $settings->validateMetadata($metadata);
      if ($errors !== []) {
        throw new \RuntimeException(implode(', ', $errors));
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to build SSO metadata for @provider: @message', [
        '@provider' => $provider,
        '@message' => $exception->getMessage(),
      ]);
      throw new HttpException(500, 'SSO metadata is not available.');
    }

    return $this->noStore(new Response($metadata, 200, [
      'Content-Type' => 'application/samlmetadata+xml; charset=UTF-8',
    ]));
  }

  /**
   * Starts SP-initiated SSO login.
   */
  public function login(Request $request, string $provider): RedirectResponse {
    $this->providerManager->enabledProvider($provider);
    $relay_state = $this->relayState->sanitize(
          $provider,
          $request->query->get('returnTo') ?: $request->query->get('RelayState'),
      );

    try {
      $url = $this->loginService->startLogin($provider, $relay_state, $this->session($request));
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to start SSO login for @provider: @message', [
        '@provider' => $provider,
        '@message' => $exception->getMessage(),
      ]);
      throw new HttpException(500, 'SSO login is not available.');
    }

    $response = new RedirectResponse((string) $url);
    $this->noStore($response);
    return $response;
  }

  /**
   * Handles SSO ACS POST responses.
   */
  public function acs(Request $request, string $provider): RedirectResponse {
    $session = $this->session($request);
    try {
      $this->loginService->processAcs($provider, $request, $session);
      $target = $this->loginService->consumeRelayState($provider, $session);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Rejected SSO response for @provider: @message', [
        '@provider' => $provider,
        '@message' => $exception->getMessage(),
      ]);
      $target = $this->loginService->consumeRelayState($provider, $session);
      $response = new RedirectResponse($this->withSsoError($target));
      $this->noStore($response);
      return $response;
    }

    $response = new RedirectResponse($target);
    $this->noStore($response);
    return $response;
  }

  /**
   * Executes a dev-only mock login and returns to RelayState.
   */
  public function mockLogin(Request $request, string $provider): RedirectResponse {
    $session = $this->session($request);
    $this->consumeMockState($request, $provider, $session);
    $this->loginService->mockLogin($provider, $session);
    $target = $this->relayState->sanitize($provider, $request->query->get('RelayState'));
    $response = new RedirectResponse($target);
    $this->noStore($response);
    return $response;
  }

  /**
   * Shows the dev-only mock identity provider handoff.
   */
  public function mockIdp(Request $request, string $provider): Response {
    $session = $this->session($request);
    $provider_config = $this->providerManager->enabledProvider($provider);
    if (!$this->providerManager->isMockProvider($provider_config)) {
      throw new HttpException(400, 'Provider is not configured as a mock provider.');
    }
    $this->providerManager->assertMockAllowed($provider_config);
    $relay_state = $this->relayState->sanitize($provider, $request->query->get('RelayState'));

    $claims = is_array($provider_config['mock_claims'] ?? NULL)
        ? $provider_config['mock_claims']
        : [];
    $label = is_scalar($provider_config['label'] ?? NULL)
        ? (string) $provider_config['label']
        : 'SSO Demo';

    $rows = $this->mockClaimRows($claims);
    $attributes = '';
    foreach ($rows as $key => $value) {
      $attributes .= '<dt>' . Html::escape($key) . '</dt><dd>' . Html::escape($value) . '</dd>';
    }

    $login_url = '/auth/sso/' . rawurlencode($provider) . '/mock-login';
    $mock_state = bin2hex(random_bytes(16));
    $session->set($this->mockStateKey($provider), $mock_state);
    $cancel_url = $relay_state !== '' ? $relay_state : '/';
    $css = implode('', [
      ':root{color-scheme:light dark;',
      'font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}',
      'body{margin:0;background:#eef2f7;color:#0f172a;}',
      'main{min-height:100vh;display:grid;place-items:center;padding:32px;}',
      '.card{width:min(680px,100%);background:#fff;border:1px solid #d7dee8;border-radius:8px;',
      'box-shadow:0 18px 60px rgba(15,23,42,.14);overflow:hidden;}',
      '.top{background:#111827;color:#fff;padding:22px 28px;}',
      '.top small{display:block;margin-top:6px;color:#cbd5e1;}',
      '.body{padding:26px 28px 28px;}',
      '.notice{margin:0 0 18px;padding:12px 14px;border:1px solid #facc15;',
      'background:#fef9c3;color:#713f12;border-radius:6px;}',
      'dl{display:grid;grid-template-columns:minmax(120px,180px) 1fr;',
      'gap:10px 18px;margin:18px 0 24px;}',
      'dt{font-weight:700;color:#334155;}',
      'dd{margin:0;min-width:0;color:#0f172a;overflow-wrap:anywhere;}',
      '.actions{display:flex;gap:12px;flex-wrap:wrap;}',
      'button,a{appearance:none;border-radius:6px;padding:10px 16px;',
      'font-weight:700;text-decoration:none;cursor:pointer;}',
      'button{border:1px solid #1d4ed8;background:#1d4ed8;color:#fff;}',
      'a{border:1px solid #cbd5e1;color:#0f172a;background:#fff;}',
      '@media (max-width:520px){main{padding:16px}.top{padding:18px 20px}',
      '.body{padding:20px}dl{grid-template-columns:1fr;gap:4px;margin:16px 0 22px}',
      'dt{margin-top:10px}.actions{flex-direction:column}',
      'button,a{box-sizing:border-box;width:100%;text-align:center}}',
      '@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e5e7eb}',
      '.card{background:#111827;border-color:#334155}.body{color:#e5e7eb}',
      '.notice{background:#422006;border-color:#854d0e;color:#fde68a}',
      'dt{color:#cbd5e1}dd{color:#f8fafc}',
      'button{background:#60a5fa;border-color:#93c5fd;color:#0f172a}',
      'a{background:#111827;color:#f8fafc;border-color:#475569}}',
    ]);
    $html = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . Html::escape($label) . ' - Demo</title>'
        . '<style>' . $css . '</style></head><body><main><section class="card" aria-labelledby="title">'
        . '<div class="top"><h1 id="title">' . Html::escape($label) . '</h1>'
        . '<small>Lokaler Mock-Identitaetsanbieter fuer Mark-a-Spot</small></div>'
        . '<div class="body"><p class="notice">Dies ist kein echter Identitaetsanbieter. '
        . 'Die Seite simuliert einen externen SSO-Login fuer lokale Demos.</p>'
        . '<p>Mark-a-Spot fordert eine Demo-Identitaet an. '
        . 'Diese Attribute wuerden an Drupal uebergeben:</p>'
        . '<dl>' . $attributes . '</dl>'
        . '<form class="actions" method="get" action="' . Html::escape($login_url) . '">'
        . '<input type="hidden" name="RelayState" value="' . Html::escape($relay_state) . '">'
        . '<input type="hidden" name="mockState" value="' . Html::escape($mock_state) . '">'
        . '<button type="submit">Mit SSO Demo fortfahren</button>'
        . '<a href="' . Html::escape($cancel_url) . '">Abbrechen</a>'
        . '</form></div></section></main></body></html>';

    return $this->noStore(new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
    ]));
  }

  /**
   * Formats configured mock claims for the demo identity provider page.
   *
   * @param array<string, mixed> $claims
   *   Mock claims from provider config.
   *
   * @return array<string, string>
   *   Display labels and values.
   */
  private function mockClaimRows(array $claims): array {
    $labels = [
      'subject' => 'Stabile SSO-ID',
      'email' => 'E-Mail',
      'first_name' => 'Vorname',
      'last_name' => 'Nachname',
      'full_name' => 'Name',
      'birthdate' => 'Geburtsdatum',
      'assurance_level' => 'Vertrauensniveau',
    ];

    $rows = [];
    foreach ($labels as $key => $label) {
      $value = $claims[$key] ?? NULL;
      if (is_scalar($value) && trim((string) $value) !== '') {
        $rows[$label] = (string) $value;
      }
    }

    return $rows ?: ['Demo-Identitaet' => 'Max Mustermann'];
  }

  /**
   * Requires a mock IdP handoff state before completing mock login.
   */
  private function consumeMockState(Request $request, string $provider, SessionInterface $session): void {
    $expected = $session->get($this->mockStateKey($provider));
    $provided = $request->query->get('mockState');
    $session->remove($this->mockStateKey($provider));

    if (!is_string($expected) || !is_string($provided) || !hash_equals($expected, $provided)) {
      throw new HttpException(400, 'Missing or invalid SSO mock state.');
    }
  }

  /**
   * Builds the session key for mock IdP handoff state.
   */
  private function mockStateKey(string $provider): string {
    return 'markaspot_sso.' . $provider . '.mock_state';
  }

  /**
   * Adds the generic frontend SSO failure marker to a sanitized target.
   */
  private function withSsoError(string $target): string {
    $parts = UrlHelper::parse($target);
    $query = is_array($parts['query'] ?? NULL) ? $parts['query'] : [];
    $query['sso_error'] = '1';

    $url = (string) ($parts['path'] ?? '/');
    $query_string = UrlHelper::buildQuery($query);
    if ($query_string !== '') {
      $url .= '?' . $query_string;
    }
    if (!empty($parts['fragment'])) {
      $url .= '#' . $parts['fragment'];
    }

    return $url;
  }

  /**
   * Returns the request session or fails with a server error.
   */
  private function session(Request $request): SessionInterface {
    if (!$request->hasSession()) {
      throw new HttpException(500, 'SSO login requires a session.');
    }
    return $request->getSession();
  }

  /**
   * Adds no-store headers to auth responses.
   */
  private function noStore(Response $response): Response {
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
