<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests organisation notification mailbox field wiring.
 *
 * @group markaspot_group
 */
class OrgNotificationMailboxFieldTest extends UnitTestCase
{
  /**
   * Tests fresh installs provide the org mailbox field expected by mail code.
   */
    public function testOrgMailboxFieldInstallConfigExists(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $storagePath = $moduleRoot . '/config/install/field.storage.group.field_head_organisation_e_mail.yml';
        $fieldPath = $moduleRoot . '/config/install/field.field.group.org.field_head_organisation_e_mail.yml';
        $formDisplayPath = $moduleRoot . '/config/optional/core.entity_form_display.group.org.default.yml';
        $storage = Yaml::decode(file_get_contents($storagePath));
        $field = Yaml::decode(file_get_contents($fieldPath));
        $formDisplay = Yaml::decode(file_get_contents($formDisplayPath));

        $this->assertSame('group.field_head_organisation_e_mail', $storage['id']);
        $this->assertSame('email', $storage['type']);
        $this->assertSame(-1, $storage['cardinality']);
        $this->assertSame('group.org.field_head_organisation_e_mail', $field['id']);
        $this->assertSame('org', $field['bundle']);
        $this->assertSame('Head Organisation E-Mail', $field['label']);
        $this->assertArrayHasKey('field_head_organisation_e_mail', $formDisplay['content']);
    }

  /**
   * Tests existing 11.7.x migrations repair legacy organisation-bundle data.
   */
    public function testUpdate11931RepairsLegacyOrgMailboxField(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $source = file_get_contents($moduleRoot . '/markaspot_group.install');

        $this->assertStringContainsString('function markaspot_group_update_11931(): string', $source);
        $this->assertStringContainsString('_markaspot_group_update_ensure_org_head_email_storage()', $source);
        $this->assertStringContainsString('_markaspot_group_update_ensure_org_head_email_field()', $source);
        $this->assertStringContainsString('_markaspot_group_update_legacy_org_head_email_table_state()', $source);
        $this->assertStringContainsString('_markaspot_group_update_legacy_org_head_email_tables_exist()', $source);
        $this->assertStringContainsString('Partial legacy field_head_organisation_e_mail schema found', $source);
        $this->assertStringContainsString(
            'FieldStorageConfig::create(_markaspot_group_update_org_head_email_storage_data())->save()',
            $source
        );
        $this->assertStringContainsString('_markaspot_group_update_migrate_org_head_email_table_bundles()', $source);
        $this->assertStringContainsString("->condition('bundle', 'organisation')", $source);
        $this->assertStringContainsString("->fields(['bundle' => 'org'])", $source);
        $this->assertStringContainsString("unset(\$bundles['organisation']);", $source);
        $this->assertStringContainsString("\$bundles['org'] = 'org';", $source);
    }
}
