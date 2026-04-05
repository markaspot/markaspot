<?php

namespace Drupal\Tests\markaspot\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the 11910 migration helper functions for organisation -> org rename.
 *
 * These are pure functions with no Drupal dependencies, defined in
 * markaspot.install. They handle the group type bundle rename from
 * 'organisation' to 'org' while preserving field names, taxonomy vocabularies,
 * and other non-group references that contain 'organisation'.
 *
 * @group markaspot
 */
class Update11910HelpersTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot.install';
  }

  /**
   * Data provider for config names that MUST be renamed.
   *
   * @return array
   *   Test cases: [input, expected].
   */
  public static function configNamesMustRenameProvider(): array {
    return [
      'group type' => [
        'group.type.organisation',
        'group.type.org',
      ],
      'group content type' => [
        'group.content_type.organisation-group_node-service_request',
        'group.content_type.org-group_node-service_request',
      ],
      'field on group bundle' => [
        'field.field.group.organisation.field_nuxt_config',
        'field.field.group.org.field_nuxt_config',
      ],
      'entity form display' => [
        'core.entity_form_display.group.organisation.default',
        'core.entity_form_display.group.org.default',
      ],
      'entity view display' => [
        'core.entity_view_display.group.organisation.default',
        'core.entity_view_display.group.org.default',
      ],
      'base field override' => [
        'core.base_field_override.group.organisation.changed',
        'core.base_field_override.group.org.changed',
      ],
      'group role' => [
        'group.role.organisation-admin',
        'group.role.org-admin',
      ],
      'group relationship type' => [
        'group.relationship_type.organisation-group_node-service_request',
        'group.relationship_type.org-group_node-service_request',
      ],
      'language content settings' => [
        'language.content_settings.group.organisation',
        'language.content_settings.group.org',
      ],
    ];
  }

  /**
   * Data provider for config names that must NOT be renamed.
   *
   * @return array
   *   Test cases: [input] (expected = same as input).
   */
  public static function configNamesMustNotRenameProvider(): array {
    return [
      'field on node with organisation in name' => [
        'field.field.node.service_request.field_organisation',
      ],
      'field storage with organisation in field name' => [
        'field.storage.node.field_organisation',
      ],
      'taxonomy vocabulary named organisation' => [
        'taxonomy.vocabulary.organisation',
      ],
      'field storage on group (field name)' => [
        'field.storage.group.field_organisation',
      ],
      'field on org bundle with organisation field name' => [
        'field.field.group.org.field_organisation',
      ],
      'taxonomy term base field override' => [
        'core.base_field_override.taxonomy_term.organisation.status',
      ],
      'taxonomy term entity form display' => [
        'core.entity_form_display.taxonomy_term.organisation.default',
      ],
      'facet named organisation' => [
        'facets.facet.organisation',
      ],
      'block named organisation' => [
        'block.block.organisation',
      ],
    ];
  }

  /**
   * Tests config names that must be renamed.
   *
   * @dataProvider configNamesMustRenameProvider
   */
  public function testFixConfigNameRenames(string $input, string $expected): void {
    $this->assertSame($expected, _markaspot_update_11910_fix_config_name($input));
  }

  /**
   * Tests config names that must NOT be renamed.
   *
   * @dataProvider configNamesMustNotRenameProvider
   */
  public function testFixConfigNamePreserves(string $input): void {
    $this->assertSame($input, _markaspot_update_11910_fix_config_name($input));
  }

  /**
   * Tests that field storage without organisation is untouched.
   */
  public function testFixConfigNameFieldStorageNoOrganisation(): void {
    $this->assertSame(
      'field.storage.group.field_nuxt_config',
      _markaspot_update_11910_fix_config_name('field.storage.group.field_nuxt_config'),
    );
  }

  /**
   * Data provider for _fix_string with group_context = TRUE.
   *
   * @return array
   *   Test cases: [input, expected].
   */
  public static function fixStringGroupContextProvider(): array {
    return [
      'exact match organisation' => [
        'organisation',
        'org',
      ],
      'plugin ID prefix' => [
        'organisation-group_node-service_request',
        'org-group_node-service_request',
      ],
      'group membership plugin' => [
        'organisation-group_membership',
        'org-group_membership',
      ],
      'group type in dotted string' => [
        'group.type.organisation',
        'group.type.org',
      ],
      'group content type in dotted string' => [
        'group.content_type.organisation-group_node-service_request',
        'group.content_type.org-group_node-service_request',
      ],
      'field.field.group bundle segment' => [
        'field.field.group.organisation.field_nuxt_config',
        'field.field.group.org.field_nuxt_config',
      ],
      'entity form display bundle segment' => [
        'core.entity_form_display.group.organisation.default',
        'core.entity_form_display.group.org.default',
      ],
      'group role in dotted string' => [
        'group.role.organisation-admin',
        'group.role.org-admin',
      ],
      'language content settings' => [
        'language.content_settings.group.organisation',
        'language.content_settings.group.org',
      ],
      'group entity internal ID' => [
        'group.organisation.changed',
        'group.org.changed',
      ],
      'group entity ID with word boundary' => [
        'group.organisation',
        'group.org',
      ],
      'empty string' => [
        '',
        '',
      ],
      'string without organisation' => [
        'some.random.config.value',
        'some.random.config.value',
      ],
    ];
  }

  /**
   * Tests _fix_string with group_context = TRUE.
   *
   * @dataProvider fixStringGroupContextProvider
   */
  public function testFixStringGroupContext(string $input, string $expected): void {
    $this->assertSame($expected, _markaspot_update_11910_fix_string($input, TRUE));
  }

  /**
   * Data provider for _fix_string with group_context = FALSE.
   *
   * @return array
   *   Test cases: [input, expected].
   */
  public static function fixStringNonGroupContextProvider(): array {
    return [
      'bare organisation preserved as vocabulary name' => [
        'organisation',
        'organisation',
      ],
      'field_organisation preserved' => [
        'field_organisation',
        'field_organisation',
      ],
      'organisation- prefix preserved (not group context)' => [
        'organisation-some_suffix',
        'organisation-some_suffix',
      ],
      'group.type.organisation still renamed (regex pattern)' => [
        'group.type.organisation',
        'group.type.org',
      ],
      'field.field.group.organisation still renamed (regex pattern)' => [
        'field.field.group.organisation.field_test',
        'field.field.group.org.field_test',
      ],
    ];
  }

  /**
   * Tests _fix_string with group_context = FALSE.
   *
   * @dataProvider fixStringNonGroupContextProvider
   */
  public function testFixStringNonGroupContext(string $input, string $expected): void {
    $this->assertSame($expected, _markaspot_update_11910_fix_string($input, FALSE));
  }

  /**
   * Tests _fix_config_data with an empty array.
   */
  public function testFixConfigDataEmptyArray(): void {
    $this->assertSame([], _markaspot_update_11910_fix_config_data([]));
  }

  /**
   * Tests _fix_config_data passes through non-array, non-string values.
   */
  public function testFixConfigDataPassthroughScalars(): void {
    $this->assertSame(42, _markaspot_update_11910_fix_config_data(42));
    $this->assertTrue(_markaspot_update_11910_fix_config_data(TRUE));
    $this->assertNull(_markaspot_update_11910_fix_config_data(NULL));
  }

  /**
   * Tests _fix_config_data renames 'organisation' keys in group context.
   */
  public function testFixConfigDataRenamesKeysInGroupContext(): void {
    $input = [
      'organisation' => [
        'label' => 'Organisation',
        'bundle' => 'organisation',
      ],
    ];
    $expected = [
      'org' => [
        'label' => 'Organisation',
        'bundle' => 'org',
      ],
    ];
    $this->assertSame($expected, _markaspot_update_11910_fix_config_data($input, TRUE));
  }

  /**
   * Tests _fix_config_data preserves 'organisation' keys outside group context.
   */
  public function testFixConfigDataPreservesKeysOutsideGroupContext(): void {
    $input = [
      'organisation' => [
        'label' => 'Organisation',
      ],
    ];
    $expected = [
      'organisation' => [
        'label' => 'organisation',
      ],
    ];
    // Outside group context, the key 'organisation' is preserved,
    // but the bare string value 'Organisation' stays as-is (not exact match).
    $result = _markaspot_update_11910_fix_config_data($input, FALSE);
    $this->assertArrayHasKey('organisation', $result);
  }

  /**
   * Tests _fix_config_data preserves field_organisation keys.
   */
  public function testFixConfigDataPreservesFieldOrganisationKey(): void {
    $input = [
      'field_organisation' => 'Some value',
      'other_field' => 'organisation',
    ];
    $result = _markaspot_update_11910_fix_config_data($input, TRUE);

    // field_organisation key must NOT be renamed (it is a field machine name).
    $this->assertArrayHasKey('field_organisation', $result);
    // But the bare string value 'organisation' IS renamed in group context.
    $this->assertSame('org', $result['other_field']);
  }

  /**
   * Tests _fix_config_data with a mixed nested structure.
   */
  public function testFixConfigDataMixedNestedStructure(): void {
    $input = [
      'dependencies' => [
        'config' => [
          'group.type.organisation',
          'field.field.group.organisation.field_nuxt_config',
        ],
        'module' => [
          'group',
          'markaspot_group',
        ],
      ],
      'id' => 'organisation-group_node-service_request',
      'field_organisation' => 'test_value',
      'status' => TRUE,
      'weight' => 0,
    ];

    $result = _markaspot_update_11910_fix_config_data($input, TRUE);

    // Dependency config values get renamed via regex patterns.
    $this->assertSame('group.type.org', $result['dependencies']['config'][0]);
    $this->assertSame(
      'field.field.group.org.field_nuxt_config',
      $result['dependencies']['config'][1],
    );
    // Module dependencies are untouched.
    $this->assertSame(['group', 'markaspot_group'], $result['dependencies']['module']);
    // Plugin ID string gets renamed.
    $this->assertSame('org-group_node-service_request', $result['id']);
    // field_organisation key is preserved.
    $this->assertArrayHasKey('field_organisation', $result);
    // Scalars pass through.
    $this->assertTrue($result['status']);
    $this->assertSame(0, $result['weight']);
  }

  /**
   * Tests _fix_config_data with deeply nested arrays.
   */
  public function testFixConfigDataDeeplyNested(): void {
    $input = [
      'level1' => [
        'level2' => [
          'level3' => [
            'target_bundle' => 'organisation',
            'type' => 'group.type.organisation',
          ],
        ],
      ],
    ];

    $result = _markaspot_update_11910_fix_config_data($input, TRUE);

    $this->assertSame('org', $result['level1']['level2']['level3']['target_bundle']);
    $this->assertSame(
      'group.type.org',
      $result['level1']['level2']['level3']['type'],
    );
  }

  /**
   * Tests _fix_config_data with a string input (delegates to _fix_string).
   */
  public function testFixConfigDataStringInput(): void {
    $this->assertSame(
      'org',
      _markaspot_update_11910_fix_config_data('organisation', TRUE),
    );
    $this->assertSame(
      'organisation',
      _markaspot_update_11910_fix_config_data('organisation', FALSE),
    );
  }

}
