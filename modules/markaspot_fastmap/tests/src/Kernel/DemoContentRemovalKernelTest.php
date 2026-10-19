<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Controller\ReportStatsController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests removing the provisioned demo requests of a workspace.
 *
 * @covers \Drupal\markaspot_fastmap\Controller\ReportStatsController::removeDemoContent
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class DemoContentRemovalKernelTest extends KernelTestBase {

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
    'taxonomy',
    'options',
    'datetime',
    'language',
    'color_field',
    'geolocation',
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
    'markaspot_tenant_admin',
    'markaspot_fastmap',
  ];

  /**
   * The workspace whose demo content is removed.
   */
  private GroupInterface $workspace;

  /**
   * Another workspace, whose demo content must survive.
   */
  private GroupInterface $otherWorkspace;

  /**
   * Tenant admin of $workspace.
   */
  private UserInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypes = [
      'user', 'node', 'file', 'taxonomy_term', 'paragraph',
      'group', 'group_relationship', 'group_config_wrapper',
    ];
    foreach ($entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);

    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    // The permission every dashboard user holds (granted by update hooks).
    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('access workspace usage')->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation', 'creator_membership' => FALSE])->save();
    foreach (['tenant_admin', 'member'] as $role) {
      GroupRole::create([
        'id' => 'jur-' . $role,
        'label' => $role,
        'group_type' => 'jur',
        'scope' => 'individual',
      ])->save();
    }
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      if (!$relationshipTypes->load('jur-' . str_replace(':', '-', $pluginId))) {
        $relationshipTypes->createFromPlugin(GroupType::load('jur'), $pluginId)->save();
      }
    }
    if (!$relationshipTypes->load('org-group_membership')) {
      $relationshipTypes->createFromPlugin(GroupType::load('org'), 'group_membership')->save();
    }
    // Membership permission calculation queries organisations by it.
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    foreach (['field_slug' => 'string', 'field_tier' => 'string', 'field_expiry_date' => 'timestamp'] as $name => $type) {
      $this->createField('group', 'jur', $name, $type);
    }
    $this->createField('node', 'service_request', 'body', 'text_long');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->workspace = $this->createWorkspace('School routes', 'school-routes');
    $this->otherWorkspace = $this->createWorkspace('Trail watch', 'trail-watch');
    $this->admin = $this->createUser('admin');
    $this->workspace->addMember($this->admin, ['group_roles' => ['jur-tenant_admin']]);
  }

  /**
   * A tenant admin removes only provisioned demo requests of the workspace.
   */
  public function testTenantAdminRemovesOnlyProvisionedDemoRequests(): void {
    $citizen = $this->createUser('citizen');
    $demoA = $this->createRequest($this->workspace, 1, "A pothole on the corner.\n\n[demo-content]");
    $demoB = $this->createRequest($this->workspace, 1, "Graffiti on the wall.\n\n[demo-content]");
    $citizenWithMarker = $this->createRequest($this->workspace, (int) $citizen->id(), 'I copied [demo-content] into my report.');
    $realByOwner = $this->createRequest($this->workspace, 1, 'A real report entered by the operator.');
    $otherDemo = $this->createRequest($this->otherWorkspace, 1, "Fallen tree.\n\n[demo-content]");

    $response = $this->post($this->admin, ['jurisdiction_id' => (int) $this->workspace->id()]);

    $this->assertSame(200, $response['status']);
    // The citizen report still carries the marker, so it still counts as
    // demo in the report stats; it is not provisioning's to delete.
    $this->assertSame(['removed' => 2, 'demo_request_count' => 1], $response['body']);
    $this->assertNull(Node::load($demoA->id()));
    $this->assertNull(Node::load($demoB->id()));
    $this->assertNotNull(Node::load($citizenWithMarker->id()), 'A citizen report with the marker survives.');
    $this->assertNotNull(Node::load($realByOwner->id()), 'An owner report without the marker survives.');
    $this->assertNotNull(Node::load($otherDemo->id()), 'Demo content of another workspace survives.');

    // A second call is a no-op.
    $this->assertSame(['removed' => 0, 'demo_request_count' => 1], $this->post($this->admin, ['jurisdiction_id' => (string) $this->workspace->id()])['body']);
  }

  /**
   * Only the workspace's own tenant admins and platform admins may remove.
   */
  public function testAccessIsLimitedToTenantAdminsOfTheWorkspace(): void {
    $demo = $this->createRequest($this->workspace, 1, "Demo.\n\n[demo-content]");
    $body = ['jurisdiction_id' => (int) $this->workspace->id()];

    $foreignAdmin = $this->createUser('foreign-admin');
    $this->otherWorkspace->addMember($foreignAdmin, ['group_roles' => ['jur-tenant_admin']]);
    $this->assertSame(403, $this->post($foreignAdmin, $body)['status'], 'Tenant admin of another workspace.');

    $member = $this->createUser('member');
    $this->workspace->addMember($member, ['group_roles' => ['jur-member']]);
    $this->assertSame(403, $this->post($member, $body)['status'], 'Member without the tenant admin role.');

    $this->assertSame(403, $this->post(new AnonymousUserSession(), $body)['status'], 'Anonymous.');
    $this->assertNotNull(Node::load($demo->id()), 'Denied calls delete nothing.');

    $platformAdmin = $this->createUser('platform-admin');
    $platformAdmin->addRole('administrator');
    $platformAdmin->save();
    $this->assertSame(['removed' => 1, 'demo_request_count' => 0], $this->post($platformAdmin, $body)['body']);
  }

  /**
   * Invalid input is rejected and unknown IDs are not disclosed.
   */
  public function testInvalidInput(): void {
    $this->assertSame(400, $this->post($this->admin, [])['status']);
    $this->assertSame(400, $this->post($this->admin, ['jurisdiction_id' => 'school-routes'])['status'], 'Slugs are not accepted.');
    $this->assertSame(400, $this->post($this->admin, ['jurisdiction_id' => -1])['status']);
    $this->assertSame(400, $this->post($this->admin, ['jurisdiction_id' => [1]])['status']);

    // Unknown IDs and non-jurisdiction groups: only platform admins learn
    // that they do not exist, everyone else gets the same 403 as for a
    // foreign workspace.
    $org = Group::create(['type' => 'org', 'label' => 'Traffic department']);
    $org->save();
    $platformAdmin = $this->createUser('platform-admin');
    $platformAdmin->addRole('administrator');
    $platformAdmin->save();
    foreach ([9999, (int) $org->id()] as $unknown) {
      $this->assertSame(403, $this->post($this->admin, ['jurisdiction_id' => $unknown])['status'], "Tenant admin, unknown {$unknown}.");
      $this->assertSame(404, $this->post($platformAdmin, ['jurisdiction_id' => $unknown])['status'], "Platform admin, unknown {$unknown}.");
    }
  }

  /**
   * The route requires a session, a CSRF header token and JSON, POST only.
   *
   * Kernel tests load routes from the installed module, so the shipped
   * routing file is asserted as written.
   */
  public function testRouteRequirements(): void {
    $routes = Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . '/markaspot_fastmap.routing.yml'));
    $route = $routes['markaspot_fastmap.demo_content_remove'];
    $this->assertSame('/api/fastmap/demo-content/remove', $route['path']);
    $this->assertSame(['POST'], $route['methods']);
    $this->assertSame('TRUE', $route['requirements']['_user_is_logged_in']);
    $this->assertSame('TRUE', $route['requirements']['_csrf_request_header_token']);
    $this->assertSame('json', $route['requirements']['_format']);
    $this->assertSame(['cookie'], $route['options']['_auth']);
    $this->assertSame(ReportStatsController::class . '::removeDemoContent', ltrim($route['defaults']['_controller'], '\\'));
  }

  /**
   * Calls the controller as the given account.
   *
   * @return array{status: int, body: mixed}
   *   The response status and decoded body.
   */
  private function post(AccountInterface $account, array $body): array {
    $this->container->get('current_user')->setAccount($account);
    $controller = ReportStatsController::create($this->container);
    $request = Request::create('/api/fastmap/demo-content/remove', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    $response = $controller->removeDemoContent($request);
    return [
      'status' => $response->getStatusCode(),
      'body' => json_decode((string) $response->getContent(), TRUE),
    ];
  }

  /**
   * Creates a published, permanent jurisdiction workspace.
   */
  private function createWorkspace(string $label, string $slug): GroupInterface {
    $group = Group::create(['type' => 'jur', 'label' => $label, 'field_slug' => $slug, 'field_tier' => 'pro']);
    $group->save();
    return $group;
  }

  /**
   * Creates a service request in a workspace.
   */
  private function createRequest(GroupInterface $workspace, int $uid, string $body): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'uid' => $uid,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'field_jurisdiction' => $workspace->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates an active user.
   */
  private function createUser(string $name): UserInterface {
    $user = User::create(['name' => $name, 'mail' => $name . '@example.test', 'status' => 1]);
    $user->save();
    return $user;
  }

  /**
   * Creates one configurable field and its storage.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = []): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'settings' => $storageSettings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
    ])->save();
  }

}
