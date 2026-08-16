<?php

namespace Drupal\markaspot_open311\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Allows API-key authentication on Drupal's private file routes.
 */
class PrivateFileRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $authenticationProviders = ['cookie', 'api_key_auth'];

    foreach (['system.files', 'system.private_file_download'] as $routeName) {
      if ($route = $collection->get($routeName)) {
        $route->setOption('_auth', $authenticationProviders);
      }
    }
  }

}
