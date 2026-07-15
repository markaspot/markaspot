<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_dashboard\Controller\PlatformMaintenanceController;
use Drupal\markaspot_dashboard\Service\MaintenanceBrandingResolverInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3) . '/src/Controller/PlatformMaintenanceController.php';
require_once dirname(__DIR__, 3) . '/src/Service/MaintenanceBrandingResolverInterface.php';
require_once dirname(__DIR__, 3) . '/markaspot_dashboard.module';

/**
 * Tests the global platform-maintenance API contract.
 *
 * @group markaspot_dashboard
 * @coversDefaultClass \Drupal\markaspot_dashboard\Controller\PlatformMaintenanceController
 */
class PlatformMaintenanceControllerTest extends UnitTestCase {

  /**
   * @covers ::status
   */
  public function testStatusOnlyExposesMaintenanceBoolean(): void {
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->once())
      ->method('get')
      ->with('system.maintenance_mode', FALSE)
      ->willReturn(TRUE);

    $response = $this->controller($state)->status();

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(['maintenance' => TRUE], json_decode((string) $response->getContent(), TRUE));
    self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
  }

  /**
   * @covers ::status
   */
  public function testStatusIncludesOnlyResolvedPublicBranding(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(TRUE);
    $branding = [
      'tenantName' => 'Mängelmelder',
      'logoLight' => '/sites/default/files/wbd-light.svg',
      'logoDark' => '/sites/default/files/wbd-dark.svg',
      'defaultLocale' => 'de',
    ];
    $resolver = $this->createMock(MaintenanceBrandingResolverInterface::class);
    $resolver->expects($this->once())
      ->method('resolve')
      ->with('1')
      ->willReturn($branding);

    $response = $this->controller($state, brandingResolver: $resolver)->status(
      Request::create('/api/platform/maintenance?jurisdiction=1'),
    );

    self::assertSame([
      'maintenance' => TRUE,
      'branding' => $branding,
    ], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * @covers ::status
   */
  public function testInactiveStatusSkipsBrandingResolution(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(FALSE);
    $resolver = $this->createMock(MaintenanceBrandingResolverInterface::class);
    $resolver->expects($this->never())->method('resolve');

    $response = $this->controller($state, brandingResolver: $resolver)->status(
      Request::create('/api/platform/maintenance?jurisdiction=1'),
    );

    self::assertSame(
      ['maintenance' => FALSE],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * @covers ::accessStatus
   */
  public function testAccessStatusOnlyExposesCurrentSessionCapability(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')
      ->with('access site in maintenance mode')
      ->willReturn(TRUE);

    $response = $this->controller($this->createMock(StateInterface::class), $account)->accessStatus();

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(['maintenance_access' => TRUE], json_decode((string) $response->getContent(), TRUE));
    self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
  }

  /**
   * @covers ::accessStatus
   */
  public function testAccessStatusIsFalseForAnonymousSession(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(FALSE);
    $account->expects($this->never())->method('hasPermission');

    $response = $this->controller($this->createMock(StateInterface::class), $account)->accessStatus();

    self::assertSame(['maintenance_access' => FALSE], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * @covers ::update
   */
  public function testUpdatePersistsBooleanMaintenanceState(): void {
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->once())
      ->method('set')
      ->with('system.maintenance_mode', TRUE);

    $response = $this->controller($state)->update(
      Request::create('/api/platform/maintenance', 'PATCH', content: '{"maintenance":true}'),
    );

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(['maintenance' => TRUE], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * @covers ::update
   */
  public function testUpdateWritesAnAuditEntryWithModeAndActorOnly(): void {
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->once())
      ->method('set')
      ->with('system.maintenance_mode', FALSE);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        'Global maintenance mode changed to @mode by uid @uid.',
        ['@mode' => 'disabled', '@uid' => 42],
      );
    $controller = new PlatformMaintenanceController($state, $account, $logger);

    $response = $controller->update(
      Request::create('/api/platform/maintenance', 'PATCH', content: '{"maintenance":false}'),
    );

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * @covers ::update
   */
  #[DataProvider('invalidMaintenancePayloadProvider')]
  public function testUpdateRejectsAnyPayloadOutsideTheBooleanContract(string $content): void {
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->never())->method('set');

    $response = $this->controller($state)->update(
      Request::create('/api/platform/maintenance', 'PATCH', content: $content),
    );

    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Provides invalid maintenance-update bodies.
   *
   * @return array<string, array{string}>
   *   Invalid JSON bodies keyed by their failure case.
   */
  public static function invalidMaintenancePayloadProvider(): array {
    return [
      'malformed JSON' => ['{'],
      'missing state' => ['{}'],
      'string state' => ['{"maintenance":"true"}'],
      'numeric state' => ['{"maintenance":1}'],
      'extra key' => ['{"maintenance":true,"reason":"deploy"}'],
      'array instead of object' => ['[]'],
    ];
  }

  /**
   * @covers ::access
   */
  #[DataProvider('platformAdminProvider')]
  public function testOnlyPlatformAdministratorsCanChangeGlobalState(int $uid, array $roles, bool $expected): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);

    self::assertSame($expected, $this->controller($this->createMock(StateInterface::class))
      ->access($account)
      ->isAllowed());
  }

  /**
   * Provides global and jurisdiction-only access cases.
   *
   * @return array<string, array{int, string[], bool}>
   *   Account ID, roles, and expected access result.
   */
  public static function platformAdminProvider(): array {
    return [
      'uid one' => [1, ['authenticated'], TRUE],
      'site administrator role' => [42, ['authenticated', 'administrator'], TRUE],
      'tenant admin cannot toggle all tenants' => [42, ['authenticated', 'tenant_admin'], FALSE],
      'editorial board cannot toggle all tenants' => [42, ['authenticated', 'editorial_board'], FALSE],
      'anonymous' => [0, ['anonymous'], FALSE],
    ];
  }

  /**
   * The routing layer must retain maintenance access and mutation safeguards.
   */
  public function testRoutesAreMaintenanceReachableAndPatchIsCookieCsrfProtected(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $routes = Yaml::parse((string) file_get_contents($moduleRoot . '/markaspot_dashboard.routing.yml'));

    $get = $routes['markaspot_dashboard.platform_maintenance_status'] ?? NULL;
    $access = $routes['markaspot_dashboard.platform_maintenance_access'] ?? NULL;
    $patch = $routes['markaspot_dashboard.platform_maintenance_update'] ?? NULL;

    self::assertIsArray($get);
    self::assertIsArray($access);
    self::assertIsArray($patch);
    self::assertSame('/api/platform/maintenance', $get['path']);
    self::assertSame(['GET'], $get['methods']);
    self::assertSame('TRUE', $get['requirements']['_access']);
    self::assertTrue($get['options']['_maintenance_access']);
    self::assertSame('/api/platform/maintenance/access', $access['path']);
    self::assertSame(['GET'], $access['methods']);
    self::assertSame('TRUE', $access['requirements']['_access']);
    self::assertSame(['cookie'], $access['options']['_auth']);
    self::assertTrue($access['options']['_maintenance_access']);
    self::assertSame(['PATCH'], $patch['methods']);
    self::assertSame(
      '\\Drupal\\markaspot_dashboard\\Controller\\PlatformMaintenanceController::access',
      $patch['requirements']['_custom_access'],
    );
    self::assertSame('TRUE', $patch['requirements']['_csrf_request_header_token']);
    self::assertSame(['cookie'], $patch['options']['_auth']);
    self::assertTrue($patch['options']['_maintenance_access']);
  }

  /**
   * The Core CSRF endpoint belongs to the guaranteed dashboard dependency.
   */
  public function testCoreCsrfRouteIsAvailableDuringMaintenance(): void {
    $routes = new RouteCollection();
    $csrfRoute = new Route('/session/token');
    $unrelatedRoute = new Route('/api/ordinary-content');
    $routes->add('system.csrftoken', $csrfRoute);
    $routes->add('markaspot_test.unrelated', $unrelatedRoute);

    markaspot_dashboard_route_alter($routes);

    self::assertTrue($csrfRoute->getOption('_maintenance_access'));
    self::assertNull($unrelatedRoute->getOption('_maintenance_access'));
  }

  /**
   * Creates the controller with its state and current-account dependencies.
   */
  private function controller(
    StateInterface $state,
    ?AccountInterface $account = NULL,
    ?LoggerChannelInterface $logger = NULL,
    ?MaintenanceBrandingResolverInterface $brandingResolver = NULL,
  ): PlatformMaintenanceController {
    if ($account === NULL) {
      $account = $this->createMock(AccountInterface::class);
      $account->method('isAuthenticated')->willReturn(FALSE);
    }
    $logger ??= $this->createMock(LoggerChannelInterface::class);
    return new PlatformMaintenanceController($state, $account, $logger, $brandingResolver);
  }

}
