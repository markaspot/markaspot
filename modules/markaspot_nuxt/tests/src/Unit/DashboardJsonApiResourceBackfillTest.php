<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Verifies the deploy-safe JSON:API dashboard resource backfill contract.
 *
 * @group markaspot_nuxt
 */
final class DashboardJsonApiResourceBackfillTest extends UnitTestCase {

  /**
   * The resource types required by the requests dashboard and its includes.
   */
  private const RESOURCE_IDS = [
    'node--service_request',
    'group--jur',
    'group--org',
    'taxonomy_term--service_category',
    'taxonomy_term--service_status',
    'taxonomy_term--district',
    'taxonomy_term--sublocality',
    'media--request_image',
    'file--file',
    'paragraph--status',
    'paragraph--internal_remark',
    'taxonomy_term--service_provider',
    'taxonomy_term--service_provider_status',
    'node--boilerplate',
    'user--user',
  ];

  /**
   * Ensures legacy tenants receive only the shipped dashboard resources.
   */
  public function testDashboardResourcesAreShippedAndBackfilled(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $install = file_get_contents($moduleRoot . '/markaspot_nuxt.install');

    $this->assertIsString($install);
    $this->assertStringContainsString('function markaspot_nuxt_update_11907(): string', $install);
    $this->assertStringContainsString('function markaspot_nuxt_update_11909(): string', $install);
    $this->assertStringContainsString("hasDefinition('jsonapi_resource_config')", $install);
    $this->assertStringContainsString('$resource = $storage->create($seed);', $install);
    $this->assertStringContainsString('$hardeningPolicies = [', $install);
    $this->assertStringContainsString("'group--jur' => ['group', 'jur', ['id', 'label', 'field_slug']]", $install);

    foreach (self::RESOURCE_IDS as $resourceId) {
      $this->assertStringContainsString("'" . $resourceId . "'", $install);

      $config = Yaml::decode((string) file_get_contents(
        $moduleRoot . '/config/optional/jsonapi_extras.jsonapi_resource_config.' . $resourceId . '.yml'
      ));
      $this->assertSame($resourceId, $config['id']);
      $this->assertFalse($config['disabled']);
    }
  }

  /**
   * Ensures the jurisdiction resource keeps contact information private.
   */
  public function testJurisdictionResourceOnlyExposesIdAndLabel(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $config = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/optional/jsonapi_extras.jsonapi_resource_config.group--jur.yml'
    ));

    $this->assertFalse($config['resourceFields']['id']['disabled']);
    $this->assertFalse($config['resourceFields']['label']['disabled']);
    $this->assertFalse($config['resourceFields']['field_slug']['disabled']);
    $this->assertTrue($config['resourceFields']['field_jurisdiction_e_mail']['disabled']);
    $this->assertTrue($config['resourceFields']['field_jurisdiction_address']['disabled']);
    $this->assertTrue($config['resourceFields']['field_nuxt_config']['disabled']);
    $this->assertTrue($config['resourceFields']['field_billing_email']['disabled']);
    $this->assertTrue($config['resourceFields']['field_billing_tax_id']['disabled']);
    $this->assertTrue($config['resourceFields']['field_stripe_customer_id']['disabled']);
    $this->assertTrue($config['resourceFields']['field_stripe_subscription_id']['disabled']);
  }

  /**
   * Ensures service-provider status exposes only the dashboard contract.
   */
  public function testServiceProviderStatusResourceUsesMinimalFields(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $config = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/optional/jsonapi_extras.jsonapi_resource_config.taxonomy_term--service_provider_status.yml'
    ));

    foreach (['tid', 'status', 'name', 'weight'] as $fieldName) {
      $this->assertFalse($config['resourceFields'][$fieldName]['disabled']);
    }
    foreach (['uuid', 'description', 'parent', 'revision_user'] as $fieldName) {
      $this->assertTrue($config['resourceFields'][$fieldName]['disabled']);
    }
  }

  /**
   * Ensures boilerplate resources expose only dashboard-required fields.
   */
  public function testBoilerplateResourceUsesMinimalFields(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $config = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/optional/jsonapi_extras.jsonapi_resource_config.node--boilerplate.yml'
    ));

    foreach (['nid', 'title', 'body', 'field_jurisdiction', 'field_boilerplate_type', 'field_organisation'] as $fieldName) {
      $this->assertFalse($config['resourceFields'][$fieldName]['disabled']);
    }
    foreach (['uuid', 'uid', 'revision_uid', 'revision_log', 'created', 'changed'] as $fieldName) {
      $this->assertTrue($config['resourceFields'][$fieldName]['disabled']);
    }
  }

  /**
   * Ensures internal remarks expose only fields rendered by the dashboard.
   */
  public function testInternalRemarkResourceUsesMinimalFields(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $config = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/optional/jsonapi_extras.jsonapi_resource_config.paragraph--internal_remark.yml'
    ));

    foreach (['id', 'uuid', 'created', 'field_internal_remark_text', 'field_author'] as $fieldName) {
      $this->assertFalse($config['resourceFields'][$fieldName]['disabled']);
    }
    foreach (['revision_id', 'parent_id', 'behavior_settings'] as $fieldName) {
      $this->assertTrue($config['resourceFields'][$fieldName]['disabled']);
    }
  }

  /**
   * Ensures legacy internal-remark resources receive the same restriction.
   */
  public function testExistingInternalRemarkResourceIsHardenedByBackfill(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $install = file_get_contents($moduleRoot . '/markaspot_nuxt.install');

    $this->assertIsString($install);
    $this->assertStringContainsString('$existing = $storage->load($resourceId);', $install);
    $this->assertStringContainsString("'paragraph--internal_remark' => ['paragraph', 'internal_remark', ['id', 'uuid', 'created', 'field_internal_remark_text', 'field_author']]", $install);
    $this->assertStringContainsString("'taxonomy_term--service_provider_status' => ['taxonomy_term', 'service_provider_status', ['tid', 'status', 'name', 'weight']]", $install);
    $this->assertStringContainsString('$hardenResourceFields($existing, $entityTypeId, $bundle, $allowedFields)', $install);
    $this->assertStringContainsString('$existing->save();', $install);
  }

  /**
   * Ensures every shipped jurisdiction field is explicitly private by default.
   */
  public function testJurisdictionConfigCoversEveryShippedJurisdictionField(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $profileRoot = dirname($moduleRoot, 2);
    $resourceConfig = Yaml::decode((string) file_get_contents(
      $moduleRoot . '/config/optional/jsonapi_extras.jsonapi_resource_config.group--jur.yml'
    ));
    $resourceFields = $resourceConfig['resourceFields'];
    $fieldConfigFiles = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($profileRoot, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($fieldConfigFiles as $file) {
      if (!$file instanceof \SplFileInfo || !str_starts_with($file->getFilename(), 'field.field.group.jur.')) {
        continue;
      }
      $fieldConfig = Yaml::decode((string) file_get_contents($file->getPathname()));
      $fieldName = $fieldConfig['field_name'] ?? NULL;
      if (!is_string($fieldName) || $fieldName === '') {
        continue;
      }

      $this->assertArrayHasKey($fieldName, $resourceFields);
      if ($fieldName === 'field_slug') {
        $this->assertFalse($resourceFields[$fieldName]['disabled']);
        continue;
      }
      $this->assertTrue($resourceFields[$fieldName]['disabled']);
    }
  }

}
