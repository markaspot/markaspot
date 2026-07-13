<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the intentionally tiny passwordless maintenance-mode allowlist.
 *
 * @group markaspot_passwordless
 */
class MaintenanceRoutingTest extends UnitTestCase {

  /**
   * OTP login may bypass Core maintenance mode, but adjacent auth APIs may not.
   */
  public function testOnlyLoginEssentialAuthRoutesBypassMaintenanceMode(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $routes = Yaml::parse((string) file_get_contents($moduleRoot . '/markaspot_passwordless.routing.yml'));

    $allowed = [
      'markaspot_passwordless.request_code',
      'markaspot_passwordless.verify_code',
      'markaspot_passwordless.user_status',
    ];

    foreach ($routes as $name => $route) {
      $hasMaintenanceAccess = (bool) ($route['options']['_maintenance_access'] ?? FALSE);
      self::assertSame(in_array($name, $allowed, TRUE), $hasMaintenanceAccess, $name);
    }

    foreach ($allowed as $name) {
      self::assertTrue($routes[$name]['options']['_maintenance_access']);
    }
  }

}
