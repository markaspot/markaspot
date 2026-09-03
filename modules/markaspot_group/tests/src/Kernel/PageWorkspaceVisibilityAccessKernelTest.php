<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityService;
use Drupal\markaspot_group\WorkspaceVisibilityNodeAccessControlHandler;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/src/Service/JurisdictionHierarchyResolverInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceVisibilityInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/WorkspaceVisibilityService.php';
require_once dirname(__DIR__, 3) . '/src/WorkspaceVisibilityNodeAccessControlHandler.php';
require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Tests workspace visibility access for page nodes.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class PageWorkspaceVisibilityAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'language',
    'options',
    'text',
    'node',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
  ];

  /**
   * Page IDs keyed by workspace visibility.
   *
   * @var array<string, int>
   */
  private array $pageIds = [];

  /**
   * Jurisdiction IDs keyed by workspace visibility.
   *
   * @var array<string, int>
   */
  private array $jurisdictionIds = [];

  /**
   * Member of every test jurisdiction.
   */
  private UserInterface $memberAccount;

  /**
   * Authenticated account without jurisdiction membership.
   */
  private UserInterface $outsiderAccount;

  /**
   * Site administrator account.
   */
  private UserInterface $administratorAccount;

  /**
   * Non-administrator with core node administration permissions.
   */
  private UserInterface $editorialAccount;

  /**
   * Page without a jurisdiction.
   */
  private int $unassignedPageId;

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
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system',
      'user',
      'field',
      'node',
      'group',
    ]);

    foreach (['anonymous', 'authenticated'] as $role_id) {
      $role = Role::load($role_id);
      $this->assertInstanceOf(Role::class, $role);
      $role->grantPermission('access content')->save();
    }
    Role::create([
      'id' => 'administrator',
      'label' => 'Administrator',
      'is_admin' => TRUE,
    ])->save();
    Role::create([
      'id' => 'editorial_board',
      'label' => 'Editorial board',
      'permissions' => [
        'access content',
        'administer nodes',
        'bypass node access',
      ],
    ])->save();

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

    $this->memberAccount = $this->createAccount('workspace-member');
    $this->outsiderAccount = $this->createAccount('authenticated-outsider');
    $this->administratorAccount = $this->createAccount(
      'site-administrator',
      ['administrator'],
    );
    $this->editorialAccount = $this->createAccount(
      'editorial-outsider',
      ['editorial_board'],
    );

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    GroupType::create([
      'id' => 'jur',
      'label' => 'Jurisdiction',
    ])->save();
    $this->ensureMembershipType();
    $this->ensurePageRelationshipType();
    GroupRole::create([
      'id' => 'jur-anonymous',
      'label' => 'Anonymous',
      'group_type' => 'jur',
      'scope' => 'outsider',
      'global_role' => 'anonymous',
      'permissions' => ['view group_node:page entity'],
    ])->save();
    GroupRole::create([
      'id' => 'jur-outsider',
      'label' => 'Outsider',
      'group_type' => 'jur',
      'scope' => 'outsider',
      'global_role' => 'authenticated',
      'permissions' => ['view group_node:page entity'],
    ])->save();
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => ['view group_node:page entity'],
    ])->save();
    $this->createVisibilityField();
    $this->createJurisdictionField();

    foreach ([
      'public' => 'public',
      'unset' => NULL,
      'submission_only' => 'submission_only',
      'authenticated' => 'authenticated',
      'blocked' => 'blocked',
    ] as $key => $visibility) {
      $jurisdiction = $this->createJurisdiction($key, $visibility);
      // Real memberships always carry jur-member (MembershipRoleNormalizer);
      // a role-less member is neither insider nor outsider for gnode grants.
      $jurisdiction->addMember($this->memberAccount, [
        'group_roles' => ['jur-member'],
      ]);
      $jurisdiction_id = (int) $jurisdiction->id();
      $this->jurisdictionIds[$key] = $jurisdiction_id;
      $this->pageIds[$key] = $this->createPage($key, $jurisdiction_id);
    }
    $this->unassignedPageId = $this->createPage('unassigned');

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
      ->willReturnCallback(static fn(int $group_id): int => $group_id);
    $this->container->set(
      'markaspot_group.hierarchy_resolver',
      $hierarchy_resolver,
    );
    $this->installWorkspaceAccessHandler();
  }

  /**
   * Tests anonymous access-checked entity queries for every visibility value.
   */
  public function testAnonymousEntityQueryVisibility(): void {
    $this->container->get('current_user')->setAccount(
      new AnonymousUserSession(),
    );

    $this->assertSame([
      $this->pageIds['public'],
      $this->pageIds['unset'],
      $this->unassignedPageId,
    ], $this->visiblePageIds());
  }

  /**
   * Tests that authentication alone does not expose restricted page listings.
   */
  public function testAuthenticatedOutsiderEntityQueryVisibility(): void {
    $this->container->get('current_user')->setAccount($this->outsiderAccount);

    $this->assertSame([
      $this->pageIds['public'],
      $this->pageIds['unset'],
      $this->unassignedPageId,
    ], $this->visiblePageIds());
  }

  /**
   * Tests direct page access for anonymous, member, admin, and root accounts.
   */
  public function testDirectPageAccessVisibilityMatrix(): void {
    $anonymous = new AnonymousUserSession();
    $root = User::load(1);
    $this->assertInstanceOf(UserInterface::class, $root);

    foreach ($this->pageIds as $visibility => $page_id) {
      $page = Node::load($page_id);
      $this->assertInstanceOf(Node::class, $page);
      $public = in_array($visibility, ['public', 'unset'], TRUE);

      $this->assertSame(
        $public,
        $page->access('view', $anonymous),
        sprintf('Anonymous access mismatch for %s.', $visibility),
      );
      $this->assertSame(
        $visibility !== 'blocked',
        $page->access('view', $this->memberAccount),
        sprintf('Member access mismatch for %s.', $visibility),
      );
      $this->assertSame(
        $public,
        $page->access('view', $this->outsiderAccount),
        sprintf('Authenticated outsider access mismatch for %s.', $visibility),
      );
      $this->assertTrue(
        $page->access('view', $this->administratorAccount),
        sprintf('Administrator cannot view %s.', $visibility),
      );
      $this->assertTrue(
        $page->access('view', $root),
        sprintf('User 1 cannot view %s.', $visibility),
      );
    }

    $unassigned = Node::load($this->unassignedPageId);
    $this->assertInstanceOf(Node::class, $unassigned);
    $this->assertTrue($unassigned->access('view', $anonymous));
  }

  /**
   * Tests that node administration cannot bypass page visibility.
   */
  public function testRevisionAccessVisibility(): void {
    foreach (['submission_only', 'authenticated', 'blocked'] as $visibility) {
      $page = Node::load($this->pageIds[$visibility]);
      $this->assertInstanceOf(Node::class, $page);
      $this->assertFalse(
        $page->access('view all revisions', $this->editorialAccount),
        sprintf('Editorial outsider listed %s revisions.', $visibility),
      );
      $this->assertFalse(
        $page->access('view revision', $this->editorialAccount),
        sprintf('Editorial outsider viewed a %s revision.', $visibility),
      );
    }

    $blocked = Node::load($this->pageIds['blocked']);
    $this->assertInstanceOf(Node::class, $blocked);
    $this->assertFalse(
      $blocked->access('revert revision', $this->editorialAccount),
    );
    $this->assertFalse(
      $blocked->access('delete revision', $this->editorialAccount),
    );
  }

  /**
   * Tests that restricted pages retain only visibility-realm view grants.
   */
  public function testPageVisibilityGrantRecordsAndAccountGrants(): void {
    foreach (['submission_only', 'authenticated', 'blocked'] as $visibility) {
      $page = Node::load($this->pageIds[$visibility]);
      $this->assertInstanceOf(Node::class, $page);
      $grants = [
        [
          'realm' => 'all',
          'gid' => 0,
          'grant_view' => 1,
          'grant_update' => 0,
          'grant_delete' => 0,
        ],
        [
          'realm' => 'foreign_editor',
          'gid' => 7,
          'grant_view' => 1,
          'grant_update' => 1,
          'grant_delete' => 0,
        ],
      ];

      markaspot_group_node_access_records_alter($grants, $page);
      $view_grants = array_values(array_filter(
        $grants,
        static fn(array $grant): bool => (bool) $grant['grant_view'],
      ));
      $this->assertNotEmpty($view_grants);
      $this->assertSame(
        ['markaspot_page_visibility'],
        array_values(array_unique(array_column($view_grants, 'realm'))),
      );
      $expected_gids = $visibility === 'blocked'
        ? [0]
        : [0, $this->jurisdictionIds[$visibility]];
      $this->assertSame($expected_gids, array_column($view_grants, 'gid'));

      $editor_grant = array_values(array_filter(
        $grants,
        static fn(array $grant): bool => $grant['realm'] === 'foreign_editor',
      ));
      $this->assertCount(1, $editor_grant);
      $this->assertSame(0, $editor_grant[0]['grant_view']);
      $this->assertSame(1, $editor_grant[0]['grant_update']);
    }

    $anonymous_grants = markaspot_group_node_grants(
      new AnonymousUserSession(),
      'view',
    );
    $this->assertSame([], $anonymous_grants);
    $this->assertSame([], markaspot_group_node_grants(
      $this->outsiderAccount,
      'view',
    ));
    $member_grants = markaspot_group_node_grants(
      $this->memberAccount,
      'view',
    );
    $this->assertSame([
      'markaspot_page_visibility' => [
        $this->jurisdictionIds['submission_only'],
        $this->jurisdictionIds['authenticated'],
      ],
    ], $member_grants);
    $this->assertSame([
      'markaspot_page_visibility' => [0],
    ], markaspot_group_node_grants(
      $this->administratorAccount,
      'view',
    ));

    $mixed_page = Node::create([
      'type' => 'page',
      'title' => 'Mixed restricted page',
      'status' => 1,
      'uid' => 1,
      'field_jurisdiction' => [
        ['target_id' => $this->jurisdictionIds['authenticated']],
        ['target_id' => $this->jurisdictionIds['blocked']],
      ],
    ]);
    $mixed_page->save();
    $mixed_grants = [];
    markaspot_group_node_access_records_alter($mixed_grants, $mixed_page);
    $this->assertSame(
      [0],
      array_column($mixed_grants, 'gid'),
      'Any blocked workspace must reduce a page to the admin grant.',
    );
    $this->assertFalse($mixed_page->access('view', $this->memberAccount));
  }

  /**
   * Tests that grant records use each translation's publication status.
   */
  public function testPageVisibilityGrantsAreTranslationAware(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    $page = Node::load($this->pageIds['authenticated']);
    $this->assertInstanceOf(Node::class, $page);
    $default_langcode = $page->language()->getId();
    $page->addTranslation('de', [
      'title' => 'Nicht veroeffentlicht',
      'status' => 0,
    ]);
    $page->save();

    $grants = [];
    markaspot_group_node_access_records_alter(
      $grants,
      $page->getTranslation('de'),
    );
    $expected_gids = [
      0,
      $this->jurisdictionIds['authenticated'],
    ];
    foreach ([$default_langcode => 1, 'de' => 0] as $langcode => $status) {
      $language_grants = array_values(array_filter(
        $grants,
        static fn(array $grant): bool => $grant['langcode'] === $langcode,
      ));
      $this->assertSame($expected_gids, array_column($language_grants, 'gid'));
      $this->assertSame(
        [$status, $status],
        array_column($language_grants, 'grant_view'),
      );
    }
  }

  /**
   * Returns visible page IDs for the active account.
   *
   * @return int[]
   *   Visible page node IDs.
   */
  private function visiblePageIds(): array {
    $query = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'page')
      ->sort('nid');
    $ids = $this->executeWithWorkspaceVisibility($query);

    return array_map('intval', array_values($ids));
  }

  /**
   * Executes an access-checked entity query with the worktree query alter.
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
      $reflection->getMethod($method_name)->invoke($query);
    }

    $sql_query = $reflection->getProperty('sqlQuery')->getValue($query);
    $this->assertTrue($sql_query->hasTag('node_access'));
    markaspot_group_query_node_access_alter($sql_query);

    foreach (['compile', 'addSort', 'finish'] as $method_name) {
      $reflection->getMethod($method_name)->invoke($query);
    }
    $result = $reflection->getMethod('result')->invoke($query);
    $this->assertIsArray($result);
    return $result;
  }

  /**
   * Installs the worktree access handler for direct Entity API checks.
   */
  private function installWorkspaceAccessHandler(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $handler = WorkspaceVisibilityNodeAccessControlHandler::createInstance(
      $this->container,
      $entity_type_manager->getDefinition('node'),
    );
    $handler->setModuleHandler($this->container->get('module_handler'));
    $reflection = new \ReflectionObject($entity_type_manager);
    $handlers_property = $reflection->getProperty('handlers');
    $handlers = $handlers_property->getValue($entity_type_manager);
    $handlers['access']['node'] = $handler;
    $handlers_property->setValue($entity_type_manager, $handlers);
  }

  /**
   * Creates an active user account.
   *
   * @param string $name
   *   Account name.
   * @param string[] $roles
   *   Optional role IDs.
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
   * Creates the page jurisdiction field.
   */
  private function createJurisdictionField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'translatable' => FALSE,
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Jurisdiction',
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:group',
        'handler_settings' => [],
      ],
    ])->save();
    $this->container->get('entity_field.manager')
      ->clearCachedFieldDefinitions();
  }

  /**
   * Creates a jurisdiction with an optional visibility value.
   */
  private function createJurisdiction(string $label, ?string $visibility): Group {
    $values = [
      'type' => 'jur',
      'label' => ucfirst(str_replace('_', ' ', $label)),
    ];
    if ($visibility !== NULL) {
      $values['field_visibility'] = $visibility;
    }
    $group = Group::create($values);
    $group->save();
    return $group;
  }

  /**
   * Creates a published page with an optional jurisdiction.
   */
  private function createPage(string $title, ?int $jurisdiction_id = NULL): int {
    $values = [
      'type' => 'page',
      'title' => ucfirst(str_replace('_', ' ', $title)),
      'status' => 1,
      'uid' => 1,
    ];
    if ($jurisdiction_id !== NULL) {
      $values['field_jurisdiction'] = ['target_id' => $jurisdiction_id];
    }
    $page = Node::create($values);
    $page->save();
    if ($jurisdiction_id !== NULL) {
      $jurisdiction = Group::load($jurisdiction_id);
      $this->assertInstanceOf(Group::class, $jurisdiction);
      $jurisdiction
        ->addRelationship($page, 'group_node:page')
        ->save();
    }
    return (int) $page->id();
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

  /**
   * Ensures the jurisdiction page relationship type exists.
   */
  private function ensurePageRelationshipType(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('group_relationship_type');
    if ($storage->load('jur-group_node-page')) {
      return;
    }
    $storage->createFromPlugin(
      GroupType::load('jur'),
      'group_node:page',
    )->save();
  }

}
