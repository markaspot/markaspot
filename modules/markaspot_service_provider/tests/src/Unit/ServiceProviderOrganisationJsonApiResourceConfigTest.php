<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests field_organisation stays exposed on the service_provider resource.
 *
 * Regression guard for #429: existing tenants installed the resource config
 * before field_organisation was added, so the scoped provider fetch silently
 * fell back to a jurisdiction-wide list. The update hook must re-expose it.
 *
 * @group markaspot_service_provider
 */
class ServiceProviderOrganisationJsonApiResourceConfigTest extends UnitTestCase {

  /**
   * The update hook and its helper must expose field_organisation for upgrades.
   */
  public function testFieldOrganisationExposedByUpdateHook(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $source = file_get_contents($moduleRoot . '/markaspot_service_provider.install');
    $this->assertIsString($source);

    // The update hook and its helper must exist.
    $this->assertStringContainsString('function markaspot_service_provider_update_9007()', $source);
    $this->assertStringContainsString('_markaspot_service_provider_ensure_sp_organisation_jsonapi', $source);
    // It must patch the service_provider taxonomy resource config.
    $this->assertStringContainsString('jsonapi_extras.jsonapi_resource_config.taxonomy_term--service_provider', $source);
    // field_organisation must be exposed (not disabled) for the scoped fetch.
    $this->assertStringContainsString("\$resource_fields['field_organisation'] = [", $source);
    $this->assertStringContainsString("'publicName' => 'field_organisation'", $source);
    $this->assertStringContainsString("'disabled' => FALSE", $source);
  }

}
