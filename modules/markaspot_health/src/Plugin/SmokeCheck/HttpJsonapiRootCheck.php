<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Verifies /jsonapi (root document) responds 200 with a JSON body.
 *
 * The JSON:API root link document is the discovery surface frontends rely on
 * to enumerate resource types. If JSON:API rebuild fails (e.g. corrupted
 * bundle field map), this route 5xx's. A fast smoke gate before slow per-
 * resource probes.
 *
 * @SmokeCheck(
 *   id = "http_jsonapi_root",
 *   label = @Translation("HTTP /jsonapi"),
 *   severity = "error",
 *   category = "http_sanity",
 *   description = @Translation("GET /jsonapi via HttpKernel must respond 200."),
 *   fix_hint = @Translation("Check the bundle_field_map_populated check; corrupted JSON:API discovery is the typical cause."),
 * )
 */
class HttpJsonapiRootCheck extends SmokeCheckPluginBase {

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
    $request = Request::create('/jsonapi', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/vnd.api+json']);
    $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
    $status = $response->getStatusCode();
    $contentType = (string) $response->headers->get('content-type', '');
    $evidence = [
      'status_code' => $status,
      'content_type' => $contentType,
    ];

    if ($status !== 200) {
      return $this->fail(1, sprintf('GET /jsonapi responded %d, expected 200.', $status), $evidence, $mode);
    }
    if (!str_contains($contentType, 'application/vnd.api+json') && !str_contains($contentType, 'application/json')) {
      return $this->fail(1, sprintf('GET /jsonapi returned content-type %s, expected JSON.', $contentType), $evidence, $mode);
    }
    return $this->pass('GET /jsonapi responded 200 with JSON body.', $evidence, $mode);
  }

}
