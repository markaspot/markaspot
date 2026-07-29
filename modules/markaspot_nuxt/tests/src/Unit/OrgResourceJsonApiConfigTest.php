<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the organisation JSON:API management resource.
 */
#[Group('markaspot_nuxt')]
class OrgResourceJsonApiConfigTest extends UnitTestCase {

  /**
   * Tests the organisation contact mailbox is writable through JSON:API.
   */
  public function testOrgMailboxFieldIsEnabledInJsonApiResource(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $configPath = $moduleRoot . '/config/optional/jsonapi_extras.jsonapi_resource_config.group--org.yml';
    $config = Yaml::decode(file_get_contents($configPath));

    $this->assertSame('group--org', $config['id']);
    $this->assertSame(FALSE, $config['disabled']);
    $mailField = $config['resourceFields']['field_head_organisation_e_mail'] ?? NULL;
    $this->assertIsArray($mailField);
    $this->assertSame(FALSE, $mailField['disabled']);
  }

  /**
   * Tests existing tenants get an update hook for the management contract.
   */
  public function testUpdate11914EnablesOrgMailboxField(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $source = file_get_contents($moduleRoot . '/markaspot_nuxt.install');
    $this->assertIsString($source);

    $this->assertStringContainsString('function markaspot_nuxt_update_11914(): string', $source);
    $this->assertStringContainsString('organisation JSON:API resource is not installed', $source);
    $this->assertStringContainsString('field_head_organisation_e_mail', $source);
    $this->assertStringContainsString("'disabled' => FALSE", $source);
  }

}
