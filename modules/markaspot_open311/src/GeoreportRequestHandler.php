<?php

namespace Drupal\markaspot_open311;

use Drupal\Core\Render\RenderContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Security\RequestSanitizer;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Drupal\rest\ResourceResponse;
use \Drupal\Component\Utility\Xss;

/**
 * Acts as intermediate request forwarder for resource plugins.
 */
class GeoreportRequestHandler implements ContainerAwareInterface {

  use ContainerAwareTrait;

  /**
   * Handles a web API request.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function handle(RouteMatchInterface $route_match, Request $request) {

    // $request = RequestSanitizer::sanitize($request, [], TRUE);
    $plugin = $route_match->getRouteObject()->getDefault('_plugin');
    $method = strtolower($request->getMethod());

    $resource = $this->container
      ->get('plugin.manager.rest')
      ->getInstance(array('id' => $plugin));
    // Deserialize incoming data if available.
    $serializer = $this->container->get('serializer');
    // Get body for Post requests.
    $received = $request->getContent();
    $request_data = NULL;
    $format = '';
    if (!empty($received)) {
      $format = $request->getContentType();

      // Only allow serialization formats that are explicitly configured. If no
      // formats are configured allow all and hope that the serializer knows the
      // format. If the serializer cannot handle it an exception will be thrown
      // that bubbles up to the client.
      $config = $this->container->get('config.factory')->get('rest.settings')->get('resources');
      $method_settings = $config[$plugin][$request->getMethod()];
      $request_all = $request->request->all();

      if (empty($method_settings['supported_formats']) || in_array($format, $method_settings['supported_formats'])) {
        $definition = $resource->getPluginDefinition();
        // $class = $definition['serialization_class'];.
        try {
          $service_request = [];
          // Create service_request data from parameters;.
          $qs = explode('&', Request::normalizeQueryString($received));
        }
        catch (UnexpectedValueException $e) {
          $error['error'] = $e->getMessage();
          $content = $serializer->serialize($error, $format);
          return new Response($content, 400, array('Content-Type' => $request->getMimeType($format)));
        }
      }
      else {
        throw new UnsupportedMediaTypeHttpException();
      }
    }
    $query_params = $request->query->all();
    $request_data = isset($request_all) ? $request_all : $query_params ;
    // $request_data = array_map('trim', $request_data);

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
    $id_suffix = isset($parameter) ? explode('&', $parameter) : NULL;

    // Invoke the operation on the resource plugin.
    // All REST routes are restricted to exactly one format, so instead of
    // parsing it out of the Accept headers again, we can simply retrieve the
    // format requirement. If there is no format associated, just pick JSON.
    // $route_match->getRouteObject()->getRequirement('_format') ?: 'json';.
    $current_path = \Drupal::service('path.current')->getPath();

    if (strstr($current_path, 'georeport')) {
      $format = pathinfo($current_path, PATHINFO_EXTENSION);
    }

    // Handle id params requests.
    if (isset($id_suffix) && $id_suffix[0] != '') {
      // $route_match->getRouteObject()->getRequirement('_format') ?: 'json';
      // All about this discussion:
      // http://www.metaltoad.com/blog/why-drupal-8-wont-ship-with-REST-content-negotiation
      $format_array = explode('.', $id_suffix[0]);
      $format = $format_array[1];
    }

    try {
      $result = call_user_func_array(array($resource, $method), array_merge($parameters, array($request_data, $request)));
    }
    catch (HttpException $e) {
      $error['error'] = $e->getMessage();
      $content = $serializer->serialize($error, $format);
      // Add the default content type, but only if the headers from the
      // exception have not specified it already.
      $headers = $e->getHeaders() + array('Content-Type' => $request->getMimeType($format));
      return new Response($content, $e->getStatusCode(), $headers);
    }
    $result = $serializer->serialize($result, $format);

    $response = new Response();
    $response->headers->set('Content-Type', $request->getMimeType($format));
    $response->setContent($result);
    return $response;
  }

}
