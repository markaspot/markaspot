<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests organisation management role defaults and update coverage.
 */
#[Group('markaspot_group')]
class OrganisationManagementConfigTest extends UnitTestCase {

  /**
   * Tests both profile role defaults grant organisation creation.
   */
  public function testTenantAdminDefaultsGrantOrgCreation(): void {
    $profile_root = dirname(__DIR__, 5);
    foreach (['install', 'optional'] as $collection) {
      $config = Yaml::decode(file_get_contents(
        $profile_root . "/config/$collection/user.role.tenant_admin.yml",
      ));
      $this->assertContains('create org group', $config['permissions']);
    }
  }

  /**
   * Tests installed tenants receive the permission through update.php.
   */
  public function testExistingTenantUpdateGrantsOrgCreation(): void {
    $module_root = dirname(__DIR__, 3);
    $source = file_get_contents($module_root . '/markaspot_group.install');

    $this->assertStringContainsString(
      'function markaspot_group_update_11944(): string',
      $source,
    );
    $this->assertStringContainsString(
      "\$role->grantPermission('create org group')->save();",
      $source,
    );
  }

  /**
   * Tests organisation management uses isolated fail-closed hierarchy rules.
   */
  public function testOrganisationResolverIsIsolatedAndObservable(): void {
    $services = Yaml::decode(file_get_contents(
      dirname(__DIR__, 3) . '/markaspot_group.services.yml',
    ))['services'];

    $this->assertSame(
      'closed',
      $services['markaspot_group.organisation_hierarchy_resolver']['arguments'][4],
    );
    $this->assertTrue(
      $services['markaspot_group.organisation_hierarchy_resolver']['arguments'][5],
    );
    $this->assertSame(
      '@markaspot_group.organisation_hierarchy_resolver',
      $services['markaspot_group.organisation_management_access']['arguments'][0],
    );
    $this->assertNotContains(
      '@group.membership_loader',
      $services['markaspot_group.organisation_management_access']['arguments'],
    );
  }

}
