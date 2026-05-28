<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests field-permissions config for personal service-request data.
 */
#[Group('service_request')]
class ServiceRequestPrivateFieldPermissionsConfigTest extends UnitTestCase {

  /**
   * The service_request module root.
   */
  private string $moduleRoot;

  /**
   * The profile root.
   */
  private string $profileRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->moduleRoot = dirname(__DIR__, 3);
    $this->profileRoot = dirname($this->moduleRoot, 2);
  }

  /**
   * Personal fields are custom-protected, not public.
   */
  public function testPersonalFieldStoragesUseCustomPermissions(): void {
    $fields = [
      'field_e_mail' => $this->moduleRoot . '/config/install/field.storage.node.field_e_mail.yml',
      'field_first_name' => $this->moduleRoot . '/config/install/field.storage.node.field_first_name.yml',
      'field_last_name' => $this->moduleRoot . '/config/install/field.storage.node.field_last_name.yml',
      'field_notification' => $this->moduleRoot . '/config/install/field.storage.node.field_notification.yml',
      'field_phone' => $this->moduleRoot . '/config/optional/field.storage.node.field_phone.yml',
      'field_gdpr' => $this->profileRoot . '/modules/markaspot_privacy/config/optional/field.storage.node.field_gdpr.yml',
    ];

    foreach ($fields as $fieldName => $path) {
      $this->assertFileExists($path, "$fieldName storage config exists.");
      $config = Yaml::decode(file_get_contents($path));
      $this->assertSame(
        'custom',
        $config['third_party_settings']['field_permissions']['permission_type'] ?? NULL,
        "$fieldName must not inherit public content visibility."
      );
    }
  }

  /**
   * Internal status uses a field-permissions protected entity reference.
   */
  public function testInternalStatusFieldUsesCustomPermissions(): void {
    $storagePath = $this->moduleRoot . '/config/install/field.storage.node.field_status_internal_term.yml';
    $fieldPath = $this->moduleRoot . '/config/install/field.field.node.service_request.field_status_internal_term.yml';

    $this->assertFileExists($storagePath);
    $storage = Yaml::decode(file_get_contents($storagePath));
    $this->assertSame('entity_reference', $storage['type']);
    $this->assertSame(
      'custom',
      $storage['third_party_settings']['field_permissions']['permission_type'] ?? NULL
    );

    $this->assertFileExists($fieldPath);
    $field = Yaml::decode(file_get_contents($fieldPath));
    $this->assertSame('node.service_request.field_status_internal_term', $field['id']);
    $this->assertSame(['internal_status' => 'internal_status'], $field['settings']['handler_settings']['target_bundles']);
  }

  /**
   * Internal status terms are jurisdiction-scoped and can carry definitions.
   */
  public function testInternalStatusTaxonomyConfigExists(): void {
    $vocabulary = $this->loadYaml($this->moduleRoot . '/config/install/taxonomy.vocabulary.internal_status.yml');
    $this->assertSame('internal_status', $vocabulary['vid']);

    $codeField = $this->loadYaml($this->moduleRoot . '/config/install/field.field.taxonomy_term.internal_status.field_internal_status_code.yml');
    $this->assertTrue($codeField['required']);

    $definitionField = $this->loadYaml($this->profileRoot . '/modules/markaspot_open311/config/install/field.field.taxonomy_term.internal_status.field_status_definition.yml');
    $this->assertSame('taxonomy_term.internal_status.field_status_definition', $definitionField['id']);

    $jurisdictionField = $this->loadYaml($this->profileRoot . '/modules/markaspot_group/config/optional/field.field.taxonomy_term.internal_status.field_jurisdiction.yml');
    $this->assertSame('taxonomy_term.internal_status.field_jurisdiction', $jurisdictionField['id']);
    $this->assertTrue($jurisdictionField['required']);

    $formDisplay = $this->loadYaml($this->profileRoot . '/modules/markaspot_open311/config/optional/core.entity_form_display.taxonomy_term.internal_status.default.yml');
    $this->assertSame(
      'status_definition_json_form',
      $formDisplay['content']['field_status_definition']['type'] ?? NULL
    );
  }

  /**
   * Dashboard management form exposes request attributes for staff editing.
   */
  public function testManagementFormExposesRequestAttributes(): void {
    $display = $this->loadYaml($this->moduleRoot . '/config/install/core.entity_form_display.node.service_request.management.yml');

    $this->assertSame(
      'text_textarea',
      $display['content']['field_request_attributes']['type'] ?? NULL
    );
    $this->assertArrayNotHasKey(
      'field_request_attributes',
      $display['hidden'] ?? [],
      'The dashboard needs request attributes visible in the management form-mode contract.'
    );
  }

  /**
   * Every service_request field must be deliberately classified for API use.
   */
  public function testEveryServiceRequestFieldHasExplicitApiClassification(): void {
    $classifiedFields = [
      // Public request shape / public filters.
      'field_address',
      'field_category',
      'field_district',
      'field_facility',
      'field_feedback',
      'field_geolocation',
      'field_jurisdiction',
      'field_object_id',
      'field_organisation',
      'field_request_image',
      'field_request_media',
      'field_status',
      'field_sublocality',
      // Citizen-supplied private data.
      'field_e_mail',
      'field_first_name',
      'field_gdpr',
      'field_last_name',
      'field_notification',
      'field_phone',
      'field_request_attributes',
      'field_approved',
      // Staff-only dashboard / operational fields.
      'field_attachment',
      'field_boilerplates_sp',
      'field_escalation',
      'field_hazard_category',
      'field_hazard_level',
      'field_internal_remark',
      'field_notes',
      'field_priority',
      'field_reassign_sp',
      'field_risk_score',
      'field_sentiment',
      'field_service_provider',
      'field_service_provider_feedback',
      'field_service_provider_files',
      'field_service_provider_notes',
      'field_service_provider_status',
      'field_sp_attachment',
      'field_status_internal',
      'field_status_internal_term',
      'field_status_notes',
    ];

    $actualFields = $this->serviceRequestFieldNames();
    sort($actualFields);
    sort($classifiedFields);

    $this->assertSame(
      $classifiedFields,
      $actualFields,
      'New service_request fields must be classified as public, citizen-private, or staff-only before they can ship through API layers.'
    );
  }

  /**
   * Staff-only API fields must fail closed through field_permissions.
   */
  public function testStaffOnlyApiFieldStoragesUseCustomPermissions(): void {
    $fields = [
      'field_attachment',
      'field_boilerplates_sp',
      'field_escalation',
      'field_hazard_category',
      'field_hazard_level',
      'field_internal_remark',
      'field_notes',
      'field_priority',
      'field_reassign_sp',
      'field_risk_score',
      'field_sentiment',
      'field_service_provider',
      'field_service_provider_feedback',
      'field_service_provider_files',
      'field_service_provider_notes',
      'field_service_provider_status',
      'field_sp_attachment',
      'field_status_internal',
      'field_status_internal_term',
      'field_status_notes',
    ];

    foreach ($fields as $fieldName) {
      $storagePath = $this->serviceRequestFieldStoragePath($fieldName);
      $this->assertNotNull($storagePath, "$fieldName storage config exists.");
      $config = $this->loadYaml($storagePath);
      $this->assertSame(
        'custom',
        $config['third_party_settings']['field_permissions']['permission_type'] ?? NULL,
        "$fieldName must fail closed outside explicit dashboard/API permissions."
      );
    }
  }

  /**
   * Citizen-writable non-public fields must still fail closed for reads.
   */
  public function testCitizenWritablePrivateFieldStoragesUseCustomPermissions(): void {
    foreach (['field_approved', 'field_request_attributes'] as $fieldName) {
      $storagePath = $this->serviceRequestFieldStoragePath($fieldName);
      $this->assertNotNull($storagePath, "$fieldName storage config exists.");
      $config = $this->loadYaml($storagePath);
      $this->assertSame(
        'custom',
        $config['third_party_settings']['field_permissions']['permission_type'] ?? NULL,
        "$fieldName must remain explicitly permissioned."
      );
    }
  }

  /**
   * GeoReport manager fields must stay in the audited staff-only allowlist.
   */
  public function testGeoreportManagerFieldsAreAudited(): void {
    $settings = $this->loadYaml($this->profileRoot . '/modules/markaspot_open311/config/install/markaspot_open311.settings.yml');
    $managerFields = $settings['field_access']['manager_fields'] ?? [];
    sort($managerFields);

    $this->assertSame([
      'field_ai_hazard_category',
      'field_hazard_category',
      'field_hazard_level',
      'field_organisation',
      'field_risk_score',
      'field_sentiment',
      'field_status_internal',
      'field_status_internal_term',
    ], $managerFields);
  }

  /**
   * Anonymous and authenticated users can submit, but cannot read PII fields.
   */
  public function testCitizenRolesOnlyCreatePersonalFields(): void {
    $personalFields = [
      'field_e_mail',
      'field_first_name',
      'field_gdpr',
      'field_last_name',
      'field_notification',
      'field_phone',
    ];

    foreach (['anonymous', 'authenticated'] as $roleId) {
      $role = $this->loadProfileRole($roleId);
      foreach ($personalFields as $fieldName) {
        $this->assertContains("create $fieldName", $role['permissions']);
        $this->assertNotContains("view $fieldName", $role['permissions']);
        $this->assertNotContains("view own $fieldName", $role['permissions']);
        $this->assertNotContains("edit $fieldName", $role['permissions']);
        $this->assertNotContains("edit own $fieldName", $role['permissions']);
      }
    }
  }

  /**
   * Citizen supplied structured attributes are write-only for public users.
   */
  public function testCitizenRolesCanCreateRequestAttributesWithoutReadingThem(): void {
    foreach (['anonymous', 'authenticated'] as $roleId) {
      $role = $this->loadProfileRole($roleId);
      $this->assertContains('create field_request_attributes', $role['permissions']);
      $this->assertNotContains('view field_request_attributes', $role['permissions']);
      $this->assertNotContains('view own field_request_attributes', $role['permissions']);
      $this->assertNotContains('edit field_request_attributes', $role['permissions']);
      $this->assertNotContains('edit own field_request_attributes', $role['permissions']);
    }
  }

  /**
   * The public approval flag backs the consent checkbox and remains write-only.
   */
  public function testCitizenRolesCanWriteApprovalConsentWithoutReadingIt(): void {
    foreach (['anonymous', 'authenticated'] as $roleId) {
      $role = $this->loadProfileRole($roleId);
      $this->assertContains('create field_approved', $role['permissions']);
      $this->assertContains('edit field_approved', $role['permissions']);
      $this->assertContains('edit own field_approved', $role['permissions']);
      $this->assertNotContains('view field_approved', $role['permissions']);
      $this->assertNotContains('view own field_approved', $role['permissions']);
    }
  }

  /**
   * Fresh install role config must not carry stale citizen edit grants.
   */
  public function testInstallAuthenticatedRoleCannotEditPersonalFields(): void {
    $role = $this->loadInstallRole('authenticated');
    $personalFields = [
      'field_e_mail',
      'field_first_name',
      'field_gdpr',
      'field_last_name',
      'field_notification',
      'field_phone',
    ];

    $this->assertContains('create field_e_mail', $role['permissions']);
    foreach ($personalFields as $fieldName) {
      $this->assertContains("create $fieldName", $role['permissions']);
      $this->assertNotContains("view $fieldName", $role['permissions']);
      $this->assertNotContains("view own $fieldName", $role['permissions']);
      $this->assertNotContains("edit $fieldName", $role['permissions']);
      $this->assertNotContains("edit own $fieldName", $role['permissions']);
    }
    $this->assertContains('create field_request_attributes', $role['permissions']);
    $this->assertNotContains('view field_request_attributes', $role['permissions']);
  }

  /**
   * Dashboard roles can read/edit personal fields for moderation workflows.
   */
  public function testDashboardRolesCanManagePersonalFields(): void {
    $personalFields = [
      'field_e_mail',
      'field_first_name',
      'field_gdpr',
      'field_last_name',
      'field_notification',
      'field_phone',
    ];

    foreach (['moderator', 'tenant_admin', 'editorial_board'] as $roleId) {
      $role = $this->loadProfileRole($roleId);
      foreach ($personalFields as $fieldName) {
        $this->assertContains("create $fieldName", $role['permissions']);
        $this->assertContains("edit $fieldName", $role['permissions']);
        $this->assertContains("view $fieldName", $role['permissions']);
      }
    }

    $moduleFields = [
      'field_e_mail',
      'field_first_name',
      'field_last_name',
      'field_notification',
    ];

    foreach (['moderator', 'editorial_board'] as $roleId) {
      $role = $this->loadModuleRole($roleId);
      foreach ($moduleFields as $fieldName) {
        $this->assertContains("create $fieldName", $role['permissions']);
        $this->assertContains("edit $fieldName", $role['permissions']);
        $this->assertContains("view $fieldName", $role['permissions']);
      }
    }
  }

  /**
   * Citizen roles cannot read or edit the internal status workflow field.
   */
  public function testCitizenRolesCannotManageInternalStatusField(): void {
    foreach (['anonymous', 'authenticated'] as $roleId) {
      $role = $this->loadProfileRole($roleId);
      foreach (['field_status_internal', 'field_status_internal_term'] as $fieldName) {
        foreach (['create', 'view', 'view own', 'edit', 'edit own'] as $operation) {
          $this->assertNotContains("$operation $fieldName", $role['permissions']);
        }
      }
    }
  }

  /**
   * Dashboard roles can read/edit the internal status workflow field.
   */
  public function testDashboardRolesCanManageInternalStatusField(): void {
    foreach (['moderator', 'tenant_admin', 'editorial_board'] as $roleId) {
      $role = $this->loadProfileRole($roleId);
      foreach (['field_status_internal', 'field_status_internal_term'] as $fieldName) {
        foreach (['create', 'view', 'view own', 'edit', 'edit own'] as $operation) {
          $this->assertContains("$operation $fieldName", $role['permissions']);
        }
      }
    }

    foreach (['moderator', 'editorial_board'] as $roleId) {
      $role = $this->loadModuleRole($roleId);
      foreach (['field_status_internal', 'field_status_internal_term'] as $fieldName) {
        foreach (['create', 'view', 'view own', 'edit', 'edit own'] as $operation) {
          $this->assertContains("$operation $fieldName", $role['permissions']);
        }
      }
    }
  }

  /**
   * Dashboard roles can read/edit operational API fields after hardening.
   */
  public function testDashboardRolesCanManageOperationalApiFields(): void {
    $fields = [
      'field_boilerplates_sp',
      'field_hazard_category',
      'field_hazard_level',
      'field_reassign_sp',
      'field_request_attributes',
      'field_risk_score',
      'field_sentiment',
      'field_service_provider_feedback',
      'field_service_provider_files',
      'field_service_provider_notes',
      'field_service_provider_status',
    ];

    foreach (['moderator', 'tenant_admin', 'editorial_board'] as $roleId) {
      $role = $this->loadProfileRole($roleId);
      foreach ($fields as $fieldName) {
        foreach (['create', 'view', 'view own', 'edit', 'edit own'] as $operation) {
          $this->assertContains("$operation $fieldName", $role['permissions']);
        }
      }
    }
  }

  /**
   * Existing tenant sites get the same field-permission alignment on updb.
   */
  public function testExistingSitesHaveFieldPermissionUpdateHook(): void {
    $path = $this->profileRoot . '/markaspot.install';
    $source = file_get_contents($path);
    $this->assertIsString($source);

    $start = strpos($source, 'function markaspot_update_11920(): string');
    $this->assertIsInt($start);
    $end = strpos($source, 'function markaspot_requirements', $start);
    $this->assertIsInt($end);
    $hook = substr($source, $start, $end - $start);

    $this->assertStringContainsString('function markaspot_update_11920(): string', $hook);
    $this->assertStringContainsString("FieldStorageConfig::loadByName('node', \$field_name)", $hook);
    $this->assertStringContainsString("\$field_storage->setThirdPartySetting('field_permissions', 'permission_type', 'custom')", $hook);
    $this->assertStringContainsString("foreach (['view', 'view own', 'edit', 'edit own'] as \$operation)", $hook);
    $this->assertStringContainsString("foreach (['moderator', 'tenant_admin', 'editorial_board'] as \$role_id)", $hook);
    $this->assertStringContainsString("foreach (['create', 'view', 'view own', 'edit', 'edit own'] as \$operation)", $hook);
    $this->assertStringNotContainsString('_markaspot_repair_field_permissions();', $hook);
  }

  /**
   * Existing tenant sites get the taxonomy-backed internal status update hook.
   */
  public function testExistingSitesHaveInternalStatusUpdateHook(): void {
    $path = $this->profileRoot . '/markaspot.install';
    $source = file_get_contents($path);
    $this->assertIsString($source);

    $start = strpos($source, 'function markaspot_update_11921(): string');
    $this->assertIsInt($start);
    $end = strpos($source, 'function markaspot_requirements', $start);
    $this->assertIsInt($end);
    $hook = substr($source, $start, $end - $start);

    $this->assertStringContainsString("Vocabulary::load('internal_status')", $hook);
    $this->assertStringContainsString('field_status_internal_term', $hook);
    $this->assertStringContainsString('field_internal_status_code', $hook);
    $this->assertStringContainsString('field_status_definition', $hook);
    $this->assertStringContainsString('field_jurisdiction', $hook);
    $this->assertStringContainsString('_markaspot_update_11921_copy_status_definition', $hook);
    $this->assertStringContainsString('_markaspot_update_11921_normalize_status_definition_json', $hook);
    $this->assertStringContainsString("\$definition = ['attributes' => array_values(\$definition)];", $hook);
    $this->assertStringContainsString('_markaspot_update_11921_enable_internal_status_jsonapi_resource($changes)', $hook);
    $this->assertStringContainsString("'type' => 'status_definition_json_form'", $hook);
    $this->assertStringContainsString("'manage dashboard notes'", $hook);
    $this->assertStringContainsString("'translate internal_status taxonomy_term'", $hook);
    $this->assertStringContainsString("FieldConfig::loadByName('taxonomy_term', 'service_status', 'field_jurisdiction')", $hook);
    $this->assertStringContainsString('_markaspot_update_11921_migrate_legacy_internal_status()', $hook);
  }

  /**
   * Existing tenant sites get operational field-permission hardening.
   */
  public function testExistingSitesHaveOperationalFieldPermissionUpdateHook(): void {
    $path = $this->profileRoot . '/markaspot.install';
    $source = file_get_contents($path);
    $this->assertIsString($source);

    $start = strpos($source, 'function markaspot_update_11922(): string');
    $this->assertIsInt($start);
    $end = strpos($source, 'function markaspot_requirements', $start);
    $this->assertIsInt($end);
    $hook = substr($source, $start, $end - $start);

    $this->assertStringContainsString('function markaspot_update_11922(): string', $hook);
    $this->assertStringContainsString('_markaspot_update_11922_operational_fields()', $hook);
    $this->assertStringContainsString('field_service_provider_notes', $hook);
    $this->assertStringContainsString('field_sentiment', $hook);
    $this->assertStringContainsString('$field_storage->setThirdPartySetting', $hook);
    $this->assertStringContainsString('_markaspot_update_11922_grant_operational_field_permissions', $hook);
  }

  /**
   * Existing tenant sites expose request attributes in management form mode.
   */
  public function testExistingSitesExposeRequestAttributesInManagementFormMode(): void {
    $path = $this->profileRoot . '/markaspot.install';
    $source = file_get_contents($path);
    $this->assertIsString($source);

    $start = strpos($source, 'function markaspot_update_11923(): string');
    $this->assertIsInt($start);
    $end = strpos($source, 'function markaspot_requirements', $start);
    $this->assertIsInt($end);
    $hook = substr($source, $start, $end - $start);

    $this->assertStringContainsString('function markaspot_update_11923(): string', $hook);
    $this->assertStringContainsString('field_request_attributes', $hook);
    $this->assertStringContainsString("'type' => 'text_textarea'", $hook);
    $this->assertStringContainsString("'group_internal'", $hook);
  }

  /**
   * Existing sites get module update hooks even when profile schema is stale.
   */
  public function testServiceRequestModuleMirrorsProfileRequestManagementUpdates(): void {
    $path = $this->profileRoot . '/modules/service_request/service_request.install';
    $source = file_get_contents($path);
    $this->assertIsString($source);

    $this->assertStringContainsString('function service_request_update_11012(): string', $source);
    $this->assertStringContainsString('function service_request_update_11013(): string', $source);
    $this->assertStringContainsString('function service_request_update_11014(): string', $source);
    $this->assertStringContainsString('function service_request_update_11015(): string', $source);
    $this->assertStringContainsString('_service_request_load_markaspot_profile_updates()', $source);
    $this->assertStringContainsString('markaspot_update_11920()', $source);
    $this->assertStringContainsString('markaspot_update_11921()', $source);
    $this->assertStringContainsString('markaspot_update_11922()', $source);
    $this->assertStringContainsString('markaspot_update_11923()', $source);
  }

  /**
   * Loads a profile-level optional role config.
   */
  private function loadProfileRole(string $roleId): array {
    $path = $this->profileRoot . "/config/optional/user.role.$roleId.yml";
    $this->assertFileExists($path, "$roleId role config exists.");
    return Yaml::decode(file_get_contents($path));
  }

  /**
   * Loads and decodes a YAML config file.
   */
  private function loadYaml(string $path): array {
    $this->assertFileExists($path);
    $data = Yaml::decode(file_get_contents($path));
    $this->assertIsArray($data);
    return $data;
  }

  /**
   * Finds service_request field instance names from profile config.
   *
   * @return string[]
   *   Field names.
   */
  private function serviceRequestFieldNames(): array {
    $paths = glob($this->profileRoot . '/{config,modules}/*/{config/install,config/optional}/field.field.node.service_request.field_*.yml', GLOB_BRACE);
    $paths = array_merge(
      $paths ?: [],
      glob($this->profileRoot . '/config/{install,optional}/field.field.node.service_request.field_*.yml', GLOB_BRACE) ?: [],
      glob($this->moduleRoot . '/config/{install,optional}/field.field.node.service_request.field_*.yml', GLOB_BRACE) ?: []
    );

    $fields = [];
    foreach (array_unique($paths) as $path) {
      $config = $this->loadYaml($path);
      $fieldName = $config['field_name'] ?? NULL;
      if (is_string($fieldName) && str_starts_with($fieldName, 'field_')) {
        $fields[] = $fieldName;
      }
    }

    return array_values(array_unique($fields));
  }

  /**
   * Finds a node field storage config by field name.
   */
  private function serviceRequestFieldStoragePath(string $fieldName): ?string {
    $paths = glob($this->profileRoot . "/{config,modules}/*/{config/install,config/optional}/field.storage.node.$fieldName.yml", GLOB_BRACE);
    $paths = array_merge(
      $paths ?: [],
      glob($this->profileRoot . "/config/{install,optional}/field.storage.node.$fieldName.yml", GLOB_BRACE) ?: [],
      glob($this->moduleRoot . "/config/{install,optional}/field.storage.node.$fieldName.yml", GLOB_BRACE) ?: []
    );

    return $paths[0] ?? NULL;
  }

  /**
   * Loads a profile-level install role config.
   */
  private function loadInstallRole(string $roleId): array {
    $path = $this->profileRoot . "/config/install/user.role.$roleId.yml";
    $this->assertFileExists($path, "$roleId install role config exists.");
    return Yaml::decode(file_get_contents($path));
  }

  /**
   * Loads a service_request install role config.
   */
  private function loadModuleRole(string $roleId): array {
    $path = $this->moduleRoot . "/config/install/user.role.$roleId.yml";
    $this->assertFileExists($path, "$roleId module role config exists.");
    return Yaml::decode(file_get_contents($path));
  }

}
