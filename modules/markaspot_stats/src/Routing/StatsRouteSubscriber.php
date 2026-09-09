<?php

declare(strict_types=1);

namespace Drupal\markaspot_stats\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Keeps historical Views exports from replacing the protected stats handlers.
 */
final class StatsRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $handlers = [
      'markaspot_stats.status' => ['/stats/status', 'getStatusStats'],
      'markaspot_stats.api_status' => ['/api/stats/status', 'getStatusStats'],
      'markaspot_stats.categories' => ['/stats/categories', 'getCategoryStats'],
      'markaspot_stats.api_categories' => ['/api/stats/categories', 'getCategoryStats'],
      'markaspot_stats.categories_hierarchical' => ['/stats/categories/hierarchical', 'getHierarchicalCategoryStats'],
    ];
    foreach ($handlers as $name => [$path, $method]) {
      $route = $collection->get($name);
      if ($route === NULL
        || ($route->getPath() !== $path && !str_starts_with($route->getPath(), $path . '/{'))) {
        continue;
      }
      // Views may reuse the module route name while replacing its controller,
      // requirements and defaults with a legacy rest_export display.
      $route->setPath($path);
      $route->setDefaults([
        '_controller' => '\Drupal\markaspot_stats\Controller\StatsController::' . $method,
        '_format' => 'json',
      ]);
      $route->setRequirements([
        '_custom_access' => 'markaspot_nuxt.feature_flag_access_check:check',
      ]);
      $route->setOption('_feature_flag', 'features.statistics');
      $route->setOption('_feature_flag_default', FALSE);
      $route->setOption('no_cache', TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Core Views replaces routes at -175. The guarded endpoints win after it.
    return [RoutingEvents::ALTER => ['onAlterRoutes', -200]];
  }

}
