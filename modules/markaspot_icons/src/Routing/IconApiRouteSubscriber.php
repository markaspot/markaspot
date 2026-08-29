<?php

declare(strict_types=1);

namespace Drupal\markaspot_icons\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Restricts local Iconify endpoints to administrative sessions.
 */
class IconApiRouteSubscriber extends RouteSubscriberBase {

  /**
   * Collections supported by the local taxonomy icon picker.
   */
  private const SUPPORTED_COLLECTIONS = '(?:'
    . 'lucide|heroicons|fa6-solid|fa6-regular|'
    . 'tabler|phosphor|ph'
    . ')';

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route_names = [
      'iconify_field.api.collections',
      'iconify_field.api.icons',
      'fa_icon_class.api.render',
    ];

    foreach ($route_names as $route_name) {
      if (!$route = $collection->get($route_name)) {
        continue;
      }

      $requirements = $route->getRequirements();
      unset($requirements['_access']);
      $requirements['_permission'] = 'access administration pages';

      if ($route_name === 'iconify_field.api.icons') {
        $requirements['collection'] = self::SUPPORTED_COLLECTIONS;
      }
      elseif ($route_name === 'fa_icon_class.api.render') {
        $requirements['icon'] = self::SUPPORTED_COLLECTIONS . ':[a-z0-9_-]+';
      }

      $route->setRequirements($requirements);
    }
  }

}
