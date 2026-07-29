<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests unpublished organisation collection access for jurisdiction managers.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class OrganisationUnpublishedAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity',
    'flexible_permissions',
    'group',
    'markaspot_group',
  ];

  /**
   * Jurisdiction manager under test.
   */
  private UserInterface $manager;

  /**
   * Authenticated foreign account.
   */
  private UserInterface $foreignAccount;

  /**
   * Unpublished organisation in the manager's jurisdiction.
   */
  private Group $managedOrganisation;

  /**
   * Unpublished organisation outside the manager's jurisdiction.
   */
  private Group $foreignOrganisation;

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

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->manager = User::create([
      'name' => 'jurisdiction-manager',
      'status' => 1,
    ]);
    $this->manager->save();
    $this->foreignAccount = User::create([
      'name' => 'foreign-account',
      'status' => 1,
    ]);
    $this->foreignAccount->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    GroupRole::create([
      'id' => 'jur-tenant_admin',
      'label' => 'Tenant administrator',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    $this->ensureMembershipType('jur');
    $this->ensureMembershipType('org');

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
    $this->createGroupReferenceField(
      'jur',
      'field_parent_jurisdiction',
      FALSE,
    );
    $this->createGroupReferenceField('org', 'field_jurisdiction', TRUE);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $managedJurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Managed jurisdiction',
    ]);
    $managedJurisdiction->save();
    $foreignJurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Foreign jurisdiction',
    ]);
    $foreignJurisdiction->save();
    $managedJurisdiction->addMember($this->manager, [
      'group_roles' => ['jur-tenant_admin'],
    ]);

    $this->managedOrganisation = Group::create([
      'type' => 'org',
      'label' => 'Inactive managed organisation',
      'status' => FALSE,
      'field_jurisdiction' => $managedJurisdiction->id(),
    ]);
    $this->managedOrganisation->save();
    $this->foreignOrganisation = Group::create([
      'type' => 'org',
      'label' => 'Inactive foreign organisation',
      'status' => FALSE,
      'field_jurisdiction' => $foreignJurisdiction->id(),
    ]);
    $this->foreignOrganisation->save();
  }

  /**
   * Tests collection and entity access stay scoped for unpublished orgs.
   */
  public function testUnpublishedOrganisationCollectionAccessIsScoped(): void {
    $this->container->get('current_user')->setAccount($this->manager);
    $this->assertSame(
      [(int) $this->managedOrganisation->id()],
      $this->unpublishedOrganisationIds(),
    );
    $this->assertTrue($this->managedOrganisation->access('view', $this->manager));
    $this->assertFalse($this->foreignOrganisation->access('view', $this->manager));

    $this->container->get('current_user')->setAccount($this->foreignAccount);
    $this->assertSame([], $this->unpublishedOrganisationIds());

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->assertSame([], $this->unpublishedOrganisationIds());
  }

  /**
   * Returns access-checked unpublished organisation IDs.
   *
   * @return int[]
   *   Organisation IDs.
   */
  private function unpublishedOrganisationIds(): array {
    $ids = $this->container
      ->get('entity_type.manager')
      ->getStorage('group')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'org')
      ->condition('status', FALSE)
      ->sort('id')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Creates a group entity reference field.
   */
  private function createGroupReferenceField(
    string $bundle,
    string $fieldName,
    bool $required,
  ): void {
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
      'required' => $required,
      'settings' => [
        'handler' => 'default:group',
        'handler_settings' => [],
      ],
    ])->save();
  }

  /**
   * Ensures the membership relationship type exists.
   */
  private function ensureMembershipType(string $groupType): void {
    $storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('group_relationship_type');
    if ($storage->load($groupType . '-group_membership')) {
      return;
    }
    $storage->createFromPlugin(GroupType::load($groupType), 'group_membership')
      ->save();
  }

}
