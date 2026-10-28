<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_passwordless\Controller\PasswordlessAuthController;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The cached group slice of /api/auth/status follows every revocation.
 *
 * The slice is cached without a time limit, so each test changes one thing
 * the payload depends on and expects the very next status call to show it.
 * A direct SQL write, which invalidates nothing, proves that a call was
 * served from the cache.
 *
 * @group markaspot_passwordless
 */
#[RunTestsInSeparateProcesses]
final class AuthStatusGroupCacheKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'image',
    'media',
    'taxonomy',
    'options',
    'datetime',
    'language',
    'entity',
    'entity_reference_revisions',
    'paragraphs',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'service_request',
  ];

  /**
   * The staff account under test.
   */
  private UserInterface $staff;

  /**
   * A jurisdiction the account is a member of.
   */
  private Group $jurisdiction;

  /**
   * An organisation of that jurisdiction.
   */
  private Group $organisation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'group']);
    $this->installSchema('markaspot_group', ['markaspot_group_invitations']);

    if (!FieldStorageConfig::loadByName('user', 'field_all_groups_member')) {
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
    }
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root@example.org', 'mail' => 'root@example.org', 'status' => 1])->save();
    Role::create(['id' => 'staff', 'label' => 'Staff'])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $relationship_types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['jur', 'org'] as $group_type) {
      if (!$relationship_types->load($group_type . '-group_membership')) {
        $relationship_types->createFromPlugin(GroupType::load($group_type), 'group_membership')->save();
      }
    }
    GroupRole::create(['id' => 'jur-member', 'label' => 'Member', 'group_type' => 'jur', 'scope' => 'individual'])->save();
    GroupRole::create(['id' => 'jur-moderator', 'label' => 'Moderator', 'group_type' => 'jur', 'scope' => 'individual'])->save();
    GroupRole::create(['id' => 'org-member', 'label' => 'Org member', 'group_type' => 'org', 'scope' => 'individual'])->save();
    // markaspot_group pairs every organisation membership with this one.
    GroupRole::create([
      'id' => 'jur-org_member',
      'label' => 'Organisation member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    // Synchronized with the global role "staff" for members of the group.
    GroupRole::create([
      'id' => 'jur-staff',
      'label' => 'Staff in the jurisdiction',
      'group_type' => 'jur',
      'scope' => 'insider',
      'global_role' => 'staff',
    ])->save();

    if (!FieldStorageConfig::loadByName('group', 'field_slug')) {
      FieldStorageConfig::create(['field_name' => 'field_slug', 'entity_type' => 'group', 'type' => 'string'])->save();
    }
    if (!FieldConfig::loadByName('group', 'jur', 'field_slug')) {
      FieldConfig::create(['field_name' => 'field_slug', 'entity_type' => 'group', 'bundle' => 'jur', 'label' => 'Slug'])->save();
    }
    if (!FieldStorageConfig::loadByName('group', 'field_jurisdiction')) {
      FieldStorageConfig::create([
        'field_name' => 'field_jurisdiction',
        'entity_type' => 'group',
        'type' => 'entity_reference',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        'settings' => ['target_type' => 'group'],
      ])->save();
    }
    if (!FieldConfig::loadByName('group', 'org', 'field_jurisdiction')) {
      FieldConfig::create([
        'field_name' => 'field_jurisdiction',
        'entity_type' => 'group',
        'bundle' => 'org',
        'label' => 'Jurisdiction',
      ])->save();
    }

    $this->jurisdiction = Group::create(['type' => 'jur', 'label' => 'Lindenhof', 'field_slug' => 'lindenhof']);
    $this->jurisdiction->save();
    $this->organisation = Group::create([
      'type' => 'org',
      'label' => 'Street works',
      'field_jurisdiction' => [$this->jurisdiction->id()],
    ]);
    $this->organisation->save();

    $this->staff = User::create([
      'name' => 'staff@example.org',
      'mail' => 'staff@example.org',
      'status' => 1,
      'roles' => ['staff'],
    ]);
    $this->staff->save();
    $this->jurisdiction->addMember($this->staff, ['group_roles' => ['jur-member']]);
    $this->container->get('current_user')->setAccount($this->staff);
  }

  /**
   * A served entry, then each change shows on the next call.
   */
  public function testEveryChangeReachesTheNextCall(): void {
    $groups = $this->statusGroups();
    $this->assertSame([(int) $this->jurisdiction->id()], array_map('intval', array_column($groups, 'id')));
    $this->assertSame(['jur-member', 'jur-staff'], $this->roleIds($groups, $this->jurisdiction));

    // Served from the cache: a write outside the entity API is not seen.
    $this->renameWithoutCacheTags($this->jurisdiction, 'Renamed in SQL');
    $this->assertSame('Lindenhof', $this->label($this->statusGroups(), $this->jurisdiction));

    // A group save.
    $this->jurisdiction = Group::load($this->jurisdiction->id());
    $this->jurisdiction->set('label', 'Lindenhof north')->save();
    $this->assertSame('Lindenhof north', $this->label($this->statusGroups(), $this->jurisdiction));

    // A changed group role on the membership.
    $membership = GroupMembership::loadSingle($this->jurisdiction, $this->staff);
    $this->assertInstanceOf(GroupMembership::class, $membership);
    $membership->set('group_roles', ['jur-moderator'])->save();
    $this->assertSame(['jur-moderator', 'jur-staff'], $this->roleIds($this->statusGroups(), $this->jurisdiction));

    // A renamed group role.
    GroupRole::load('jur-moderator')->set('label', 'Team lead')->save();
    $roles = array_column($this->groupById($this->statusGroups(), $this->jurisdiction)['roles'], 'label', 'id');
    $this->assertSame('Team lead', $roles['jur-moderator']);

    // A new membership, with the organisation's stored jurisdiction.
    $this->organisation->addMember($this->staff, ['group_roles' => ['org-member']]);
    $organisation = $this->groupById($this->statusGroups(), $this->organisation);
    $this->assertSame((string) $this->jurisdiction->id(), $organisation['jurisdiction_id']);

    // The organisation moves to no jurisdiction.
    $this->organisation = Group::load($this->organisation->id());
    $this->organisation->set('field_jurisdiction', [])->save();
    $this->assertNull($this->groupById($this->statusGroups(), $this->organisation)['jurisdiction_id']);

    // A removed membership.
    $this->jurisdiction->removeMember($this->staff);
    $this->assertNull($this->groupById($this->statusGroups(), $this->jurisdiction));
  }

  /**
   * Deleting a global role removes user__roles rows without a cache tag.
   */
  public function testDeletedGlobalRoleDropsItsSynchronizedGroupRole(): void {
    $this->assertContains('jur-staff', $this->roleIds($this->statusGroups(), $this->jurisdiction));

    Role::load('staff')->delete();
    // Core removes the user__roles rows in SQL; the next request's account
    // reads them again.
    $this->container->get('current_user')->setAccount(User::load($this->staff->id()));

    $this->assertNotContains('jur-staff', $this->roleIds($this->statusGroups(), $this->jurisdiction));
  }

  /**
   * A role assignment removed without any cache tag gets a new cache ID.
   *
   * Only the account's live roles in the cache ID cover a removal outside
   * the entity API, such as SQL. The entity caches are reset here because a
   * raw SQL write leaves them stale in production too, exactly as before the
   * group cache existed; the test proves the cache ID rekeys.
   */
  public function testRoleRemovedInSqlDropsItsSynchronizedGroupRole(): void {
    $this->assertContains('jur-staff', $this->roleIds($this->statusGroups(), $this->jurisdiction));

    $this->container->get('database')->delete('user__roles')
      ->condition('entity_id', $this->staff->id())
      ->condition('roles_target_id', 'staff')
      ->execute();
    // What a new request would start with: no per-process caches.
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('user')->resetCache([$this->staff->id()]);
    $entity_type_manager->getStorage('group_relationship')->resetCache();
    $entity_type_manager->getStorage('group_role')->resetUserGroupRoleCache($this->staff);
    $this->container->get('entity.memory_cache')->deleteAll();
    $this->container->get('current_user')->setAccount(User::load($this->staff->id()));

    $this->assertNotContains('jur-staff', $this->roleIds($this->statusGroups(), $this->jurisdiction));
  }

  /**
   * A synchronized group role follows a changed global role mapping.
   */
  public function testSynchronizedRoleMappingChangeReachesTheNextCall(): void {
    $this->assertContains('jur-staff', $this->roleIds($this->statusGroups(), $this->jurisdiction));

    GroupRole::load('jur-staff')->set('global_role', 'administrator')->save();
    $this->container->get('entity_type.manager')->getStorage('group_role')->resetUserGroupRoleCache($this->staff);

    $this->assertNotContains('jur-staff', $this->roleIds($this->statusGroups(), $this->jurisdiction));
  }

  /**
   * The report-view jurisdictions are computed on every call.
   */
  public function testReportViewJurisdictionsStayLive(): void {
    $workspace = $this->createMock(WorkspaceVisibilityInterface::class);
    $workspace->method('requestUsesPublicApiKey')->willReturn(FALSE);
    $workspace->expects($this->exactly(2))->method('getReportViewJurisdictionIds')->willReturn([7]);
    $this->container->set('markaspot_group.workspace_visibility', $workspace);

    $this->statusGroups();
    $response = PasswordlessAuthController::create($this->container)->status(Request::create('/api/auth/status'));
    $this->assertSame([7], json_decode((string) $response->getContent(), TRUE)['user']['report_view_jurisdictions']);
  }

  /**
   * A request with a public API key is answered live and not stored.
   */
  public function testPublicApiKeyRequestsAreNotCached(): void {
    $workspace = $this->createMock(WorkspaceVisibilityInterface::class);
    $workspace->method('requestUsesPublicApiKey')->willReturn(TRUE);
    $workspace->method('getReportViewJurisdictionIds')->willReturn([]);
    $this->container->set('markaspot_group.workspace_visibility', $workspace);

    $this->statusGroups();
    $this->renameWithoutCacheTags($this->jurisdiction, 'Live rename');
    $this->assertSame('Live rename', $this->label($this->statusGroups(), $this->jurisdiction));
  }

  /**
   * A build that overlaps an invalidation is not kept.
   */
  public function testBuildOverlappingAnInvalidationIsDropped(): void {
    $racing = RacingAuthController::create($this->container);
    $racing->status(Request::create('/api/auth/status'));

    $this->renameWithoutCacheTags($this->jurisdiction, 'After the race');
    $this->assertSame('After the race', $this->label($this->statusGroups(), $this->jurisdiction));
  }

  /**
   * Returns the groups of a status call.
   *
   * @return list<array<string, mixed>>
   *   The groups payload.
   */
  private function statusGroups(): array {
    $response = PasswordlessAuthController::create($this->container)->status(Request::create('/api/auth/status'));
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($payload['authenticated']);
    return $payload['user']['groups'];
  }

  /**
   * Returns the payload entry of a group, or NULL.
   *
   * @param list<array<string, mixed>> $groups
   *   The groups payload.
   * @param \Drupal\group\Entity\Group $group
   *   The group.
   *
   * @return array<string, mixed>|null
   *   The entry.
   */
  private function groupById(array $groups, Group $group): ?array {
    foreach ($groups as $entry) {
      if ((string) $entry['id'] === (string) $group->id()) {
        return $entry;
      }
    }
    return NULL;
  }

  /**
   * Returns the label of a group in the payload.
   *
   * @param list<array<string, mixed>> $groups
   *   The groups payload.
   * @param \Drupal\group\Entity\Group $group
   *   The group.
   */
  private function label(array $groups, Group $group): string {
    $entry = $this->groupById($groups, $group);
    $this->assertNotNull($entry);
    return (string) $entry['label'];
  }

  /**
   * Returns the sorted role IDs of a group in the payload.
   *
   * @param list<array<string, mixed>> $groups
   *   The groups payload.
   * @param \Drupal\group\Entity\Group $group
   *   The group.
   *
   * @return list<string>
   *   The role IDs.
   */
  private function roleIds(array $groups, Group $group): array {
    $entry = $this->groupById($groups, $group);
    $this->assertNotNull($entry);
    $ids = array_column($entry['roles'], 'id');
    sort($ids);
    return $ids;
  }

  /**
   * Renames a group in SQL, which invalidates no cache tag.
   */
  private function renameWithoutCacheTags(Group $group, string $label): void {
    $this->container->get('database')->update('groups_field_data')
      ->fields(['label' => $label])
      ->condition('id', $group->id())
      ->execute();
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache([$group->id()]);
    $this->container->get('entity.memory_cache')->deleteAll();
  }

}

/**
 * Invalidates a guard tag while it builds the group slice.
 */
final class RacingAuthController extends PasswordlessAuthController {

  /**
   * {@inheritdoc}
   */
  protected function getUserGroups($user, ?CacheableMetadata $cacheability = NULL): array {
    $groups = parent::getUserGroups($user, $cacheability);
    if ($cacheability !== NULL) {
      // Another request removes or changes a group during this build.
      Cache::invalidateTags(['group_list']);
    }
    return $groups;
  }

}
