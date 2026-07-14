<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests single organisation assignment install and update defaults.
 */
#[Group('markaspot_group')]
class SingleOrganisationAssignmentUpdateTest extends UnitTestCase {

  /**
   * Tests fresh installs restrict service requests to one organisation.
   */
  public function testFreshInstallDefaultsToSingleOrganisation(): void {
    $module_root = dirname(__DIR__, 3);
    $source = file_get_contents(
      $module_root . '/config/install/markaspot_group.settings.yml',
    );
    $this->assertNotFalse($source);
    $config = Yaml::decode($source);

    $this->assertTrue($config['single_organisation_assignment']);
  }

  /**
   * Tests the update preserves tenants that already use multiple assignments.
   */
  public function testUpdate11940UsesExistingAssignmentData(): void {
    $module_root = dirname(__DIR__, 3);
    $source = file_get_contents($module_root . '/markaspot_group.install');
    $this->assertNotFalse($source);

    $this->assertStringContainsString(
      'function markaspot_group_update_11940(): string',
      $source,
    );
    $this->assertStringContainsString("tableExists(\$table)", $source);
    $this->assertStringContainsString("fieldExists(\$table, 'bundle')", $source);
    $this->assertStringContainsString(
      "->condition('bundle', 'service_request')",
      $source,
    );
    $this->assertStringContainsString("->groupBy('entity_id')", $source);
    $this->assertStringContainsString("->having('COUNT(*) > 1')", $source);
    $this->assertStringContainsString('->range(0, 1)', $source);
    $this->assertStringContainsString(
      '$single_organisation_assignment = !$has_multiple_assignments;',
      $source,
    );
    $this->assertStringContainsString(
      'Seeded single_organisation_assignment to FALSE because existing service requests use multiple organisation assignments.',
      $source,
    );
    $this->assertStringContainsString(
      'Seeded single_organisation_assignment to TRUE because no existing service request has multiple organisation assignments.',
      $source,
    );
  }

}
