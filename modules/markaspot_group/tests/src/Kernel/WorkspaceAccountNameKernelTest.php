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
    $this->assertStringContainsString('Renamed 4 account(s)', markaspot_group_update_11956());

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
    $this->assertStringContainsString('Renamed 0 account(s)', markaspot_group_update_11956());
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
    $this->assertStringContainsString('not a multi-tenant install', markaspot_group_update_11956());
    $this->assertSame('owner@example.org', User::load($owner->id())->getAccountName());
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
