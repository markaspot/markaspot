<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Controller\GroupInvitationController;
use Drupal\markaspot_group\Service\WorkspaceAccountName;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests account names that do not expose e-mail addresses.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class WorkspaceAccountNameKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_permissions',
    'file',
    'filter',
    'text',
    'node',
    'taxonomy',
    'entity',
    'flexible_permissions',
    'group',
    'markaspot_group',
  ];

  /**
   * Workspace A, slug "lindenhof".
   */
  private Group $tenantA;

  /**
   * Workspace B, slug "riverside".
   */
  private Group $tenantB;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['system', 'user', 'field', 'group']);
    $this->installSchema('markaspot_group', ['markaspot_group_invitations']);

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
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root@example.org', 'mail' => 'root@example.org', 'status' => 1])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['jur', 'org'] as $groupType) {
      if (!$storage->load($groupType . '-group_membership')) {
        $storage->createFromPlugin(GroupType::load($groupType), 'group_membership')->save();
      }
    }
    foreach (['jur-tenant_admin', 'jur-member', 'jur-org_member'] as $roleId) {
      if (!GroupRole::load($roleId)) {
        GroupRole::create([
          'id' => $roleId,
          'label' => $roleId,
          'group_type' => 'jur',
          'scope' => 'individual',
        ])->save();
      }
    }
    FieldStorageConfig::create([
      'field_name' => 'field_slug',
      'entity_type' => 'group',
      'type' => 'string',
    ])->save();
    FieldConfig::create(['field_name' => 'field_slug', 'entity_type' => 'group', 'bundle' => 'jur', 'label' => 'Slug'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'group',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'group',
      'bundle' => 'org',
      'label' => 'Jurisdiction',
    ])->save();

    $this->tenantA = Group::create(['type' => 'jur', 'label' => 'Lindenhof', 'field_slug' => 'lindenhof']);
    $this->tenantA->save();
    $this->tenantB = Group::create(['type' => 'jur', 'label' => 'Riverside', 'field_slug' => 'riverside']);
    $this->tenantB->save();
  }

  /**
   * Owner and member names are readable, unique and short enough.
   */
  public function testNamesForOwnersAndMembers(): void {
    $names = $this->container->get('markaspot_group.workspace_account_name');
    $this->assertSame('lindenhof-admin', $names->forOwner('lindenhof'));
    $this->account('lindenhof-admin');
    $this->assertSame('lindenhof-admin-2', $names->forOwner('lindenhof'));
    $this->assertMatchesRegularExpression('/^lindenhof-member-[0-9a-f]{4}$/', $names->forMember('lindenhof'));

    $long = str_repeat('a', 70);
    $this->assertLessThanOrEqual(UserInterface::USERNAME_MAX_LENGTH, strlen($names->forOwner($long)));
    $this->assertLessThanOrEqual(UserInterface::USERNAME_MAX_LENGTH, strlen($names->forMember($long)));

    $this->assertTrue(WorkspaceAccountName::exposesMail('Anna@Example.org', 'anna@example.org'));
    $this->assertTrue(WorkspaceAccountName::exposesMail('someone@else.example', 'anna@example.org'));
    $this->assertFalse(WorkspaceAccountName::exposesMail('lindenhof-admin', 'anna@example.org'));
  }

  /**
   * Invited members get a workspace name, not their address.
   */
  public function testInvitationCreatesWorkspaceName(): void {
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(GroupInvitationController::class);
    $method = new \ReflectionMethod($controller, 'findOrCreateUser');
    $user = $method->invoke($controller, 'anna.schmidt@example.org', 'en', 'lindenhof');

    $this->assertInstanceOf(UserInterface::class, $user);
    $this->assertMatchesRegularExpression('/^lindenhof-member-[0-9a-f]{4,8}$/', $user->getAccountName());
    $this->assertStringNotContainsString('anna', $user->getAccountName());
    $this->assertSame('anna.schmidt@example.org', $user->getEmail());
  }

  /**
   * The update renames passwordless e-mail names once, on multi-tenant sites.
   */
  public function testUpdateRenamesMailNamesOnce(): void {
    $owner = $this->account('owner@example.org');
    $this->tenantA->addMember($owner, ['group_roles' => ['jur-tenant_admin']]);
    $member = $this->account('member@example.org');
    $this->tenantB->addMember($member);
    $organisation = Group::create([
      'type' => 'org',
      'label' => 'Department',
      'field_jurisdiction' => $this->tenantB->id(),
    ]);
    $organisation->save();
    $orgMember = $this->account('org.member@example.org');
    $organisation->addMember($orgMember);
    $loner = $this->account('loner@example.org');
    $withPassword = $this->account('password@example.org', 'secret-password');
    $plain = $this->account('plain-name');

    $this->container->get('module_handler')->loadInclude('markaspot_group', 'install');
    $this->assertStringContainsString('Renamed 4 account(s)', $this->runRenameUpdate());

    $this->assertSame('lindenhof-admin', User::load($owner->id())->getAccountName());
    $this->assertMatchesRegularExpression('/^riverside-member-[0-9a-f]{4}$/', User::load($member->id())->getAccountName());
    $this->assertMatchesRegularExpression('/^riverside-member-[0-9a-f]{4}$/', User::load($orgMember->id())->getAccountName());
    $this->assertMatchesRegularExpression('/^workspace-member-[0-9a-f]{4}$/', User::load($loner->id())->getAccountName());
    $this->assertSame('password@example.org', User::load($withPassword->id())->getAccountName(), 'An account with a password keeps its login name.');
    $this->assertSame('plain-name', User::load($plain->id())->getAccountName());
    $this->assertSame('root@example.org', User::load(1)->getAccountName(), 'uid 1 is never renamed.');

    // The mail is unchanged, so the OTP login by mail finds the same account.
    $byMail = $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['mail' => 'owner@example.org']);
    $this->assertSame([(int) $owner->id()], array_map('intval', array_keys($byMail)));

    // Idempotent.
    $this->assertStringContainsString('Renamed 0 account(s)', $this->runRenameUpdate());
    $this->assertSame('lindenhof-admin', User::load($owner->id())->getAccountName());
  }

  /**
   * Single-jurisdiction installs keep every name.
   */
  public function testUpdateLeavesSingleJurisdictionInstalls(): void {
    $this->tenantB->delete();
    $owner = $this->account('owner@example.org');
    $this->tenantA->addMember($owner, ['group_roles' => ['jur-tenant_admin']]);

    $this->container->get('module_handler')->loadInclude('markaspot_group', 'install');
    $this->assertStringContainsString('not a multi-tenant install', $this->runRenameUpdate());
    $this->assertSame('owner@example.org', User::load($owner->id())->getAccountName());
  }

  /**
   * Slug and role come from the same membership.
   */
  public function testUpdateTakesSlugAndRoleFromOneMembership(): void {
    // Member of the lower-ID workspace A, administrator of B.
    $account = $this->account('both@example.org');
    $this->tenantA->addMember($account);
    $this->tenantB->addMember($account, ['group_roles' => ['jur-tenant_admin']]);

    $this->container->get('module_handler')->loadInclude('markaspot_group', 'install');
    $this->runRenameUpdate();
    $this->assertSame('riverside-admin', User::load($account->id())->getAccountName());
  }

  /**
   * Password accounts keep their name, also where login is by mail.
   *
   * Imported accounts carry a random password that cannot be told apart
   * from a real one, so the update never infers the login mode.
   */
  public function testUpdateKeepsPasswordAccountsOnFastmap(): void {
    $imported = $this->account('imported@example.org', 'random-import-password');
    $this->tenantA->addMember($imported);
    $this->enableModules(['markaspot_fastmap']);

    $this->container->get('module_handler')->loadInclude('markaspot_group', 'install');
    $this->runRenameUpdate();
    $this->assertSame('imported@example.org', User::load($imported->id())->getAccountName());
  }

  /**
   * Privileged accounts keep their name even without a password.
   */
  public function testUpdateNeverRenamesPrivilegedAccounts(): void {
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    Role::create(['id' => 'editorial_board', 'label' => 'Editorial board'])->save();
    Role::create(['id' => 'moderator', 'label' => 'Moderator'])->save();
    Role::create(['id' => 'user_admin', 'label' => 'User admin', 'permissions' => ['administer users']])->save();
    Role::create(['id' => 'node_bypass', 'label' => 'Bypass', 'permissions' => ['bypass node access']])->save();

    $kept = [];
    foreach (['administrator', 'editorial_board', 'moderator', 'user_admin', 'node_bypass'] as $role) {
      $account = $this->account($role . '@example.org');
      $account->addRole($role);
      $account->save();
      $this->tenantA->addMember($account, ['group_roles' => ['jur-tenant_admin']]);
      $kept[$role] = $account;
    }
    $member = $this->account('member@example.org');
    $this->tenantA->addMember($member);

    $this->container->get('module_handler')->loadInclude('markaspot_group', 'install');
    $this->assertStringContainsString('Renamed 1 account(s)', $this->runRenameUpdate());
    foreach ($kept as $role => $account) {
      $this->assertSame($role . '@example.org', User::load($account->id())->getAccountName(), "A $role account keeps its name.");
    }
    $this->assertMatchesRegularExpression('/^lindenhof-member-[0-9a-f]{4}$/', User::load($member->id())->getAccountName());
  }

  /**
   * Provisioning a workspace for an existing account keeps login names.
   *
   * The owner of a new workspace may already have an account named after
   * the address: an OTP account gets the workspace name, an account with a
   * password or a privileged role keeps its name.
   */
  public function testProvisioningRenamesOnlyRenameableAccounts(): void {
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $otp = $this->account('otp@example.org');
    $password = $this->account('password@example.org', 'secret-password');
    $admin = $this->account('admin@example.org');
    $admin->addRole('administrator');
    $admin->save();

    $this->enableModules(['markaspot_fastmap']);
    $provisioning = $this->container->get('markaspot_fastmap.workspace_provisioning');
    $method = new \ReflectionMethod($provisioning, 'createTenantAdmin');
    foreach (['otp@example.org', 'password@example.org', 'admin@example.org'] as $mail) {
      $method->invoke($provisioning, $mail, 'Lindenhof', 'lindenhof');
    }

    $this->assertSame('lindenhof-admin', User::load($otp->id())->getAccountName());
    $this->assertSame('password@example.org', User::load($password->id())->getAccountName());
    $this->assertSame('admin@example.org', User::load($admin->id())->getAccountName());
    $this->assertFalse(WorkspaceAccountName::isRenameable(User::load(1)), 'uid 1 is never renamed.');
  }

  /**
   * Imports name new accounts after the workspace on multi-tenant installs.
   */
  public function testImporterNamesAccountsAfterTheWorkspace(): void {
    $this->enableModules(['markaspot_tenant_import']);
    $importer = $this->container->get('markaspot_tenant_import.tenant_importer');
    $method = new \ReflectionMethod($importer, 'newAccountName');
    $this->assertSame('lindenhof-admin', $method->invoke($importer, 'owner@example.org', 'tenant_admin', $this->tenantA));
    $this->assertMatchesRegularExpression('/^lindenhof-member-[0-9a-f]{4}$/', $method->invoke($importer, 'staff@example.org', 'moderator', $this->tenantA));

    // A dedicated single-jurisdiction install keeps the address as login name.
    $this->tenantB->delete();
    $this->assertSame('owner@example.org', $method->invoke($importer, 'owner@example.org', 'tenant_admin', $this->tenantA));
  }

  /**
   * Without a parent field, scoped user queries work without hierarchy.
   *
   * This fixture has no field_parent_jurisdiction, like installs and tests
   * that never added it.
   */
  public function testScopedUserQueryWithoutParentField(): void {
    $this->assertFalse($this->container->get('database')->schema()->tableExists('group__field_parent_jurisdiction'));
    $resolver = $this->container->get('markaspot_group.hierarchy_resolver');
    $this->assertSame([(int) $this->tenantA->id()], array_map('intval', $resolver->getDescendantIds((int) $this->tenantA->id())));

    $staff = $this->account('staff@example.org');
    $this->tenantA->addMember($staff);
    $colleague = $this->account('colleague@example.org');
    $this->tenantA->addMember($colleague);
    $stranger = $this->account('stranger@example.org');
    $this->tenantB->addMember($stranger);

    $this->container->get('current_user')->setAccount($staff);
    $uids = $this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(TRUE)->execute();
    $this->assertContains((string) $colleague->id(), array_map('strval', $uids));
    $this->assertNotContains((string) $stranger->id(), array_map('strval', $uids));
  }

  /**
   * Runs update 11956 through all its batches, returns the final message.
   */
  private function runRenameUpdate(): string {
    $sandbox = [];
    do {
      $message = markaspot_group_update_11956($sandbox);
    } while (($sandbox['#finished'] ?? 1) < 1);
    return $message;
  }

  /**
   * Creates an account; names with "@" get that address as mail.
   */
  private function account(string $name, ?string $password = NULL): UserInterface {
    $user = User::create([
      'name' => $name,
      'mail' => str_contains($name, '@') ? $name : $name . '@example.org',
      'status' => 1,
      'pass' => $password,
    ]);
    $user->save();
    return $user;
  }

}
