<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

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
require_once dirname(__DIR__, 3) . '/src/WorkspaceVisibilityNodeAccessControlHandler.php';
require_once dirname(__DIR__, 3) . '/markaspot_group.module';

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
    $this->assertSame(
      [$this->requestIds['public']],
      $this->visibleRequestIds(),
      'Anonymous: public is visible; all other modes are hidden.',
    );

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
    $this->assertCount(5, $group_ids);
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
