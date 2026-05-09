<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Verifies the public frontpage responds with 2xx or 3xx via HttpKernel.
 *
 * Sub-request via HttpKernel is intentional: it exercises Drupal's full
 * routing + controller pipeline without leaving the process boundary, so
 * the smoke check stays fast and is independent of nginx/traefik. Real
 * end-to-end HTTP is the job of the external bash wrapper.
 *
 * @SmokeCheck(
 *   id = "http_frontend_public",
 *   label = @Translation("HTTP frontpage"),
 *   severity = "error",
 *   category = "http_sanity",
 *   description = @Translation("GET / via HttpKernel must respond 2xx or 3xx."),
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

    if ($status >= 200 && $status < 400) {
      return $this->pass(sprintf('GET / responded %d.', $status), $evidence, $mode);
    }
    return $this->fail(1, sprintf('GET / responded %d.', $status), $evidence, $mode);
  }

}
