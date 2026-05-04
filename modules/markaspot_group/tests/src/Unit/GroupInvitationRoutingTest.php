<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests invitation route authentication policy.
 *
 * @group markaspot_group
 */
class GroupInvitationRoutingTest extends UnitTestCase {

  /**
   * Tests invitation management endpoints require cookie sessions.
   */
  public function testInvitationManagementRoutesDoNotAllowApiKeyAuth(): void {
    $routingPath = dirname(__DIR__, 3) . '/markaspot_group.routing.yml';
    $routes = Yaml::decode(file_get_contents($routingPath));

    foreach (
      [
        'markaspot_group.member_update' => TRUE,
        'markaspot_group.member_profile_update' => TRUE,
        'markaspot_group.invitation_send' => TRUE,
        'markaspot_group.invitation_list' => FALSE,
        'markaspot_group.invitation_revoke' => TRUE,
      ] as $routeName => $requiresCsrf
    ) {
      $this->assertSame(['cookie'], $routes[$routeName]['options']['_auth']);
      $this->assertNotContains('api_key_auth', $routes[$routeName]['options']['_auth']);
      if ($requiresCsrf) {
        $this->assertSame('TRUE', $routes[$routeName]['requirements']['_csrf_request_header_token']);
        $this->assertArrayNotHasKey('_csrf_token', $routes[$routeName]['requirements']);
      }
      else {
        $this->assertArrayNotHasKey('_csrf_request_header_token', $routes[$routeName]['requirements']);
        $this->assertArrayNotHasKey('_csrf_token', $routes[$routeName]['requirements']);
      }
    }

    $claimRoute = $routes['markaspot_group.invitation_claim'];
    $this->assertSame('TRUE', $claimRoute['requirements']['_access']);
    $this->assertArrayNotHasKey('_auth', $claimRoute['options']);
    $this->assertArrayNotHasKey('_csrf_request_header_token', $claimRoute['requirements']);
    $this->assertArrayNotHasKey('_csrf_token', $claimRoute['requirements']);
  }

}
