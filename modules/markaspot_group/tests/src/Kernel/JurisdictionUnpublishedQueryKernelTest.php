<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\jsonapi\Access\TemporaryQueryGuard;
use Drupal\jsonapi\Query\EntityCondition;
use Drupal\jsonapi\Query\EntityConditionGroup;
use Drupal\jsonapi\Query\Filter;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_nuxt\JsonApi\CachedCountEntityResource;
use Drupal\markaspot_nuxt\JsonApi\CountCacheQueryWrapper;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests jurisdiction-only draft visibility across entity and collection reads.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class JurisdictionUnpublishedQueryKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file',
    'entity', 'flexible_permissions', 'group', 'gnode', 'markaspot_group',
    'serialization', 'jsonapi',
  ];

  /**
   * Jurisdiction manager with no organisation membership.
   */
  private UserInterface $manager;

  /**
   * Directly managed jurisdiction.
   */
  private Group $jurisdiction;

  /**
   * Authorised draft owned by another account.
   */
  private Node $draft;

  /**
   * Draft in an unrelated jurisdiction.
   */
  private Node $foreignDraft;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node', 'group']);
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $plugin) {
      $type = $relationships->createFromPlugin(GroupType::load('jur'), $plugin);
      if (!$relationships->load($type->id())) {
        $type->save();
      }
    }
    GroupRole::create([
      'id' => 'jur-editorial',
      'label' => 'Jurisdiction editor',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => [
        'view group_node:service_request entity',
        'view unpublished group_node:service_request entity',
        'view own unpublished group_node:service_request entity',
      ],
    ])->save();
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
    foreach (['field_jurisdiction', 'field_organisation'] as $field) {
      FieldStorageConfig::create([
        'field_name' => $field,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'group'],
      ])->save();
      FieldConfig::create([
        'field_name' => $field,
        'entity_type' => 'node',
        'bundle' => 'service_request',
        'label' => $field,
      ])->save();
    }
    Role::load('authenticated')->grantPermission('access content')->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->manager = User::create(['name' => 'jurisdiction-manager', 'status' => 1]);
    $this->manager->save();
    $this->container->get('current_user')->setAccount($root);
    $this->jurisdiction = Group::create(['type' => 'jur', 'label' => 'Managed jurisdiction']);
    $this->jurisdiction->save();
    $foreign = Group::create(['type' => 'jur', 'label' => 'Foreign jurisdiction']);
    $foreign->save();
    $this->jurisdiction->addMember($this->manager, ['group_roles' => ['jur-editorial']]);
    $this->draft = $this->createRequest($this->jurisdiction, FALSE);
    $this->foreignDraft = $this->createRequest($foreign, FALSE);
    $this->container->get('current_user')->setAccount($this->manager);
  }

  /**
   * Group-allowed drafts survive Core's additional node grant check.
   */
  public function testJurisdictionOnlyDraftQueryMatchesEntityAccess(): void {
    $this->assertTrue($this->draft->get('field_organisation')->isEmpty());
    $this->assertFalse($this->manager->hasPermission('bypass node access'));
    $this->assertTrue($this->draft->access('view', $this->manager));
    $this->assertFalse($this->foreignDraft->access('view', $this->manager));
    $this->assertSame([(int) $this->draft->id()], $this->draftIds());
    $this->assertArrayNotHasKey('markaspot_jurisdiction', markaspot_group_node_grants($this->manager, 'update'));
    $this->assertArrayNotHasKey('markaspot_jurisdiction', markaspot_group_node_grants($this->manager, 'delete'));
  }

  /**
   * Membership alone and own-draft permission cannot reveal others' drafts.
   */
  public function testOwnOnlyPermissionDoesNotGrantOthersDrafts(): void {
    GroupRole::load('jur-editorial')->revokePermission('view unpublished group_node:service_request entity')->save();
    $this->assertTrue($this->jurisdiction->hasPermission('view own unpublished group_node:service_request entity', $this->manager));
    $this->assertFalse($this->draft->access('view', $this->manager));
    $this->assertSame([], $this->draftIds());
    $this->assertArrayNotHasKey('markaspot_jurisdiction', markaspot_group_node_grants($this->manager, 'view'));
  }

  /**
   * Individual admin and synchronized insider permissions match Group checks.
   */
  public function testEffectiveAdminAndInsiderPermissionsMatchGroup(): void {
    $role = GroupRole::load('jur-editorial');
    $role->revokePermission('view unpublished group_node:service_request entity')->set('admin', TRUE)->save();
    $this->assertTrue($this->jurisdiction->hasPermission('view unpublished group_node:service_request entity', $this->manager));
    $this->assertSame([(int) $this->draft->id()], $this->draftIds());
    $role->set('admin', FALSE)->save();
    GroupRole::create([
      'id' => 'jur-synced_reader',
      'label' => 'Synchronized reader',
      'group_type' => 'jur',
      'scope' => 'insider',
      'global_role' => 'authenticated',
      'permissions' => ['view unpublished group_node:service_request entity'],
    ])->save();
    $this->assertTrue($this->jurisdiction->hasPermission('view unpublished group_node:service_request entity', $this->manager));
    $this->assertSame([(int) $this->draft->id()], $this->draftIds());
  }

  /**
   * Membership revocation takes effect without rewriting report data.
   */
  public function testRemovedMembershipRevokesDraftQuery(): void {
    $this->assertSame([(int) $this->draft->id()], $this->draftIds());
    $this->jurisdiction->removeMember($this->manager);
    $this->assertSame([], $this->draftIds());
    $this->assertArrayNotHasKey('markaspot_jurisdiction', markaspot_group_node_grants($this->manager, 'view'));
  }

  /**
   * Anonymous and role-only accounts receive no jurisdiction draft grant.
   */
  public function testAnonymousAndRoleOnlyAccountsCannotReadDrafts(): void {
    Role::create(['id' => 'tenant_admin', 'label' => 'Tenant administrator'])->save();
    $outsider = User::create(['name' => 'role-only', 'status' => 1, 'roles' => ['tenant_admin']]);
    $outsider->save();
    foreach ([$outsider, User::load(0)] as $account) {
      $this->container->get('current_user')->setAccount($account);
      $this->assertSame([], $this->draftIds());
      $this->assertArrayNotHasKey('markaspot_jurisdiction', markaspot_group_node_grants($account, 'view'));
    }
  }

  /**
   * Actual JSON:API row and count queries keep authorised jurisdiction drafts.
   */
  public function testJsonApiJurisdictionFilterRowsAndCounts(): void {
    // Exercise Core's published/own subsets. Entity's empty fixture handler
    // otherwise advertises AMONG_ALL and hides the integration regression.
    $this->container->get('entity_type.manager')->getDefinition('node')->setHandlerClass('query_access', NULL);
    TemporaryQueryGuard::setModuleHandler($this->container->get('module_handler'));
    $reflection = new \ReflectionClass(CachedCountEntityResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('entityTypeManager')->setValue($resource, $this->container->get('entity_type.manager'));
    $reflection->getProperty('fieldManager')->setValue($resource, $this->container->get('entity_field.manager'));
    $type = new ResourceType('node', 'service_request', Node::class);
    $filter = new Filter(new EntityConditionGroup('AND', [
      new EntityCondition('field_jurisdiction.target_id', [
        (int) $this->jurisdiction->id(),
        (int) $this->foreignDraft->get('field_jurisdiction')->target_id,
      ], 'IN'),
    ]));
    $params = [Filter::KEY_NAME => $filter];
    $query = $reflection->getMethod('getCollectionQuery')->invoke($resource, $type, $params, new CacheableMetadata());
    $this->assertSame([(int) $this->draft->id()], array_map('intval', array_values($query->execute())));
    $count = $reflection->getMethod('getCollectionCountQuery')->invoke($resource, $type, $params, new CacheableMetadata());
    $this->assertSame(1, (int) $count->execute());
  }

  /**
   * Unfiltered count caching cannot retain drafts after permission revocation.
   */
  public function testPermissionRevocationInvalidatesUnfilteredCount(): void {
    $reflection = new \ReflectionClass(CachedCountEntityResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('entityTypeManager')->setValue($resource, $this->container->get('entity_type.manager'));
    $reflection->getProperty('fieldManager')->setValue($resource, $this->container->get('entity_field.manager'));
    $type = new ResourceType('node', 'service_request', Node::class);
    $method = $reflection->getMethod('getCollectionCountQuery');
    $count = $method->invoke($resource, $type, [], new CacheableMetadata());
    $this->assertSame(1, (int) $count->execute());
    GroupRole::load('jur-editorial')->revokePermission('view unpublished group_node:service_request entity')->save();
    $this->assertSame([], $this->draftIds());
    $count = $method->invoke($resource, $type, [], new CacheableMetadata());
    $this->assertSame(0, (int) $count->execute());
  }

  /**
   * Uncacheable effective permissions disable count reuse, including skipping.
   */
  public function testUncacheablePermissionMetadataDisablesCountCache(): void {
    $original = $this->container->get('cache_context.user.group_permissions');
    $context = $this->createMock(CacheContextInterface::class);
    $context->method('getContext')->willReturn($original->getContext());
    $context->method('getCacheableMetadata')->willReturn((new CacheableMetadata())->setCacheMaxAge(0));
    $this->container->set('cache_context.user.group_permissions', $context);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->never())->method('get');
    $cache->expects($this->never())->method('set');
    $this->container->set('cache.default', $cache);
    $reflection = new \ReflectionClass(CachedCountEntityResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('entityTypeManager')->setValue($resource, $this->container->get('entity_type.manager'));
    $reflection->getProperty('fieldManager')->setValue($resource, $this->container->get('entity_field.manager'));
    $type = new ResourceType('node', 'service_request', Node::class);
    $method = $reflection->getMethod('getCollectionCountQuery');
    $metadata = new CacheableMetadata();
    $count = $method->invoke($resource, $type, [], $metadata);
    $this->assertSame(0, $metadata->getCacheMaxAge());
    $this->assertSame(1, (int) $count->execute());
    $stack = $this->container->get('request_stack');
    $stack->push(new Request(['skip-count' => 'true']));
    try {
      $skipped_metadata = new CacheableMetadata();
      $skipped = $method->invoke($resource, $type, [], $skipped_metadata);
      $this->assertSame(0, $skipped_metadata->getCacheMaxAge());
      $this->assertSame(CountCacheQueryWrapper::SKIPPED_COUNT_SENTINEL, $skipped->execute());
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Routing remains authoritative when its mirrored relationship is missing.
   */
  public function testMissingRelationshipDoesNotChangeRoutingAuthority(): void {
    foreach ($this->jurisdiction->getRelationshipsByEntity($this->draft, 'group_node:service_request') as $relationship) {
      $relationship->delete();
    }
    $this->assertSame((int) $this->jurisdiction->id(), (int) $this->draft->get('field_jurisdiction')->target_id);
    $this->assertTrue($this->draft->access('view', $this->manager));
    $this->assertFalse($this->foreignDraft->access('view', $this->manager));
    $this->assertSame([(int) $this->draft->id()], $this->draftIds());
  }

  /**
   * Publishing, unpublishing, moving and unassignment replace old grants.
   */
  public function testPublicationAndJurisdictionTransitionsReplaceGrants(): void {
    $this->container->get('current_user')->setAccount(User::load(1));
    $this->draft->setPublished()->save();
    $this->assertContains('all', $this->grantRealms($this->draft));
    $this->draft->setUnpublished()->save();
    $this->assertNotContains('all', $this->grantRealms($this->draft));
    $this->container->get('current_user')->setAccount($this->manager);
    $this->assertSame([(int) $this->draft->id()], $this->draftIds());

    $this->container->get('current_user')->setAccount(User::load(1));
    $this->draft->set('field_jurisdiction', $this->foreignDraft->get('field_jurisdiction')->target_id)->save();
    $this->container->get('current_user')->setAccount($this->manager);
    $this->assertSame([], $this->draftIds());
    $this->assertFalse($this->draft->access('view', $this->manager));
    $this->container->get('current_user')->setAccount(User::load(1));
    $this->draft->set('field_jurisdiction', [])->save();
    $this->assertNotContains('markaspot_jurisdiction', $this->grantRealms($this->draft));
  }

  /**
   * New scoped records preserve the published anonymous default grant.
   */
  public function testPublishedRequestsRemainPublic(): void {
    Role::load('anonymous')->grantPermission('access content')->save();
    GroupRole::create([
      'id' => 'jur-public',
      'label' => 'Public reader',
      'group_type' => 'jur',
      'scope' => 'outsider',
      'global_role' => 'anonymous',
      'permissions' => ['view group_node:service_request entity'],
    ])->save();
    $this->container->get('current_user')->setAccount(User::load(1));
    $this->draft->setPublished()->save();
    $this->container->get('current_user')->setAccount(User::load(0));
    $this->assertTrue($this->draft->access('view', User::load(0)));
    $ids = $this->container->get('entity_type.manager')->getStorage('node')
      ->getQuery()->accessCheck(TRUE)->condition('type', 'service_request')->execute();
    $this->assertSame([(int) $this->draft->id()], array_map('intval', array_values($ids)));
    $this->assertFalse($this->foreignDraft->access('view', User::load(0)));
  }

  /**
   * Root jurisdiction grants cannot reveal a referenced foreign draft.
   */
  public function testReferencedForeignDraftFilterKeepsCoreProtection(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_test_reference',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_test_reference',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Referenced request',
    ])->save();
    $this->container->get('current_user')->setAccount(User::load(1));
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $draft = Node::load($this->draft->id());
    $draft->set('field_test_reference', $this->foreignDraft->id())->save();
    $this->container->get('current_user')->setAccount($this->manager);
    $this->assertSame([(int) $draft->id()], $this->draftIds());
    $this->container->get('entity_type.manager')->getDefinition('node')->setHandlerClass('query_access', NULL);
    TemporaryQueryGuard::setModuleHandler($this->container->get('module_handler'));
    $reflection = new \ReflectionClass(CachedCountEntityResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('entityTypeManager')->setValue($resource, $this->container->get('entity_type.manager'));
    $reflection->getProperty('fieldManager')->setValue($resource, $this->container->get('entity_field.manager'));
    $filter = new Filter(new EntityConditionGroup('AND', [
      new EntityCondition('nid', (int) $draft->id()),
      new EntityCondition('field_test_reference.entity.nid', (int) $this->foreignDraft->id()),
    ]));
    $query = $reflection->getMethod('getCollectionQuery')->invoke($resource, new ResourceType('node', 'service_request', Node::class), [Filter::KEY_NAME => $filter], new CacheableMetadata());
    $this->assertSame([], $query->execute());
  }

  /**
   * Explicit jurisdiction membership never inherits parent or child scope.
   */
  public function testParentAndChildMembershipsRemainSeparate(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_parent_jurisdiction',
      'entity_type' => 'group',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_parent_jurisdiction',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Parent jurisdiction',
    ])->save();
    $this->container->get('current_user')->setAccount(User::load(1));
    $child = Group::create([
      'type' => 'jur',
      'label' => 'Child',
      'field_parent_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $child->save();
    $child_draft = $this->createRequest($child, FALSE);
    $this->container->get('current_user')->setAccount($this->manager);
    $this->assertSame([(int) $this->draft->id()], $this->draftIds());
    $this->jurisdiction->removeMember($this->manager);
    $child->addMember($this->manager, ['group_roles' => ['jur-editorial']]);
    $this->assertSame([(int) $child_draft->id()], $this->draftIds());
  }

  /**
   * Grant-only updates are bounded, repeatable and preserve content/rebuilds.
   */
  public function testExistingGrantBackfillIsBatchedAndRepeatable(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_group.install';
    $this->container->get('current_user')->setAccount(User::load(1));
    for ($index = 0; $index < 51; $index++) {
      $this->createRequest($this->jurisdiction, $index === 0);
    }
    $database = $this->container->get('database');
    $database->delete('node_access')->condition('realm', 'markaspot_jurisdiction')->execute();
    $before = $this->contentSnapshot();
    node_access_needs_rebuild(FALSE);
    $sandbox = [];
    markaspot_group_update_11952($sandbox);
    $this->assertSame(53, $sandbox['total']);
    $this->assertSame(50, $sandbox['rewritten']);
    $this->assertLessThan(1, $sandbox['#finished']);
    do {
      markaspot_group_update_11952($sandbox);
    } while ($sandbox['#finished'] < 1);
    $this->assertSame(53, $sandbox['rewritten']);
    $first_grants = $database->select('node_access', 'na')->fields('na')->orderBy('nid')->orderBy('realm')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $this->assertSame($before, $this->contentSnapshot());
    $this->assertFalse((bool) node_access_needs_rebuild());
    node_access_needs_rebuild(TRUE);
    $again = [];
    do {
      markaspot_group_update_11952($again);
    } while ($again['#finished'] < 1);
    $this->assertSame(53, $again['rewritten']);
    $this->assertSame($first_grants, $database->select('node_access', 'na')->fields('na')->orderBy('nid')->orderBy('realm')->execute()->fetchAll(\PDO::FETCH_ASSOC));
    $this->assertSame($before, $this->contentSnapshot());
    $this->assertTrue((bool) node_access_needs_rebuild());
    node_access_needs_rebuild(FALSE);
  }

  /**
   * Returns the stored realms for a node.
   */
  private function grantRealms(Node $node): array {
    return $this->container->get('database')->select('node_access', 'na')
      ->fields('na', ['realm'])->condition('nid', $node->id())->execute()->fetchCol();
  }

  /**
   * Captures content and relationship data without derived access records.
   */
  private function contentSnapshot(): array {
    $snapshot = [];
    foreach ([
      'node',
      'node_field_data',
      'node_revision',
      'node_field_revision',
      'node__field_jurisdiction',
      'group_relationship_field_data',
    ] as $table) {
      $snapshot[$table] = $this->container->get('database')->select($table, 'data')->fields('data')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    return $snapshot;
  }

  /**
   * Creates a service request with only its jurisdiction relationship.
   */
  private function createRequest(Group $jurisdiction, bool $published): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Synthetic jurisdiction request',
      'uid' => 1,
      'status' => (int) $published,
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    $node->save();
    if ($jurisdiction->getRelationshipsByEntity($node, 'group_node:service_request') === []) {
      $jurisdiction->addRelationship($node, 'group_node:service_request');
    }
    return $node;
  }

  /**
   * Returns access-checked service request draft IDs.
   *
   * @return int[]
   *   Node IDs.
   */
  private function draftIds(): array {
    return array_map('intval', array_values($this->container->get('entity_type.manager')
      ->getStorage('node')->getQuery()->accessCheck(TRUE)
      ->condition('type', 'service_request')->condition('status', 0)
      ->sort('nid')->execute()));
  }

}
