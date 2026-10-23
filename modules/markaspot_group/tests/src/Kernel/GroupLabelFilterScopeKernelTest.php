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
use Drupal\markaspot_group\Plugin\views\filter\GroupLabelFilter;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which organisations the management View offers as filter options.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Plugin\views\filter\GroupLabelFilter
 */
#[RunTestsInSeparateProcesses]
final class GroupLabelFilterScopeKernelTest extends KernelTestBase {

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
    'views',
    'search_api',
    'markaspot_group',
  ];

  /**
   * Disable uid 1 bypass so every account exercises its actual permissions.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

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
    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['jur', 'org'] as $groupType) {
      if (!$relationshipTypes->load($groupType . '-group_membership')) {
        $relationshipTypes->createFromPlugin(GroupType::load($groupType), 'group_membership')->save();
      }
    }
    // Joining an organisation derives a jurisdiction membership.
    GroupRole::create([
      'id' => 'jur-org_member',
      'label' => 'Organisation member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    // Every authenticated account may view organisations, as shipped.
    foreach (['outsider', 'insider'] as $scope) {
      GroupRole::create([
        'id' => 'org-' . $scope,
        'label' => $scope,
        'group_type' => 'org',
        'scope' => $scope,
        'global_role' => 'authenticated',
        'permissions' => ['view group'],
      ])->save();
    }
    $this->createGroupReferenceField('jur', 'field_parent_jurisdiction');
    $this->createGroupReferenceField('org', 'field_jurisdiction');

    Role::create(['id' => 'moderator', 'label' => 'Moderator'])->save();
    Role::create(['id' => 'operator', 'label' => 'Operator'])->save();
    Role::load('operator')->grantPermission('bypass node access')->save();
  }

  /**
   * Organisation staff of a multi-jurisdiction install see their own groups.
   *
   * @covers ::groupOptions
   */
  public function testOrganisationStaffSeesOwnOrganisations(): void {
    $tenantA = $this->group('jur', 'Tenant A');
    $tenantB = $this->group('jur', 'Tenant B');
    $roads = $this->group('org', 'Roads A', $tenantA);
    $this->group('org', 'Parks A', $tenantA);
    $this->group('org', 'Roads B', $tenantB);

    $staff = $this->account('staff', ['moderator']);
    $roads->addMember($staff);

    $this->assertSame(['Roads A'], array_values($this->options($staff)));
  }

  /**
   * A single jurisdiction keeps organisations without a jurisdiction.
   *
   * Migrated classic installs have one jurisdiction and organisations that
   * often lack the jurisdiction reference.
   *
   * @covers ::groupOptions
   */
  public function testSingleJurisdictionStaffWithoutOrganisationSeesAll(): void {
    $city = $this->group('jur', 'City');
    $this->group('org', 'Roads', $city);
    $this->group('org', 'Waste');

    $staff = $this->account('staff', ['moderator']);

    $this->assertSame(['Roads', 'Waste'], array_values($this->options($staff)));
  }

  /**
   * Organisation membership still narrows the list on a single jurisdiction.
   *
   * @covers ::groupOptions
   */
  public function testSingleJurisdictionOrganisationStaffSeesOwnOrganisations(): void {
    $city = $this->group('jur', 'City');
    $this->group('org', 'Roads', $city);
    $waste = $this->group('org', 'Waste');

    $staff = $this->account('staff', ['moderator']);
    $waste->addMember($staff);

    $this->assertSame(['Waste'], array_values($this->options($staff)));
  }

  /**
   * Operators see every organisation.
   *
   * @covers ::groupOptions
   */
  public function testOperatorSeesAllOrganisations(): void {
    $tenantA = $this->group('jur', 'Tenant A');
    $tenantB = $this->group('jur', 'Tenant B');
    $roads = $this->group('org', 'Roads A', $tenantA);
    $this->group('org', 'Roads B', $tenantB);

    $operator = $this->account('operator', ['operator']);
    $roads->addMember($operator);

    $this->assertSame(['Roads A', 'Roads B'], array_values($this->options($operator)));
  }

  /**
   * Builds the organisation filter options for an account.
   *
   * @return array<int|string, string>
   *   Group IDs keyed to labels.
   */
  private function options(UserInterface $account): array {
    $this->container->get('current_user')->setAccount($account);
    drupal_static_reset('_markaspot_group_jurisdiction_group_count');
    $filter = GroupLabelFilter::create($this->container, [], 'markaspot_group_label', []);
    $filter->options['group_bundle'] = 'org';
    $method = new \ReflectionMethod(GroupLabelFilter::class, 'groupOptions');

    return $method->invoke($filter);
  }

  /**
   * Creates a group, optionally referencing a jurisdiction.
   */
  private function group(string $type, string $label, ?Group $jurisdiction = NULL): Group {
    $values = ['type' => $type, 'label' => $label];
    if ($jurisdiction !== NULL) {
      $values['field_jurisdiction'] = [$jurisdiction->id()];
    }
    $group = Group::create($values);
    $group->save();
    return $group;
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

}
