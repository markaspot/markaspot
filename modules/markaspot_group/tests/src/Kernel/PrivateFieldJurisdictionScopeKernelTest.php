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
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests private report fields stay inside the report's jurisdiction scope.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class PrivateFieldJurisdictionScopeKernelTest extends KernelTestBase {

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
   * Disable uid 1 bypass so every account exercises its actual permissions.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * Jurisdiction of workspace A.
   */
  private Group $tenantA;

  /**
   * Sub-jurisdiction below workspace A.
   */
  private Group $districtA;

  /**
   * Jurisdiction of workspace B.
   */
  private Group $tenantB;

  /**
   * Tenant administrator who belongs to workspace A only.
   */
  private UserInterface $adminA;

  /**
   * Published report in workspace A.
   */
  private NodeInterface $reportA;

  /**
   * Published report in workspace B.
   */
  private NodeInterface $reportB;

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

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $this->ensureMembershipType('jur');
    $this->ensureMembershipType('org');
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
    FieldStorageConfig::create([
      'field_name' => 'field_organisation', 'entity_type' => 'node',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'group'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create(['field_name' => 'field_organisation', 'entity_type' => 'node', 'bundle' => 'service_request'])->save();
    $this->createCustomPermissionField('field_e_mail', 'email');
    $this->createCustomPermissionField('field_status_notes', 'string');
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    // Staff hold global field permissions, as the shipped roles do.
    $staff_permissions = [
      'view field_e_mail',
      'view field_status_notes',
    ];
    foreach (['tenant_admin', 'moderator', 'editorial_board'] as $role_id) {
      Role::create(['id' => $role_id, 'label' => $role_id])->save();
      $role = Role::load($role_id);
      foreach ($staff_permissions as $permission) {
        $role->grantPermission($permission);
      }
      if ($role_id === 'editorial_board') {
        $role->grantPermission('bypass node access');
      }
      $role->save();
    }
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $anonymous = Role::load('anonymous');
    $anonymous->grantPermission('view field_status_notes')->save();
    $authenticated = Role::load('authenticated');
    $authenticated->grantPermission('view own field_e_mail')->save();

    $this->tenantA = $this->jurisdiction('Tenant A');
    $this->districtA = $this->jurisdiction('District A', $this->tenantA);
    $this->tenantB = $this->jurisdiction('Tenant B');

    $this->adminA = $this->account('admin-a', ['tenant_admin']);
    $this->tenantA->addMember($this->adminA);

    $this->reportA = $this->report($this->tenantA);
    $this->reportB = $this->report($this->tenantB);
  }

  /**
   * A tenant administrator reads contact data of the own workspace only.
   */
  public function testStaffReadsContactDataOnlyInOwnJurisdiction(): void {
    $this->assertTrue($this->reportA->get('field_e_mail')->access('view', $this->adminA));
    $this->assertTrue($this->report($this->districtA)->get('field_e_mail')->access('view', $this->adminA));

    $foreign = $this->reportB->get('field_e_mail')->access('view', $this->adminA, TRUE);
    $this->assertTrue($foreign->isForbidden());
    $this->assertContains('user', $foreign->getCacheContexts());
    $this->assertContains('group_relationship_list:plugin:group_membership:entity:' . $this->adminA->id(), $foreign->getCacheTags());
  }

  /**
   * Moderators are scoped like tenant administrators.
   */
  public function testModeratorsAreScopedByMembership(): void {
    $moderatorA = $this->account('moderator-a', ['moderator']);
    $this->tenantA->addMember($moderatorA);
    $this->assertTrue($this->reportA->get('field_e_mail')->access('view', $moderatorA));
    $this->assertFalse($this->reportB->get('field_e_mail')->access('view', $moderatorA));

    // A sub-jurisdiction membership does not reach up to the parent.
    $districtModerator = $this->account('district-moderator', ['moderator']);
    $this->districtA->addMember($districtModerator);
    $this->assertFalse($this->reportA->get('field_e_mail')->access('view', $districtModerator));

    // Organisations assigned to a jurisdiction cover its reports.
    $organisation = Group::create(['type' => 'org', 'label' => 'Org A', 'field_jurisdiction' => $this->tenantA->id()]);
    $organisation->save();
    $orgModerator = $this->account('org-moderator', ['moderator']);
    $organisation->addMember($orgModerator);
    $this->reportA->set('field_organisation', $organisation->id());
    $this->assertTrue($this->reportA->get('field_e_mail')->access('view', $orgModerator));
    $this->assertFalse($this->reportB->get('field_e_mail')->access('view', $orgModerator));
  }

  /**
   * Derived jurisdiction membership never opens sibling-org private fields.
   */
  public function testSiblingOrganisationIsDeniedEvenOnSingleJurisdictionInstalls(): void {
    $a = Group::create(['type' => 'org', 'label' => 'A', 'field_jurisdiction' => $this->tenantA->id()]);
    $a->save();
    $b = Group::create(['type' => 'org', 'label' => 'B', 'field_jurisdiction' => $this->tenantA->id()]);
    $b->save();
    $staff = $this->account('org-only-staff', ['moderator']);
    $b->addMember($staff);
    $own = $this->report($this->tenantA)->set('field_organisation', $b->id());
    $sibling = $this->report($this->tenantA)->set('field_organisation', $a->id());
    $this->assertTrue($own->get('field_e_mail')->access('view', $staff));
    $this->assertFalse($sibling->get('field_e_mail')->access('view', $staff));
    // Exercise the legacy single-jurisdiction shortcut explicitly. It must
    // not remove the organisation boundary established above.
    $count = &drupal_static('_markaspot_group_jurisdiction_group_count');
    $count = 1;
    $this->assertFalse($sibling->get('field_e_mail')->access('view', $staff));
    $this->assertFalse(_markaspot_group_service_request_organisation_view_access($sibling, $staff)->isAllowed());
  }

  /**
   * Elevated jurisdiction staff keep private-field filters on a single
   * jurisdiction even when they also belong to an organisation.
   */
  public function testElevatedStaffWithOrganisationKeepPrivateFieldFilters(): void {
    GroupRole::create([
      'id' => 'jur-moderator',
      'label' => 'Moderator',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    $organisation = Group::create(['type' => 'org', 'label' => 'Tiefbau', 'field_jurisdiction' => $this->tenantA->id()]);
    $organisation->save();
    $elevated = $this->account('jur-moderator-with-org', ['moderator']);
    $this->tenantA->addMember($elevated, ['group_roles' => ['jur-moderator']]);
    $organisation->addMember($elevated);
    $orgOnly = $this->account('org-only-filter', ['moderator']);
    $organisation->addMember($orgOnly);

    $count = &drupal_static('_markaspot_group_jurisdiction_group_count');
    $count = 1;
    $definition = $this->reportA->get('field_e_mail')->getFieldDefinition();
    $this->assertTrue(markaspot_group_jsonapi_entity_field_filter_access($definition, $elevated)->isNeutral());
    $this->assertTrue(markaspot_group_jsonapi_entity_field_filter_access($definition, $orgOnly)->isForbidden());
  }

  /**
   * Allowed results vary per user, and one of several jurisdictions suffices.
   */
  public function testAllowedResultVariesPerUserAndAcceptsAnyJurisdiction(): void {
    $allowed = $this->reportA->get('field_e_mail')->access('view', $this->adminA, TRUE);
    $this->assertTrue($allowed->isAllowed());
    $this->assertContains('user', $allowed->getCacheContexts());

    $shared = $this->report($this->tenantB);
    $shared->set('field_jurisdiction', [$this->tenantB->id(), $this->tenantA->id()]);
    $this->assertTrue($shared->get('field_e_mail')->access('view', $this->adminA));
  }

  /**
   * Private fields cannot serve as a cross-tenant JSON:API filter oracle.
   */
  public function testPrivateFieldsAreNotFilterableAcrossJurisdictions(): void {
    $definition = $this->reportB->get('field_e_mail')->getFieldDefinition();
    $public = $this->reportB->get('field_status_notes')->getFieldDefinition();

    $denied = markaspot_group_jsonapi_entity_field_filter_access($definition, $this->adminA);
    $this->assertTrue($denied->isForbidden());
    $this->assertContains('user', $denied->getCacheContexts());
    $this->assertTrue(markaspot_group_jsonapi_entity_field_filter_access($public, $this->adminA)->isNeutral());

    $editorial = $this->account('editorial-filter', ['editorial_board']);
    $this->assertTrue(markaspot_group_jsonapi_entity_field_filter_access($definition, $editorial)->isNeutral());
  }

  /**
   * Any role with 'bypass node access' stays unscoped.
   */
  public function testBypassNodeAccessStaysUnscoped(): void {
    Role::create(['id' => 'operator', 'label' => 'Operator'])->save();
    Role::load('operator')
      ->grantPermission('bypass node access')
      ->grantPermission('view field_e_mail')
      ->save();
    $operator = $this->account('operator', ['operator']);
    $this->assertTrue($this->reportB->get('field_e_mail')->access('view', $operator));
  }

  /**
   * Joining the jurisdiction grants access without stale cached denials.
   */
  public function testMembershipChangeLiftsTheDenial(): void {
    $this->assertFalse($this->reportB->get('field_e_mail')->access('view', $this->adminA));
    $this->tenantB->addMember($this->adminA);
    $this->assertTrue($this->reportB->get('field_e_mail')->access('view', $this->adminA));
  }

  /**
   * Reporters, editorial staff and administrators keep their access.
   */
  public function testReporterEditorialAndAdministratorsKeepAccess(): void {
    $reporter = $this->account('reporter', []);
    $ownReport = $this->report($this->tenantB, $reporter);
    $this->assertTrue($ownReport->get('field_e_mail')->access('view', $reporter));
    $this->assertFalse($this->reportB->get('field_e_mail')->access('view', $reporter));

    $editorial = $this->account('editorial', ['editorial_board']);
    $this->assertTrue($this->reportB->get('field_e_mail')->access('view', $editorial));

    $administrator = $this->account('administrator', ['administrator']);
    $this->assertTrue($this->reportB->get('field_e_mail')->access('view', $administrator));
  }

  /**
   * Public fields and anonymous visitors are not affected.
   */
  public function testPublicFieldsAndAnonymousVisitorsAreUnscoped(): void {
    $this->assertTrue($this->reportB->get('field_status_notes')->access('view', $this->adminA));
    $this->assertTrue($this->reportB->get('field_status_notes')->access('view', User::getAnonymousUser()));
    $this->assertFalse($this->reportB->get('field_e_mail')->access('view', User::getAnonymousUser()));
  }

  /**
   * An install with a single jurisdiction keeps global field permissions.
   */
  public function testSingleJurisdictionInstallIsUnscoped(): void {
    $this->districtA->delete();
    $this->tenantA->delete();
    drupal_static_reset('_markaspot_group_jurisdiction_group_count');

    $moderator = $this->account('dedicated-moderator', ['moderator']);
    $this->assertTrue($this->reportB->get('field_e_mail')->access('view', $moderator));
    $definition = $this->reportB->get('field_e_mail')->getFieldDefinition();
    $this->assertTrue(markaspot_group_jsonapi_entity_field_filter_access($definition, $moderator)->isNeutral());
  }

  /**
   * Creates a jurisdiction group, optionally below a parent.
   */
  private function jurisdiction(string $label, ?Group $parent = NULL): Group {
    $group = Group::create([
      'type' => 'jur',
      'label' => $label,
      'field_parent_jurisdiction' => $parent?->id(),
    ]);
    $group->save();
    return $group;
  }

  /**
   * Builds a persisted-looking published report in a jurisdiction.
   *
   * Field access needs no stored node; saving would sync group relationships,
   * which needs the gnode plugins this test does not install.
   */
  private function report(Group $jurisdiction, ?UserInterface $owner = NULL): NodeInterface {
    static $nid = 1000;
    $node = Node::create([
      'nid' => ++$nid,
      'type' => 'service_request',
      'title' => 'Report',
      'status' => 1,
      'uid' => $owner?->id() ?? 1,
      'field_jurisdiction' => [$jurisdiction->id()],
      'field_e_mail' => 'citizen@example.org',
      'field_status_notes' => 'Public answer',
    ]);
    $node->enforceIsNew(FALSE);
    return $node;
  }

  /**
   * Creates a service request field with custom field permissions.
   */
  private function createCustomPermissionField(string $fieldName, string $type): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'type' => $type,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => $fieldName,
    ])->save();
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
