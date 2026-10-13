<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the repair of drifted outsider moderator group roles.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class ModeratorRoleDriftRepairKernelTest extends KernelTestBase {

  /**
   * Permissions of the drifted jur-moderator role on fastmap.
   */
  private const DRIFTED_JUR_PERMISSIONS = [
    'view group',
    'view group_node:boilerplate entity',
    'view group_node:service_request entity',
    'view group_node:service_request relationship',
    'view own unpublished group_node:service_request entity',
    'view unpublished group_node:service_request entity',
  ];

  /**
   * Permissions of the drifted org-moderator role on fastmap.
   */
  private const DRIFTED_ORG_PERMISSIONS = [
    'access group_node overview',
    'create group_node:service_request entity',
    'view group',
    'view group_node:service_request entity',
    'view group_node:service_request relationship',
    'view own unpublished group_node:service_request entity',
    'view unpublished group_node:service_request entity',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'entity',
    'flexible_permissions',
    'group',
    'markaspot_group',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['system', 'user', 'field', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create(['id' => 'moderator', 'label' => 'Moderator'])->save();

    // Group insert hooks query this user field.
    FieldStorageConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'All groups member',
    ])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $relationship_types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['jur', 'org'] as $group_type) {
      if (!$relationship_types->load($group_type . '-group_membership')) {
        $relationship_types->createFromPlugin(GroupType::load($group_type), 'group_membership')->save();
      }
    }

    GroupRole::create([
      'id' => 'org-insider',
      'label' => 'Moderator',
      'group_type' => 'org',
      'scope' => 'insider',
      'global_role' => 'moderator',
      'permissions' => ['view group', 'update any group_node:service_request entity'],
    ])->save();

    require_once dirname(__DIR__, 3) . '/markaspot_group.install';
  }

  /**
   * Drifted roles are restored on a multi-tenant install.
   */
  public function testMultiTenantDriftIsRepaired(): void {
    $this->createDriftedRoles();
    $own = $this->group('jur', 'Tenant A');
    $foreign = $this->group('jur', 'Tenant B');
    $foreign_org = $this->group('org', 'Tenant B works');
    $own_org = $this->group('org', 'Tenant A works');

    $outsider = $this->moderator('outsider');
    $member = $this->moderator('member');
    $own_org->addMember($member);

    $this->assertTrue($this->can('view unpublished group_node:service_request entity', $outsider, $foreign));
    $this->assertTrue($this->can('create group_node:service_request entity', $outsider, $foreign_org));

    $message = markaspot_group_update_11955();

    $this->assertStringContainsString('jur-moderator restored to the individual scope', $message);
    $this->assertStringContainsString('org-moderator restored to the individual scope', $message);
    // The drifted jur role lacks the shipped page permissions; that is
    // reported, not changed.
    $this->assertStringContainsString('shipped but absent: create group_node:page entity', $message);
    foreach (['jur-moderator' => self::DRIFTED_JUR_PERMISSIONS, 'org-moderator' => self::DRIFTED_ORG_PERMISSIONS] as $role_id => $permissions) {
      $role = GroupRole::load($role_id);
      $this->assertSame('individual', $role->getScope());
      $this->assertFalse($role->getGlobalRoleId());
      $this->assertSame($permissions, $role->getPermissions());
    }

    // A moderator outside a tenant no longer sees or creates its reports.
    foreach ([$own, $foreign] as $jurisdiction) {
      $this->assertFalse($this->can('view unpublished group_node:service_request entity', $outsider, $jurisdiction));
      $this->assertFalse($this->can('view group', $outsider, $jurisdiction));
    }
    $this->assertFalse($this->can('create group_node:service_request entity', $outsider, $foreign_org));
    $this->assertFalse($this->can('create group_node:service_request entity', $member, $foreign_org));

    // Membership still grants the insider moderation rights.
    $this->assertTrue($this->can('update any group_node:service_request entity', $member, $own_org));
    $this->assertFalse($this->can('update any group_node:service_request entity', $member, $foreign_org));

    $this->assertNoModeratorDependency();

    // A second run changes nothing.
    $second = markaspot_group_update_11955();
    $this->assertStringContainsString('jur-moderator unchanged (scope individual)', $second);
    $this->assertStringContainsString('org-moderator unchanged (scope individual)', $second);
    $this->assertNoModeratorDependency();
  }

  /**
   * An individually assigned org-moderator survives the scope change.
   */
  public function testIndividualMembershipSurvivesRepair(): void {
    // The membership got org-moderator while the role was still individual;
    // the role drifted afterwards.
    GroupRole::create([
      'id' => 'org-moderator',
      'label' => 'Moderation',
      'group_type' => 'org',
      'scope' => 'individual',
      'permissions' => self::DRIFTED_ORG_PERMISSIONS,
    ])->save();
    $this->group('jur', 'Tenant A');
    $this->group('jur', 'Tenant B');
    $org = $this->group('org', 'Tenant A works');
    $staff = User::create(['name' => 'staff', 'status' => 1]);
    $staff->save();
    $org->addMember($staff, ['group_roles' => ['org-moderator']]);

    // Group refuses to move an assigned role to the outsider scope, so the
    // drift can only arrive as written config (e.g. a config import).
    $this->container->get('config.factory')->getEditable('group.role.org-moderator')
      ->set('scope', 'outsider')
      ->set('global_role', 'moderator')
      ->save();
    $this->container->get('entity_type.manager')->getStorage('group_role')->resetCache();
    $this->assertSame('outsider', GroupRole::load('org-moderator')->getScope());

    markaspot_group_update_11955();

    $membership = $org->getMember($staff);
    $this->assertNotFalse($membership);
    $this->assertArrayHasKey('org-moderator', $membership->getRoles(FALSE));
    $this->assertTrue($this->can('create group_node:service_request entity', $staff, $org));
    $this->assertNoModeratorDependency(['org-moderator']);
  }

  /**
   * A single-tenant install keeps its drifted roles.
   */
  public function testSingleTenantDriftIsLeftAlone(): void {
    $this->createDriftedRoles();
    $tenant = $this->group('jur', 'Only tenant');
    $outsider = $this->moderator('outsider');

    $message = markaspot_group_update_11955();

    $this->assertSame('Moderator group roles left unchanged: 1 jurisdiction(s), not a multi-tenant install.', $message);
    foreach (['jur-moderator', 'org-moderator'] as $role_id) {
      $role = GroupRole::load($role_id);
      $this->assertSame('outsider', $role->getScope());
      $this->assertSame('moderator', $role->getGlobalRoleId());
    }
    $this->assertTrue($this->can('view unpublished group_node:service_request entity', $outsider, $tenant));
  }

  /**
   * Shipped roles stay as they are.
   */
  public function testShippedRolesAreNoOp(): void {
    foreach (['jur', 'org'] as $group_type) {
      GroupRole::create([
        'id' => $group_type . '-moderator',
        'label' => 'Moderation',
        'group_type' => $group_type,
        'scope' => 'individual',
        'permissions' => ['view group'],
      ])->save();
    }
    $this->group('jur', 'Tenant A');
    $this->group('jur', 'Tenant B');

    $message = markaspot_group_update_11955();

    $this->assertSame('Moderator group roles on 2 jurisdictions: jur-moderator unchanged (scope individual); org-moderator unchanged (scope individual).', $message);
  }

  /**
   * Asserts the persisted roles no longer depend on the Drupal moderator role.
   *
   * @param string[] $role_ids
   *   The group roles to check.
   */
  private function assertNoModeratorDependency(array $role_ids = ['jur-moderator', 'org-moderator']): void {
    foreach ($role_ids as $role_id) {
      $config = $this->container->get('config.factory')->get('group.role.' . $role_id);
      $this->assertNull($config->get('global_role'), $role_id);
      $this->assertNotContains('user.role.moderator', $config->get('dependencies.config') ?? [], $role_id);
    }
  }

  /**
   * Creates jur-moderator and org-moderator in their drifted form.
   */
  private function createDriftedRoles(): void {
    foreach (['jur' => self::DRIFTED_JUR_PERMISSIONS, 'org' => self::DRIFTED_ORG_PERMISSIONS] as $group_type => $permissions) {
      GroupRole::create([
        'id' => $group_type . '-moderator',
        'label' => 'Moderation',
        'group_type' => $group_type,
        'scope' => 'outsider',
        'global_role' => 'moderator',
        'permissions' => $permissions,
      ])->save();
    }
  }

  /**
   * Creates a group.
   */
  private function group(string $type, string $label): GroupInterface {
    $group = Group::create(['type' => $type, 'label' => $label]);
    $group->save();
    return $group;
  }

  /**
   * Creates an account with the Drupal moderator role.
   */
  private function moderator(string $name): UserInterface {
    $account = User::create(['name' => $name, 'status' => 1, 'roles' => ['moderator']]);
    $account->save();
    return $account;
  }

  /**
   * Checks a group permission for an account.
   */
  private function can(string $permission, UserInterface $account, GroupInterface $group): bool {
    return $this->container->get('group_permission.checker')->hasPermissionInGroup($permission, $account, $group);
  }

}
