<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_service_provider\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests service-provider JSON:API resources stay available and private.
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
  public function testProviderJsonApiResourcesAreSeededAndKeepMailPrivate(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $source = file_get_contents($moduleRoot . '/markaspot_service_provider.install');
    $this->assertIsString($source);

    // The update hook and its helper must exist.
    $this->assertStringContainsString('function markaspot_service_provider_update_9007()', $source);
    $this->assertStringContainsString('function markaspot_service_provider_update_9008()', $source);
    $this->assertStringContainsString('function _markaspot_service_provider_load_jsonapi_resource', $source);
    $this->assertStringContainsString('function _markaspot_service_provider_create_status_vocabulary()', $source);
    $this->assertStringContainsString('_markaspot_service_provider_create_status_vocabulary();', $source);
    $this->assertStringContainsString("_markaspot_service_provider_load_jsonapi_resource('taxonomy_term--service_provider'", $source);
    $this->assertStringContainsString("_markaspot_service_provider_load_jsonapi_resource('taxonomy_term--service_provider_status'", $source);
    $this->assertStringContainsString('_markaspot_service_provider_ensure_sp_status_jsonapi();', $source);
    $this->assertStringContainsString('_markaspot_service_provider_ensure_sp_organisation_jsonapi', $source);
    // It must seed from the Nuxt optional resource config source when this
    // feature is enabled after markaspot_nuxt.
    $this->assertStringContainsString("new FileStorage(\$module_path . '/config/optional')", $source);
    $this->assertStringContainsString("'jsonapi_extras.jsonapi_resource_config.' . \$resource_id", $source);
    // field_organisation must be exposed (not disabled) for the scoped fetch.
    $this->assertStringContainsString("\$resource_fields['field_organisation'] = [", $source);
    $this->assertStringContainsString("'publicName' => 'field_organisation'", $source);
    $this->assertStringContainsString("'disabled' => FALSE", $source);
    // Provider mail is used by backend delivery only and must not be exposed.
    $this->assertStringContainsString("\$resource_fields['field_sp_email'] = [", $source);
    $this->assertStringContainsString("'publicName' => 'field_sp_email'", $source);
    $this->assertStringContainsString("'disabled' => TRUE", $source);
  }

}
