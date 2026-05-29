<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests organisation JSON:API resource surface reduction.
 *
 * @group markaspot_nuxt
 */
class OrgResourceJsonApiConfigTest extends UnitTestCase
{
  /**
   * Tests org notification mailboxes are hidden from group--org JSON:API.
   */
    public function testOrgMailboxFieldIsDisabledInJsonApiResource(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $configPath = $moduleRoot . '/config/optional/jsonapi_extras.jsonapi_resource_config.group--org.yml';
        $config = Yaml::decode(file_get_contents($configPath));

        $this->assertSame('group--org', $config['id']);
        $this->assertSame(false, $config['disabled']);
        $mailField = $config['resourceFields']['field_head_organisation_e_mail'] ?? null;
        $this->assertIsArray($mailField);
        $this->assertSame(true, $mailField['disabled']);
    }

  /**
   * Tests existing tenants get an update hook for the org resource lockdown.
   */
    public function testUpdate11903DisablesOrgMailboxField(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $source = file_get_contents($moduleRoot . '/markaspot_nuxt.install');
        $this->assertIsString($source);

        $this->assertStringContainsString('function markaspot_nuxt_update_11903(): string', $source);
        $this->assertStringContainsString('resource not enabled on this tenant', $source);
        $this->assertStringContainsString('field_head_organisation_e_mail', $source);
        $this->assertStringContainsString("mail_field['disabled'] = TRUE", $source);
    }
}
