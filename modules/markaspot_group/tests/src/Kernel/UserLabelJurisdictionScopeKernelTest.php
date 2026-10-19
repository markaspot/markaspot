<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Site\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolver;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests user labels stay inside a shared jurisdiction scope.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class UserLabelJurisdictionScopeKernelTest extends KernelTestBase {

  use UserCreationTrait;

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
    'image',
    'serialization',
    'jsonapi',
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
   * Owner of workspace A, named like a fastmap self-signup.
   */
  private UserInterface $ownerA;

  /**
   * Owner of workspace B, named like a fastmap self-signup.
   */
  private UserInterface $ownerB;

  /**
   * Counts tree walks so a test can prove each tree is walked once.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    if ($container->hasDefinition('markaspot_group.hierarchy_resolver')) {
      $container->getDefinition('markaspot_group.hierarchy_resolver')->setClass(CountingHierarchyResolver::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['system', 'user', 'field', 'file', 'node', 'group']);

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

    foreach (['tenant_admin', 'moderator', 'editorial_board'] as $role_id) {
      Role::create(['id' => $role_id, 'label' => $role_id])->save();
    }
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    Role::load('authenticated')->grantPermission('access content')->save();

    $this->tenantA = $this->jurisdiction('Tenant A');
    $this->districtA = $this->jurisdiction('District A', $this->tenantA);
    $this->tenantB = $this->jurisdiction('Tenant B');

    $this->ownerA = $this->account('owner-a@example.org', ['tenant_admin']);
    $this->tenantA->addMember($this->ownerA);
    $this->ownerB = $this->account('owner-b@example.org', ['tenant_admin']);
    $this->tenantB->addMember($this->ownerB);
  }

  /**
   * Owners of different workspaces do not see each other's names.
   */
  public function testForeignLabelIsForbidden(): void {
    $access = $this->ownerB->access('view label', $this->ownerA, TRUE);
    $this->assertTrue($access->isForbidden());
    $this->assertContains('user', $access->getCacheContexts());
    $this->assertContains('group_list', $access->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:entity:' . $this->ownerA->id(), $access->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:entity:' . $this->ownerB->id(), $access->getCacheTags());

    // The own name stays visible.
    $this->assertTrue($this->ownerA->access('view label', $this->ownerA));
  }

  /**
   * A shared jurisdiction scope keeps the label visible, in both directions.
   */
  public function testSharedScopeKeepsLabel(): void {
    $staffA = $this->account('staff-a', ['moderator']);
    $this->tenantA->addMember($staffA);
    $this->assertTrue($this->ownerA->access('view label', $staffA));
    $this->assertTrue($staffA->access('view label', $this->ownerA));

    // Hierarchy: the parent's staff and a district's staff share the district.
    $districtStaff = $this->account('district-staff', ['moderator']);
    $this->districtA->addMember($districtStaff);
    $this->assertTrue($districtStaff->access('view label', $this->ownerA));
    $this->assertTrue($this->ownerA->access('view label', $districtStaff));
    $this->assertFalse($districtStaff->access('view label', $this->ownerB));

    // An organisation membership counts for its jurisdiction tree.
    $organisation = Group::create([
      'type' => 'org',
      'label' => 'Department',
      'field_jurisdiction' => $this->tenantA->id(),
    ]);
    $organisation->save();
    $orgStaff = $this->account('org-staff', ['moderator']);
    $organisation->addMember($orgStaff);
    $this->assertTrue($orgStaff->access('view label', $this->ownerA));
    $this->assertTrue($this->ownerA->access('view label', $orgStaff));
    $this->assertFalse($orgStaff->access('view label', $this->ownerB));

    // An account without any membership shares no scope.
    $loner = $this->account('loner', ['moderator']);
    $this->assertFalse($loner->access('view label', $this->ownerA));
  }

  /**
   * Platform staff and single-jurisdiction installs stay unscoped.
   */
  public function testPrivilegedAccountsAndSingleJurisdictionStayNeutral(): void {
    foreach (['administrator', 'editorial_board'] as $role_id) {
      $this->assertTrue($this->ownerA->access('view label', $this->account('platform-' . $role_id, [$role_id])), $role_id);
    }
    Role::create(['id' => 'user_admin', 'label' => 'User admin', 'permissions' => ['administer users']])->save();
    $this->assertTrue($this->ownerA->access('view label', $this->account('user-admin', ['user_admin'])));
    $this->assertTrue(User::load(0)->access('view label', $this->ownerB));

    // One jurisdiction: everybody works in the same scope.
    $this->tenantB->delete();
    $this->districtA->delete();
    drupal_static_reset('_markaspot_group_jurisdiction_group_count');
    $this->container->get('entity_type.manager')->getAccessControlHandler('user')->resetCache();
    $this->assertTrue($this->ownerA->access('view label', $this->account('unrelated', ['moderator'])));
  }

  /**
   * JSON:API no longer lists foreign names as label-only resources.
   */
  public function testJsonApiUserCollectionHidesForeignNames(): void {
    $staffA = $this->account('staff-a', ['moderator']);
    $this->tenantA->addMember($staffA);
    $this->container->get('router.builder')->rebuild();
    $this->setCurrentUser($staffA);

    $request = Request::create('/jsonapi/user/user');
    $request->headers->set('Accept', 'application/vnd.api+json');
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $names = array_map(
      static fn(array $resource): ?string => $resource['attributes']['display_name'] ?? $resource['attributes']['name'] ?? NULL,
      json_decode((string) $response->getContent(), TRUE)['data'] ?? [],
    );

    $this->assertContains('owner-a@example.org', $names, 'Names of the own workspace stay listed.');
    $this->assertNotContains('owner-b@example.org', $names, 'Foreign names are not listed.');
  }

  /**
   * Included user references do not carry foreign names either.
   *
   * File owners and assignees reach JSON:API as include=uid or
   * include=field_assignee; they get the same label check.
   */
  public function testJsonApiIncludesHideForeignNames(): void {
    $staffA = $this->account('staff-a', ['moderator']);
    $this->tenantA->addMember($staffA);
    Role::load('authenticated')->grantPermission('access content')->save();

    $file = File::create(['uri' => 'public://foreign.txt', 'uid' => $this->ownerB->id(), 'status' => 1]);
    $file->save();
    $ownFile = File::create(['uri' => 'public://own.txt', 'uid' => $this->ownerA->id(), 'status' => 1]);
    $ownFile->save();

    NodeType::create(['type' => 'task', 'name' => 'Task'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_assignee',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_assignee',
      'entity_type' => 'node',
      'bundle' => 'task',
      'label' => 'Assignee',
    ])->save();
    Node::create([
      'type' => 'task',
      'title' => 'Task',
      'status' => 1,
      'uid' => $this->ownerA->id(),
      'field_assignee' => $this->ownerB->id(),
    ])->save();

    $this->container->get('router.builder')->rebuild();
    $this->setCurrentUser($staffA);

    $files = $this->includedNames('/jsonapi/file/file?include=uid');
    $this->assertContains('owner-a@example.org', $files);
    $this->assertNotContains('owner-b@example.org', $files, 'A foreign file owner is not included.');

    $tasks = $this->includedNames('/jsonapi/node/task?include=field_assignee,uid');
    $this->assertContains('owner-a@example.org', $tasks);
    $this->assertNotContains('owner-b@example.org', $tasks, 'A foreign assignee is not included.');
  }

  /**
   * Returns the user names a JSON:API response includes.
   *
   * @return string[]
   *   Names of included user resources, label-only or full.
   */
  private function includedNames(string $uri): array {
    $request = Request::create($uri);
    $request->headers->set('Accept', 'application/vnd.api+json');
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $document = json_decode((string) $response->getContent(), TRUE);
    $names = [];
    foreach ($document['included'] ?? [] as $resource) {
      if (($resource['type'] ?? '') === 'user--user') {
        $names[] = $resource['attributes']['display_name'] ?? $resource['attributes']['name'] ?? '';
      }
    }
    return $names;
  }

  /**
   * The user autocomplete lists accounts of the shared scope only.
   *
   * The autocomplete renders labels without a 'view label' check, and its
   * key can be taken from any form with a user reference widget.
   */
  public function testUserAutocompleteListsSharedScopeOnly(): void {
    $staffA = $this->account('staff-a', ['moderator']);
    $this->tenantA->addMember($staffA);
    $this->container->get('router.builder')->rebuild();
    $this->setCurrentUser($staffA);

    $settings = ['match_operator' => 'CONTAINS', 'match_limit' => 10];
    [$targetType, $handler] = ['user', 'default:user'];
    $key = Crypt::hmacBase64(serialize($settings) . $targetType . $handler, Settings::getHashSalt());
    $this->container->get('keyvalue')->get('entity_autocomplete')->set($key, $settings);

    $request = Request::create('/entity_reference_autocomplete/user/default:user/' . $key, 'GET', ['q' => 'owner']);
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $labels = array_column(json_decode((string) $response->getContent(), TRUE), 'label');

    $this->assertContains('owner-a@example.org', $labels);
    $this->assertNotContains('owner-b@example.org', $labels, 'A foreign account is not suggested.');
  }

  /**
   * Anonymous visitors get no user suggestions, even with a valid key.
   */
  public function testAnonymousAutocompleteListsNobody(): void {
    $this->container->get('router.builder')->rebuild();
    $this->setCurrentUser(new AnonymousUserSession());

    $settings = ['match_operator' => 'CONTAINS', 'match_limit' => 10];
    $key = Crypt::hmacBase64(serialize($settings) . 'userdefault:user', Settings::getHashSalt());
    $this->container->get('keyvalue')->get('entity_autocomplete')->set($key, $settings);

    $request = Request::create('/entity_reference_autocomplete/user/default:user/' . $key, 'GET', ['q' => 'owner']);
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([], json_decode((string) $response->getContent(), TRUE));
    $this->assertStringNotContainsString('owner-', (string) $response->getContent());

    // Single-jurisdiction installs keep core behaviour.
    $this->tenantB->delete();
    $this->districtA->delete();
    drupal_static_reset();
    $uids = $this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(TRUE)->execute();
    $this->assertContains((string) $this->ownerA->id(), array_map('strval', $uids));
  }

  /**
   * Scope expansion walks each jurisdiction tree once.
   *
   * Two roots: tenant A with two districts, tenant C with one. A member of
   * every jurisdiction of both trees must not walk tree A three times.
   */
  public function testScopeWalksEachTreeOnce(): void {
    $districtA2 = $this->jurisdiction('District A2', $this->tenantA);
    $tenantC = $this->jurisdiction('Tenant C');
    $districtC = $this->jurisdiction('District C', $tenantC);
    $staff = $this->account('staff-multi', ['moderator']);
    foreach ([$this->tenantA, $this->districtA, $districtA2, $districtC] as $jurisdiction) {
      $jurisdiction->addMember($staff);
    }
    $memberC = $this->account('member-c', ['moderator']);
    $tenantC->addMember($memberC);

    drupal_static_reset();
    // The membership scope is resolved first; count only the tree expansion.
    _markaspot_group_account_scope_jurisdiction_ids((int) $staff->id());
    CountingHierarchyResolver::$descendantWalks = [];
    $uids = _markaspot_group_scope_user_ids((int) $staff->id());

    $this->assertContains((int) $this->ownerA->id(), $uids);
    $this->assertContains((int) $memberC->id(), $uids, 'Members of the second tree are in scope.');
    $this->assertNotContains((int) $this->ownerB->id(), $uids);
    $walks = array_count_values(CountingHierarchyResolver::$descendantWalks);
    ksort($walks);
    $this->assertSame([(int) $this->tenantA->id() => 1, (int) $tenantC->id() => 1], $walks, 'Each tree is walked once, from its root.');
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
   * Creates an account with the given Drupal roles.
   */
  private function account(string $name, array $roles): UserInterface {
    $user = User::create([
      'name' => $name,
      'mail' => str_contains($name, '@') ? $name : $name . '@example.org',
      'status' => 1,
      'roles' => $roles,
    ]);
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
   * Installs the membership relationship type for a group type.
   */
  private function ensureMembershipType(string $groupType): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$storage->load($groupType . '-group_membership')) {
      $storage->createFromPlugin(GroupType::load($groupType), 'group_membership')->save();
    }
  }

}

/**
 * Hierarchy resolver that records each descendant walk.
 */
class CountingHierarchyResolver extends JurisdictionHierarchyResolver {

  /**
   * Group IDs whose descendants were requested, in order.
   *
   * @var int[]
   */
  public static array $descendantWalks = [];

  /**
   * {@inheritdoc}
   */
  public function getDescendantIds(int $groupId): array {
    self::$descendantWalks[] = $groupId;
    return parent::getDescendantIds($groupId);
  }

}
