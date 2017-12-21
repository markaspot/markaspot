<?php

namespace Drupal\markaspot_open311\Routing;

use Drupal\Core\Routing\RouteFilterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides a route filter, which filters by the request format.
 */
class GeoreportRequestFormatRouteFilter implements RouteFilterInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(Route $route) {
    // Return $route->hasRequirement('_format');.
  }

  /**
   * {@inheritdoc}
   */
  public function filter(RouteCollection $collection, Request $request) {

    $current_path = \Drupal::service('path.current')->getPath();
    if (strstr($current_path, 'georeport')) {
      $format = $request->getRequestFormat('html');

    }
    else {
      $format = $request->getRequestFormat('html');
    }

    /** @var \Symfony\Component\Routing\Route $route */
    foreach ($collection as $name => $route) {
      // If the route has no _format specification, we move it to the end. If it
      // does, then no match means the route is removed entirely.
      if ($supported_formats = array_filter(explode('|', $route->getRequirement('_format')))) {
        if (!in_array($format, $supported_formats)) {
          $collection->remove($name);
        }
      }
      else {
        $collection->add($name, $route);
      }
    }

    if (count($collection)) {
      return $collection;
    }

    // We do not throw a
    // \Symfony\Component\Routing\Exception\ResourceNotFoundException here
    // because we don't want to return a 404 status code, but rather a 406.
    throw new NotAcceptableHttpException("No route found for the specified format $format.");
  }

}
