<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\jsonapi\Access\TemporaryQueryGuard;
use Drupal\jsonapi\Query\Filter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_nuxt\JsonApi\CachedCountEntityResource;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Symfony\Component\Routing\Route;

/**
 * Tests permission-gated priority filters and their independent query scope.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class PriorityFilterAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file',
    'entity', 'flexible_permissions', 'group', 'gnode', 'markaspot_group',
    'serialization', 'jsonapi', 'field_permissions', 'markaspot_nuxt',
    'image', 'media', 'taxonomy', 'options', 'datetime', 'language',
    'entity_reference_revisions', 'paragraphs', 'markaspot_validation',
    'service_request',
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
    $this->assertSame(realpath(dirname(__DIR__, 3) . '/markaspot_group.module'),
      realpath((new \ReflectionFunction('markaspot_group_jsonapi_entity_field_filter_access'))->getFileName()));
    $this->installSchema('node', ['node_access']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
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
      if (!FieldStorageConfig::loadByName('node', $field)) {
        FieldStorageConfig::create([
          'field_name' => $field,
          'entity_type' => 'node',
          'type' => 'entity_reference',
          'settings' => ['target_type' => 'group'],
        ])->save();
      }
      if (!FieldConfig::loadByName('node', 'service_request', $field)) {
        FieldConfig::create([
          'field_name' => $field,
          'entity_type' => 'node',
          'bundle' => 'service_request',
          'label' => $field,
        ])->save();
      }
    }
    FieldStorageConfig::create([
      'field_name' => 'field_status', 'entity_type' => 'node',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status', 'entity_type' => 'node', 'bundle' => 'service_request',
    ])->save();
    foreach (['field_priority', 'field_other_private'] as $field) {
      if (!FieldStorageConfig::loadByName('node', $field)) {
        FieldStorageConfig::create([
          'field_name' => $field, 'entity_type' => 'node', 'type' => 'boolean',
          'third_party_settings' => ['field_permissions' => ['permission_type' => 'custom']],
        ])->save();
      }
      if (!FieldConfig::loadByName('node', 'service_request', $field)) {
        FieldConfig::create([
          'field_name' => $field, 'entity_type' => 'node', 'bundle' => 'service_request',
        ])->save();
      }
    }
    Role::create([
      'id' => 'priority_reader', 'label' => 'Priority reader',
      'permissions' => ['view field_priority', 'view field_other_private'],
    ])->save();
    Role::load('authenticated')->grantPermission('access content')->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->manager = User::create(['name' => 'jurisdiction-manager', 'status' => 1, 'roles' => ['priority_reader']]);
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
    $this->container->get('entity_type.manager')->getDefinition('node')->setHandlerClass('query_access', NULL);
    TemporaryQueryGuard::setModuleHandler($this->container->get('module_handler'));
    drupal_static_reset('_markaspot_group_jurisdiction_group_count');
  }

  /**
   * Both frontend filters include only the allowed tenant, before paging/count.
   */
  public function testPriorityConditionsScopeRowsAndCounts(): void {
    $own = [];
    foreach ([1, 0, NULL] as $value) {
      $node = $this->createRequest($this->jurisdiction, TRUE);
      $node->set('field_priority', $value)->save();
      $own[] = (int) $node->id();
      $foreign = $this->createRequest($this->foreignDraft->get('field_jurisdiction')->entity, TRUE);
      $foreign->set('field_priority', $value)->save();
    }
    // The original drafts have no value and belong to their respective tenant.
    foreach (['1' => [$own[0]], '0' => [(int) $this->draft->id(), $own[1], $own[2]]] as $value => $expected) {
      $this->assertRowsAndCount($this->priorityFilter((string) $value), $expected);
    }
  }

  /**
   * No permission (including own-only permission) rejects every filter branch.
   */
  public function testRevokedPermissionRejectsBothFilters(): void {
    $this->parseFilter($this->priorityFilter('1'));
    Role::load('priority_reader')->revokePermission('view field_priority')
      ->grantPermission('view own field_priority')->save();
    foreach (['1', '0', 'null'] as $value) {
      try {
        $filter = $value === 'null'
          ? ['priority-null' => ['condition' => ['path' => 'field_priority', 'operator' => 'IS NULL']]]
          : $this->priorityFilter($value);
        $this->parseFilter($filter);
        $this->fail('A private priority filter must fail after permission revocation.');
      }
      catch (CacheableAccessDeniedHttpException $exception) {
        $this->assertSame(403, $exception->getStatusCode());
        $this->assertStringContainsString('field_priority', $exception->getMessage());
        $this->assertFalse($this->container->get('request_stack')->getCurrentRequest()
          ->attributes->has('_markaspot_private_organisation_filter_scope'));
      }
    }
  }

  /**
   * Permission alone, non-collection routes and private sorts stay refused.
   */
  public function testPriorityExceptionCannotWidenOtherAccess(): void {
    $outsider = User::create(['name' => 'unbound-reader', 'status' => 1, 'roles' => ['priority_reader']]);
    $outsider->save();
    $this->container->get('current_user')->setAccount($outsider);
    $this->assertPriorityDenied();
    $this->container->get('current_user')->setAccount($this->manager);
    $this->assertPriorityDenied(['_controller' => 'jsonapi.entity_resource:getIndividual']);
    foreach (['field_priority', '-field_priority', ['priority' => ['path' => 'field_priority']]] as $sort) {
      $this->collectionRequest([], $sort);
      $this->assertTrue(markaspot_group_jsonapi_entity_field_filter_access(
        FieldConfig::loadByName('node', 'service_request', 'field_priority'), $this->manager,
      )->isForbidden());
    }
    $this->collectionRequest();
    $this->assertTrue(markaspot_group_jsonapi_entity_field_filter_access(
      FieldConfig::loadByName('node', 'service_request', 'field_other_private'), $this->manager,
    )->isForbidden());
  }

  /**
   * Organisation-only readers cannot probe sibling organisations with NULL/OR.
   */
  public function testOrganisationScopeStillIntersectsPriorityFilters(): void {
    $this->container->get('current_user')->setAccount(User::load(1));
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction', 'entity_type' => 'group',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_jurisdiction', 'entity_type' => 'group', 'bundle' => 'org'])->save();
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $plugin) {
      $type = $relationships->createFromPlugin(GroupType::load('org'), $plugin);
      if (!$relationships->load($type->id())) {
        $type->save();
      }
    }
    GroupRole::create([
      'id' => 'jur-org_member', 'label' => 'Derived member',
      'group_type' => 'jur', 'scope' => 'individual',
    ])->save();
    foreach (['insider', 'outsider'] as $scope) {
      GroupRole::create([
        'id' => 'org-priority-' . $scope, 'label' => 'Published report reader',
        'group_type' => 'org', 'scope' => $scope, 'global_role' => 'authenticated',
        'permissions' => ['view group_node:service_request entity'],
      ])->save();
    }
    $worker = User::create(['name' => 'organisation-reader', 'status' => 1, 'roles' => ['priority_reader']]);
    $worker->save();
    $own_org = Group::create(['type' => 'org', 'label' => 'Roads', 'field_jurisdiction' => $this->jurisdiction->id()]);
    $own_org->save();
    $other_org = Group::create(['type' => 'org', 'label' => 'Parks', 'field_jurisdiction' => $this->jurisdiction->id()]);
    $other_org->save();
    $own_org->addMember($worker);
    $own = $this->createRequest($this->jurisdiction, TRUE);
    $own->set('field_organisation', $own_org->id())->set('field_priority', NULL)->save();
    $sibling = $this->createRequest($this->jurisdiction, TRUE);
    $sibling->set('field_organisation', $other_org->id())->set('field_priority', 0)->save();
    $this->container->get('current_user')->setAccount($worker);
    $this->assertRowsAndCount($this->priorityFilter('0'), [(int) $own->id()]);
  }

  /**
   * Existing organisation and assignee exceptions retain their permissions.
   */
  public function testOrganisationAndAssigneeExceptionsAreUnchanged(): void {
    FieldStorageConfig::loadByName('node', 'field_organisation')
      ->setThirdPartySetting('field_permissions', 'permission_type', 'custom')->save();
    if (!FieldStorageConfig::loadByName('node', 'field_assignee')) {
      FieldStorageConfig::create([
        'field_name' => 'field_assignee', 'entity_type' => 'node',
        'type' => 'entity_reference', 'settings' => ['target_type' => 'user'],
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'service_request', 'field_assignee')) {
      FieldConfig::create([
        'field_name' => 'field_assignee', 'entity_type' => 'node', 'bundle' => 'service_request',
      ])->save();
    }
    Role::load('priority_reader')->grantPermission('assign service requests')
      ->grantPermission('view field_organisation')->save();
    $this->collectionRequest();
    $organisation = markaspot_group_jsonapi_entity_field_filter_access(
      FieldConfig::loadByName('node', 'service_request', 'field_organisation'), $this->manager,
    );
    $this->assertTrue($organisation->isNeutral());
    $this->assertTrue($this->container->get('request_stack')->getCurrentRequest()
      ->attributes->has('_markaspot_private_organisation_filter_scope'));
    $this->collectionRequest();
    $assignee = markaspot_group_jsonapi_entity_field_filter_access(
      FieldConfig::loadByName('node', 'service_request', 'field_assignee'), $this->manager,
    );
    $this->assertTrue($assignee->isAllowed());
    Role::load('priority_reader')->revokePermission('assign service requests')->save();
    $this->collectionRequest();
    $this->assertTrue(markaspot_group_jsonapi_entity_field_filter_access(
      FieldConfig::loadByName('node', 'service_request', 'field_assignee'), $this->manager,
    )->isForbidden());
  }

  /**
   * Returns the frontend's exact JSON:API parameter structure.
   */
  private function priorityFilter(string $value): array {
    if ($value === '1') {
      return ['priority' => ['condition' => ['path' => 'field_priority', 'operator' => '=', 'value' => '1']]];
    }
    return [
      'priority-or' => ['group' => ['conjunction' => 'OR']],
      'priority-zero' => ['condition' => [
        'path' => 'field_priority', 'operator' => '=', 'value' => '0', 'memberOf' => 'priority-or',
      ]],
      'priority-null' => ['condition' => [
        'path' => 'field_priority', 'operator' => 'IS NULL', 'memberOf' => 'priority-or',
      ]],
    ];
  }

  /**
   * Starts a fresh collection request so a previous scope cannot mask a denial.
   */
  private function collectionRequest(array $defaults = [], mixed $sort = ''): Request {
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $request->attributes->remove('_markaspot_private_organisation_filter_scope');
    $request->attributes->set('_route_object', new Route('/jsonapi/node/service_request', $defaults + [
      '_is_jsonapi' => TRUE, 'resource_type' => 'node--service_request',
      '_controller' => 'jsonapi.entity_resource:getCollection',
    ]));
    $request->query->set('sort', $sort);
    return $request;
  }

  /**
   * Resolves the actual frontend conditions through Core's field access hooks.
   */
  private function parseFilter(array $filter): Filter {
    $request = $this->collectionRequest();
    $request->query->set('filter', $filter);
    return Filter::createFromQueryParameter($filter,
      $this->container->get('jsonapi.resource_type.repository')->get('node', 'service_request'),
      $this->container->get('jsonapi.field_resolver'));
  }

  /**
   * Verifies an unsupported scope or route keeps the existing private denial.
   */
  private function assertPriorityDenied(array $defaults = []): void {
    $this->collectionRequest($defaults);
    $access = markaspot_group_jsonapi_entity_field_filter_access(
      FieldConfig::loadByName('node', 'service_request', 'field_priority'),
      $this->container->get('current_user'),
    );
    $this->assertTrue($access->isForbidden());
    $this->assertSame('Private service request fields cannot be used as JSON:API filters across jurisdictions.', $access->getReason());
  }

  /**
   * Executes the decorated collection's row and count queries.
   */
  private function assertRowsAndCount(array $filter, array $expected): void {
    $params = [Filter::KEY_NAME => $this->parseFilter($filter)];
    $this->assertTrue($this->container->get('request_stack')->getCurrentRequest()
      ->attributes->has('_markaspot_private_organisation_filter_scope'));
    $reflection = new \ReflectionClass(CachedCountEntityResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('entityTypeManager')->setValue($resource, $this->container->get('entity_type.manager'));
    $reflection->getProperty('fieldManager')->setValue($resource, $this->container->get('entity_field.manager'));
    $type = $this->container->get('jsonapi.resource_type.repository')->get('node', 'service_request');
    $cache = new CacheableMetadata();
    $query = $reflection->getMethod('getCollectionQuery')->invoke($resource, $type, $params, $cache);
    $ids = array_map('intval', array_values($query->execute()));
    sort($ids);
    sort($expected);
    $this->assertSame($expected, $ids);
    $count = $reflection->getMethod('getCollectionCountQuery')->invoke($resource, $type, $params, new CacheableMetadata());
    $this->assertSame(count($expected), (int) $count->execute());
    $this->assertSame(0, $cache->getCacheMaxAge());
    $this->assertContains('user', $cache->getCacheContexts());
    $this->assertContains('user.permissions', $cache->getCacheContexts());
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

}
