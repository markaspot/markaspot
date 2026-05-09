<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Verifies the Drupal root route responds without a server-side crash.
 *
 * Mark-a-Spot's Drupal backend is admin-only by architecture: the public
 * citizen surface is the Nuxt frontend, not Drupal-/. Anon hitting /
 * therefore legitimately returns 401 or 403 on hardened tenants where
 * "access content" was revoked from the anonymous role; on tenants that
 * still grant anon "access content" / responds 200 with the Drupal stub
 * front page. Both states are operationally OK and pass this check; the
 * only thing that fails it is a 5xx, which signals a Drupal boot crash.
 *
 * Sub-request via HttpKernel is intentional: it exercises Drupal's full
 * routing + controller pipeline without leaving the process boundary, so
 * the smoke check stays fast and is independent of nginx/traefik. Real
 * end-to-end HTTP is the job of the external bash wrapper.
 *
 * @SmokeCheck(
 *   id = "http_frontend_public",
 *   label = @Translation("HTTP Drupal root"),
 *   severity = "error",
 *   category = "http_sanity",
 *   description = @Translation("GET / via HttpKernel must respond without a 5xx; 200/3xx/401/403 all pass."),
 *   fix_hint = @Translation("Check Drupal logs for 5xx errors on the front route."),
 * )
 */
class HttpFrontendPublicCheck extends SmokeCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected HttpKernelInterface $httpKernel,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_kernel'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);
    $request = Request::create('/', 'GET');
    $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
    $status = $response->getStatusCode();
    $evidence = ['status_code' => $status];

    // 5xx = Drupal boot or controller crash → real failure.
    // 200-399 = Drupal rendered something (front page or redirect).
    // 401/403 = Drupal is up but anon is denied; correct on admin-only
    // tenants where "access content" was revoked from the anonymous role.
    // Other 4xx (e.g. 404 on /) is unusual and reported as failure so an
    // operator can investigate routing health.
    if ($status >= 500) {
      return $this->fail(1, sprintf('GET / responded %d (server crash).', $status), $evidence, $mode);
    }
    if (($status >= 200 && $status < 400) || $status === 401 || $status === 403) {
      return $this->pass(sprintf('GET / responded %d.', $status), $evidence, $mode);
    }
    return $this->fail(1, sprintf('GET / responded %d (unexpected non-5xx, non-allow).', $status), $evidence, $mode);
  }

}
