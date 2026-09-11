<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\markaspot_group\Access\FormOnlyReportRouteAccessCheck;
use Drupal\markaspot_group\Access\FormOnlyGlobalReportAccessCheck;
use Symfony\Component\Routing\Route;
use Drupal\markaspot_group\Service\FormOnlyReportQueryScope;
use Drupal\markaspot_open311\Controller\GeoreportStatsController;
use Drupal\markaspot_group\EventSubscriber\FormOnlySubmissionReceiptSubscriber;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityService;
use Drupal\markaspot_group\WorkspaceVisibilityNodeAccessControlHandler;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

require_once dirname(__DIR__, 3) . '/src/Service/JurisdictionHierarchyResolverInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceVisibilityInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceVisibilityService.php';
require_once dirname(__DIR__, 3) . '/src/Service/FormOnlyReportQueryScope.php';
require_once dirname(__DIR__, 3) . '/src/WorkspaceVisibilityNodeAccessControlHandler.php';
require_once dirname(__DIR__, 3) . '/src/EventSubscriber/FormOnlySubmissionReceiptSubscriber.php';
require_once dirname(__DIR__, 3) . '/src/Access/FormOnlyReportRouteAccessCheck.php';
require_once dirname(__DIR__, 3) . '/src/Access/FormOnlyGlobalReportAccessCheck.php';
require_once dirname(__DIR__, 3) . '/markaspot_group.module';
require_once dirname(__DIR__, 3) . '/src/Trait/JurisdictionIdResolverTrait.php';
require_once dirname(__DIR__, 4) . '/markaspot_open311/src/Traits/LanguageNegotiationTrait.php';
require_once dirname(__DIR__, 4) . '/markaspot_open311/src/Controller/GeoreportStatsController.php';

