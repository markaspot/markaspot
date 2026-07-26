<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\FileInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use Drupal\markaspot_nuxt\Controller\TenantSettingsController;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the complete jurisdiction branding asset lifecycle.
 *
 * The generated placeholder deliberately uses one managed file in all three
 * fields. These tests keep the database references, file.usage records, file
 * entities, and public files real so a field-only assertion cannot hide an
 * orphan or a prematurely deleted shared asset.
 *
 * @group markaspot_fastmap
 *
 * @covers \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService::provisionWorkspace
 * @covers \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService::teardownWorkspace
 * @covers \Drupal\markaspot_nuxt\Controller\TenantSettingsController::uploadLogo
 * @covers \Drupal\markaspot_nuxt\Controller\TenantSettingsController::deleteLogo
 */
#[RunTestsInSeparateProcesses]
final class BrandingAssetLifecycleKernelTest extends KernelTestBase {

  /**
   * Branding fields under test.
   */
  private const BRANDING_FIELDS = [
    'logo_light' => 'field_logo_light',
    'logo_dark' => 'field_logo_dark',
    'app_icon' => 'field_favicon',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'taxonomy',
    'options',
    'datetime',
    'language',
    'color_field',
    'geolocation',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_fastmap',
  ];

  /**
   * The real workspace provisioning service.
   */
  private WorkspaceProvisioningServiceInterface $provisioning;

