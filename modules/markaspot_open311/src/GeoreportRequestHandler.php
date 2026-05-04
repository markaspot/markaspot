<?php

namespace Drupal\markaspot_open311;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\rest\RestResourceConfigInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Acts as intermediate request forwarder for resource plugins.
 */
class GeoreportRequestHandler implements ContainerInjectionInterface {

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface|\Symfony\Component\Serializer\Encoder\DecoderInterface
   */
  protected $serializer;

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $currentPath;

  /**
   * Implements constructor for create class object.
   *
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path service.
   */
  public function __construct(SerializerInterface $serializer, CurrentPathStack $current_path) {
    $this->serializer = $serializer;
    $this->currentPath = $current_path;
  }

  /**
   * Create dependency injection for class.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('serializer'),
      $container->get('path.current'),
    );
  }

  /**
   * Handles a web API request.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param \Drupal\rest\RestResourceConfigInterface $_rest_resource_config
   *   The REST resource config entity.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function handle(RouteMatchInterface $route_match, Request $request, RestResourceConfigInterface $_rest_resource_config) {
    // Start timing for performance measurement.
    $startTime = microtime(TRUE);

    $method = strtolower($request->getMethod());
    $resource = $_rest_resource_config->getResourcePlugin();

    // Process request content for POST/PUT methods.
    $received = $request->getContent();
    $request_all = [];

    if (!empty($received)) {
      $format = $request->getContentTypeFormat();
      $method_settings = $_rest_resource_config->get('configuration')[$request->getMethod()] ?? [];

      // Validate supported formats.
      if (!empty($method_settings['supported_formats']) && !in_array($format, $method_settings['supported_formats'])) {
        throw new UnsupportedMediaTypeHttpException();
      }

      // Deserialize JSON content if present.
      if ($format === 'json' && !empty($received)) {
        try {
          $request_all = $this->serializer->decode($received, $format);
        }
        catch (UnexpectedValueException $e) {
          // Fall back to form parameters.
          $request_all = $request->request->all();
        }
      }
      else {
        // For non-JSON formats (form, xml), use request parameters.
        $request_all = $request->request->all();
      }
    }

    // Get query parameters and merge with request body for complete data.
    $query_params = $request->query->all();
    $request_data = $request_all ?: $query_params;

    // Process route parameters.
    $route_parameters = $route_match->getParameters();
    $parameters = [];
    foreach ($route_parameters as $key => $parameter) {
      if ($key[0] !== '_') {
        $parameters[] = $parameter;
      }
    }

    // Determine format from URL extension.
    $current_path = $this->currentPath->getPath();
    if (strstr($current_path, 'georeport')) {
      $ext = pathinfo($current_path, PATHINFO_EXTENSION);
      if (!empty($ext)) {
        $format = $ext;
      }
    }
    // Default to json when no format could be determined (e.g. POST
    // endpoints without a file extension in the URL).
    if (empty($format)) {
      $format = 'json';
    }

    // Call the resource method.
    $result = call_user_func_array([
      $resource, $method,
    ], array_merge($parameters, [$request_data, $request]));

    // Add cache headers for GET requests to improve client-side caching.
    $response = new Response();
    if ($method === 'get' && !isset($query_params['debug'])) {
      if ($this->isCredentialedRequest($request)) {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Cache-Policy', 'private, no-store');
      }
      else {
        // Cache for 3 minutes.
        $response->setMaxAge(180);
        $response->setSharedMaxAge(180);
        $response->headers->set('X-Cache-Policy', 'public, max-age=180');
      }
    }

    // Serialize response.
    // Structured responses with metadata (e.g., meta=true) contain
    // associative arrays like {requests: [...], meta: {total: N}}.
    // Drupal's Serializer normalizer chain flattens these to indexed
    // arrays, destroying the wrapper structure. For JSON, encode directly.
    if (is_array($result) && isset($result['meta']) && $format === 'json') {
      $serializedResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      // Standard REST pagination header for any consumer.
      if (isset($result['meta']['total'])) {
        $response->headers->set('X-Total-Count', (string) $result['meta']['total']);
      }
    }
    else {
      $serializedResult = $this->serializer->serialize($result, $format);
    }

    // Set the appropriate Content-Type header.
    $response->headers->set('Content-Type', $request->getMimeType($format));
    $response->setContent($serializedResult);

    // Add performance timing header for monitoring.
    $executionTime = round((microtime(TRUE) - $startTime) * 1000, 2);
    $response->headers->set('X-API-Execution-Time', $executionTime . 'ms');

    return $response;
  }

  /**
   * Detects requests whose response must not be stored by shared caches.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE when credentials are present.
   */
  protected function isCredentialedRequest(Request $request): bool {
    $queryString = $request->getQueryString() ?: '';

    return str_contains($queryString, 'api_key')
      || $request->query->has('api_key')
      || $request->request->has('api_key')
      || $request->headers->has('api_key')
      || $request->headers->has('api-key')
      || $request->headers->has('apikey')
      || $request->headers->has('x-api-key')
      || $request->headers->has('authorization')
      || $request->headers->has('cookie')
      || $request->server->has('HTTP_API_KEY');
  }

}
