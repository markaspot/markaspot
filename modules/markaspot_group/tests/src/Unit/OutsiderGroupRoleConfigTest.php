<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the workspace enumeration boundary on synchronized outsider roles.
 *
 * Nested JSON:API filters through the group relationship are intentionally
 * unavailable to anonymous visitors. Clients must filter pages through
 * field_jurisdiction.meta.drupal_internal__target_id instead.
 *
 * @group markaspot_group
 */
final class OutsiderGroupRoleConfigTest extends UnitTestCase {

  /**
   * Tests that synchronized outsider roles cannot view the group entity.
   */
  public function testOutsiderRolesDoNotGrantViewGroup(): void {
    $config_directory = dirname(__DIR__, 3) . '/config/install';

    foreach (['jur-anonymous', 'jur-outsider'] as $role_id) {
      $path = $config_directory . '/group.role.' . $role_id . '.yml';
      $role = Yaml::decode((string) file_get_contents($path));

      $this->assertSame($role_id, $role['id']);
      $this->assertSame('outsider', $role['scope']);
      $this->assertNotContains(
        'view group',
        $role['permissions'],
        sprintf('%s must not expose workspace enumeration.', $role_id),
      );
    }
  }

}