  /**
   * The controller under test with real file services.
   */
  private TenantSettingsController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system',
      'user',
      'field',
      'filter',
      'node',
      'group',
    ]);

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->provisioning = $this->container->get('markaspot_fastmap.workspace_provisioning');
    $this->controller = TenantSettingsController::create($this->container);
  }

  /**
   * A generated shared signet survives until its final field is cleared.
   */
  public function testGeneratedSharedAssetDeletionLifecycle(): void {
    $group = $this->provisionWorkspace('shared-delete');
    $fids = $this->brandingFieldIds($group);

    $this->assertCount(3, array_filter($fids));
    $this->assertCount(
      1,
      array_unique($fids),
      'Provisioning must attach one file entity to every branding field.',
    );
    $sharedFid = reset($fids);
    $this->assertIsInt($sharedFid);
    $sharedFile = $this->container->get('entity_type.manager')
      ->getStorage('file')
      ->load($sharedFid);
    $this->assertInstanceOf(FileInterface::class, $sharedFile);
    $this->assertSame(
      'public://jurisdictions/' . $group->id() . '/logos/signet.svg',
      $sharedFile->getFileUri(),
    );
    $sharedPath = $this->assertStoredFileExists($sharedFid);
    $this->assertGroupFileUsage($sharedFid, $group, 3);

    $this->deleteVariant($group, 'app_icon');
    $group = $this->reloadGroup($group);
    $this->assertFieldEmpty($group, 'field_favicon');
    $this->assertFieldTargets($group, 'field_logo_light', $sharedFid);
    $this->assertFieldTargets($group, 'field_logo_dark', $sharedFid);
    $this->assertStoredFileExists($sharedFid, $sharedPath);
    $this->assertGroupFileUsage($sharedFid, $group, 2);

    $this->deleteVariant($group, 'logo_light');
    $group = $this->reloadGroup($group);
    $this->assertFieldEmpty($group, 'field_logo_light');
    $this->assertFieldTargets($group, 'field_logo_dark', $sharedFid);
    $this->assertStoredFileExists($sharedFid, $sharedPath);
    $this->assertGroupFileUsage($sharedFid, $group, 1);

    $this->deleteVariant($group, 'logo_dark');
    $group = $this->reloadGroup($group);
    $this->assertFieldEmpty($group, 'field_logo_dark');
    $this->assertStoredFileDeleted($sharedFid, $sharedPath);
    $this->assertGroupFileUsage($sharedFid, $group, 0);
  }

  /**
   * Independently uploaded assets are deleted independently.
   */
  public function testUploadedAssetDeletionIsVariantScoped(): void {
    $group = $this->createEmptyJurisdiction('uploaded-assets');

    $response = $this->controller->uploadLogo(
      $this->uploadRequest([
        'logo_light' => $this->svgUpload('light.svg', '#ef4444'),
        'logo_dark' => $this->svgUpload('dark.svg', '#111827'),
        'app_icon' => $this->svgUpload('icon.svg', '#2563eb'),
      ]),
      (string) $group->id(),
    );
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());

    $group = $this->reloadGroup($group);
    $fids = $this->brandingFieldIds($group);
    $this->assertCount(
      3,
      array_unique($fids),
      'Each uploaded variant must have its own managed file entity.',
    );
    $paths = [];
    foreach ($fids as $variant => $fid) {
      $paths[$variant] = $this->assertStoredFileExists($fid);
      $this->assertGroupFileUsage($fid, $group, 1);
    }

    $this->deleteVariant($group, 'logo_light');
    $group = $this->reloadGroup($group);
    $this->assertFieldEmpty($group, 'field_logo_light');
    $this->assertStoredFileDeleted($fids['logo_light'], $paths['logo_light']);
    $this->assertGroupFileUsage($fids['logo_light'], $group, 0);

    foreach (['logo_dark', 'app_icon'] as $variant) {
      $fieldName = self::BRANDING_FIELDS[$variant];
      $this->assertFieldTargets($group, $fieldName, $fids[$variant]);
      $this->assertStoredFileExists($fids[$variant], $paths[$variant]);
      $this->assertGroupFileUsage($fids[$variant], $group, 1);
    }
  }

  /**
   * Replacing a generated app icon preserves logos and removes later orphans.
   */
  public function testGeneratedAppIconReplacementLifecycle(): void {
    $group = $this->provisionWorkspace('app-icon-replace');
    $generatedFids = $this->brandingFieldIds($group);
    $generatedFid = $generatedFids['app_icon'];
    $generatedPath = $this->assertStoredFileExists($generatedFid);
    $this->assertGroupFileUsage($generatedFid, $group, 3);

    $firstResponse = $this->controller->uploadLogo(
      $this->uploadRequest([
        'app_icon' => $this->svgUpload('first-icon.svg', '#7c3aed'),
      ]),
      (string) $group->id(),
    );
    $this->assertSame(200, $firstResponse->getStatusCode(), $firstResponse->getContent());

    $group = $this->reloadGroup($group);
    $firstIconFid = $this->fieldTargetId($group, 'field_favicon');
    $this->assertNotSame($generatedFid, $firstIconFid);
    $this->assertFieldTargets($group, 'field_logo_light', $generatedFid);
    $this->assertFieldTargets($group, 'field_logo_dark', $generatedFid);
    $this->assertStoredFileExists($generatedFid, $generatedPath);
    $firstIconPath = $this->assertStoredFileExists($firstIconFid);
    $this->assertGroupFileUsage($generatedFid, $group, 2);
    $this->assertGroupFileUsage($firstIconFid, $group, 1);

    $secondResponse = $this->controller->uploadLogo(
      $this->uploadRequest([
        'app_icon' => $this->svgUpload('second-icon.svg', '#059669'),
      ]),
      (string) $group->id(),
    );
    $this->assertSame(200, $secondResponse->getStatusCode(), $secondResponse->getContent());

    $group = $this->reloadGroup($group);
    $secondIconFid = $this->fieldTargetId($group, 'field_favicon');
    $this->assertNotSame($firstIconFid, $secondIconFid);
    $this->assertFieldTargets($group, 'field_logo_light', $generatedFid);
    $this->assertFieldTargets($group, 'field_logo_dark', $generatedFid);
    $this->assertStoredFileExists($generatedFid, $generatedPath);
    $this->assertStoredFileExists($secondIconFid);
    $this->assertStoredFileDeleted($firstIconFid, $firstIconPath);
    $this->assertGroupFileUsage($generatedFid, $group, 2);
    $this->assertGroupFileUsage($firstIconFid, $group, 0);
    $this->assertGroupFileUsage($secondIconFid, $group, 1);
  }

  /**
   * Tears down a workspace and expects its generated signet to be gone.
   *
   * Teardown clears the group's file.usage records by deleting the group, so
   * the signet would otherwise survive as a permanent managed file with empty
   * usage plus bytes on disk. Every provisioned workspace carries one, so each
   * torn-down demo used to leave exactly one orphan behind.
   */
  public function testWorkspaceTeardownRemovesGeneratedAsset(): void {
    $group = $this->provisionWorkspace('teardown-assets');
    $fid = $this->fieldTargetId($group, 'field_favicon');
    $path = $this->assertStoredFileExists($fid);
    $this->assertGroupFileUsage($fid, $group, 3);

    $this->provisioning->teardownWorkspace((int) $group->id());

    $this->container->get('entity_type.manager')
      ->getStorage('group')
      ->resetCache([(int) $group->id()]);
    $this->assertNull(Group::load($group->id()));

    $this->container->get('entity_type.manager')->getStorage('file')->resetCache([$fid]);
    $file = $this->container->get('entity_type.manager')
      ->getStorage('file')
      ->load($fid);
    $this->assertNull($file, 'The generated signet must not survive its workspace.');
    $this->assertGroupFileUsage($fid, $group, 0);
    $this->assertFileDoesNotExist($path, 'The signet bytes must be gone from disk.');
  }

  /**
   * Creates the node and taxonomy bundles used by real provisioning.
   */
  private function createContentModel(): void {
    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service Request',
    ])->save();
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    if (!FilterFormat::load('plain_text')) {
      FilterFormat::create([
        'format' => 'plain_text',
        'name' => 'Plain text',
      ])->save();
    }
    if (!FilterFormat::load('basic_html')) {
      FilterFormat::create([
        'format' => 'basic_html',
        'name' => 'Basic HTML',
      ])->save();
    }

    Vocabulary::create([
      'vid' => 'service_category',
      'name' => 'Service categories',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Service statuses',
    ])->save();

    $this->createField('taxonomy_term', 'service_category', 'field_jurisdiction', 'entity_reference', [
      'target_type' => 'group',
    ]);
    $this->createField('taxonomy_term', 'service_status', 'field_jurisdiction', 'entity_reference', [
      'target_type' => 'group',
    ]);
    $this->createField('taxonomy_term', 'service_category', 'field_service_code', 'string');
    $this->createField('taxonomy_term', 'service_category', 'field_category_hex', 'color_field_type', [
      'format' => '#HEXHEX',
    ]);
    $this->createField('taxonomy_term', 'service_category', 'field_category_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_status_hex', 'color_field_type', [
      'format' => '#HEXHEX',
    ]);
    $this->createField('taxonomy_term', 'service_status', 'field_status_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');

    foreach (['service_request', 'page'] as $bundle) {
      $this->createField('node', $bundle, 'body', 'text_with_summary');
      $this->createField('node', $bundle, 'field_jurisdiction', 'entity_reference', [
        'target_type' => 'group',
      ]);
    }
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);
    $this->createField('node', 'service_request', 'field_geolocation', 'geolocation');
    $this->createField('node', 'service_request', 'field_address', 'string');
  }

  /**
   * Creates the jurisdiction bundle and fields used by branding lifecycle.
   */
  private function createJurisdictionModel(): void {
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');

    GroupType::create([
      'id' => 'jur',
      'label' => 'Jurisdiction',
    ])->save();
    GroupRole::create([
      'id' => 'jur-tenant_admin',
      'label' => 'Tenant Admin',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();

    $relationshipStorage = $this->container
      ->get('entity_type.manager')
      ->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      $relationshipTypeId = 'jur-' . str_replace(':', '-', $pluginId);
      if (!$relationshipStorage->load($relationshipTypeId)) {
        $relationshipStorage
          ->createFromPlugin(GroupType::load('jur'), $pluginId)
          ->save();
      }
    }

    $this->createField('group', 'jur', 'field_slug', 'string');
    $this->createField('group', 'jur', 'field_platform_name', 'string');
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'jur', 'field_boundary', 'text_long');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', [
      'target_type' => 'group',
    ]);
    $this->createField('group', 'jur', 'field_expiry_date', 'timestamp');
    $this->createField(
      'group',
      'jur',
      'field_service_categories',
      'entity_reference',
      ['target_type' => 'taxonomy_term'],
      -1,
    );
    foreach (self::BRANDING_FIELDS as $fieldName) {
      $this->createField('group', 'jur', $fieldName, 'file', [
        'target_type' => 'file',
        'display_field' => FALSE,
        'display_default' => FALSE,
        'uri_scheme' => 'public',
      ], 1, [
        'handler' => 'default:file',
        'file_directory' => 'jurisdictions/branding',
        'file_extensions' => 'svg png',
        'max_filesize' => '500 KB',
        'description_field' => FALSE,
      ]);
    }
  }

  /**
   * Creates one configurable field and its storage.
   */
  private function createField(
    string $entityType,
    string $bundle,
    string $fieldName,
    string $type,
    array $storageSettings = [],
    int $cardinality = 1,
    array $fieldSettings = [],
  ): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $storageSettings,
      ])->save();
    }

    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
      'required' => FALSE,
      'settings' => $fieldSettings ?: $storageSettings,
    ])->save();
  }

  /**
   * Provisions a real workspace with one demo category.
   */
  private function provisionWorkspace(string $slug): Group {
    $result = $this->provisioning->provisionWorkspace([
      'name' => ucwords(str_replace('-', ' ', $slug)),
      'slug' => $slug,
      'email' => $slug . '@example.test',
      'categories' => ['Road damage'],
      'language' => 'en',
      'lat' => 51.0,
      'lng' => 7.0,
      'zoom' => 13,
      'template' => 'civic-report',
    ]);

    $group = Group::load($result['group_id']);
    $this->assertInstanceOf(Group::class, $group);
    return $group;
  }

  /**
   * Creates a jurisdiction without generated assets for upload-only coverage.
   */
  private function createEmptyJurisdiction(string $slug): Group {
    $group = Group::create([
      'type' => 'jur',
      'label' => ucwords(str_replace('-', ' ', $slug)),
      'field_slug' => $slug,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Returns target IDs keyed by public branding variant.
   *
   * @return array<string, int>
   *   Managed file IDs keyed by request variant.
   */
  private function brandingFieldIds(Group $group): array {
    $fids = [];
    foreach (self::BRANDING_FIELDS as $variant => $fieldName) {
      $fids[$variant] = $this->fieldTargetId($group, $fieldName);
    }
    return $fids;
  }

  /**
   * Returns a required file target ID.
   */
  private function fieldTargetId(Group $group, string $fieldName): int {
    $this->assertTrue($group->hasField($fieldName));
    $this->assertFalse($group->get($fieldName)->isEmpty());
    $fid = (int) $group->get($fieldName)->target_id;
    $this->assertGreaterThan(0, $fid);
    return $fid;
  }

  /**
   * Calls the real delete endpoint for one public variant.
   */
  private function deleteVariant(Group $group, string $variant): void {
    $request = Request::create(
      '/tenant/settings/logo?variant=' . rawurlencode($variant),
      'DELETE',
    );
    $response = $this->controller->deleteLogo($request, (string) $group->id());
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
  }

  /**
   * Builds a multipart-style request with uploaded files.
   *
   * @param array<string, \Symfony\Component\HttpFoundation\File\UploadedFile> $files
   *   Uploaded files keyed by controller request variant.
   */
  private function uploadRequest(array $files): Request {
    return new Request([], [], [], [], $files);
  }

  /**
   * Creates a valid square SVG upload in the test filesystem.
   */
  private function svgUpload(string $filename, string $color): UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'branding-asset-');
    $this->assertNotFalse($path);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96">'
      . '<rect width="96" height="96" fill="' . $color . '"/></svg>';
    $this->assertNotFalse(file_put_contents($path, $svg));

    return new UploadedFile($path, $filename, 'image/svg+xml', NULL, TRUE);
  }

  /**
   * Reloads a group after controller persistence.
   */
  private function reloadGroup(Group $group): Group {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache([(int) $group->id()]);
    $reloaded = $storage->load($group->id());
    $this->assertInstanceOf(Group::class, $reloaded);
    return $reloaded;
  }

  /**
   * Asserts a branding field is empty.
   */
  private function assertFieldEmpty(Group $group, string $fieldName): void {
    $this->assertTrue($group->hasField($fieldName));
    $this->assertTrue($group->get($fieldName)->isEmpty());
  }

  /**
   * Asserts a branding field still targets the expected file.
   */
  private function assertFieldTargets(Group $group, string $fieldName, int $fid): void {
    $this->assertSame($fid, $this->fieldTargetId($group, $fieldName));
  }

  /**
   * Asserts both the managed file entity and its public file exist.
   */
  private function assertStoredFileExists(int $fid, ?string $expectedPath = NULL): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache([$fid]);
    $file = $storage->load($fid);
    $this->assertInstanceOf(FileInterface::class, $file);
    $path = $this->realPath((string) $file->getFileUri());
    if ($expectedPath !== NULL) {
      $this->assertSame($expectedPath, $path);
    }
    $this->assertFileExists($path);
    return $path;
  }

  /**
   * Asserts both the managed file entity and its public file are gone.
   */
  private function assertStoredFileDeleted(int $fid, string $path): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache([$fid]);
    $this->assertNull($storage->load($fid));
    $this->assertFileDoesNotExist($path);
  }

  /**
   * Asserts the real file.usage count for one jurisdiction reference.
   */
  private function assertGroupFileUsage(int $fid, Group $group, int $expectedCount): void {
    $query = $this->container->get('database')
      ->select('file_usage', 'fu')
      ->condition('fid', $fid)
      ->condition('module', 'file')
      ->condition('type', 'group')
      ->condition('id', $group->id());
    $query->addExpression('SUM(count)', 'usage_count');
    $actualCount = (int) $query->execute()->fetchField();
    $this->assertSame($expectedCount, $actualCount);

    if ($expectedCount > 0) {
      $file = $this->container->get('entity_type.manager')
        ->getStorage('file')
        ->load($fid);
      $this->assertInstanceOf(FileInterface::class, $file);
      $usage = $this->container->get('file.usage')->listUsage($file);
      $this->assertSame(
        $expectedCount,
        (int) ($usage['file']['group'][$group->id()] ?? 0),
      );
    }
  }

  /**
   * Resolves a stream URI to the underlying test filesystem path.
   */
  private function realPath(string $uri): string {
    $path = $this->container->get('file_system')->realpath($uri);
    $this->assertIsString($path);
    return $path;
  }

}