/**
 * Tests workspace visibility on access-checked node entity queries.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class WorkspaceVisibilityQueryKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'node',
    'taxonomy',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
  ];

  /**
   * Plain authenticated account.
   */
  private UserInterface $authenticatedAccount;

  /**
   * Site administrator account.
   */
  private UserInterface $administratorAccount;

  /**
   * Non-administrator account with core's node access bypass permission.
   */
  private UserInterface $nodeAccessBypassAccount;

  /**
   * Staff accounts keyed by elevated jurisdiction role ID.
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  private array $staffAccounts = [];

  /**
   * Request IDs keyed by visibility mode.
   *
   * @var array<string, int>
   */
  private array $requestIds = [];

  /**
   * Request assigned to public and foreign blocked jurisdictions.
   */
  private int $mixedRequestId;

  /**
   * Category assigned to the blocked jurisdiction.
   */
  private int $blockedCategoryId;

  /**
   * Unrelated page node ID.
   */
  private int $pageId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system',
      'user',
      'field',
      'node',
      'taxonomy',
      'group',
    ]);

    User::create([
      'uid' => 0,
      'name' => '',
      'status' => 0,
    ])->save();
    $root = User::create([
      'uid' => 1,
      'name' => 'root',
      'status' => 1,
    ]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);

    Role::create([
      'id' => 'administrator',
      'label' => 'Administrator',
      'is_admin' => TRUE,
    ])->save();
    Role::create([
      'id' => 'node_access_bypass',
      'label' => 'Node access bypass',
      'permissions' => [
        'access content',
        'bypass node access',
      ],
    ])->save();
    $this->authenticatedAccount = $this->createAccount('authenticated-reader');
    $this->administratorAccount = $this->createAccount(
      'site-administrator',
      ['administrator'],
    );
    $this->nodeAccessBypassAccount = $this->createAccount(
      'node-access-bypass',
      ['node_access_bypass'],
    );

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_category',
      'name' => 'Service category',
    ])->save();
    GroupType::create([
      'id' => 'jur',
      'label' => 'Jurisdiction',
    ])->save();
    $this->ensureMembershipType();

    $elevated_roles = [
      'jur-admin',
      'jur-editorial',
      'jur-moderator',
      'jur-tenant_admin',
    ];
    foreach ($elevated_roles as $role_id) {
      GroupRole::create([
        'id' => $role_id,
        'label' => $role_id,
        'group_type' => 'jur',
        'scope' => 'individual',
      ])->save();
      $this->staffAccounts[$role_id] = $this->createAccount($role_id);
    }

    $this->createVisibilityField();
    $this->createJurisdictionField();
    $this->createCategoryFields();

    $jurisdictions = [];
    foreach ([
      'public',
      'submission_only',
      'form_only',
      'authenticated',
      'blocked',
    ] as $visibility) {
      $jurisdictions[$visibility] = $this->createJurisdiction(
        ucfirst(str_replace('_', ' ', $visibility)),
        $visibility,
      );
      foreach ($this->staffAccounts as $role_id => $account) {
        $jurisdictions[$visibility]->addMember($account, [
          'group_roles' => [$role_id],
        ]);
      }
    }
    $foreign_blocked = $this->createJurisdiction(
      'Foreign blocked',
      'blocked',
    );
    $blocked_category = Term::create([
      'vid' => 'service_category',
      'name' => 'Blocked category',
      'field_jurisdiction' => [
        'target_id' => (int) $jurisdictions['blocked']->id(),
      ],
    ]);
    $blocked_category->save();
    $this->blockedCategoryId = (int) $blocked_category->id();

    foreach ($jurisdictions as $visibility => $jurisdiction) {
      $this->requestIds[$visibility] = $this->createRequest(
        ucfirst(str_replace('_', ' ', $visibility)) . ' request',
        [(int) $jurisdiction->id()],
      );
    }
    $this->mixedRequestId = $this->createRequest('Mixed request', [
      (int) $jurisdictions['public']->id(),
      (int) $foreign_blocked->id(),
    ]);
    $page = Node::create([
      'type' => 'page',
      'title' => 'Unrelated page',
      'status' => 1,
      'uid' => 1,
    ]);
    $page->save();
    $this->pageId = (int) $page->id();

    node_access_rebuild();
    $this->container->set(
      'markaspot_group.workspace_visibility',
      new WorkspaceVisibilityService(
        $this->container->get('entity_type.manager'),
        $this->container->get('config.factory'),
        $this->container->get('entity_field.manager'),
        $this->container->get('request_stack'),
      ),
    );
    $hierarchy_resolver = $this->createMock(
      JurisdictionHierarchyResolverInterface::class,
    );
    $hierarchy_resolver->method('getRootJurisdictionId')
      ->willReturnCallback(
        static fn(int $group_id): int => $group_id,
      );
    $this->container->set(
      'markaspot_group.hierarchy_resolver',
      $hierarchy_resolver,
    );
  }

  /**
   * Tests every pinned viewer and visibility matrix cell.
   */
  public function testVisibilityMatrixOnRealEntityQueries(): void {
    $this->container->get('current_user')->setAccount(
      new AnonymousUserSession(),
    );
    $context = new RenderContext();
    $anonymous_ids = $this->container->get('renderer')->executeInRenderContext(
      $context,
      fn(): array => $this->visibleRequestIds(),
    );
    $this->assertSame(
      [$this->requestIds['public']],
      $anonymous_ids,
      'Anonymous: public is visible; all other modes are hidden.',
    );
    $this->assertSame(Cache::PERMANENT, $context->pop()->getCacheMaxAge());

    $this->container->get('current_user')->setAccount(
      $this->authenticatedAccount,
    );
    $this->assertSame(
      [
        $this->requestIds['public'],
        $this->requestIds['submission_only'],
        $this->requestIds['authenticated'],
      ],
      $this->visibleRequestIds(),
      'Authenticated reader: all modes except blocked are visible.',
    );

    foreach ($this->staffAccounts as $role_id => $account) {
      $this->container->get('current_user')->setAccount($account);
      $this->assertSame(
        array_values($this->requestIds),
        $this->visibleRequestIds(),
        sprintf(
          'Staff role %s sees all modes in its own jurisdictions.',
          $role_id,
        ),
      );
    }

    $this->container->get('current_user')->setAccount(
      $this->administratorAccount,
    );
    $this->assertSame(
      [...array_values($this->requestIds), $this->mixedRequestId],
      $this->visibleRequestIds(),
      'Site administrator sees every visibility mode globally.',
    );

    $root = User::load(1);
    $this->assertInstanceOf(UserInterface::class, $root);
    $this->container->get('current_user')->setAccount($root);
    $this->assertSame(
      [...array_values($this->requestIds), $this->mixedRequestId],
      $this->visibleRequestIds(),
      'User 1 sees every visibility mode globally.',
    );
  }

  /**
   * Tests one restrictive assignment wins for multi-jurisdiction requests.
   */
  public function testAnyUnreadableJurisdictionExcludesRequest(): void {
    $this->container->get('current_user')->setAccount(
      $this->authenticatedAccount,
    );
    $this->assertNotContains(
      $this->mixedRequestId,
      $this->visibleRequestIds(),
    );

    foreach ($this->staffAccounts as $account) {
      $this->container->get('current_user')->setAccount($account);
      $this->assertNotContains(
        $this->mixedRequestId,
        $this->visibleRequestIds(),
        'Staff access to the public target must not override a foreign blocked target.',
      );
    }
  }

  /**
   * Tests unrelated node bundles and entity types are not filtered.
   */
  public function testQueryAlterLeavesOtherBundlesAndEntityTypesUntouched(): void {
    $this->container->get('current_user')->setAccount(
      new AnonymousUserSession(),
    );
    $page_query = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'page');
    $page_ids = $this->executeWithWorkspaceVisibility($page_query);
    $this->assertSame([$this->pageId], array_map(
      'intval',
      array_values($page_ids),
    ));

    $group_ids = $this->container->get('entity_type.manager')
      ->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(6, $group_ids);
  }

  /**
   * Tests workspace visibility runs before core's node access bypass.
   */
  public function testNodeAccessBypassCannotReadBlockedRequest(): void {
    $node = Node::load($this->requestIds['blocked']);
    $this->assertInstanceOf(Node::class, $node);
    $handler = WorkspaceVisibilityNodeAccessControlHandler::createInstance(
      $this->container,
      $this->container->get('entity_type.manager')->getDefinition('node'),
    );

    $result = $handler->access(
      $node,
      'view',
      $this->nodeAccessBypassAccount,
      TRUE,
    );
    $this->assertTrue($result->isForbidden());
    $legacy_blocked_request_id = $this->createRequest(
      'Legacy blocked request',
      [],
      $this->blockedCategoryId,
    );
    $legacy_node = Node::load($legacy_blocked_request_id);
    $this->assertInstanceOf(Node::class, $legacy_node);
    $legacy_result = $handler->access(
      $legacy_node,
      'view',
      $this->nodeAccessBypassAccount,
      TRUE,
    );
    $this->assertTrue(
      $legacy_result->isForbidden(),
      'Category fallback must protect unassigned legacy requests.',
    );
    foreach (['update', 'delete'] as $operation) {
      $operation_result = $handler->access(
        $node,
        $operation,
        $this->nodeAccessBypassAccount,
        TRUE,
      );
      $this->assertTrue(
        $operation_result->isForbidden(),
        sprintf(
          'Node access bypass must not permit %s in a blocked workspace.',
          $operation,
        ),
      );
    }

    $administrator_result = $handler->access(
      $node,
      'view',
      $this->administratorAccount,
      TRUE,
    );
    $this->assertTrue($administrator_result->isAllowed());
    foreach (['update', 'delete'] as $operation) {
      $administrator_operation_result = $handler->access(
        $node,
        $operation,
        $this->administratorAccount,
        TRUE,
      );
      $this->assertTrue(
        $administrator_operation_result->isAllowed(),
        sprintf(
          'Site administrator retains %s access in a blocked workspace.',
          $operation,
        ),
      );
    }
  }

  /**
   * Form-only denies citizens, generic members and foreign staff on all reads.
   */
  public function testFormOnlyRequiresScopedStaffAndInvalidatesMembershipCache(): void {
    $node = Node::load($this->requestIds['form_only']);
    $jurisdiction_id = (int) $node->get('field_jurisdiction')->target_id;
    $jurisdiction = Group::load($jurisdiction_id);
    $service = $this->container->get('markaspot_group.workspace_visibility');
    $handler = WorkspaceVisibilityNodeAccessControlHandler::createInstance(
      $this->container,
      $this->container->get('entity_type.manager')->getDefinition('node'),
    );
    $jurisdiction->addMember($this->authenticatedAccount);
    foreach ([new AnonymousUserSession(), $this->authenticatedAccount, $this->nodeAccessBypassAccount] as $account) {
      $this->assertTrue($service->canAnonymousSubmit($jurisdiction_id));
      $this->assertFalse($service->allowsReadFor($account, $jurisdiction_id));
      $this->assertSame([], $service->getReportViewJurisdictionIds($account));
      foreach (['view', 'view revision', 'view all revisions', 'update', 'delete', 'revert revision', 'delete revision'] as $operation) {
        $this->assertTrue($handler->access($node, $operation, $account, TRUE)->isForbidden());
      }
      $this->container->get('current_user')->setAccount($account);
      $query = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
        ->accessCheck(TRUE)->condition('nid', $node->id());
      $this->assertSame([], $this->executeWithWorkspaceVisibility($query));
    }
    $foreign_staff = $this->createAccount('foreign-staff');
    $foreign = $this->createJurisdiction('Foreign form', 'form_only');
    $foreign->addMember($foreign_staff, ['group_roles' => ['jur-moderator']]);
    $this->assertFalse($service->allowsReadFor($foreign_staff, $jurisdiction_id));
    $staff = $this->staffAccounts['jur-moderator'];
    $this->assertTrue($service->allowsReadFor($staff, $jurisdiction_id));
    $this->assertSame([$jurisdiction_id], $service->getReportViewJurisdictionIds($staff));
    $jurisdiction->removeMember($staff);
    $service->resetCache($jurisdiction_id);
    $this->assertFalse($service->allowsReadFor($staff, $jurisdiction_id));
  }

  /**
   * Responsible org staff may read only reports assigned to their organisation.
   */
  public function testFormOnlyOrganisationScopeDoesNotWidenJurisdictionAccess(): void {
    $node = Node::load($this->requestIds['form_only']);
    $jurisdiction_id = (int) $node->get('field_jurisdiction')->target_id;
    $root_id = (int) Node::load($this->requestIds['public'])->get('field_jurisdiction')->target_id;
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getRootJurisdictionId')->willReturnCallback(
      static fn(int $id): int => $id === $jurisdiction_id ? $root_id : $id,
    );
    $this->container->set('markaspot_group.hierarchy_resolver', $hierarchy);
    $org_type = GroupType::create(['id' => 'org', 'label' => 'Organisation']);
    $org_type->save();
    $relationship_type_storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationship_type_storage->load('org-group_membership')) {
      $relationship_type_storage->createFromPlugin($org_type, 'group_membership')->save();
    }
    GroupRole::create([
      'id' => 'org-moderator', 'label' => 'Org moderator',
      'group_type' => 'org', 'scope' => 'individual',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction', 'entity_type' => 'group',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction', 'entity_type' => 'group',
      'bundle' => 'org', 'label' => 'Jurisdiction',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_organisation', 'entity_type' => 'node',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_organisation', 'entity_type' => 'node',
      'bundle' => 'service_request', 'label' => 'Organisation',
    ])->save();
    $org = Group::create([
      'type' => 'org', 'label' => 'Responsible organisation',
      'field_jurisdiction' => ['target_id' => $root_id],
    ]);
    $org->save();
    $staff = $this->createAccount('org-staff');
    $org->addMember($staff, ['group_roles' => ['org-moderator']]);
    $org->addMember($this->authenticatedAccount);
    $service = $this->container->get('markaspot_group.workspace_visibility');
    $this->assertFalse($service->allowsReadFor($this->authenticatedAccount, $jurisdiction_id));
    $this->assertTrue($service->allowsReadFor($staff, $jurisdiction_id));
    $this->assertSame([$jurisdiction_id], $service->getReportViewJurisdictionIds($staff));
    $this->assertFalse($service->allowsReportReadFor($staff, $jurisdiction_id, []));
    $this->assertTrue($service->allowsReportReadFor($staff, $jurisdiction_id, [(int) $org->id()]));
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
    $node = Node::load($node->id());
    $node->set('field_organisation', ['target_id' => $org->id()])->save();
    $other_id = $this->createRequest('Unassigned private report', [$jurisdiction_id]);
    $this->container->get('current_user')->setAccount($staff);
    $context = new RenderContext();
    $visible = $this->container->get('renderer')->executeInRenderContext(
      $context,
      fn(): array => $this->visibleRequestIds(),
    );
    $this->assertContains((int) $node->id(), $visible);
    $this->assertNotContains($other_id, $visible);
    $this->assertSame(0, $context->pop()->getCacheMaxAge());
    $this->assertContains((int) $node->id(), $this->aggregateVisibleIds(FALSE));
    $this->assertNotContains($other_id, $this->aggregateVisibleIds(FALSE));
    $handler = WorkspaceVisibilityNodeAccessControlHandler::createInstance(
      $this->container,
      $this->container->get('entity_type.manager')->getDefinition('node'),
    );
    $this->assertTrue($handler->access(Node::load($other_id), 'view', $staff, TRUE)->isForbidden());
    $request = Request::create('/jsonapi/node/service_request', 'POST');
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    $response = new JsonResponse([
      'data' => [
        'id' => $node->uuid(),
        'type' => 'node--service_request',
        'attributes' => ['title' => 'Authorized org report'],
      ],
    ], 201);
    $original_response = $response->getContent();
    $event = new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      $request, HttpKernelInterface::MAIN_REQUEST, $response,
    );
    $receipt = new FormOnlySubmissionReceiptSubscriber(
      $this->container->get('entity_type.manager'), $staff, $service,
    );
    $receipt->onResponse($event);
    $this->assertSame($original_response, $event->getResponse()->getContent());
  }

  /**
   * Raw aggregate SQL cannot count form-only reports for citizens or API keys.
   */
  public function testFormOnlyAggregateScopeUsesSqlAndApiKeyAnonymousPolicy(): void {
    $form_node = Node::load($this->requestIds['form_only']);
    $public_node = Node::load($this->requestIds['public']);
    $mixed_id = $this->createRequest('Mixed public and form-only', [
      (int) $public_node->get('field_jurisdiction')->target_id,
      (int) $form_node->get('field_jurisdiction')->target_id,
    ]);
    foreach ([new AnonymousUserSession(), $this->authenticatedAccount] as $account) {
      $this->container->get('current_user')->setAccount($account);
      $visible = $this->aggregateVisibleIds(FALSE);
      $this->assertNotContains($this->requestIds['form_only'], $visible);
      $this->assertNotContains($mixed_id, $visible);
      $this->assertContains($this->requestIds['public'], $visible);
    }
    $this->container->get('current_user')->setAccount($this->staffAccounts['jur-moderator']);
    $this->assertContains($this->requestIds['form_only'], $this->aggregateVisibleIds(FALSE));
    $this->assertNotContains($this->requestIds['form_only'], $this->aggregateVisibleIds(TRUE));
  }

  /**
   * Executes the production aggregate visibility predicate on the kernel DB.
   *
   * @return int[]
   *   Report IDs available to aggregate SQL.
   */
  private function aggregateVisibleIds(bool $uses_api_key): array {
    $controller = new GeoreportStatsController(
      $this->container->get('database'),
      $this->container->get('request_stack'),
      $this->container->get('language_manager'),
      NULL,
      NULL,
      new FormOnlyReportQueryScope(
        $this->container->get('markaspot_group.workspace_visibility'),
        $this->container->get('entity_type.manager'),
        $this->container->get('entity_field.manager'),
        $this->container->get('database'),
        $this->container->get('config.factory'),
      ),
    );
    $method = new \ReflectionMethod($controller, 'getFormOnlySqlRestriction');
    $predicate = $method->invoke($controller, $uses_api_key);
    return array_map('intval', $this->container->get('database')->query(
      "SELECT n.nid FROM {node_field_data} n WHERE n.type = 'service_request' $predicate ORDER BY n.nid",
    )->fetchCol());
  }

  /**
   * Public keys never inherit privileged owner or accompanying cookie access.
   */
  public function testPrivilegedApiKeyAndStaffCookieCannotReadFormOnly(): void {
    $node = Node::load($this->requestIds['form_only']);
    $jurisdiction_id = (int) $node->get('field_jurisdiction')->target_id;
    $visibility = $this->container->get('markaspot_group.workspace_visibility');
    $route_access = new FormOnlyReportRouteAccessCheck($this->container->get('entity_type.manager'), $visibility);
    $global_access = new FormOnlyGlobalReportAccessCheck($visibility, $this->container->get('entity_type.manager'), $this->container->get('config.factory'));
    $route_match = new RouteMatch('markaspot_feedback.rest', new Route('/api/feedback/{uuid}'), ['uuid' => $node->uuid()]);
    $handler = WorkspaceVisibilityNodeAccessControlHandler::createInstance(
      $this->container,
      $this->container->get('entity_type.manager')->getDefinition('node'),
    );
    $request = Request::create('/jsonapi/node/service_request', 'GET', [], ['staff_session' => 'present']);
    $request->headers->set('apikey', 'public-proxy-key');
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      foreach ([User::load(1), $this->administratorAccount, $this->staffAccounts['jur-moderator']] as $account) {
        $this->container->get('current_user')->setAccount($account);
        $this->assertFalse($visibility->allowsReadFor($account, $jurisdiction_id));
        $this->assertSame([], $visibility->getReportViewJurisdictionIds($account));
        $this->assertContains($jurisdiction_id, $visibility->getUnreadableJurisdictionIds($account));
        $access = $handler->access($node, 'view', $account, TRUE);
        $this->assertTrue($access->isForbidden());
        $this->assertSame(0, $access->getCacheMaxAge());
        foreach (['update', 'delete', 'revert revision', 'delete revision'] as $operation) {
          $this->assertTrue($handler->access($node, $operation, $account, TRUE)->isForbidden());
          $this->assertTrue(_markaspot_group_workspace_visibility_node_access($node, $operation, $account)->isForbidden());
        }
        $this->assertTrue($global_access->access($account, $request)->isForbidden());
        $follow_up_access = $route_access->access($route_match, $account);
        $this->assertTrue($follow_up_access->isForbidden());
        $this->assertSame(0, $follow_up_access->getCacheMaxAge());
        $this->assertSame(0, _markaspot_group_workspace_visibility_node_access($node, 'view', $account)->getCacheMaxAge());
        $this->assertNotContains($this->requestIds['form_only'], $this->visibleRequestIds());
        $this->assertContains($this->requestIds['submission_only'], $this->visibleRequestIds());
        $this->assertNotContains($this->requestIds['form_only'], $this->aggregateVisibleIds(TRUE));
        $request->setMethod('POST');
        $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
        $response = new JsonResponse([
          'data' => [
            'id' => $node->uuid(),
            'type' => 'node--service_request',
            'attributes' => ['description' => 'Private report body'],
          ],
        ], 201);
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber = new FormOnlySubmissionReceiptSubscriber($this->container->get('entity_type.manager'), $account, $visibility);
        $subscriber->onResponse($event);
        $this->assertSame([], json_decode((string) $response->getContent(), TRUE)['data']['attributes']);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $request->setMethod('GET');
      }
    }
    finally {
      $stack->pop();
    }
    $this->container->get('current_user')->setAccount(
      $this->administratorAccount,
    );
    $this->assertTrue($visibility->allowsReadFor($this->administratorAccount, $jurisdiction_id));
    $this->assertSame(
      [],
      $visibility->getUnreadableJurisdictionIds($this->administratorAccount),
    );
    $this->assertSame(
      [],
      $visibility->getUnreadablePageJurisdictionIds($this->administratorAccount),
    );
    foreach ($visibility->getReportViewJurisdictionIds($this->administratorAccount) as $view_id) {
      $this->assertNull(
        $visibility->getFormOnlyOrganisationScope(
          $this->administratorAccount,
          $view_id,
        ),
      );
    }
    $this->assertTrue($global_access->access($this->administratorAccount, Request::create('/api/ai/processing/status'))->isAllowed());
    $query = $this->container->get('database')
      ->select('node_field_data', 'n');
    $query->addField('n', 'nid');
    $query->addTag('node_access');
    $query->addMetaData('account', $this->administratorAccount);
    $query->addMetaData('entity_type', 'node');
    $context = new RenderContext();
    $this->container->get('renderer')->executeInRenderContext(
      $context,
      static fn() => markaspot_group_query_node_access_alter($query),
    );
    $this->assertTrue($context->isEmpty());
  }

  /**
   * Tests clearing the jurisdiction cannot bypass the blocked-save backstop.
   */
  public function testPresaveChecksOriginalBlockedJurisdiction(): void {
    $node = Node::load($this->requestIds['blocked']);
    $this->assertInstanceOf(Node::class, $node);
    $node->setOriginal(clone $node);
    $node->set('field_jurisdiction', NULL);
    $this->container->get('current_user')->setAccount(
      $this->nodeAccessBypassAccount,
    );

    $this->expectException(AccessDeniedHttpException::class);
    _markaspot_group_enforce_workspace_visibility_presave($node);
  }

  /**
   * Returns visible service request IDs for the active account.
   *
   * @return int[]
   *   Visible service request node IDs.
   */
  private function visibleRequestIds(): array {
    $query = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'service_request')
      ->sort('nid');
    $ids = $this->executeWithWorkspaceVisibility($query);

    return array_map('intval', array_values($ids));
  }

  /**
   * Executes a real entity query with the worktree's SQL query alter.
   *
   * Kernel extension discovery resolves profile modules from the main Drupal
   * checkout. This test loads the isolated worktree module directly, then
   * applies its node_access alter to the Select query built by EntityQuery.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query.
   *
   * @return array
   *   Entity query results.
   */
  private function executeWithWorkspaceVisibility(QueryInterface $query): array {
    $reflection = new \ReflectionObject($query);
    foreach (['alter', 'prepare'] as $method_name) {
      $method = $reflection->getMethod($method_name);
      $method->invoke($query);
    }

    $sql_query_property = $reflection->getProperty('sqlQuery');
    $sql_query = $sql_query_property->getValue($query);
    $this->assertTrue($sql_query->hasTag('node_access'));
    markaspot_group_query_node_access_alter($sql_query);

    foreach (['compile', 'addSort', 'finish'] as $method_name) {
      $method = $reflection->getMethod($method_name);
      $method->invoke($query);
    }
    $result_method = $reflection->getMethod('result');
    $result = $result_method->invoke($query);
    $this->assertIsArray($result);
    return $result;
  }

  /**
   * Creates an active user account.
   *
   * @param string $name
   *   Account name.
   * @param string[] $roles
   *   Optional site role IDs.
   */
  private function createAccount(string $name, array $roles = []): UserInterface {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@example.test',
      'status' => 1,
      'roles' => $roles,
    ]);
    $account->save();
    return $account;
  }

  /**
   * Creates the jurisdiction visibility field.
   */
  private function createVisibilityField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_visibility',
      'entity_type' => 'group',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'public' => 'Public',
          'submission_only' => 'Submission only',
          'form_only' => 'Form only',
          'authenticated' => 'Authenticated',
          'blocked' => 'Blocked',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_visibility',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Visibility',
    ])->save();
  }

  /**
   * Creates the multi-value request jurisdiction field.
   */
  private function createJurisdictionField(): void {
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
      'settings' => [
        'handler' => 'default:group',
        'handler_settings' => [],
      ],
    ])->save();
    $this->container->get('entity_field.manager')
      ->clearCachedFieldDefinitions();
  }

  /**
   * Creates category ownership and service-request category fields.
   */
  private function createCategoryFields(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'taxonomy_term',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'service_category',
      'label' => 'Jurisdiction',
      'settings' => [
        'handler' => 'default:group',
        'handler_settings' => [],
      ],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Category',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [],
      ],
    ])->save();
    $this->container->get('entity_field.manager')
      ->clearCachedFieldDefinitions();
  }

  /**
   * Creates a jurisdiction with one visibility mode.
   */
  private function createJurisdiction(string $label, string $visibility): Group {
    $group = Group::create([
      'type' => 'jur',
      'label' => $label,
      'field_visibility' => $visibility,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Creates a published request assigned to one or more jurisdictions.
   *
   * @param string $title
   *   Request title.
   * @param int[] $jurisdiction_ids
   *   Jurisdiction target IDs.
   * @param int|null $category_id
   *   Optional service category term ID.
   */
  private function createRequest(string $title, array $jurisdiction_ids, ?int $category_id = NULL): int {
    $values = [
      'type' => 'service_request',
      'title' => $title,
      'status' => 1,
      'uid' => 1,
      'field_jurisdiction' => array_map(
        static fn(int $id): array => ['target_id' => $id],
        $jurisdiction_ids,
      ),
    ];
    if ($category_id !== NULL) {
      $values['field_category'] = ['target_id' => $category_id];
    }
    $node = Node::create($values);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Ensures the jurisdiction membership relationship type exists.
   */
  private function ensureMembershipType(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('group_relationship_type');
    if ($storage->load('jur-group_membership')) {
      return;
    }
    $storage->createFromPlugin(
      GroupType::load('jur'),
      'group_membership',
    )->save();
  }

}
