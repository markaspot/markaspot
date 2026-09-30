<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Controller\GroupMembersController;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that editorial users join every organisation of their jurisdiction.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class EditorialOrgMembershipKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
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
    Role::create(['id' => 'editorial_board', 'label' => 'Editorial board'])->save();
    Role::create(['id' => 'moderator', 'label' => 'Moderator'])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    Role::create(['id' => 'tenant_admin', 'label' => 'Tenant admin'])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $this->ensureMembershipType('jur');
    $this->ensureMembershipType('org');
    $jurisdictionRoles = [
      'jur-member' => 'Member',
      'jur-editorial' => 'Editorial',
      'jur-moderator' => 'Moderator',
      'jur-tenant_admin' => 'Tenant admin',
    ];
    foreach ($jurisdictionRoles as $roleId => $label) {
      GroupRole::create([
        'id' => $roleId,
        'label' => $label,
        'group_type' => 'jur',
        'scope' => 'individual',
      ])->save();
    }
    GroupRole::create([
      'id' => 'jur-org_member',
      'label' => 'Organisation member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    GroupRole::create([
      'id' => 'org-insider',
      'label' => 'Moderator',
      'group_type' => 'org',
      'scope' => 'insider',
      'global_role' => 'moderator',
      'permissions' => ['view group'],
    ])->save();
    foreach (['org-editorial', 'org-editorial_member'] as $roleId) {
      $this->container->get('entity_type.manager')->getStorage('group_role')
        ->createFromStorageRecord($this->shippedRole($roleId))
        ->save();
    }

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
    $this->createGroupReferenceField('jur', 'field_parent_jurisdiction');
    $this->createGroupReferenceField('org', 'field_jurisdiction');
    // Moving an organisation restamps its service requests.
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    foreach (['field_organisation', 'field_jurisdiction'] as $fieldName) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'group'],
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'bundle' => 'service_request',
        'label' => $fieldName,
      ])->save();
    }
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * An editor joins every organisation of their root jurisdiction only.
   */
  public function testEditorJoinsOrganisationsOfOwnRootOnly(): void {
    $rootA = $this->jurisdiction('A');
    $childA = $this->jurisdiction('A child', $rootA);
    $rootB = $this->jurisdiction('B');
    $orgA = $this->organisation('Org A', $rootA);
    $orgAChild = $this->organisation('Org A child', $childA);
    $orgB = $this->organisation('Org B', $rootB);

    $editor = $this->user('editor', ['editorial_board']);
    // Two roots and no jurisdiction membership: no scope, no membership.
    $this->assertSame([], $this->orgMemberships($editor));

    // A plain membership is weak on a site with several roots.
    $weakEditor = $this->user('weak-editor', ['editorial_board']);
    $rootA->addMember($weakEditor);
    $this->assertSame([], $this->orgMemberships($weakEditor));
    $this->assertSame([], $this->service()->rootJurisdictionIds($weakEditor));

    $this->joinJurisdiction($rootA, $editor);
    $this->assertSame(
      [(int) $orgA->id(), (int) $orgAChild->id()],
      $this->orgMemberships($editor),
    );

    // Membership in a child jurisdiction scopes to the same root.
    $childEditor = $this->user('child-editor', ['editorial_board']);
    $this->joinJurisdiction($childA, $childEditor);
    $this->assertSame(
      [(int) $orgA->id(), (int) $orgAChild->id()],
      $this->orgMemberships($childEditor),
    );

    // The synchronized role gives everything in member organisations and
    // nothing elsewhere.
    $this->assertTrue($orgA->hasPermission('edit group', $editor));
    $this->assertTrue($orgA->hasPermission('administer members', $editor));
    $this->assertFalse($orgB->hasPermission('edit group', $editor));
  }

  /**
   * The org-to-jurisdiction mirror does not duplicate memberships.
   */
  public function testSyncCreatesSingleMembershipsWithoutRecursion(): void {
    $root = $this->jurisdiction('Only root');
    $orgs = [
      $this->organisation('Org 1', $root),
      $this->organisation('Org 2', $root),
      $this->organisation('Org 3', $root),
    ];

    // One root: an editor without jurisdiction membership belongs to it.
    $editor = $this->user('solo-editor', ['editorial_board']);

    $orgIds = array_map(fn(GroupInterface $org) => (int) $org->id(), $orgs);
    $this->assertSame($orgIds, $this->orgMemberships($editor));
    $counts = $this->membershipCounts($editor);
    $this->assertSame(1, $counts['jur'], 'The mirror created exactly one jurisdiction membership.');
    $this->assertSame(3, $counts['org']);
    $this->assertContains('jur-org_member', $this->jurisdictionRoles($root, $editor));

    // A second sync changes nothing.
    $this->assertSame(0, $this->service()->syncEditor($editor));
    $this->assertSame($counts, $this->membershipCounts($editor));
  }

  /**
   * New organisations, late jurisdiction stamps and role grants add editors.
   */
  public function testEditorsFollowNewOrganisationsAndRoleGrants(): void {
    $rootA = $this->jurisdiction('A');
    $rootB = $this->jurisdiction('B');
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);

    $newOrg = $this->organisation('New org', $rootA);
    $this->assertContains((int) $newOrg->id(), $this->orgMemberships($editor));
    $foreignOrg = $this->organisation('Foreign org', $rootB);
    $this->assertNotContains((int) $foreignOrg->id(), $this->orgMemberships($editor));

    $unstamped = $this->organisation('Unstamped org', NULL);
    $this->assertNotContains((int) $unstamped->id(), $this->orgMemberships($editor));
    $unstamped->set('field_jurisdiction', $rootA->id())->save();
    $this->assertContains((int) $unstamped->id(), $this->orgMemberships($editor));

    $promoted = $this->user('promoted');
    $this->joinJurisdiction($rootA, $promoted);
    $this->assertSame([], $this->orgMemberships($promoted));
    $promoted->addRole('editorial_board');
    $promoted->save();
    $this->assertSame(
      [(int) $newOrg->id(), (int) $unstamped->id()],
      $this->orgMemberships($promoted),
    );
  }

  /**
   * Moderators and administrators are left to their own rules.
   */
  public function testModeratorsAndAdministratorsAreNotEditorialMembers(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $orgA = $this->organisation('Org A', $rootA);
    $orgA2 = $this->organisation('Org A2', $rootA);

    $moderator = $this->user('moderator', ['moderator']);
    $orgA->addMember($moderator);
    $this->assertSame([(int) $orgA->id()], $this->orgMemberships($moderator));
    $this->assertTrue($orgA->hasPermission('view group', $moderator));
    $this->assertFalse($orgA->hasPermission('edit group', $moderator));
    $this->assertFalse($orgA2->hasPermission('view group', $moderator));

    $adminEditor = $this->user('admin-editor', ['administrator', 'editorial_board']);
    $this->joinJurisdiction($rootA, $adminEditor);
    $this->assertSame([], $this->orgMemberships($adminEditor));
  }

  /**
   * The "All groups member" flag stays inside the jurisdiction of non-admins.
   */
  public function testAllGroupsMemberFlagIsScopedForNonAdministrators(): void {
    $rootA = $this->jurisdiction('A');
    $rootB = $this->jurisdiction('B');
    $flagged = $this->user('flagged', [], TRUE);
    $this->joinJurisdiction($rootA, $flagged);
    $flaggedAdmin = $this->user('flagged-admin', ['administrator'], TRUE);

    $orgA = $this->organisation('Org A', $rootA);
    $orgB = $this->organisation('Org B', $rootB);

    $this->assertSame([(int) $orgA->id()], $this->orgMemberships($flagged));
    $this->assertSame(
      [(int) $orgA->id(), (int) $orgB->id()],
      $this->orgMemberships($flaggedAdmin),
    );
  }

  /**
   * Moving an organisation never carries editorial rights across tenants.
   */
  public function testOrganisationMoveKeepsEditorsInsideTheirTenant(): void {
    $rootA = $this->jurisdiction('A');
    $rootB = $this->jurisdiction('B');
    $editorA = $this->user('editor-a', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editorA);
    $editorB = $this->user('editor-b', ['editorial_board']);
    $this->joinJurisdiction($rootB, $editorB);
    $moving = $this->organisation('Moving org', $rootA);
    $orgB = $this->organisation('Org B', $rootB);
    $this->assertSame([(int) $moving->id()], $this->orgMemberships($editorA));

    $moving->set('field_jurisdiction', $rootB->id())->save();

    // The editor of A lost the moved organisation and gained nothing in B,
    // although the move mirrored a derived jurisdiction membership into B.
    $this->assertSame([], $this->orgMemberships($editorA));
    $this->assertSame([(int) $rootA->id()], $this->service()->rootJurisdictionIds($editorA));
    $this->assertSame(
      [(int) $moving->id(), (int) $orgB->id()],
      $this->orgMemberships($editorB),
    );
  }

  /**
   * Editors manage the organisations of their own jurisdiction only.
   */
  public function testEditorsManageOrganisationsOfOwnJurisdiction(): void {
    $rootA = $this->jurisdiction('A');
    $rootB = $this->jurisdiction('B');
    $orgA = $this->organisation('Org A', $rootA);
    $orgB = $this->organisation('Org B', $rootB);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $plain = $this->user('plain');
    $rootA->addMember($plain);

    $access = $this->container->get('markaspot_group.organisation_management_access');
    $this->assertTrue($access->canManageJurisdiction($editor, (int) $rootA->id()));
    $this->assertFalse($access->canManageJurisdiction($editor, (int) $rootB->id()));
    $this->assertFalse($access->managesAnyJurisdiction($plain));

    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('group');
    $this->assertTrue($handler->createAccess('org', $editor));
    $this->assertTrue($orgA->access('update', $editor));
    $this->assertFalse($orgB->access('update', $editor));

    // Saving an own organisation passes the management validator; moving it
    // into a foreign tenant does not.
    $this->container->get('current_user')->setAccount($editor);
    $orgA->set('label', 'Renamed by editor')->save();
    $this->assertSame('Renamed by editor', Group::load($orgA->id())->label());
    $orgA->set('field_jurisdiction', $rootB->id());
    $violations = array_filter(
      iterator_to_array($orgA->validate()),
      fn($violation) => $violation->getPropertyPath() === 'field_jurisdiction',
    );
    $this->assertNotEmpty($violations);
  }

  /**
   * An editor's manual membership in a foreign tenant carries no rights.
   */
  public function testForeignManualMembershipCarriesNoEditorialRights(): void {
    $rootA = $this->jurisdiction('A');
    $rootB = $this->jurisdiction('B');
    $orgA = $this->organisation('Org A', $rootA);
    $orgB = $this->organisation('Org B', $rootB);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);

    // An administrator of B adds the editor of A as a plain member.
    $orgB->addMember($editor);
    $this->service()->syncEditor($editor);

    $this->assertContains('org-editorial', $this->orgRoles($orgA, $editor));
    $this->assertTrue($orgA->hasPermission('edit group', $editor));
    $this->assertNotContains('org-editorial', $this->orgRoles($orgB, $editor));
    $this->assertFalse($orgB->hasPermission('edit group', $editor));
    $this->assertFalse($orgB->hasPermission('administer members', $editor));
  }

  /**
   * Demotion revokes editorial rights and keeps pre-existing memberships.
   */
  public function testDemotionKeepsOnlyOwnMemberships(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $ownOrg = $this->organisation('Own org', $rootA);
    $otherOrg = $this->organisation('Other org', $rootA);
    $moderator = $this->user('moderator', ['moderator']);
    $this->joinJurisdiction($rootA, $moderator, 'jur-moderator');
    $ownOrg->addMember($moderator);

    $moderator->addRole('editorial_board');
    $moderator->save();
    $this->assertSame([(int) $ownOrg->id(), (int) $otherOrg->id()], $this->orgMemberships($moderator));
    $this->assertSame(['org-editorial'], $this->orgRoles($ownOrg, $moderator));
    $this->assertSame(['org-editorial', 'org-editorial_member'], $this->orgRoles($otherOrg, $moderator));
    $this->assertTrue($otherOrg->hasPermission('edit group', $moderator));

    $moderator->removeRole('editorial_board');
    $moderator->save();
    $this->assertSame([(int) $ownOrg->id()], $this->orgMemberships($moderator));
    $this->assertSame([], $this->orgRoles($ownOrg, $moderator));
    $this->assertFalse($ownOrg->hasPermission('edit group', $moderator));
    $this->assertTrue($ownOrg->hasPermission('view group', $moderator));
    $this->assertFalse($otherOrg->hasPermission('view group', $moderator));
  }

  /**
   * Leaving the jurisdiction revokes the editorial memberships.
   */
  public function testLeavingJurisdictionRevokes(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $org = $this->organisation('Org', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $this->assertSame([(int) $org->id()], $this->orgMemberships($editor));

    $rootA->removeMember($editor);
    $this->assertSame([], $this->orgMemberships($editor));
    $this->assertFalse($org->hasPermission('view group', $editor));
  }

  /**
   * Only staff jurisdiction roles establish scope; role edits are applied.
   */
  public function testStaffRolesEstablishScope(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $org = $this->organisation('Org', $rootA);

    $member = $this->user('member-editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $member, 'jur-member');
    $this->assertSame([], $this->orgMemberships($member));

    // Promoting the existing membership to a staff role grants the rights.
    $relationship = $rootA->getMember($member)->getGroupRelationship();
    $relationship->set('group_roles', ['jur-member', 'jur-moderator'])->save();
    $this->assertSame([(int) $org->id()], $this->orgMemberships($member));

    // Demoting it again revokes them.
    $relationship = $rootA->getMember($member)->getGroupRelationship();
    $relationship->set('group_roles', ['jur-member'])->save();
    $this->assertSame([], $this->orgMemberships($member));
  }

  /**
   * Hand-assigned editorial roles are dropped (Group forms, JSON:API).
   */
  public function testHandAssignedEditorialRolesAreDropped(): void {
    $rootA = $this->jurisdiction('A');
    $rootB = $this->jurisdiction('B');
    $orgB = $this->organisation('Org B', $rootB);
    $editorA = $this->user('editor-a', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editorA);
    $outsider = $this->user('outsider');

    // Created by an administrator of B with the internal roles submitted.
    $orgB->addMember($editorA, ['group_roles' => ['org-editorial', 'org-editorial_member']]);
    $this->assertNotNull($orgB->getMember($editorA));
    $this->assertSame([], $this->orgRoles($orgB, $editorA));
    $this->assertFalse($orgB->hasPermission('edit group', $editorA));

    // Patched onto an existing membership.
    $orgB->addMember($outsider);
    $relationship = $orgB->getMember($outsider)->getGroupRelationship();
    $relationship->set('group_roles', ['org-editorial'])->save();
    $this->assertSame([], $this->orgRoles($orgB, $outsider));
    $this->assertFalse($orgB->hasPermission('edit group', $outsider));
  }

  /**
   * Hand-removed editorial roles are restored.
   */
  public function testHandRemovedEditorialRolesAreRestored(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $org = $this->organisation('Org', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $this->assertSame(['org-editorial', 'org-editorial_member'], $this->orgRoles($org, $editor));

    $relationship = $org->getMember($editor)->getGroupRelationship();
    $relationship->set('group_roles', [])->save();
    $this->assertSame(['org-editorial', 'org-editorial_member'], $this->orgRoles($org, $editor));
    $this->assertTrue($org->hasPermission('edit group', $editor));
  }

  /**
   * A membership cannot be moved to another user (JSON:API PATCH entity_id).
   */
  public function testMembershipCannotBeRetargeted(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $org = $this->organisation('Org', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $outsider = $this->user('outsider');

    $relationship = $org->getMember($editor)->getGroupRelationship();
    $relationship->set('entity_id', $outsider->id());
    try {
      $relationship->save();
      $this->fail('Re-targeting a membership must be refused.');
    }
    catch (EntityStorageException) {
    }
    $this->assertFalse($org->getMember($outsider));
    $this->assertFalse($org->hasPermission('edit group', $outsider));
  }

  /**
   * A hand-deleted editorial membership comes back; a deleted org does not.
   */
  public function testHandDeletedEditorialMembershipIsRestored(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $org = $this->organisation('Org', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);

    $org->getMember($editor)->getGroupRelationship()->delete();
    $this->assertSame([(int) $org->id()], $this->orgMemberships($editor));
    $restored = $this->container->get('database')->query(
      "SELECT gr.group_roles_target_id FROM {group_relationship_field_data} r JOIN {group_relationship__group_roles} gr ON gr.entity_id = r.id WHERE r.gid = :gid AND r.entity_id = :uid ORDER BY gr.delta",
      [':gid' => $org->id(), ':uid' => $editor->id()],
    )->fetchCol();
    $this->assertSame(['org-editorial', 'org-editorial_member'], $restored);

    $orgId = (int) $org->id();
    Group::load($orgId)->delete();
    $orphans = $this->container->get('database')->query(
      'SELECT COUNT(*) FROM {group_relationship_field_data} WHERE gid = :gid',
      [':gid' => $orgId],
    )->fetchField();
    $this->assertSame(0, (int) $orphans);
  }

  /**
   * Deleting an editor removes their memberships instead of restoring them.
   */
  public function testDeletedEditorLeavesNoMembership(): void {
    $this->installSchema('user', ['users_data']);
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $this->organisation('Org', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $uid = (int) $editor->id();
    $this->assertCount(1, $this->orgMemberships($editor));

    $editor->delete();
    $left = $this->container->get('database')->query(
      "SELECT COUNT(*) FROM {group_relationship_field_data} WHERE entity_id = :uid AND plugin_id = 'group_membership'",
      [':uid' => $uid],
    )->fetchField();
    $this->assertSame(0, (int) $left);
  }

  /**
   * Editors see and manage the member matrix of their own tenant only.
   */
  public function testEditorMatrixIsScopedToOwnTenant(): void {
    $rootA = $this->jurisdiction('A');
    $childA = $this->jurisdiction('A child', $rootA);
    $rootB = $this->jurisdiction('B');
    $orgA = $this->organisation('Org A', $childA);
    $this->organisation('Org B', $rootB);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $memberA = $this->user('member-a');
    $this->joinJurisdiction($childA, $memberA, 'jur-member');
    $memberB = $this->user('member-b');
    $this->joinJurisdiction($rootB, $memberB, 'jur-member');

    $controller = $this->matrixController($editor);
    $this->assertTrue($controller->accessCheck($editor)->isAllowed());
    $this->assertFalse($controller->accessCheck($memberA)->isAllowed());
    $matrix = json_decode((string) $controller->getMatrix(Request::create('/api/group-members'))->getContent(), TRUE);
    $groupIds = array_column($matrix['groups'], 'id');
    sort($groupIds);
    $this->assertSame([(int) $rootA->id(), (int) $childA->id(), (int) $orgA->id()], $groupIds);
    $uids = array_column($matrix['users'], 'uid');
    $this->assertContains((int) $memberA->id(), $uids);
    $this->assertNotContains((int) $memberB->id(), $uids);

    $this->assertSame(403, $controller->getUserDetail((int) $memberB->id())->getStatusCode());
    [$status] = $this->patchMemberships($controller, $memberB, $this->setRoles($rootB, 'jur-moderator'));
    $this->assertSame(403, $status);

    [$status, $body] = $this->patchMemberships($controller, $memberA, $this->setRoles($childA, 'jur-moderator'));
    $this->assertSame([200, 'ok'], [$status, $body['status']]);
    $this->assertSame(['jur-moderator'], $this->jurisdictionRoles(Group::load($childA->id()), $memberA));

    // Organisations of the tenant work like jurisdictions.
    GroupRole::create(['id' => 'org-member', 'label' => 'Member', 'group_type' => 'org', 'scope' => 'individual'])->save();
    [, $body] = $this->patchMemberships($controller, $memberA, $this->setRoles($orgA, 'org-member'));
    $this->assertSame('ok', $body['status']);
    $this->assertSame(['org-member'], $this->orgRoles(Group::load($orgA->id()), $memberA));
    [, $body] = $this->patchMemberships($controller, $memberA, [$orgA->id() => ['action' => 'remove']]);
    $this->assertSame('ok', $body['status']);
    $this->assertFalse(Group::load($orgA->id())->getMember($memberA));
    $detail = json_decode((string) $controller->getUserDetail((int) $editor->id())->getContent(), TRUE);
    $this->assertSame(['roles' => [], 'group_label' => 'Org A', 'managed' => 'editorial'], $detail['memberships'][(string) $orgA->id()]);
    [, $body] = $this->patchMemberships($controller, $memberA, $this->setRoles($childA, 'jur-tenant_admin'));
    $this->assertSame(['Only administrators can assign the tenant_admin role.'], $body['errors']);
    $this->assertSame(200, $this->patchProfile($controller, $memberA, ['name' => 'member-a-renamed']));
  }

  /**
   * Editors leave their peers, tenant admins and themselves alone.
   */
  public function testEditorCannotChangePeersOrSelf(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $this->organisation('Org A', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $peer = $this->user('peer', ['editorial_board']);
    $this->joinJurisdiction($rootA, $peer);
    $tenantAdmin = $this->user('tenant-admin');
    $this->joinJurisdiction($rootA, $tenantAdmin, 'jur-tenant_admin');

    $controller = $this->matrixController($editor);
    foreach ([$peer, $tenantAdmin, $editor] as $target) {
      [, $body] = $this->patchMemberships($controller, $target, $this->setRoles($rootA, 'jur-moderator'));
      $this->assertSame('error', $body['status'], $target->getAccountName());
      $this->assertStringContainsString('Only tenant administrators', $body['errors'][0]);
    }
    $this->assertSame(['jur-tenant_admin'], $this->jurisdictionRoles(Group::load($rootA->id()), $tenantAdmin));
    $this->assertSame(403, $this->patchProfile($controller, $tenantAdmin, ['status' => 0]));
    $this->assertSame(403, $this->patchProfile($controller, $peer, ['status' => 0]));
    $this->assertTrue(User::load($tenantAdmin->id())->isActive());
  }

  /**
   * Tenant-admin rights in one tenant do not lift peer protection in another.
   *
   * Within its own tenant, tenant administration covers memberships but not
   * the account of another tenant administrator.
   */
  public function testTenantAdminExceptionIsScoped(): void {
    $rootA = $this->jurisdiction('A');
    $rootB = $this->jurisdiction('B');
    $caller = $this->user('caller', ['editorial_board']);
    $this->joinJurisdiction($rootA, $caller, 'jur-tenant_admin');
    $this->joinJurisdiction($rootB, $caller);
    $peerA = $this->user('peer-a', ['editorial_board']);
    $this->joinJurisdiction($rootA, $peerA);
    $peerB = $this->user('peer-b', ['editorial_board']);
    $this->joinJurisdiction($rootB, $peerB);
    $tenantAdminA = $this->user('tenant-admin-a');
    $this->joinJurisdiction($rootA, $tenantAdminA, 'jur-tenant_admin');

    $controller = $this->matrixController($caller);
    $this->assertSame(403, $this->patchProfile($controller, $tenantAdminA, ['status' => 0]));
    $this->assertSame(403, $this->patchProfile($controller, $tenantAdminA, ['name' => 'renamed']));
    $this->assertTrue(User::load($tenantAdminA->id())->isActive());
    $this->assertSame('tenant-admin-a', User::load($tenantAdminA->id())->getAccountName());
    [, $body] = $this->patchMemberships($controller, $peerA, $this->setRoles($rootA, 'jur-moderator'));
    $this->assertSame('ok', $body['status']);
    [, $body] = $this->patchMemberships($controller, $peerB, $this->setRoles($rootB, 'jur-moderator'));
    $this->assertSame('error', $body['status']);
    [, $body] = $this->patchMemberships($controller, $caller, $this->setRoles($rootB, 'jur-member'));
    $this->assertSame('error', $body['status']);
    $this->assertSame(['jur-editorial'], $this->jurisdictionRoles(Group::load($rootB->id()), $caller));
  }

  /**
   * Tenant administrators cannot demote or remove each other.
   *
   * The member matrix never lets one tenant administrator take the role or
   * the tenant membership of another; plain members of the same tenant stay
   * manageable.
   */
  public function testTenantAdministratorsCannotDemoteEachOther(): void {
    $rootA = $this->jurisdiction('A');
    $caller = $this->user('caller');
    $this->joinJurisdiction($rootA, $caller, 'jur-tenant_admin');
    $peer = $this->user('peer');
    $this->joinJurisdiction($rootA, $peer, 'jur-tenant_admin');
    $moderator = $this->user('moderator', ['moderator']);
    $this->joinJurisdiction($rootA, $moderator, 'jur-moderator');

    $controller = $this->matrixController($caller);
    [, $body] = $this->patchMemberships($controller, $peer, $this->setRoles($rootA, 'jur-moderator'));
    $this->assertSame('error', $body['status']);
    $this->assertStringContainsString('Only administrators can remove the tenant_admin role', $body['errors'][0]);
    [, $body] = $this->patchMemberships($controller, $peer, [(int) $rootA->id() => ['action' => 'remove']]);
    $this->assertSame('error', $body['status']);
    $this->assertStringContainsString('Only administrators can remove a tenant administrator', $body['errors'][0]);
    $this->assertSame(['jur-tenant_admin'], $this->jurisdictionRoles(Group::load($rootA->id()), $peer));
    $this->assertTrue(User::load($peer->id())->hasRole('tenant_admin'));

    [, $body] = $this->patchMemberships($controller, $moderator, $this->setRoles($rootA, 'jur-member'));
    $this->assertSame('ok', $body['status']);
  }

  /**
   * The Group UI keeps tenant administrator memberships with administrators.
   */
  public function testGroupUiKeepsTenantAdministratorMemberships(): void {
    // As shipped, tenant administrators administer jurisdiction members.
    GroupRole::load('jur-tenant_admin')->grantPermission('administer members')->save();
    $rootA = $this->jurisdiction('A');
    $caller = $this->user('caller');
    $this->joinJurisdiction($rootA, $caller, 'jur-tenant_admin');
    $peer = $this->user('peer');
    $this->joinJurisdiction($rootA, $peer, 'jur-tenant_admin');
    $moderator = $this->user('moderator', ['moderator']);
    $this->joinJurisdiction($rootA, $moderator, 'jur-moderator');

    $relationship = fn(UserInterface $user) => Group::load($rootA->id())->getMember($user)->getGroupRelationship();
    $this->assertFalse($relationship($peer)->access('update', $caller));
    $this->assertFalse($relationship($peer)->access('delete', $caller));
    $this->assertTrue($relationship($moderator)->access('delete', $caller));
  }

  /**
   * Editors cannot move an organisation into another tenant.
   */
  public function testEditorCannotMoveOrganisationToAnotherTenant(): void {
    $rootA = $this->jurisdiction('A');
    $rootB = $this->jurisdiction('B');
    $org = $this->organisation('Org A', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $this->container->get('current_user')->setAccount($editor);

    $org = Group::load($org->id());
    $org->set('field_jurisdiction', $rootB->id());
    $messages = array_map(static fn($violation) => (string) $violation->getMessage(), iterator_to_array($org->validate()));
    $this->assertContains('Only administrators can move an organisation to another tenant.', $messages);

    // Tenant-admin rights in the target tenant do not cover the source.
    $this->joinJurisdiction($rootB, $editor, 'jur-tenant_admin');
    $this->container->get('current_user')->setAccount(User::load($editor->id()));
    $org = Group::load($org->id());
    $org->set('field_jurisdiction', $rootB->id());
    $messages = array_map(static fn($violation) => (string) $violation->getMessage(), iterator_to_array($org->validate()));
    $this->assertContains('Only administrators can move an organisation to another tenant.', $messages);
  }

  /**
   * In the Group UI, peers, tenant admins and own roles stay with admins.
   */
  public function testGroupUiKeepsPeersWithTenantAdmins(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $org = $this->organisation('Org A', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $peer = $this->user('peer', ['editorial_board']);
    $this->joinJurisdiction($rootA, $peer);
    $tenantAdmin = $this->user('tenant-admin');
    $this->joinJurisdiction($rootA, $tenantAdmin, 'jur-tenant_admin');
    $moderator = $this->user('moderator', ['moderator']);
    $org = Group::load($org->id());
    $org->addMember($tenantAdmin);
    $org->addMember($moderator);

    $relationship = fn(UserInterface $user) => Group::load($org->id())->getMember($user)->getGroupRelationship();
    $this->assertFalse($relationship($peer)->access('update', $editor));
    $this->assertFalse($relationship($peer)->access('delete', $editor));
    $this->assertFalse($relationship($tenantAdmin)->access('update', $editor));
    $this->assertFalse($relationship($tenantAdmin)->access('delete', $editor));
    $this->assertFalse($relationship($editor)->access('update', $editor));
    $this->assertTrue($relationship($editor)->access('delete', $editor));
    $this->assertTrue($relationship($moderator)->access('update', $editor));
    $this->assertTrue($relationship($moderator)->access('delete', $editor));

    // A refusal depends on the target's peer status and is never cached;
    // other accounts keep a cacheable result.
    $this->assertSame(0, $relationship($peer)->access('update', $editor, TRUE)->getCacheMaxAge());
    $this->assertNotSame(0, $relationship($moderator)->access('update', User::load($tenantAdmin->id()), TRUE)->getCacheMaxAge());
  }

  /**
   * In the Group UI, editors add only accounts of their own tenant.
   */
  public function testGroupUiAddsOnlyOwnTenantMembers(): void {
    $rootA = $this->jurisdiction('A');
    $childA = $this->jurisdiction('A child', $rootA);
    $rootB = $this->jurisdiction('B');
    $org = $this->organisation('Org A', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $memberA = $this->user('member-a');
    $this->joinJurisdiction($childA, $memberA, 'jur-member');
    $memberB = $this->user('member-b');
    $this->joinJurisdiction($rootB, $memberB, 'jur-member');
    $tenantAdmin = $this->user('tenant-admin');
    $this->joinJurisdiction($rootA, $tenantAdmin, 'jur-tenant_admin');
    $this->container->get('current_user')->setAccount($editor);

    $message = 'Editors may only add members of their own tenant who are no editors or tenant administrators.';
    $violations = function (UserInterface $user) use ($org): array {
      $relationship = $this->container->get('entity_type.manager')->getStorage('group_relationship')
        ->createForEntityInGroup($user, Group::load($org->id()), 'group_membership');
      return array_map(static fn($violation) => (string) $violation->getMessage(), iterator_to_array($relationship->validate()));
    };
    $this->assertNotContains($message, $violations($memberA));
    $this->assertContains($message, $violations($memberB));
    $this->assertContains($message, $violations($tenantAdmin));
  }

  /**
   * The matrix does not pretend to remove a role-managed membership.
   */
  public function testMatrixRefusesToRemoveEditorialMembership(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $org = $this->organisation('Org A', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);

    $controller = $this->matrixController(User::load(1));
    [, $body] = $this->patchMemberships($controller, $editor, [$org->id() => ['action' => 'remove']]);
    $this->assertSame('error', $body['status']);
    $this->assertStringContainsString('follows the editorial role', $body['errors'][0]);
    $this->assertSame([(int) $org->id()], $this->orgMemberships($editor));
  }

  /**
   * The resync strips editorial roles from accounts that are no editors.
   */
  public function testResyncStripsStrayEditorialRoles(): void {
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $org = $this->organisation('Org', $rootA);
    $moderator = $this->user('moderator', ['moderator']);
    $org->addMember($moderator, ['group_roles' => []]);
    // Written before the presave guard was registered.
    $relationshipId = (int) $org->getMember($moderator)->getGroupRelationship()->id();
    $this->container->get('database')->insert('group_relationship__group_roles')->fields([
      'bundle' => 'org-group_membership',
      'deleted' => 0,
      'entity_id' => $relationshipId,
      'revision_id' => $relationshipId,
      'langcode' => 'en',
      'delta' => 0,
      'group_roles_target_id' => 'org-editorial',
    ])->execute();
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache();
    $this->assertSame(['org-editorial'], $this->orgRoles(Group::load($org->id()), $moderator));

    $counts = $this->service()->syncAllEditors();
    $this->assertSame(1, $counts['revoked']);
    $this->assertSame([], $this->orgRoles(Group::load($org->id()), $moderator));
    $this->assertSame([(int) $org->id()], $this->orgMemberships($moderator));
  }

  /**
   * Without the editorial roles (before update 11954) nothing is granted.
   */
  public function testMissingRolesGrantNothing(): void {
    foreach (['org-editorial', 'org-editorial_member'] as $roleId) {
      GroupRole::load($roleId)->delete();
    }
    $rootA = $this->jurisdiction('A');
    $this->jurisdiction('B');
    $this->organisation('Org', $rootA);
    $editor = $this->user('editor', ['editorial_board']);
    $this->joinJurisdiction($rootA, $editor);
    $this->organisation('Later', $rootA);

    $this->assertSame([], $this->orgMemberships($editor));
    $this->assertSame(0, $this->service()->syncAllEditors()['granted']);
  }

  /**
   * A second root ends the single-root fallback; removing it restores it.
   */
  public function testSecondRootEndsSingleRootFallback(): void {
    $root = $this->jurisdiction('Only root');
    $org = $this->organisation('Org', $root);
    $editor = $this->user('weak-editor', ['editorial_board']);
    $this->assertContains('org-editorial', $this->orgRoles($org, $editor));

    $second = $this->jurisdiction('Second root');
    $this->assertSame([], $this->orgRoles($org, $editor));
    $this->assertFalse($org->hasPermission('edit group', $editor));

    $second->delete();
    $this->assertContains('org-editorial', $this->orgRoles($org, $editor));
  }

  /**
   * A jurisdiction with a dangling parent gives no scope.
   */
  public function testUnresolvableJurisdictionFailsClosed(): void {
    $orphan = Group::create([
      'type' => 'jur',
      'label' => 'Orphan',
      'field_parent_jurisdiction' => 9999,
    ]);
    $orphan->save();
    $root = $this->jurisdiction('Root');
    $this->organisation('Org', $root);
    $editor = $this->user('orphan-editor', ['editorial_board']);
    $this->joinJurisdiction($orphan, $editor);

    $this->assertSame([], $this->service()->rootJurisdictionIds($editor));
    $this->assertSame([], $this->orgMemberships($editor));
  }

  /**
   * Update 11954 creates the role with its fixed UUID and is idempotent.
   */
  public function testUpdateHookCreatesRoleAndBackfills(): void {
    $root = $this->jurisdiction('Only root');
    $org = $this->organisation('Org', $root);
    GroupRole::load('org-editorial')->delete();
    GroupRole::load('org-editorial_member')->delete();
    // Grant the role without triggering the sync, like pre-update data.
    $editor = $this->user('legacy-editor');
    $this->container->get('database')->insert('user__roles')->fields([
      'bundle' => 'user',
      'deleted' => 0,
      'entity_id' => $editor->id(),
      'revision_id' => $editor->id(),
      'langcode' => 'en',
      'delta' => 0,
      'roles_target_id' => 'editorial_board',
    ])->execute();
    $this->container->get('entity_type.manager')->getStorage('user')->resetCache([(int) $editor->id()]);
    $this->assertSame([], $this->orgMemberships($editor));

    $this->container->get('module_handler')->loadInclude('markaspot_group', 'install');
    $message = markaspot_group_update_11954();
    $this->assertStringContainsString('created org-editorial, org-editorial_member', $message);
    $this->assertStringContainsString('Editors 1, memberships granted 1', $message);
    $role = GroupRole::load('org-editorial');
    $this->assertSame('6902c34a-e00d-4d86-a419-d8a3e386cfc9', $role->uuid());
    $this->assertSame('individual', $role->getScope());
    $this->assertTrue($role->isAdmin());
    $this->assertSame([(int) $org->id()], $this->orgMemberships($editor));

    $this->assertStringContainsString('created none', markaspot_group_update_11954());
    $this->assertSame('5ac658b7-0098-49bd-ac10-f20db60af905', GroupRole::load('org-editorial_member')->uuid());
    $this->assertSame([(int) $org->id()], $this->orgMemberships($editor));
  }

  /**
   * Reads a shipped group role.
   */
  private function shippedRole(string $roleId): array {
    $data = Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . '/config/optional/group.role.' . $roleId . '.yml'));
    $this->assertIsArray($data);
    return $data;
  }

  /**
   * Creates a jurisdiction group.
   */
  private function jurisdiction(string $label, ?GroupInterface $parent = NULL): GroupInterface {
    $group = Group::create([
      'type' => 'jur',
      'label' => $label,
      'field_parent_jurisdiction' => $parent?->id(),
    ]);
    $group->save();
    return $group;
  }

  /**
   * Adds a user to a jurisdiction with an explicit member role.
   */
  private function joinJurisdiction(GroupInterface $jurisdiction, UserInterface $user, string $roleId = 'jur-editorial'): void {
    $jurisdiction->addMember($user, ['group_roles' => [$roleId]]);
  }

  /**
   * Individual roles of a user's membership in an organisation.
   */
  private function orgRoles(GroupInterface $organisation, UserInterface $user): array {
    $membership = $organisation->getMember($user);
    return $membership ? array_keys($membership->getRoles(FALSE)) : [];
  }

  /**
   * Creates an organisation group.
   */
  private function organisation(string $label, ?GroupInterface $jurisdiction): GroupInterface {
    $group = Group::create([
      'type' => 'org',
      'label' => $label,
      'field_jurisdiction' => $jurisdiction?->id(),
    ]);
    $group->save();
    return $group;
  }

  /**
   * Creates an active user.
   */
  private function user(string $name, array $roles = [], bool $allGroupsMember = FALSE): UserInterface {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.test',
      'status' => 1,
      'roles' => $roles,
      'field_all_groups_member' => $allGroupsMember,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Sorted IDs of the organisations a user is a member of.
   *
   * @return int[]
   *   Organisation group IDs.
   */
  private function orgMemberships(UserInterface $user): array {
    $ids = $this->container->get('database')->query(
      "SELECT gid FROM {group_relationship_field_data} WHERE entity_id = :uid AND plugin_id = 'group_membership' AND group_type = 'org' ORDER BY gid",
      [':uid' => $user->id()],
    )->fetchCol();
    return array_map('intval', $ids);
  }

  /**
   * Counts a user's membership relationships per group type.
   */
  private function membershipCounts(UserInterface $user): array {
    $counts = $this->container->get('database')->query(
      "SELECT group_type, COUNT(*) FROM {group_relationship_field_data} WHERE entity_id = :uid AND plugin_id = 'group_membership' GROUP BY group_type",
      [':uid' => $user->id()],
    )->fetchAllKeyed();
    return ['jur' => (int) ($counts['jur'] ?? 0), 'org' => (int) ($counts['org'] ?? 0)];
  }

  /**
   * Individual roles of a user's membership in a jurisdiction.
   */
  private function jurisdictionRoles(GroupInterface $jurisdiction, UserInterface $user): array {
    $membership = $jurisdiction->getMember($user);
    return $membership ? array_keys($membership->getRoles(FALSE)) : [];
  }

  /**
   * Builds the member matrix controller for a caller.
   */
  private function matrixController(UserInterface $caller): GroupMembersController {
    $this->container->get('current_user')->setAccount($caller);
    return GroupMembersController::create($this->container);
  }

  /**
   * Sends a membership PATCH and returns status code and decoded body.
   */
  private function patchMemberships(GroupMembersController $controller, UserInterface $target, array $memberships): array {
    $response = $controller->updateMemberships(Request::create(
      '/api/group-members/' . $target->id(), 'PATCH', [], [], [], [],
      json_encode(['memberships' => $memberships], JSON_THROW_ON_ERROR),
    ), (int) $target->id());
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), TRUE)];
  }

  /**
   * Builds a matrix update that sets one role in a group.
   */
  private function setRoles(GroupInterface $group, string $roleId): array {
    return [(int) $group->id() => ['action' => 'set', 'roles' => [$roleId]]];
  }

  /**
   * Sends a profile PATCH and returns the status code.
   */
  private function patchProfile(GroupMembersController $controller, UserInterface $target, array $body): int {
    return $controller->updateUserProfile(Request::create(
      '/api/group-members/' . $target->id() . '/profile', 'PATCH', [], [], [], [],
      json_encode($body, JSON_THROW_ON_ERROR),
    ), (int) $target->id())->getStatusCode();
  }

  /**
   * The service under test.
   */
  private function service(): object {
    return $this->container->get('markaspot_group.editorial_org_membership');
  }

  /**
   * Creates an unlimited group reference field on a group bundle.
   */
  private function createGroupReferenceField(string $bundle, string $fieldName): void {
    if (!FieldStorageConfig::loadByName('group', $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'group',
        'type' => 'entity_reference',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        'settings' => ['target_type' => 'group'],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'group',
      'bundle' => $bundle,
      'label' => $fieldName,
      'settings' => [
        'handler' => 'default:group',
        'handler_settings' => [],
      ],
    ])->save();
  }

  /**
   * Installs the membership relationship type for a group type.
   */
  private function ensureMembershipType(string $groupType): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$storage->load($groupType . '-group_membership')) {
      $storage->createFromPlugin(GroupType::load($groupType), 'group_membership')->save();
    }
  }

}
