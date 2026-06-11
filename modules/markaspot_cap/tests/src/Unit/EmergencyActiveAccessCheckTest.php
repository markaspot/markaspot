<?php

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_cap\Access\EmergencyActiveAccessCheck;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Routing\Route;

/**
 * Tests the EmergencyActiveAccessCheck access checker.
 *
 * Verifies that the gate operates on State (not regex path matching) so
 * URL-encoded paths like '/api/c%61p/v1/alerts' cannot bypass the check.
 *
 * @group markaspot_cap
 * @coversDefaultClass \Drupal\markaspot_cap\Access\EmergencyActiveAccessCheck
 */
class EmergencyActiveAccessCheckTest extends UnitTestCase {

  /**
   * @covers ::access
   */
  public function testDeniesWhenEmergencyModeOff(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with('markaspot_emergency.status', 'off')
      ->willReturn('off');

    $account = $this->createMock(AccountInterface::class);
    $route = new Route('/api/cap/v1/alerts');

    $checker = new EmergencyActiveAccessCheck($state);
    $result = $checker->access($route, $account);

    // allowedIf(false) yields a neutral result; Drupal denies the request
    // because the requirement '_cap_emergency_active: TRUE' is not satisfied.
    $this->assertFalse($result->isAllowed());
  }

  /**
   * @covers ::access
   */
  public function testAllowsWhenEmergencyModeActive(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with('markaspot_emergency.status', 'off')
      ->willReturn('active');

    $account = $this->createMock(AccountInterface::class);
    $route = new Route('/api/cap/v1/alerts');

    $checker = new EmergencyActiveAccessCheck($state);
    $result = $checker->access($route, $account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Verifies that the access result carries the emergency status cache tag.
   *
   * The check runs after routing, which normalizes percent-encoded paths.
   * URL-encoding tricks therefore cannot bypass this route-level gate.
   *
   * @covers ::access
   */
  public function testAccessResultCarriesEmergencyCacheTag(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with('markaspot_emergency.status', 'off')
      ->willReturn('active');

    $account = $this->createMock(AccountInterface::class);
    $route = new Route('/api/cap/v1/alerts');

    $checker = new EmergencyActiveAccessCheck($state);
    $result = $checker->access($route, $account);

    $this->assertContains('markaspot_emergency:status', $result->getCacheTags());
  }

}
