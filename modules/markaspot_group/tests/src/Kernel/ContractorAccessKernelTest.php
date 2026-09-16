<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonapi\Query\EntityCondition;
use Drupal\jsonapi\Query\EntityConditionGroup;
use Drupal\jsonapi\Query\Filter;
use Drupal\jsonapi\Access\TemporaryQueryGuard;
use Drupal\jsonapi\JsonApiFilter;
use Drupal\markaspot_nuxt\JsonApi\OrganisationQueryGuard;
use Drupal\markaspot_nuxt\JsonApi\CachedCountEntityResource;
use Drupal\markaspot_nuxt\JsonApi\CountCacheQueryWrapper;
use Drupal\markaspot_nuxt\JsonApi\DeferredAccessQueryWrapper;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\markaspot_group\Controller\RequestResponsibilityController;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__, 4) . '/markaspot_nuxt/src/JsonApi/OrganisationQueryGuard.php';

/**
 * Tests organisation scope and personal-data shielding for contractors.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class ContractorAccessKernelTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'text',
    'filter',
    'file',
    'image',
    'media',
    'entity_reference_revisions',
    'paragraphs',
    'taxonomy',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'serialization',
    'jsonapi',
  ];

  /**
   * Contractor account under test.
   */
  private UserInterface $contractor;

  /**
   * Moderator account under test.
   */
  private UserInterface $moderator;

  /**
   * Request routed to the contractor's organisation.
   */
  private Node $organisationARequest;

  /**
   * Request routed to a different organisation.
   */
  private Node $organisationBRequest;

  /**
   * Root jurisdiction shared by the test organisations.
   */
  private Group $jurisdiction;

  /**
   * Organisation assigned to the contractor.
   */
  private Group $organisationA;

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
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service Request',
    ])->save();
    ParagraphsType::create([
      'id' => 'status',
      'label' => 'Status note',
    ])->save();
    ParagraphsType::create([
      'id' => 'internal_remark',
      'label' => 'Internal remark',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Service status',
    ])->save();
    $this->createMediaType('image', [
      'id' => 'request_image',
      'label' => 'Request image',
    ]);
    GroupType::create([
      'id' => 'jur',
      'label' => 'Jurisdiction',
    ])->save();
    GroupType::create([
      'id' => 'org',
      'label' => 'Organisation',
    ])->save();
    GroupRole::create([
      'id' => 'jur-org_member',
      'label' => 'Organisation member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    $this->ensureRelationshipType('jur', 'group_membership');
    $this->ensureRelationshipType('jur', 'group_node:service_request');
    $this->ensureRelationshipType('org', 'group_membership');
    $this->ensureRelationshipType('org', 'group_node:service_request');

    $this->createServiceRequestFields();
    $this->createDrupalRole('contractor');
    $this->createDrupalRole('moderator');
    $contractor_role = Role::load('contractor');
    if ($contractor_role === NULL) {
      throw new \LogicException('Contractor role fixture was not created.');
    }
    $contractor_role
      ->grantPermission('delete own request_image media')
      ->grantPermission('view own unpublished media')
      ->save();
    $this->createGroupRole('org-contractor');
    $this->createGroupRole('org-insider');

    User::create([
      'uid' => 1,
      'name' => 'root',
      'status' => 1,
    ])->save();
    $this->contractor = $this->createAccount('external-contractor', 'contractor');
    $this->moderator = $this->createAccount('internal-moderator', 'moderator');

    $this->jurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Jurisdiction',
    ]);
    $this->jurisdiction->save();
    $this->organisationA = Group::create([
      'type' => 'org',
      'label' => 'Organisation A',
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $this->organisationA->save();
    $organisationB = Group::create([
      'type' => 'org',
      'label' => 'Organisation B',
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $organisationB->save();

    $this->organisationA->addMember($this->contractor);
    $this->organisationA->addMember($this->moderator);

    $this->organisationARequest = $this->createRequest(
      'Request A',
      $this->organisationA,
      'reporter-a@example.test',
      FALSE,
    );
    $this->organisationBRequest = $this->createRequest(
      'Request B',
      $organisationB,
      'reporter-b@example.test',
      TRUE,
    );
  }

  /**
   * Contractors can manage their unpublished request but cannot view PII.
   */
  public function testContractorOwnOrganisationAccessShieldsReporterEmail(): void {
    $this->container->get('current_user')->setAccount($this->contractor);
    $this->assertFalse($this->contractor->hasPermission('edit any service_request content'));
    $this->assertFalse($this->contractor->hasPermission('view all revisions'));
    $this->assertFalse($this->contractor->hasPermission('manage dashboard notes'));
    $this->assertFalse($this->contractor->hasPermission('access open311 advanced properties'));
    $this->assertFalse($this->contractor->hasPermission('access open311 full export'));
    $this->assertFalse($this->contractor->hasPermission('access markaspot statistics'));
    $this->assertNotContains(
      'create group_node:service_request entity',
      GroupRole::load('org-contractor')?->getPermissions() ?? [],
    );
    $this->assertFalse(
      $this->container
        ->get('entity_type.manager')
        ->getAccessControlHandler('node')
        ->createAccess('service_request', $this->contractor),
    );
    $view_access = $this->organisationARequest->access('view', $this->contractor, TRUE);
    $this->assertTrue($view_access->isAllowed());
    $this->assertTrue($this->organisationARequest->access('update', $this->contractor));
    $this->assertTrue(
      $this->organisationARequest->get('field_status')->access('edit', $this->contractor),
    );
    $this->assertFalse(
      $this->organisationARequest->get('title')->access('edit', $this->contractor),
    );
    $this->assertFalse(
      $this->organisationARequest->get('field_e_mail')->access('view', $this->contractor),
    );
  }

  /**
   * Contractors cannot bypass field access through the responsibility API.
   */
  public function testContractorCannotReassignResponsibilityThroughController(): void {
    $this->container->get('current_user')->setAccount($this->contractor);
    $target_organisation = $this->organisationBRequest->get('field_organisation')->entity;
    $this->assertInstanceOf(Group::class, $target_organisation);
    $this->assertTrue($this->organisationARequest->access('update', $this->contractor));
    $this->assertFalse($this->organisationARequest->get('field_organisation')->access('edit', $this->contractor));

    $controller = RequestResponsibilityController::create($this->container);
    $http_request = Request::create(
      '/api/request-responsibility/' . $this->organisationARequest->id(),
      'PATCH',
      content: json_encode([
        'organisation_uuids' => [$target_organisation->uuid()],
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->update($this->organisationARequest, $http_request);

    $this->assertSame(403, $response->getStatusCode());
    $this->container->get('entity_type.manager')->getStorage('node')
      ->resetCache([$this->organisationARequest->id()]);
    $reloaded = Node::load($this->organisationARequest->id());
    $this->assertInstanceOf(Node::class, $reloaded);
    $this->assertSame(
      [(int) $this->organisationA->id()],
      array_map(
        'intval',
        array_column($reloaded->get('field_organisation')->getValue(), 'target_id'),
      ),
    );
  }

  /**
   * Enabling the dashboard later restores its contractor permission.
   */
  public function testDashboardEnableBackfillsContractorNotePermission(): void {
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $permissions['add dashboard status notes'] = [
      'title' => 'Add Dashboard Status Notes',
      'description' => NULL,
      'restrict access' => TRUE,
      'provider' => 'markaspot_dashboard',
    ];
    $permission_handler = $this->createMock(PermissionHandlerInterface::class);
    $permission_handler->method('getPermissions')->willReturn($permissions);
    $this->container->set('user.permissions', $permission_handler);

    $role = Role::load('contractor');
    $this->assertNotNull($role);
    $role->revokePermission('add dashboard status notes')->save();

    require_once dirname(__DIR__, 5) . '/modules/markaspot_dashboard/markaspot_dashboard.install';
    $this->assertTrue(_markaspot_dashboard_grant_contractor_status_note_permission());

    $this->container->get('entity_type.manager')
      ->getStorage('user_role')
      ->resetCache(['contractor']);
    $role = Role::load('contractor');
    $this->assertNotNull($role);
    $this->assertTrue($role->hasPermission('add dashboard status notes'));
    $this->assertFalse(_markaspot_dashboard_grant_contractor_status_note_permission());
  }

  /**
   * Moderators retain reporter email access.
   */
  public function testModeratorCanViewReporterEmail(): void {
    $this->container->get('current_user')->setAccount($this->moderator);
    $view_access = $this->organisationARequest->access('view', $this->moderator, TRUE);
    $this->assertTrue($view_access->isAllowed());
    $this->assertTrue(
      $this->organisationARequest->get('field_e_mail')->access('view', $this->moderator),
    );
    $this->assertSame(
      'reporter-a@example.test',
      $this->organisationARequest->get('field_e_mail')->value,
    );
  }

  /**
   * Organisation read permission also applies to access-checked queries.
   */
  public function testModeratorCanQueryOwnUnpublishedRequest(): void {
    $this->organisationBRequest->setUnpublished()->save();
    $this->container->get('current_user')->setAccount($this->moderator);
    $this->assertFalse($this->moderator->hasPermission('bypass node access'));
    $this->assertTrue($this->organisationARequest->access('view', $this->moderator));
    $this->assertTrue($this->organisationA->hasPermission('view unpublished group_node:service_request entity', $this->moderator));
    $this->assertSame(
      [(int) $this->organisationARequest->id()],
      $this->queryUnpublishedRequests(),
    );
    // Read grants must not introduce new update or delete grants for staff.
    $this->assertArrayNotHasKey('markaspot_contractor_organisation', markaspot_group_node_grants($this->moderator, 'update'));
    $this->assertArrayNotHasKey('markaspot_contractor_organisation', markaspot_group_node_grants($this->moderator, 'delete'));
  }

  /**
   * Own-unpublished permission alone must not grant other authors' drafts.
   */
  public function testMembershipWithoutUnpublishedPermissionGetsNoReadGrant(): void {
    $role = GroupRole::load('org-insider');
    $this->assertNotNull($role);
    $role->revokePermission('view unpublished group_node:service_request entity')->save();
    $this->container->get('current_user')->setAccount($this->moderator);
    $this->assertTrue($this->organisationA->hasPermission('view own unpublished group_node:service_request entity', $this->moderator));
    $this->assertFalse($this->organisationA->hasPermission('view unpublished group_node:service_request entity', $this->moderator));
    $this->assertArrayNotHasKey('markaspot_contractor_organisation', markaspot_group_node_grants($this->moderator, 'view'));
    $this->assertSame([], $this->queryUnpublishedRequests());
  }

  /**
   * Removing membership revokes the core grant as well as query access.
   */
  public function testRemovedMembershipRevokesUnpublishedReadGrant(): void {
    $this->container->get('current_user')->setAccount($this->moderator);
    $this->assertSame([(int) $this->organisationARequest->id()], $this->queryUnpublishedRequests());
    $this->organisationA->removeMember($this->moderator);
    $this->assertArrayNotHasKey('markaspot_contractor_organisation', markaspot_group_node_grants($this->moderator, 'view'));
    $this->assertSame([], $this->queryUnpublishedRequests());
  }

  /**
   * Unauthenticated callers receive no unpublished organisation records.
   */
  public function testAnonymousCannotQueryUnpublishedRequests(): void {
    $this->container->get('current_user')->setAccount(User::load(0));
    $this->assertSame([], $this->queryUnpublishedRequests());
  }

  /**
   * Runs the real entity query used by report collections.
   */
  private function queryUnpublishedRequests(): array {
    return array_values(array_map('intval', $this->container
      ->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('type', 'service_request')
      ->condition('status', 0)->sort('nid')->execute()));
  }

  /**
   * JSON:API subsets include permitted drafts and exclude foreign drafts.
   */
  public function testJsonApiOrganisationFilterSubsets(): void {
    $this->organisationBRequest->setUnpublished()->save();
    $this->container->get('current_user')->setAccount($this->moderator);
    $ids = [(int) $this->organisationARequest->id(), (int) $this->organisationBRequest->id()];
    $query = $this->createGuardedQuery('nid', $ids);
    $this->assertSame([(int) $this->organisationARequest->id()], array_map('intval', array_values($query->execute())));
    $this->assertSame(1, (int) $this->createGuardedQuery('nid', $ids)->count()->execute());

    $role = GroupRole::load('org-insider');
    $role->revokePermission('view unpublished group_node:service_request entity')->save();
    $this->assertSame([], $this->createGuardedQuery('nid', [(int) $this->organisationARequest->id()])->execute());
  }

  /**
   * Traversed node filters cannot reveal a foreign unpublished reference.
   */
  public function testJsonApiReferencedNodeSubsets(): void {
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
    ])->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->organisationARequest = $storage->loadUnchanged($this->organisationARequest->id());
    $this->organisationBRequest = $storage->loadUnchanged($this->organisationBRequest->id());
    $this->organisationBRequest->setUnpublished()->save();
    $this->organisationARequest->set('field_test_reference', $this->organisationBRequest->id())->save();
    $this->container->get('current_user')->setAccount($this->moderator);
    $this->assertSame([], $this->createGuardedQuery('field_test_reference.entity.nid', [(int) $this->organisationBRequest->id()])->execute());

    $this->organisationARequest->set('field_test_reference', $this->organisationARequest->id())->save();
    $this->assertSame([], $this->createGuardedQuery('field_test_reference.entity.nid', [(int) $this->organisationARequest->id()])->execute());
    $this->organisationARequest->setPublished()->save();
    $this->assertSame([(int) $this->organisationARequest->id()], array_map('intval', array_values($this->createGuardedQuery('field_test_reference.entity.nid', [(int) $this->organisationARequest->id()])->execute())));
  }

  /**
   * Other unpublished node bundles never enter the organisation subset.
   */
  public function testJsonApiOtherNodeBundlesKeepCoreFilterGuard(): void {
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $page = Node::create(['type' => 'page', 'title' => 'Private page', 'uid' => $this->contractor->id(), 'status' => 0]);
    $page->save();
    $this->container->get('current_user')->setAccount($this->moderator);
    $this->assertSame([], $this->createGuardedQuery('nid', [(int) $page->id()])->execute());
    $this->organisationA->removeMember($this->moderator);
    $this->assertSame([], $this->createGuardedQuery('nid', [(int) $this->organisationARequest->id()])->execute());
    $this->container->get('current_user')->setAccount(User::load(0));
    $this->assertSame([], $this->createGuardedQuery('nid', [(int) $this->organisationARequest->id()])->execute());
  }

  /**
   * Contractors retain scoped draft access while PII remains shielded.
   */
  public function testJsonApiContractorDraftScope(): void {
    $this->organisationBRequest->setUnpublished()->save();
    $this->container->get('current_user')->setAccount($this->contractor);
    $ids = [(int) $this->organisationARequest->id(), (int) $this->organisationBRequest->id()];
    $this->assertSame([(int) $this->organisationARequest->id()], array_map('intval', array_values($this->createGuardedQuery('nid', $ids)->execute())));
    $this->assertFalse($this->organisationARequest->get('field_e_mail')->access('view', $this->contractor));
  }

  /**
   * Organisation grants cannot override Core's mandatory base permission.
   */
  public function testJsonApiRequiresAccessContent(): void {
    Role::load('moderator')->revokePermission('access content')->save();
    $this->container->get('current_user')->setAccount($this->moderator);
    $this->assertFalse($this->moderator->hasPermission('access content'));
    $this->assertSame([], $this->createGuardedQuery('nid', [(int) $this->organisationARequest->id()])->execute());
  }

  /**
   * Accounts without organisation grants preserve Core's cacheability.
   */
  public function testJsonApiNoOrganisationPreservesCoreGuard(): void {
    $this->useCoreNodeFilterSubsets();
    $this->organisationA->removeMember($this->moderator);
    OrganisationQueryGuard::setModuleHandler($this->container->get('module_handler'));
    $entity_type = $this->container->get('entity_type.manager')->getDefinition('node');
    $core_cache = new CacheableMetadata();
    $org_cache = new CacheableMetadata();
    $method = new \ReflectionMethod(TemporaryQueryGuard::class, 'getAccessConditionForKnownSubsets');
    $core = $method->invoke(NULL, $entity_type, $this->moderator, $core_cache);
    $method = new \ReflectionMethod(OrganisationQueryGuard::class, 'getAccessConditionForKnownSubsets');
    $scoped = $method->invoke(NULL, $entity_type, $this->moderator, $org_cache);
    $this->assertEquals($core, $scoped);
    $this->assertSame($core_cache->getCacheMaxAge(), $org_cache->getCacheMaxAge());
  }

  /**
   * Explicit filter vetoes from other modules remain authoritative.
   */
  public function testJsonApiPreservesExplicitModuleVeto(): void {
    $this->useCoreNodeFilterSubsets();
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('invokeAllWith')->willReturnCallback(static function ($hook, $callback): void {
      $callback(static fn() => [JsonApiFilter::AMONG_ALL => AccessResult::forbidden()], 'test_veto');
    });
    OrganisationQueryGuard::setModuleHandler($handler);
    $cache = new CacheableMetadata();
    $method = new \ReflectionMethod(OrganisationQueryGuard::class, 'getAccessConditionForKnownSubsets');
    $condition = $method->invoke(NULL, $this->container->get('entity_type.manager')->getDefinition('node'), $this->moderator, $cache);
    $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE);
    $filter = new Filter(new EntityConditionGroup('AND', [$condition]));
    $query->condition($filter->queryCondition($query));
    $this->assertSame([], $query->execute());
  }

  /**
   * The real resource decorator wires filter, count and pass-through paths.
   */
  public function testJsonApiResourceDecoratorIntegration(): void {
    $this->useCoreNodeFilterSubsets();
    $this->organisationBRequest->setUnpublished()->save();
    $this->container->get('current_user')->setAccount($this->moderator);
    // Only the two dependencies used by the query builder are needed here.
    $reflection = new \ReflectionClass(CachedCountEntityResource::class);
    $resource = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('entityTypeManager')->setValue($resource, $this->container->get('entity_type.manager'));
    $reflection->getProperty('fieldManager')->setValue($resource, $this->container->get('entity_field.manager'));
    $type = new ResourceType('node', 'service_request', Node::class);
    $filter = new Filter(new EntityConditionGroup('AND', [new EntityCondition('nid', (int) $this->organisationARequest->id())]));
    $params = [Filter::KEY_NAME => $filter];
    $cache = new CacheableMetadata();
    $method = $reflection->getMethod('getCollectionQuery');
    $query = $method->invoke($resource, $type, $params, $cache);
    $this->assertInstanceOf(DeferredAccessQueryWrapper::class, $query);
    $this->assertSame([(int) $this->organisationARequest->id()], array_map('intval', array_values($query->execute())));
    $this->assertSame(0, $cache->getCacheMaxAge());

    $count_cache = new CacheableMetadata();
    $count = $reflection->getMethod('getCollectionCountQuery')->invoke($resource, $type, $params, $count_cache);
    $this->assertNotInstanceOf(CountCacheQueryWrapper::class, $count);
    $this->assertSame(1, (int) $count->execute());

    // The row request must skip expensive totals even when organisation
    // access makes the query uncacheable. The separate exact count above
    // must continue to execute with the same access scope.
    $request_stack = $this->container->get('request_stack');
    foreach (['skipCount', 'skip-count'] as $parameter) {
      $request_stack->push(Request::create('/jsonapi/node/service_request', 'GET', [$parameter => '1']));
      try {
        $skipped_cache = new CacheableMetadata();
        $skipped = $reflection->getMethod('getCollectionCountQuery')->invoke($resource, $type, $params, $skipped_cache);
        $this->assertInstanceOf(CountCacheQueryWrapper::class, $skipped);
        $this->assertSame(CountCacheQueryWrapper::SKIPPED_COUNT_SENTINEL, $skipped->execute());
        $this->assertSame(0, $skipped_cache->getCacheMaxAge());
      }
      finally {
        $request_stack->pop();
      }
    }

    $no_filter = $method->invoke($resource, $type, [], new CacheableMetadata());
    $this->assertSame([(int) $this->organisationARequest->id()], array_map('intval', array_values($no_filter->execute())));

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $page = Node::create(['type' => 'page', 'title' => 'Private page', 'uid' => $this->contractor->id(), 'status' => 0]);
    $page->save();
    $page_type = new ResourceType('node', 'page', Node::class);
    $page_filter = new Filter(new EntityConditionGroup('AND', [new EntityCondition('nid', (int) $page->id())]));
    $query = $method->invoke($resource, $page_type, [Filter::KEY_NAME => $page_filter], new CacheableMetadata());
    $this->assertNotInstanceOf(DeferredAccessQueryWrapper::class, $query);
    $this->assertSame([], $query->execute());
  }

  /**
   * Exercises Core's published/own guard instead of Entity's fixture bypass.
   *
   * The minimal fixture has no Entity query-access events. Its empty handler
   * consequently advertises AMONG_ALL, unlike the scoped runtime. Removing only
   * that fixture handler retains the real node_access grants and SQL checks.
   */
  private function useCoreNodeFilterSubsets(): void {
    $entity_type = $this->container->get('entity_type.manager')->getDefinition('node');
    $entity_type->setHandlerClass('query_access', NULL);
    TemporaryQueryGuard::setModuleHandler($this->container->get('module_handler'));
    $method = new \ReflectionMethod(TemporaryQueryGuard::class, 'getAccessConditionForKnownSubsets');
    $this->assertNotNull($method->invoke(NULL, $entity_type, $this->moderator, new CacheableMetadata()));
  }

  /**
   * Builds the real access-checked query with JSON:API's recursive guard.
   */
  private function createGuardedQuery(string $field, array $ids): QueryInterface {
    $this->useCoreNodeFilterSubsets();
    $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE)->sort('nid');
    $filter = new Filter(new EntityConditionGroup('AND', [new EntityCondition($field, $ids, 'IN')]));
    $query->condition($filter->queryCondition($query));
    $cacheability = new CacheableMetadata();
    OrganisationQueryGuard::setFieldManager($this->container->get('entity_field.manager'));
    OrganisationQueryGuard::setModuleHandler($this->container->get('module_handler'));
    OrganisationQueryGuard::applyAccessControls($filter, $query, $cacheability);
    return $query;
  }

  /**
   * Contractors cannot access or query another organisation's request.
   */
  public function testContractorCannotAccessForeignOrganisationRequest(): void {
    $this->container->get('current_user')->setAccount($this->contractor);
    $this->assertFalse($this->organisationBRequest->access('view', $this->contractor));
    $this->assertFalse($this->organisationBRequest->access('update', $this->contractor));

    // A Views-style self-join must scope every alias using the query account,
    // even when the active account itself has the site bypass.
    $root = User::load(1);
    $this->assertInstanceOf(UserInterface::class, $root);
    $this->container->get('current_user')->setAccount($root);
    $query = $this->container->get('database')
      ->select('node_field_data', 'in_scope_request');
    $foreign_alias = $query->join(
      'node_field_data',
      'foreign_request',
      '%alias.nid = :foreign_request_nid',
      [':foreign_request_nid' => $this->organisationBRequest->id()],
    );
    $query->addField($foreign_alias, 'nid');
    $query->condition(
      'in_scope_request.nid',
      $this->organisationARequest->id(),
    );
    $query->addTag('node_access')
      ->addMetaData('base_table', 'node_field_data')
      ->addMetaData('account', $this->contractor);
    $this->assertSame([], $query->execute()->fetchCol());
    $this->container->get('current_user')->setAccount($this->contractor);

    // Simulate recoverable Group mirror drift. The authoritative routing field
    // still points to organisation B, so a stale A relationship must not leak
    // the request into contractor entity queries or Open311 result lists.
    $this->organisationA->addRelationship(
      $this->organisationBRequest,
      'group_node:service_request',
    );

    $node_ids = $this->container
      ->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'service_request')
      ->sort('nid')
      ->execute();

    $this->assertSame(
      [(int) $this->organisationARequest->id()],
      array_values(array_map('intval', $node_ids)),
    );
  }

  /**
   * Contractors cannot bypass the restricted note endpoint via JSON:API.
   */
  public function testContractorCannotWriteStatusParagraphsDirectly(): void {
    $paragraph = Paragraph::create(['type' => 'status']);
    $paragraph->save();

    $this->assertFalse($paragraph->access('update', $this->contractor));
    $this->assertFalse($paragraph->access('delete', $this->contractor));
    $this->assertFalse(
      $this->container
        ->get('entity_type.manager')
        ->getAccessControlHandler('paragraph')
        ->createAccess('status', $this->contractor),
    );
  }

  /**
   * Contractors cannot read or mutate internal staff remarks.
   */
  public function testContractorCannotAccessInternalRemarksDirectly(): void {
    $paragraph = Paragraph::create(['type' => 'internal_remark']);
    $paragraph->save();

    $this->assertFalse($paragraph->access('view', $this->contractor));
    $this->assertFalse($paragraph->access('update', $this->contractor));
    $this->assertFalse($paragraph->access('delete', $this->contractor));
    $this->assertFalse(
      $this->container
        ->get('entity_type.manager')
        ->getAccessControlHandler('paragraph')
        ->createAccess('internal_remark', $this->contractor),
    );
  }

  /**
   * Contractors can attach only request images that they own.
   */
  public function testContractorCanOnlyAttachOwnCompletionMedia(): void {
    $own_media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Own completion image',
      'uid' => $this->contractor->id(),
      'status' => FALSE,
    ]);
    $own_media->save();
    $foreign_media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Reporter evidence',
      'uid' => $this->moderator->id(),
      'status' => FALSE,
    ]);
    $foreign_media->save();

    $this->container->get('current_user')->setAccount($this->contractor);
    $this->organisationARequest->set('field_request_media', [$own_media->id()]);
    $this->organisationARequest->save();
    $this->assertSame(
      [(int) $own_media->id()],
      array_map(
        'intval',
        array_column(
          $this->organisationARequest->get('field_request_media')->getValue(),
          'target_id',
        ),
      ),
    );

    $this->organisationARequest->set('field_request_media', [
      $own_media->id(),
      $foreign_media->id(),
    ]);
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Contractors may only add or remove their own completion media.');
    $this->organisationARequest->save();
  }

  /**
   * Contractors cannot delete owned media from a foreign request.
   */
  public function testContractorCannotDeleteOwnedMediaFromForeignRequest(): void {
    $media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Reassigned completion image',
      'uid' => $this->contractor->id(),
      'status' => FALSE,
    ]);
    $media->save();

    $this->container->get('current_user')->setAccount(User::load(1));
    $this->organisationBRequest->set('field_request_media', [$media->id()]);
    $this->organisationBRequest->save();

    $this->assertFalse($media->access('delete', $this->contractor));
  }

  /**
   * Contractors cannot assign a status from another jurisdiction catalog.
   */
  public function testContractorCannotAssignForeignJurisdictionStatus(): void {
    $foreign_jurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Foreign jurisdiction',
    ]);
    $foreign_jurisdiction->save();
    $foreign_status = Term::create([
      'vid' => 'service_status',
      'name' => 'Foreign status',
      'field_jurisdiction' => $foreign_jurisdiction->id(),
    ]);
    $foreign_status->save();

    $this->container->get('current_user')->setAccount($this->contractor);
    $this->organisationARequest->set('field_status', $foreign_status->id());
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('selected status is outside');
    $this->organisationARequest->save();
  }

  /**
   * Contractors cannot clear an existing service request status.
   */
  public function testContractorCannotClearExistingStatus(): void {
    $status = Term::create([
      'vid' => 'service_status',
      'name' => 'Assigned',
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $status->save();

    $this->container->get('current_user')->setAccount(User::load(1));
    $this->organisationARequest->set('field_status', $status->id());
    $this->organisationARequest->save();

    $node_storage = $this->container->get('entity_type.manager')
      ->getStorage('node');
    $node_storage->resetCache([$this->organisationARequest->id()]);
    $request = $node_storage->load($this->organisationARequest->id());
    $this->assertInstanceOf(Node::class, $request);

    $this->container->get('current_user')->setAccount($this->contractor);
    $request->set('field_status', NULL);
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Contractors may not clear');
    $request->save();
  }

  /**
   * Contractors can save when an existing empty status remains unchanged.
   */
  public function testContractorCanSaveUnchangedEmptyStatus(): void {
    $node_storage = $this->container->get('entity_type.manager')
      ->getStorage('node');
    $node_storage->resetCache([$this->organisationARequest->id()]);
    $request = $node_storage->load($this->organisationARequest->id());
    $this->assertInstanceOf(Node::class, $request);
    $this->assertTrue($request->get('field_status')->isEmpty());

    $this->container->get('current_user')->setAccount($this->contractor);
    $request->save();

    $this->assertTrue($request->get('field_status')->isEmpty());
  }

  /**
   * Creates field storage used by the access checks.
   */
  private function createServiceRequestFields(): void {
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

    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'group',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'group',
      'bundle' => 'org',
      'label' => 'Jurisdiction',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'taxonomy_term',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'service_status',
      'label' => 'Jurisdiction',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Jurisdiction',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_organisation',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_organisation',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Organisation',
      'settings' => [
        'handler' => 'default:group',
        'handler_settings' => [
          'target_bundles' => ['org' => 'org'],
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_e_mail',
      'entity_type' => 'node',
      'type' => 'email',
      'third_party_settings' => [
        'field_permissions' => [
          'permission_type' => 'custom',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_e_mail',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Reporter email',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_request_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_request_media',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Request media',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => ['request_image' => 'request_image'],
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'third_party_settings' => [
        'field_permissions' => [
          'permission_type' => 'custom',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Status',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['service_status' => 'service_status'],
        ],
      ],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Creates a Drupal role from its canonical shipped config.
   */
  private function createDrupalRole(string $role_id): void {
    $profile_root = dirname(__DIR__, 5);
    $path = $role_id === 'contractor'
      ? $profile_root . '/config/optional/user.role.contractor.yml'
      : $profile_root . '/modules/service_request/config/install/user.role.' . $role_id . '.yml';
    $config = Yaml::decode(file_get_contents(
      $path,
    ));
    Role::create([
      'id' => $config['id'],
      'label' => $config['label'],
      'weight' => $config['weight'],
      'is_admin' => $config['is_admin'],
      'permissions' => $config['permissions'],
    ])->save();
  }

  /**
   * Creates a synchronized group role from shipped config.
   */
  private function createGroupRole(string $role_id): void {
    $module_root = dirname(__DIR__, 3);
    // org-contractor ships in config/optional (it depends on the
    // hook-created user.role.contractor); the other roles in config/install.
    $path = $module_root . '/config/install/group.role.' . $role_id . '.yml';
    if (!file_exists($path)) {
      $path = $module_root . '/config/optional/group.role.' . $role_id . '.yml';
    }
    $config = Yaml::decode(file_get_contents($path));
    GroupRole::create([
      'id' => $config['id'],
      'label' => $config['label'],
      'weight' => $config['weight'],
      'admin' => $config['admin'],
      'scope' => $config['scope'],
      'global_role' => $config['global_role'],
      'group_type' => $config['group_type'],
      'permissions' => $config['permissions'],
    ])->save();
  }

  /**
   * Creates an active account with one staff role.
   */
  private function createAccount(string $name, string $role_id): UserInterface {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@example.test',
      'status' => 1,
      'roles' => [$role_id],
    ]);
    $account->save();
    return $account;
  }

  /**
   * Creates a related service request.
   */
  private function createRequest(
    string $title,
    Group $organisation,
    string $email,
    bool $published,
  ): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => $title,
      'status' => $published,
      'uid' => 1,
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_organisation' => [$organisation->id()],
      'field_e_mail' => $email,
    ]);
    $node->save();

    $relationship_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('group_relationship');
    $relationships = $relationship_storage->loadByProperties([
      'gid' => $organisation->id(),
      'entity_id' => $node->id(),
      'plugin_id' => 'group_node:service_request',
    ]);
    if ($relationships === []) {
      $organisation->addRelationship($node, 'group_node:service_request');
    }

    return $node;
  }

  /**
   * Ensures a group relationship type exists for a plugin.
   */
  private function ensureRelationshipType(string $group_type, string $plugin_id): void {
    $id = $group_type . '-' . str_replace(':', '-', $plugin_id);
    $storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('group_relationship_type');
    if ($storage->load($id)) {
      return;
    }
    $storage->createFromPlugin(GroupType::load($group_type), $plugin_id)->save();
  }

}
