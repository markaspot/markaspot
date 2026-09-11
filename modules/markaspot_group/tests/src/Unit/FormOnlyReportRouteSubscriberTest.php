<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\markaspot_group\Routing\FormOnlyReportRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests guards on actual route definitions, including feedback mutations.
 *
 * @group markaspot_group
 */
class FormOnlyReportRouteSubscriberTest extends UnitTestCase {

  /**
   * Tests all selected routes keep their existing permission and method rules.
   */
  public function testActualRoutesRetainExistingRequirements(): void {
    $collection = new RouteCollection();
    $original = [];
    $modules = dirname(__DIR__, 4);
    foreach (['markaspot_service_provider', 'markaspot_feedback', 'markaspot_nuxt', 'markaspot_ai'] as $module) {
      foreach (Yaml::parseFile("$modules/$module/$module.routing.yml") as $name => $definition) {
        $route = new Route($definition['path'], $definition['defaults'] ?? [], $definition['requirements'] ?? [], $definition['options'] ?? [], '', [], $definition['methods'] ?? []);
        $collection->add($name, $route);
        $original[$name] = clone $route;
      }
    }
    $subscriber = new FormOnlyReportRouteSubscriber();
    (new \ReflectionMethod($subscriber, 'alterRoutes'))->invoke($subscriber, $collection);
    $token_authenticated = [
      'markaspot_service_provider.response_form',
      'markaspot_service_provider.rest_update',
      'markaspot_service_provider.rest_get',
      'markaspot_service_provider.rest_auth',
      'markaspot_feedback.form',
      'markaspot_feedback.rest',
      'markaspot_feedback.get',
    ];
    $protected = [
      'markaspot_nuxt.vote_sum',
      'markaspot_ai.sentiment_analyze',
    ];
    $global = [
      'markaspot_ai.processing_status',
      'markaspot_ai.processing_queue',
      'markaspot_ai.processing_run',
      'markaspot_ai.attributes.status',
      'markaspot_ai.attributes.queue',
    ];
    $actual_protected = [];
    $actual_global = [];
    foreach ($collection as $name => $route) {
      $expected = $original[$name]->getRequirements();
      if (in_array($name, $protected, TRUE)) {
        $expected['_form_only_report_access'] = 'TRUE';
      }
      if (in_array($name, $global, TRUE)) {
        $expected['_form_only_global_report_access'] = 'TRUE';
      }
      $this->assertSame($expected, $route->getRequirements(), $name);
      $this->assertSame($original[$name]->getMethods(), $route->getMethods(), $name);
      $this->assertSame($original[$name]->getDefaults(), $route->getDefaults(), $name);
      if ($route->getRequirement('_form_only_report_access') === 'TRUE') {
        $actual_protected[] = $name;
      }
      if ($route->getRequirement('_form_only_global_report_access') === 'TRUE') {
        $actual_global[] = $name;
      }
    }
    $this->assertSame($protected, $actual_protected);
    $this->assertSame($global, $actual_global);
    foreach (array_merge($token_authenticated, $protected, $global) as $name) {
      $this->assertNotNull($collection->get($name), $name);
    }
    foreach ($token_authenticated as $name) {
      $this->assertNull(
        $collection->get($name)?->getRequirement('_form_only_report_access'),
        $name,
      );
    }
  }

  /**
   * Named-route checks must skip the guard without an incoming request.
   */
  public function testGlobalAccessDeclaresIncomingRequestRequirement(): void {
    $services = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot_group.services.yml');
    $tags = $services['services']['markaspot_group.form_only_global_report_access']['tags'];
    $this->assertSame([
      'name' => 'access_check',
      'applies_to' => '_form_only_global_report_access',
      'needs_incoming_request' => TRUE,
    ], $tags[0]);
  }

}
