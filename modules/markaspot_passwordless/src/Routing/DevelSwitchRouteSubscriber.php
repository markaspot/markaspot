<?php

declare(strict_types=1);

namespace Drupal\markaspot_passwordless\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Applies the profile's impersonation target rules to Devel routes.
 */
class DevelSwitchRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['devel.switch', 'devel.switch_user'] as $routeName) {
      $route = $collection->get($routeName);
      if ($route !== NULL) {
        $route->setRequirement(
          '_custom_access',
          'markaspot_passwordless.devel_switch_target_access:access',
        );
      }
    }
  }

}
