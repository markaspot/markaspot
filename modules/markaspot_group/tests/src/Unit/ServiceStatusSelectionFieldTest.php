<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests service status selection field shipping and update coverage.
 *
 * @group markaspot_group
 */
class ServiceStatusSelectionFieldTest extends UnitTestCase {

  /**
   * Tests fresh-install storage, field, and form display configuration.
   */
  public function testFreshInstallConfiguration(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $profileRoot = dirname($moduleRoot, 2);
    $storage = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/install/field.storage.group.field_service_statuses.yml',
    ));
    $field = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/install/field.field.group.jur.field_service_statuses.yml',
    ));
    $formDisplay = Yaml::decode((string) file_get_contents(
      $profileRoot . '/config/optional/core.entity_form_display.group.jur.default.yml',
    ));

    $this->assertSame('entity_reference', $storage['type']);
    $this->assertSame('taxonomy_term', $storage['settings']['target_type']);
    $this->assertSame(-1, $storage['cardinality']);
    $this->assertTrue($storage['translatable']);

    $this->assertSame('jur', $field['bundle']);
    $this->assertSame(
      'jurisdiction_category:taxonomy_term',
      $field['settings']['handler'],
    );
    $this->assertSame(
      ['service_status' => 'service_status'],
      $field['settings']['handler_settings']['target_bundles'],
    );
    $this->assertSame(
      'entity_reference_autocomplete',
      $formDisplay['content']['field_service_statuses']['type'],
    );
  }

  /**
   * Tests that existing sites receive the same storage and field instance.
   */
  public function testUpdate11942CreatesMissingConfiguration(): void {
    $source = (string) file_get_contents(
      dirname(__DIR__, 3) . '/markaspot_group.install',
    );

    $this->assertStringContainsString(
      'function markaspot_group_update_11942(): string',
      $source,
    );
    $this->assertStringContainsString(
      "->load('group.field_service_statuses')",
      $source,
    );
    $this->assertStringContainsString(
      "->load('group.jur.field_service_statuses')",
      $source,
    );
    $this->assertStringContainsString(
      "getComponent('field_service_statuses') === NULL",
      $source,
    );
  }

}
