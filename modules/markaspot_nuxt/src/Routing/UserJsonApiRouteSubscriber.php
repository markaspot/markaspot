<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds an anonymous access gate to JSON:API user resource routes.
 */
class UserJsonApiRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $route) {
      if ($route->getDefault('resource_type') !== 'user--user') {
        continue;
      }

      $methods = $route->getMethods();
      if ($methods !== [] && !in_array('GET', $methods, TRUE)) {
        continue;
      }

      $route->setRequirement('_custom_access', 'markaspot_nuxt.user_jsonapi_access_check:access');
    }
  }

}
