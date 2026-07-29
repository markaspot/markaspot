<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/markaspot_group.install';

/**
 * Tests jurisdiction tier field ownership and update coverage.
 *
 * @group markaspot_group
 */
final class TierFieldConfigTest extends UnitTestCase {

  /**
   * Tests fresh installs receive the core-owned field configuration.
   */
  public function testFreshInstallConfiguration(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $storage = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/install/field.storage.group.field_tier.yml',
    ));
    $field = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/install/field.field.group.jur.field_tier.yml',
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
        'free' => 'Free',
        'starter' => 'Starter',
        'pro' => 'Pro',
        'heart' => 'Heart',
        'community' => 'Community',
        'premium' => 'Premium',
      ],
      array_column($storage['settings']['allowed_values'], 'label', 'value'),
    );
    $this->assertSame(
      ['field.storage.group.field_tier', 'group.type.jur'],
      $field['dependencies']['config'],
    );
    $this->assertSame('jur', $field['bundle']);
    $this->assertContains('field_permissions:field_permissions', $info['dependencies']);
  }

  /**
   * Tests existing sites receive additive, guarded update logic.
   */
  public function testUpdate11945IsGuardedAndAdditive(): void {
    $source = (string) file_get_contents(
      dirname(__DIR__, 3) . '/markaspot_group.install',
    );

    $this->assertStringContainsString(
      'function markaspot_group_update_11945(): string',
      $source,
    );
    $this->assertStringContainsString(
      "FieldStorageConfig::loadByName('group', 'field_tier')",
      $source,
    );
    $this->assertStringContainsString(
      "FieldConfig::loadByName('group', 'jur', 'field_tier')",
      $source,
    );
    $this->assertStringContainsString(
      "_markaspot_group_tier_allowed_values(\$allowed_values)",
      $source,
    );
    $this->assertStringContainsString(
      "'community' => 'Community'",
      $source,
    );
    $this->assertStringContainsString(
      "'premium' => 'Premium'",
      $source,
    );
  }

  /**
   * Tests the allowed-value merge preserves data and is idempotent.
   */
  public function testFlatAllowedValueMergeIsIdempotent(): void {
    $existing = [
      'free' => 'Free',
      'starter' => 'Starter',
      'pro' => 'Professional',
      'heart' => 'Heart',
    ];

    $updated = _markaspot_group_tier_allowed_values($existing);

    $this->assertSame('Professional', $updated['pro']);
    $this->assertSame('Community', $updated['community']);
    $this->assertSame('Premium', $updated['premium']);
    $this->assertSame(
      $updated,
      _markaspot_group_tier_allowed_values($updated),
    );
  }

  /**
   * Tests the list-of-mappings shape remains valid and idempotent.
   */
  public function testListAllowedValueMergeIsIdempotent(): void {
    $existing = [
      ['value' => 'free', 'label' => 'Free'],
      ['value' => 'starter', 'label' => 'Starter'],
      ['value' => 'pro', 'label' => 'Professional'],
      ['value' => 'heart', 'label' => 'Heart'],
    ];

    $updated = _markaspot_group_tier_allowed_values($existing);

    $this->assertSame(
      [
        ...$existing,
        ['value' => 'community', 'label' => 'Community'],
        ['value' => 'premium', 'label' => 'Premium'],
      ],
      $updated,
    );
    $this->assertSame(
      $updated,
      _markaspot_group_tier_allowed_values($updated),
    );
  }

}
