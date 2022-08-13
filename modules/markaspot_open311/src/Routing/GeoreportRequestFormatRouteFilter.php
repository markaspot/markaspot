<?php

namespace Drupal\markaspot_open311\Routing;

use Drupal\Core\Routing\FilterInterface;
use Drupal\Core\Path\CurrentPathStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides a route filter, which filters by the request format.
 */
class GeoreportRequestFormatRouteFilter implements FilterInterface {

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * Implements constructor for create class object.
   *
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path service.
   */
  public function __construct(CurrentPathStack $current_path) {
    $this->currentPath = $current_path;
  }

  /**
   * Create dependency injection for class.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('path.current'),
     );
  }

  /**
   * {@inheritdoc}
   */
  public function filter(RouteCollection $collection, Request $request) {
    // $subscriber->setCurrentPath($container->get('path.current'));
    $current_path = $this->currentPath->getPath();
    // var_dump($current_path);
    // die;
    // $current_path = \Drupal::service('path.current')->getPath();
    if (!strstr($current_path, 'georeport/v2')) {
      $format = $request->getRequestFormat('html');
    }
    else {
      /** @var \Symfony\Component\Routing\Route $route */
      foreach ($collection as $name => $route) {
        $suffix = isset($current_path) ? explode('.', $current_path) : NULL;
        $format = $suffix[1];
        // If the route has no _format specification, we move it to the end.
        // If it does, then no match means the route is removed entirely.
        if ($supported_formats = array_filter(explode('|', $route->getRequirement('_format')))) {
          if (!in_array($format, $supported_formats)) {
            $collection->remove($name);
          }
        }
        else {
          $collection->add($name, $route);
        }
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
