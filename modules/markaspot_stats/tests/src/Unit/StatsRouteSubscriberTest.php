<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_stats\Unit;

use Drupal\Core\Routing\RoutingEvents;
use Drupal\markaspot_stats\Routing\StatsRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests legacy Views displays cannot take over protected statistics routes.
 *
 * @group markaspot_stats
 */
class StatsRouteSubscriberTest extends UnitTestCase {

  /**
   * Reproduces the installed legacy Views override, preserving other routes.
   */
  public function testLegacyViewControllerIsReplacedAfterViewsAlter(): void {
    $routes = new RouteCollection();
    foreach ([
      'markaspot_stats.status' => '/stats/status',
      'markaspot_stats.categories' => '/stats/categories',
      'markaspot_stats.categories_hierarchical' => '/stats/categories/hierarchical',
      'markaspot_stats.api_status' => '/api/stats/status',
      'markaspot_stats.api_categories' => '/api/stats/categories',
    ] as $name => $path) {
      $legacy_path = in_array($name, ['markaspot_stats.status', 'markaspot_stats.categories'], TRUE)
        ? $path . '/{arg_0}/{arg_1}/{arg_2}' : $path;
      $routes->add($name, new Route($legacy_path, [
        '_controller' => 'Drupal\views\Routing\ViewPageController::handle',
        'view_id' => 'stats',
        'display_id' => 'rest_export_2',
        'arg_0' => 'all',
        'arg_1' => 'all',
        'arg_2' => 'all',
      ], ['_permission' => 'access content']));
    }
    $other = new Route('/other', ['_controller' => 'other::handler']);
    $routes->add('other', $other);
    $subscriber = new StatsRouteSubscriber();
    (new \ReflectionMethod($subscriber, 'alterRoutes'))->invoke($subscriber, $routes);
    foreach ($routes as $name => $route) {
      if ($name === 'other') {
        $this->assertSame('other::handler', $route->getDefault('_controller'));
        continue;
      }
      $this->assertStringStartsWith('\Drupal\markaspot_stats\Controller\StatsController::', $route->getDefault('_controller'));
      $this->assertNull($route->getDefault('view_id'));
      $this->assertStringNotContainsString('{arg_', $route->getPath());
      $this->assertSame('markaspot_nuxt.feature_flag_access_check:check', $route->getRequirement('_custom_access'));
      $this->assertNull($route->getRequirement('_permission'));
      $this->assertTrue($route->getOption('no_cache'));
    }
    $this->assertLessThan(-175, StatsRouteSubscriber::getSubscribedEvents()[RoutingEvents::ALTER][1]);
  }

}
