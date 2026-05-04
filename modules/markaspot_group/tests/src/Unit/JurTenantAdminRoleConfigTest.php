<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the tenant-admin group role install config ownership.
 *
 * @group markaspot_group
 */
class JurTenantAdminRoleConfigTest extends UnitTestCase {

  /**
   * Tests that the tenant-admin role is installed by markaspot_group.
   */
  public function testTenantAdminRoleLivesInGroupModule(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $modulesRoot = dirname($moduleRoot);
    $groupRolePath = $moduleRoot . '/config/install/group.role.jur-tenant_admin.yml';
    $fastmapRolePath = $modulesRoot . '/markaspot_fastmap/config/install/group.role.jur-tenant_admin.yml';
    $tenantAdminRolePath = $modulesRoot . '/markaspot_tenant_admin/config/install/group.role.jur-tenant_admin.yml';

    $this->assertFileExists($groupRolePath);
    $this->assertFileDoesNotExist($fastmapRolePath);
    $this->assertFileDoesNotExist($tenantAdminRolePath);

    $role = Yaml::decode(file_get_contents($groupRolePath));
    $this->assertSame('jur-tenant_admin', $role['id']);
    $this->assertSame('jur', $role['group_type']);
    $this->assertSame('individual', $role['scope']);
    $this->assertFalse($role['admin']);
    $this->assertNull($role['global_role']);
    $this->assertSame([
      'administer members',
      'create group_node:service_request entity',
      'create group_node:service_request relationship',
      'update any group_node:service_request entity',
      'update own group_node:service_request entity',
      'view group',
      'view group_node:service_request entity',
      'view group_node:service_request relationship',
      'view own unpublished group_node:service_request entity',
      'view unpublished group_node:service_request entity',
    ], $role['permissions']);
    $this->assertNotContains('administer group', $role['permissions']);
    $this->assertNotContains('edit group', $role['permissions']);
    $this->assertNotContains('delete group', $role['permissions']);
    $this->assertNotContains('create group_node:page entity', $role['permissions']);
    $this->assertNotContains('create group_node:boilerplate entity', $role['permissions']);
  }

  /**
   * Tests that existing sites have an update path for the moved role.
   */
  public function testTenantAdminRoleUpdatePathNormalizesPermissions(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $modulesRoot = dirname($moduleRoot);
    $installFile = $moduleRoot . '/markaspot_group.install';
    $fastmapInstallFile = $modulesRoot . '/markaspot_fastmap/markaspot_fastmap.install';
    $source = file_get_contents($installFile);
    $fastmapSource = file_get_contents($fastmapInstallFile);

    $this->assertStringContainsString('function markaspot_group_update_11921', $source);
    $this->assertStringContainsString("moduleExists('markaspot_fastmap')", $source);
    $this->assertStringContainsString("moduleExists('markaspot_boilerplate')", $source);
    $this->assertStringContainsString("'permissions' => \$allowed_permissions", $source);
    $this->assertStringContainsString("set('admin', FALSE)", $source);
    $this->assertStringContainsString('array_intersect($allowed_permissions, $permissions)', $source);
    $this->assertStringContainsString('function markaspot_fastmap_update_dependencies', $fastmapSource);
    $this->assertStringContainsString("'markaspot_group' => 11921", $fastmapSource);
    $this->assertStringNotContainsString("'id' => 'jur-tenant_admin'", $fastmapSource);
  }

}
