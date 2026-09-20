<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_boilerplate\Kernel;

use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3) . '/markaspot_boilerplate.install';

/**
 * Tests optional template management rights on installation and update.
 *
 * @group markaspot_boilerplate
 */
#[RunTestsInSeparateProcesses]
final class BoilerplateInstallKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'options', 'entity',
    'flexible_permissions', 'group', 'gnode',
  ];

  /**
   * The optional template rights, separate from the generic Group baseline.
   */
  private const PERMISSIONS = [
    'create group_node:boilerplate entity',
    'create group_node:boilerplate relationship',
    'delete any group_node:boilerplate entity',
    'update any group_node:boilerplate entity',
    'view group_node:boilerplate entity',
    'view group_node:boilerplate relationship',
    'view unpublished group_node:boilerplate entity',
  ];

  /**
   * Ensures optional rights are granted only by their enabled provider.
   */
  public function testConditionalInstallAndUpdate(): void {
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'group']);
    $type = GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction']);
    $type->save();
    NodeType::create(['type' => 'boilerplate', 'name' => 'Template'])->save();
    $this->container->get('entity_type.manager')->getStorage('group_relationship_type')
      ->createFromPlugin($type, 'group_node:boilerplate')->save();
    $role = GroupRole::create([
      'id' => 'jur-tenant_admin', 'label' => 'Tenant admin',
      'group_type' => 'jur', 'scope' => 'individual',
      'permissions' => ['view group'],
    ]);
    $role->save();
    $base = Yaml::parseFile(dirname(__DIR__, 4) . '/markaspot_group/config/install/group.role.jur-tenant_admin.yml');
    foreach (self::PERMISSIONS as $permission) {
      $this->assertNotContains($permission, $base['permissions']);
    }
    markaspot_boilerplate_install();
    markaspot_boilerplate_update_11006();
    $this->assertSame(['view group'], GroupRole::load('jur-tenant_admin')->getPermissions());

    $this->container->get('module_installer')->install(['markaspot_boilerplate']);
    $role = GroupRole::load('jur-tenant_admin');
    foreach (self::PERMISSIONS as $permission) {
      $this->assertTrue($role->hasPermission($permission), $permission);
      $role->revokePermission($permission);
    }
    $role->save();
    markaspot_boilerplate_modules_installed(['node']);
    $this->assertSame(['view group'], GroupRole::load('jur-tenant_admin')->getPermissions());
    markaspot_boilerplate_install(TRUE);
    $this->assertSame(['view group'], GroupRole::load('jur-tenant_admin')->getPermissions());
    markaspot_boilerplate_update_11006();
    $role = GroupRole::load('jur-tenant_admin');
    foreach (self::PERMISSIONS as $permission) {
      $this->assertTrue($role->hasPermission($permission), $permission);
    }
    $this->assertTrue($role->hasPermission('view group'));
    markaspot_boilerplate_update_11006();
    $this->assertCount(8, GroupRole::load('jur-tenant_admin')->getPermissions());
    foreach (self::PERMISSIONS as $permission) {
      $role->revokePermission($permission);
    }
    $role->save();
    markaspot_boilerplate_modules_installed(['markaspot_group']);
    $this->assertCount(8, GroupRole::load('jur-tenant_admin')->getPermissions());
    $role->delete();
    markaspot_boilerplate_update_11006();
    $this->assertNull(GroupRole::load('jur-tenant_admin'));
  }

}
