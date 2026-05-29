<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests org JSON:API resource surface reduction.
 *
 * @group markaspot_service_provider
 */
class OrgJsonApiResourceConfigTest extends UnitTestCase
{
    /**
     * Tests service-provider updates do not expose internal org mailboxes.
     */
    public function testOrgMailboxFieldIsDisabledWhenResourceIsCreated(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $source = file_get_contents($moduleRoot . '/markaspot_service_provider.install');
        $this->assertIsString($source);

        $this->assertStringContainsString('_markaspot_service_provider_org_group_jsonapi_resource_fields()', $source);
        $this->assertStringContainsString("'field_head_organisation_e_mail' => [", $source);
        $this->assertStringContainsString("'disabled' => TRUE", $source);
    }
}
