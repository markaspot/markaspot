<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_health\Controller\HealthCheckController;
use Drupal\markaspot_health\HealthCheckPluginManager;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the /api/admin/health-check endpoint wiring.
 *
 * Skips when the markaspot_group dependency tree cannot be installed in the
 * kernel test environment; in that case the route, permission, and plugin
 * manager are still asserted, which is the load-bearing wiring this submodule
 * owns.
 *
 * @group markaspot_health
 */
class HealthCheckEndpointTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'markaspot_health',
  ];

  /**
   * Tests that the plugin manager service is available.
   */
  public function testPluginManagerService(): void {
    $manager = $this->container->get('plugin.manager.markaspot_health_check');
    $this->assertInstanceOf(HealthCheckPluginManager::class, $manager);
  }

  /**
   * Tests that the permission is registered.
   */
  public function testPermissionRegistered(): void {
    /** @var \Drupal\user\PermissionHandlerInterface $handler */
    $handler = $this->container->get('user.permissions');
    $permissions = $handler->getPermissions();
    $this->assertArrayHasKey('view health checks', $permissions);
    $this->assertTrue($permissions['view health checks']['restrict access']);
  }

  /**
   * Tests the controller produces the documented JSON response shape.
   *
   * Uses a stub plugin manager so the test does not depend on markaspot_group.
   */
  public function testControllerResponseShape(): void {
    $resultPassed = new HealthCheckResult('stub_a', 'Stub A', TRUE, 'info', 0, 'ok');
    $resultFailed = new HealthCheckResult('stub_b', 'Stub B', FALSE, 'error', 7, 'broken');

    $manager = $this->getMockBuilder(HealthCheckPluginManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['runAll'])
      ->getMock();
    $manager->method('runAll')->willReturn([$resultPassed, $resultFailed]);

    $controller = new HealthCheckController($manager);
    // Intentionally no jurisdiction query: this test only asserts the
    // documented JSON shape. Jurisdiction-resolution requires the group
    // module which is not installed in this kernel test.
    $response = $controller->report(new Request());

    $this->assertInstanceOf(JsonResponse::class, $response);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertIsArray($payload);
    $this->assertArrayHasKey('checks', $payload);
    $this->assertArrayHasKey('summary', $payload);
    $this->assertArrayHasKey('checked_at', $payload);
    $this->assertCount(2, $payload['checks']);
    $this->assertSame([
      'errors' => 1,
      'warnings' => 0,
      'infos' => 0,
      'passed' => 1,
    ], $payload['summary']);
  }

  /**
   * Tests that the route is registered with the expected access requirement.
   */
  public function testRouteRegistered(): void {
    /** @var \Drupal\Core\Routing\RouteProviderInterface $routeProvider */
    $routeProvider = $this->container->get('router.route_provider');
    $route = $routeProvider->getRouteByName('markaspot_health.report');

    $this->assertSame('/api/admin/health-check', $route->getPath());
    $this->assertSame('view health checks', $route->getRequirement('_permission'));
    $this->assertContains('GET', $route->getMethods());
  }

}
