<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_feedback\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that feedback grants follow the installation's e-mail field grants.
 *
 * @group markaspot_feedback
 */
#[RunTestsInSeparateProcesses]
final class FeedbackFieldPermissionsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node',
    'field_permissions', 'markaspot_feedback',
  ];

  /**
   * Field permission operations, including separate own-entity grants.
   */
  private const OPERATIONS = ['create', 'edit', 'edit own', 'view', 'view own'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_feedback.install';
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::load('anonymous')->grantPermission('access content')->save();
    Role::load('authenticated')->grantPermission('access content')->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    FieldStorageConfig::loadByName('node', 'field_feedback')?->delete();
    FieldStorageConfig::create([
      'field_name' => 'field_e_mail', 'entity_type' => 'node', 'type' => 'email',
      'third_party_settings' => ['field_permissions' => ['permission_type' => 'custom']],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_e_mail', 'entity_type' => 'node', 'bundle' => 'service_request',
    ])->save();
  }

  /**
   * E-mail grants are mirrored except citizen create, even on unknown roles.
   */
  public function testUpdateMirrorsEachEmailGrant(): void {
    $this->createFeedbackField('public');
    $all = Role::create(['id' => 'custom_contact_staff', 'label' => 'Contact staff']);
    foreach (self::OPERATIONS as $operation) {
      $all->grantPermission($operation . ' field_e_mail');
      Role::create([
        'id' => str_replace(' ', '_', $operation),
        'label' => $operation,
        'permissions' => [$operation . ' field_e_mail'],
      ])->save();
    }
    $all->save();
    // Familiar staff role names alone must never grant feedback permissions.
    Role::create(['id' => 'moderator', 'label' => 'Moderator'])->save();
    Role::create(['id' => 'admin_role', 'label' => 'Admin', 'is_admin' => TRUE])->save();
    Role::load('anonymous')->grantPermission('create field_e_mail')->save();
    Role::load('authenticated')->grantPermission('create field_e_mail')->save();
    Role::load('authenticated')->grantPermission('edit own field_e_mail')->save();
    $before = $this->rolePermissions();

    $message = markaspot_feedback_update_9004();

    $this->assertStringContainsString('switched from public', $message);
    $this->assertSame('custom', $this->permissionType());
    foreach ($before as $id => $permissions) {
      foreach (self::OPERATIONS as $operation) {
        $this->assertSame(
          in_array($operation . ' field_e_mail', $permissions, TRUE)
            && !($operation === 'create' && in_array($id, ['anonymous', 'authenticated'], TRUE)),
          in_array($operation . ' field_feedback', Role::load($id)->getPermissions(), TRUE),
          "$id/$operation",
        );
      }
    }
    $this->assertNull(Role::load('tenant_admin'));
    $user = User::create(['name' => 'fictional-staff', 'roles' => ['view'], 'status' => 1]);
    $user->save();
    $report = Node::create(['type' => 'service_request', 'status' => 1, 'field_feedback' => 'Fictional feedback']);
    $this->assertTrue($report->get('field_feedback')->access('view', $user));
    $this->assertFalse($report->get('field_feedback')->access('view', User::getAnonymousUser()));
  }

  /**
   * An unset setting counts as public, and a second run changes nothing.
   */
  public function testUnsetSettingAndIdempotence(): void {
    $this->createFeedbackField(NULL);
    Role::load('authenticated')->grantPermission('create field_e_mail')->save();
    $this->assertStringContainsString('switched from public (default)', markaspot_feedback_update_9004());
    $this->assertSame('custom', $this->permissionType());
    $before = $this->rolePermissions();
    $this->assertStringContainsString('already uses custom', markaspot_feedback_update_9004());
    $this->assertSame($before, $this->rolePermissions());
  }

  /**
   * Deliberate custom/private storage and all existing grants stay untouched.
   */
  public function testUpdateKeepsDeliberateSettings(): void {
    foreach (['private', 'custom'] as $type) {
      $this->createFeedbackField($type);
      $storage = FieldStorageConfig::loadByName('node', 'field_feedback');
      Role::create([
        'id' => 'contact_staff', 'label' => 'Contact staff',
        'permissions' => ['view field_e_mail'],
      ])->save();
      if ($type === 'custom') {
        Role::load('anonymous')->grantPermission('view field_feedback')->save();
      }
      $settings = $storage->get('third_party_settings');
      $permissions = $this->rolePermissions();

      $this->assertStringContainsString("already uses $type", markaspot_feedback_update_9004());

      $this->assertSame($settings, FieldStorageConfig::loadByName('node', 'field_feedback')->get('third_party_settings'));
      $this->assertSame($permissions, $this->rolePermissions());
      markaspot_feedback_install();
      $this->assertSame($settings, FieldStorageConfig::loadByName('node', 'field_feedback')->get('third_party_settings'));
      $this->assertSame($permissions, $this->rolePermissions());
      $storage->delete();
      Role::load('contact_staff')->delete();
    }
  }

  /**
   * Other permission plugins are a deliberate choice too.
   */
  public function testUpdateKeepsUnknownPermissionType(): void {
    $this->createFeedbackField('third_party_plugin');
    $settings = FieldStorageConfig::loadByName('node', 'field_feedback')->get('third_party_settings');
    $permissions = $this->rolePermissions();
    markaspot_feedback_update_9004();
    $this->assertSame($settings, FieldStorageConfig::loadByName('node', 'field_feedback')->get('third_party_settings'));
    $this->assertSame($permissions, $this->rolePermissions());
  }

  /**
   * A field left by uninstall receives the same transition on reinstall.
   */
  public function testReinstallSecuresPreservedPublicField(): void {
    $this->createFeedbackField('public');
    Role::create([
      'id' => 'contact_staff', 'label' => 'Contact staff',
      'permissions' => ['view field_e_mail'],
    ])->save();
    markaspot_feedback_install();
    $this->assertSame('custom', $this->permissionType());
    $this->assertContains('view field_feedback', Role::load('contact_staff')->getPermissions());
  }

  /**
   * New installs create a custom field and mirror the existing contact role.
   */
  public function testInstallMirrorsEmailGrants(): void {
    foreach (['anonymous', 'authenticated'] as $id) {
      Role::load($id)->grantPermission('create field_e_mail')->save();
    }
    Role::create([
      'id' => 'contact_staff', 'label' => 'Contact staff',
      'permissions' => ['view field_e_mail'],
    ])->save();
    $shipped = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/config/optional/field.storage.node.field_feedback.yml'));
    $this->assertSame('custom', $shipped['third_party_settings']['field_permissions']['permission_type']);

    markaspot_feedback_install();

    $this->assertSame('custom', $this->permissionType());
    $this->assertContains('view field_feedback', Role::load('contact_staff')->getPermissions());
    $this->assertNotContains('edit field_feedback', Role::load('contact_staff')->getPermissions());
    foreach (['anonymous', 'authenticated'] as $id) {
      $this->assertNotContains('create field_feedback', Role::load($id)->getPermissions());
    }
  }

  /**
   * Every shipped role, including all three tenant-admin files, has parity.
   */
  public function testShippedRolePermissionsMatchEmail(): void {
    $root = dirname(__DIR__, 5);
    $roles = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
      if (preg_match('/^user\.role\..+\.yml$/', $file->getFilename())) {
        $roles[$file->getPathname()] = Yaml::decode(file_get_contents($file->getPathname()));
      }
    }
    $this->assertArrayHasKey($root . '/modules/markaspot_tenant_admin/config/install/user.role.tenant_admin.yml', $roles);
    foreach ($roles as $file => $role) {
      $permissions = $role['permissions'] ?? [];
      foreach (self::OPERATIONS as $operation) {
        $this->assertSame(
          in_array($operation . ' field_e_mail', $permissions, TRUE)
            && !($operation === 'create' && in_array($role['id'], ['anonymous', 'authenticated'], TRUE)),
          in_array($operation . ' field_feedback', $permissions, TRUE),
          "$file/$operation",
        );
      }
    }
  }

  /**
   * Creates field_feedback with the given field_permissions type.
   */
  private function createFeedbackField(?string $type): void {
    FieldStorageConfig::create([
      'field_name' => 'field_feedback', 'entity_type' => 'node', 'type' => 'text_long',
      'third_party_settings' => $type === NULL ? [] : [
        'field_permissions' => ['permission_type' => $type],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_feedback', 'entity_type' => 'node', 'bundle' => 'service_request',
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Returns the stored field_permissions type of field_feedback.
   */
  private function permissionType(): ?string {
    return FieldStorageConfig::loadByName('node', 'field_feedback')->getThirdPartySetting('field_permissions', 'permission_type');
  }

  /**
   * Snapshots stored grants, including grants unrelated to feedback.
   *
   * @return array<string, string[]>
   *   Permissions keyed by role ID.
   */
  private function rolePermissions(): array {
    return array_map(static fn(Role $role): array => $role->getPermissions(), Role::loadMultiple());
  }

}
