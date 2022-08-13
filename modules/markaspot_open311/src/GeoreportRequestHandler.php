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

    // $request = RequestSanitizer::sanitize($request, [], TRUE);
    // $plugin = $route_match->getRouteObject()->getDefault('_plugin');
    $method = strtolower($request->getMethod());
    $resource = $_rest_resource_config->getResourcePlugin();

    $received = $request->getContent();
    // var_dump($received);
    if (!empty($received)) {
      $format = $request->getContentType();

      // Only allow serialization formats that are explicitly configured. If no
      // formats are configured allow all and hope that the serializer knows the
      // format. If the serializer cannot handle it an exception will be thrown
      // that bubbles up to the client.
      // $config = $this->config;.
      $method_settings = $_rest_resource_config->get('configuration')[$request->getMethod()];

      $request_all = $request->request->all();

      if (empty($method_settings['supported_formats']) || in_array($format, $method_settings['supported_formats'])) {
        try {

        }
        catch (UnexpectedValueException $e) {
          $error['error'] = $e->getMessage();
          $content = $this->serializer->serialize($error, $format);
          return new Response($content, 400, ['Content-Type' => $request->getMimeType($format)]);
        }
      }
      else {
        throw new UnsupportedMediaTypeHttpException();
      }
    }
    $query_params = $request->query->all();
    $request_data = $request_all ?? $query_params;
    // Determine the request parameters that should be passed to the resource
    // plugin.
    $route_parameters = $route_match->getParameters();
    $parameters = [];
    // Filter out all internal parameters starting with "_".
    foreach ($route_parameters as $key => $parameter) {
      if ($key[0] !== '_') {
        $parameters[] = $parameter;
      }
    }
    // $id_suffix = isset($parameter) ? explode('&', $parameter) : NULL;
    // Invoke the operation on the resource plugin.
    // All REST routes are restricted to exactly one format, so instead of
    // parsing it out of the Accept headers again, we can simply retrieve the
    // format requirement. If there is no format associated, just pick JSON.
    // $route_match->getRouteObject()->getRequirement('_format') ?: 'json';.
    $current_path = $this->currentPath->getPath();

    if (strstr($current_path, 'georeport')) {
      $format = pathinfo($current_path, PATHINFO_EXTENSION);
    }

    $result = call_user_func_array([
      $resource, $method,
    ], array_merge($parameters, [$request_data, $request]));
    // var_dump($this->serializer);.
    $result = $this->serializer->serialize($result, $format);

    $response = new Response();

    $response->headers->set('Content-Type', $request->getMimeType($format));
    $response->setContent($result);
    return $response;
  }

}
