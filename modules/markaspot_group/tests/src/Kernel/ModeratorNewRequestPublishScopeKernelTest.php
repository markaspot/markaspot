<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests moderators publish new reports only in jurisdictions they belong to.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class ModeratorNewRequestPublishScopeKernelTest extends KernelTestBase {

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
   * Disable uid 1 bypass so every account exercises its actual permissions.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * Jurisdiction of tenant A.
   */
  private Group $tenantA;

  /**
   * Sub-jurisdiction below tenant A.
   */
  private Group $districtA;

  /**
   * Jurisdiction of tenant B.
   */
  private Group $tenantB;

  /**
   * Moderator who is a member of an organisation in tenant A only.
   */
  private UserInterface $orgModerator;

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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node', 'group']);

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
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create(['id' => 'moderator', 'label' => 'Moderator'])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $this->ensureMembershipType('jur');
    $this->ensureMembershipType('org');
    // Organisation memberships are mirrored into the jurisdiction as this.
    GroupRole::create([
      'id' => 'jur-org_member',
      'label' => 'Organisation member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    $this->createGroupReferenceField('jur', 'field_parent_jurisdiction');
    $this->createGroupReferenceField('org', 'field_jurisdiction');

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Jurisdiction',
      'settings' => ['handler' => 'default:group', 'handler_settings' => []],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->tenantA = Group::create(['type' => 'jur', 'label' => 'Tenant A']);
    $this->tenantA->save();
    $this->districtA = Group::create([
      'type' => 'jur',
      'label' => 'District A',
      'field_parent_jurisdiction' => $this->tenantA->id(),
    ]);
    $this->districtA->save();
    $this->tenantB = Group::create(['type' => 'jur', 'label' => 'Tenant B']);
    $this->tenantB->save();

    $organisationA = Group::create([
      'type' => 'org',
      'label' => 'Organisation A',
      'field_jurisdiction' => $this->tenantA->id(),
    ]);
    $organisationA->save();
    $this->orgModerator = $this->account('org-moderator', ['moderator']);
    $organisationA->addMember($this->orgModerator);
  }

  /**
   * An organisation moderator publishes only inside the own tenant.
   */
  public function testOrganisationModeratorPublishesOnlyInOwnTenant(): void {
    $this->assertFalse($this->publishAccess($this->orgModerator, [$this->tenantA])->isForbidden());
    // Organisations assigned to the root cover the tenant's sub-jurisdictions.
    $this->assertFalse($this->publishAccess($this->orgModerator, [$this->districtA])->isForbidden());

    $foreign = $this->publishAccess($this->orgModerator, [$this->tenantB]);
    $this->assertTrue($foreign->isForbidden());
    $this->assertSame(0, $foreign->getCacheMaxAge());
    // Appending a foreign jurisdiction behind the own one must not pass.
    $this->assertTrue($this->publishAccess($this->orgModerator, [$this->tenantA, $this->tenantB])->isForbidden());
    // Without a submitted jurisdiction the scope cannot be proven.
    $this->assertTrue($this->publishAccess($this->orgModerator, [])->isForbidden());
  }

  /**
   * Unpublished creation and existing reports are not gated here.
   */
  public function testUnpublishedCreationAndExistingReportsStayUngated(): void {
    $this->assertTrue($this->publishAccess($this->orgModerator, [$this->tenantB], FALSE)->isNeutral());

    // An ID marks the report as persisted; its access is service_request's.
    $existing = Node::create([
      'nid' => 4242,
      'type' => 'service_request',
      'title' => 'Existing',
      'status' => 1,
      'field_jurisdiction' => [$this->tenantB->id()],
    ]);
    $existing->enforceIsNew(FALSE);
    $this->assertFalse($existing->isNew());
    $this->assertTrue($this->fieldAccess($existing, $this->orgModerator)->isNeutral());
  }

  /**
   * Jurisdiction members, administrators and non-moderators.
   */
  public function testJurisdictionMembershipAdministratorsAndOtherRoles(): void {
    $jurisdictionModerator = $this->account('jur-moderator', ['moderator']);
    $this->tenantA->addMember($jurisdictionModerator);
    $this->assertFalse($this->publishAccess($jurisdictionModerator, [$this->districtA])->isForbidden());
    $this->assertTrue($this->publishAccess($jurisdictionModerator, [$this->tenantB])->isForbidden());

    // A sub-jurisdiction membership does not reach up to the parent.
    $districtModerator = $this->account('district-moderator', ['moderator']);
    $this->districtA->addMember($districtModerator);
    $this->assertTrue($this->publishAccess($districtModerator, [$this->tenantA])->isForbidden());

    $administrator = $this->account('admin-moderator', ['moderator', 'administrator']);
    $this->assertFalse($this->publishAccess($administrator, [$this->tenantB])->isForbidden());

    $staff = $this->account('staff', []);
    $this->assertTrue($this->publishAccess($staff, [$this->tenantB])->isNeutral());
  }

  /**
   * The fastmap published-limit bypass is scoped the same way.
   *
   * A global 'bypass mas validation' (moderators synced from any organisation)
   * must not keep a report published in a foreign workspace at its limit.
   */
  public function testFastmapTierLimitBypassFollowsMembership(): void {
    require_once dirname(__DIR__, 4) . '/markaspot_fastmap/markaspot_fastmap.module';

    $staff = $this->permissionAccount($this->orgModerator, ['bypass mas validation']);
    $this->assertTrue(_markaspot_fastmap_account_bypasses_tier_limit($staff, $this->tenantA));
    $this->assertTrue(_markaspot_fastmap_account_bypasses_tier_limit($staff, $this->districtA));
    $this->assertFalse(_markaspot_fastmap_account_bypasses_tier_limit($staff, $this->tenantB));
    // Without a resolved workspace the limit check is skipped anyway.
    $this->assertTrue(_markaspot_fastmap_account_bypasses_tier_limit($staff, NULL));

    $operator = $this->permissionAccount($this->orgModerator, ['administer nodes']);
    $this->assertTrue(_markaspot_fastmap_account_bypasses_tier_limit($operator, $this->tenantB));

    $citizen = $this->permissionAccount($this->orgModerator, []);
    $this->assertFalse(_markaspot_fastmap_account_bypasses_tier_limit($citizen, $this->tenantA));
  }

  /**
   * Wraps a real user with a fixed permission set.
   */
  private function permissionAccount(UserInterface $user, array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($user->id());
    $account->method('getRoles')->willReturn($user->getRoles());
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    return $account;
  }

  /**
   * Returns this module's status field access for a new report.
   *
   * @param \Drupal\user\UserInterface $account
   *   The acting account.
   * @param \Drupal\group\Entity\Group[] $jurisdictions
   *   Submitted jurisdictions.
   * @param bool $published
   *   The submitted publication status.
   */
  private function publishAccess(UserInterface $account, array $jurisdictions, bool $published = TRUE): AccessResultInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'New report',
      'status' => (int) $published,
      'field_jurisdiction' => array_map(static fn(Group $group) => ['target_id' => $group->id()], $jurisdictions),
    ]);
    return $this->fieldAccess($node, $account);
  }

  /**
   * Invokes the hook directly so core's neutral node default cannot mask it.
   */
  private function fieldAccess(NodeInterface $node, UserInterface $account): AccessResultInterface {
    $items = $node->get('status');
    return markaspot_group_entity_field_access('edit', $items->getFieldDefinition(), $account, $items);
  }

  /**
   * Creates an account with the given Drupal roles.
   */
  private function account(string $name, array $roles): UserInterface {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
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
      'settings' => ['handler' => 'default:group', 'handler_settings' => []],
    ])->save();
  }

  /**
   * Ensures the membership relationship type exists.
   */
  private function ensureMembershipType(string $groupType): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$storage->load($groupType . '-group_membership')) {
      $storage->createFromPlugin(GroupType::load($groupType), 'group_membership')->save();
    }
  }

}
