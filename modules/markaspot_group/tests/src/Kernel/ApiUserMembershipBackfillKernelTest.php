<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the api_user jurisdiction membership update hook.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class ApiUserMembershipBackfillKernelTest extends KernelTestBase {

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
  ];

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

    User::create([
      'uid' => 0,
      'name' => '',
      'status' => 0,
    ])->save();
    User::create([
      'uid' => 1,
      'name' => 'root',
      'status' => 1,
    ])->save();

    GroupType::create([
      'id' => 'jur',
      'label' => 'Jurisdiction',
    ])->save();
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    $relationshipTypeStorage = $this->container->get('entity_type.manager')
      ->getStorage('group_relationship_type');
    if (!$relationshipTypeStorage->load('jur-group_membership')) {
      $relationshipTypeStorage
        ->createFromPlugin(GroupType::load('jur'), 'group_membership')
        ->save();
    }

    require_once dirname(__DIR__, 3) . '/markaspot_group.install';
  }

  /**
   * Missing memberships and roles are repaired idempotently.
   */
  public function testBackfillConvergesMembershipStatesIdempotently(): void {
    $apiUser = $this->createApiUser();
    $missingMembership = $this->createJurisdiction('Missing membership');
    $rolelessMembership = $this->createJurisdiction('Roleless membership');
    $completeMembership = $this->createJurisdiction('Complete membership');

    $rolelessMembership
      ->addRelationship($apiUser, 'group_membership')
      ->save();
    $complete = $completeMembership
      ->addRelationship($apiUser, 'group_membership');
    $complete->set('group_roles', ['jur-member']);
    $complete->save();

    $this->assertSame(
      'Backfilled api_user jurisdiction memberships: created 1, repaired 1, skipped 1.',
      markaspot_group_update_11947(),
    );
    foreach ([$missingMembership, $rolelessMembership, $completeMembership] as $group) {
      $this->assertSame(['jur-member'], $this->membershipRoles($group, $apiUser));
    }

    $this->assertSame(
      'Backfilled api_user jurisdiction memberships: created 0, repaired 0, skipped 3.',
      markaspot_group_update_11947(),
    );
    foreach ([$missingMembership, $rolelessMembership, $completeMembership] as $group) {
      $this->assertSame(['jur-member'], $this->membershipRoles($group, $apiUser));
    }
  }

  /**
   * An absent api_user is reported without creating an account.
   */
  public function testBackfillSkipsWhenApiUserDoesNotExist(): void {
    $this->createJurisdiction('First workspace');
    $this->createJurisdiction('Child workspace');

    $this->assertSame(
      'Skipped api_user jurisdiction membership backfill because the account does not exist; created 0, repaired 0, skipped 2.',
      markaspot_group_update_11947(),
    );
    $this->assertSame([], $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->loadByProperties(['name' => 'api_user']));
  }

  /**
   * The profile user-link repair runs before the membership backfill.
   */
  public function testBackfillDependsOnApiUserLinkUpdate(): void {
    $this->assertSame(
      ['markaspot' => 11925],
      markaspot_group_update_dependencies()['markaspot_group'][11947],
    );
  }

  /**
   * Creates the shared API key owner.
   */
  private function createApiUser(): UserInterface {
    $user = User::create([
      'name' => 'api_user',
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates a jurisdiction group.
   */
  private function createJurisdiction(string $label): GroupInterface {
    $group = Group::create([
      'type' => 'jur',
      'label' => $label,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Gets individual roles from a user's group membership.
   *
   * @return string[]
   *   Group role IDs.
   */
  private function membershipRoles(GroupInterface $group, UserInterface $user): array {
    $relationships = $this->container->get('entity_type.manager')
      ->getStorage('group_relationship')
      ->loadByProperties([
        'gid' => $group->id(),
        'entity_id' => $user->id(),
        'plugin_id' => 'group_membership',
      ]);
    $relationship = reset($relationships);
    $this->assertNotFalse($relationship);
    return array_column(
      $relationship->get('group_roles')->getValue(),
      'target_id',
    );
  }

}
