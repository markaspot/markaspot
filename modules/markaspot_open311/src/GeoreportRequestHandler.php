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

    $method = strtolower($request->getMethod());
    $resource = $_rest_resource_config->getResourcePlugin();

    $received = $request->getContent();
    if (!empty($received)) {
      $format = $request->getContentTypeFormat(); // Updated here

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

    $route_parameters = $route_match->getParameters();
    $parameters = [];
    foreach ($route_parameters as $key => $parameter) {
      if ($key[0] !== '_') {
        $parameters[] = $parameter;
      }
    }

    $current_path = $this->currentPath->getPath();

    if (strstr($current_path, 'georeport')) {
      $format = pathinfo($current_path, PATHINFO_EXTENSION);
    }

    $result = call_user_func_array([
      $resource, $method,
    ], array_merge($parameters, [$request_data, $request]));

    $result = $this->serializer->serialize($result, $format);

    $response = new Response();

    $response->headers->set('Content-Type', $request->getMimeType($format));
    $response->setContent($result);
    return $response;
  }

}
