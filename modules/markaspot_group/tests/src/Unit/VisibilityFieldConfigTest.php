<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/markaspot_group.install';

/**
 * Tests jurisdiction visibility field ownership and update coverage.
 *
 * @group markaspot_group
 */
final class VisibilityFieldConfigTest extends UnitTestCase {

  /**
   * Tests fresh installs receive the core-owned field configuration.
   */
  public function testFreshInstallConfiguration(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $storage = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/install/field.storage.group.field_visibility.yml',
    ));
    $field = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/install/field.field.group.jur.field_visibility.yml',
    ));
    $info = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/markaspot_group.info.yml',
    ));

    $this->assertSame('list_string', $storage['type']);
    $this->assertSame('custom', $storage['third_party_settings']['field_permissions']['permission_type']);
    $this->assertSame(
      ['field_permissions', 'group', 'options'],
      $storage['dependencies']['module'],
    );
    $this->assertSame(
      [
        'public' => 'Public',
        'submission_only' => 'Submission Only',
        'form_only' => 'Form Only',
        'authenticated' => 'Authenticated',
        'blocked' => 'Blocked',
      ],
      array_column($storage['settings']['allowed_values'], 'label', 'value'),
    );
    $this->assertSame(
      ['field.storage.group.field_visibility', 'group.type.jur'],
      $field['dependencies']['config'],
    );
    $this->assertSame([['value' => 'public']], $field['default_value']);
    $this->assertContains('field_permissions:field_permissions', $info['dependencies']);

    $fastmapRoot = dirname($moduleRoot) . '/markaspot_fastmap';
    $this->assertFileDoesNotExist($fastmapRoot . '/config/install/field.storage.group.field_visibility.yml');
    $this->assertFileDoesNotExist($fastmapRoot . '/config/install/field.field.group.jur.field_visibility.yml');
  }

  /**
   * Tests existing sites receive additive guarded logic without a backfill.
   */
  public function testUpdate11946IsGuardedAndDoesNotBackfillGroups(): void {
    $source = (string) file_get_contents(
      dirname(__DIR__, 3) . '/markaspot_group.install',
    );
    $start = strpos($source, 'function markaspot_group_update_11946(): string');
    $end = strpos($source, 'function _markaspot_group_visibility_allowed_values', $start);

    $this->assertNotFalse($start);
    $this->assertNotFalse($end);
    $hook = substr($source, $start, $end - $start);

    $this->assertStringContainsString(
      "FieldStorageConfig::loadByName('group', 'field_visibility')",
      $hook,
    );
    $this->assertStringContainsString(
      '$jurisdiction_group_type = _markaspot_group_update_jurisdiction_group_type()',
      $hook,
    );
    $this->assertStringContainsString(
      "FieldConfig::loadByName('group', \$jurisdiction_group_type, 'field_visibility')",
      $hook,
    );
    $this->assertStringContainsString(
      "'bundle' => \$jurisdiction_group_type",
      $hook,
    );
    $this->assertStringContainsString(
      '_markaspot_group_visibility_allowed_values($allowed_values)',
      $hook,
    );
    $this->assertStringNotContainsString("getStorage('group')", $hook);
    $this->assertStringNotContainsString("->set('field_visibility'", $hook);
  }

  /**
   * Tests flat allowed values remain valid and idempotent.
   */
  public function testFlatAllowedValueMergeIsIdempotent(): void {
    $existing = [
      'public' => 'Public site',
      'submission_only' => 'Submission Only',
      'authenticated' => 'Authenticated',
    ];

    $updated = _markaspot_group_visibility_allowed_values($existing);

    $this->assertSame('Public site', $updated['public']);
    $this->assertSame('Blocked', $updated['blocked']);
    $this->assertSame('Form Only', $updated['form_only']);
    $this->assertSame(
      $updated,
      _markaspot_group_visibility_allowed_values($updated),
    );
  }

  /**
   * Tests list mappings remain valid and idempotent.
   */
  public function testListAllowedValueMergeIsIdempotent(): void {
    $existing = [
      ['value' => 'public', 'label' => 'Public site'],
      ['value' => 'submission_only', 'label' => 'Submission Only'],
      ['value' => 'authenticated', 'label' => 'Authenticated'],
    ];

    $updated = _markaspot_group_visibility_allowed_values($existing);

    $this->assertSame(
      [
        ...$existing,
        ['value' => 'blocked', 'label' => 'Blocked'],
        ['value' => 'form_only', 'label' => 'Form Only'],
      ],
      $updated,
    );
    $this->assertSame(
      $updated,
      _markaspot_group_visibility_allowed_values($updated),
    );
  }

  /**
   * Tests historical FastMap hooks remain available for upgraded SaaS sites.
   */
  public function testHistoricalFastmapHooksRemainUnchangedInPlace(): void {
    $fastmapInstall = (string) file_get_contents(
      dirname(dirname(__DIR__, 3)) . '/markaspot_fastmap/markaspot_fastmap.install',
    );

    $this->assertStringContainsString('function markaspot_fastmap_update_11901(): void', $fastmapInstall);
    $this->assertStringContainsString('function markaspot_fastmap_update_11920(): void', $fastmapInstall);
    $this->assertStringContainsString('function markaspot_fastmap_update_11921(): void', $fastmapInstall);
  }

}
