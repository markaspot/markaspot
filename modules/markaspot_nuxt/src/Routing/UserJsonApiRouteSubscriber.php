<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Restricts every JSON:API user resource route to logged-in accounts.
 *
 * Covers the collection, individual, related and relationship routes of the
 * user--user resource for all methods. Anonymous requests get 403 before
 * entity access runs; authenticated requests continue to entity and field
 * access as before. User references inside other resources are hidden from
 * anonymous requests by markaspot_nuxt_entity_field_access().
 */
class UserJsonApiRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $route) {
      if ($route->getDefault('resource_type') === 'user--user') {
        $route->setRequirement('_user_is_logged_in', 'TRUE');
      }
    }
  }

}
