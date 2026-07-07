<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Controller\RequestAssigneesController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests per-user service request assignment access and validation.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class ServiceRequestAssigneeKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'options',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
  ];

  /**
   * Jurisdiction group under test.
   */
  private Group $jurisdiction;

  /**
   * Organisation group under test.
   */
  private Group $organisation;

  /**
   * Service request under test.
   */
  private Node $request;

  /**
   * Organisation member under test.
   */
  private UserInterface $orgMember;

  /**
   * Jurisdiction member under test.
   */
  private UserInterface $jurMember;

  /**
   * Foreign user under test.
   */
  private UserInterface $foreignUser;

  /**
   * Foreign assigning user under test.
   */
  private UserInterface $foreignAssigner;

  /**
   * Blocked organisation member under test.
   */
  private UserInterface $blockedOrgMember;

  /**
   * Assigning user under test.
   */
  private UserInterface $assigner;

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
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    GroupRole::create([
      'id' => 'jur-org_member',
      'label' => 'Organisation member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    $this->ensureRelationshipType('jur', 'group_membership');
    $this->ensureRelationshipType('org', 'group_membership');
    $this->ensureRelationshipType('jur', 'group_node:service_request');
    $this->ensureRelationshipType('org', 'group_node:service_request');

    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_tier', 'list_string', [
      'allowed_values' => [
        'free' => 'Free',
        'starter' => 'Starter',
        'pro' => 'Pro',
        'heart' => 'Heart',
      ],
    ]);
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('node', 'service_request', 'field_organisation', 'entity_reference', ['target_type' => 'group'], -1);
    $this->createField('node', 'service_request', 'field_assignee', 'entity_reference', ['target_type' => 'user']);
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->orgMember = $this->createUser([], 'org-member', FALSE, ['mail' => 'org@example.test']);
    $this->jurMember = $this->createUser([], 'jur-member', FALSE, ['mail' => 'jur@example.test']);
    $this->foreignUser = $this->createUser(['access content'], 'foreign', FALSE, ['mail' => 'foreign@example.test']);
    $this->foreignAssigner = $this->createUser(['assign service requests', 'access content'], 'foreign-assigner', FALSE, ['mail' => 'foreign-assign@example.test']);
    $this->blockedOrgMember = $this->createUser([], 'blocked-org-member', FALSE, [
      'mail' => 'blocked-org@example.test',
      'status' => 0,
    ]);
    $this->assigner = $this->createUser(['assign service requests', 'access content'], 'assigner', FALSE, ['mail' => 'assign@example.test']);

    $this->jurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Demo Jurisdiction',
      'field_tier' => 'pro',
    ]);
    $this->jurisdiction->save();
    $this->organisation = Group::create([
      'type' => 'org',
      'label' => 'Public Works',
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $this->organisation->save();

    $this->jurisdiction->addMember($this->jurMember);
    $this->jurisdiction->addMember($this->assigner);
    $this->organisation->addMember($this->orgMember);
    $this->organisation->addMember($this->blockedOrgMember);

    $this->request = Node::create([
      'type' => 'service_request',
      'title' => 'Broken light',
      'status' => 1,
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_organisation' => [$this->organisation->id()],
      'field_assignee' => $this->orgMember->id(),
    ]);
    $this->request->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Anonymous and foreign accounts cannot view field_assignee.
   */
  public function testAssigneeViewAccessIsDeniedForAnonymousAndForeignAccounts(): void {
    $this->assertFalse($this->request->get('field_assignee')->access('view', new AnonymousUserSession()));
    $this->assertFalse($this->request->get('field_assignee')->access('view', $this->foreignUser));
    $this->assertFalse($this->request->get('field_assignee')->access('view', $this->foreignAssigner));
  }

  /**
   * Jurisdiction members can view field_assignee.
   */
  public function testAssigneeViewAccessAllowsJurisdictionMember(): void {
    $this->assertTrue($this->request->get('field_assignee')->access('view', $this->jurMember));
  }

  /**
   * Assignee label view access follows the assignee reference access rules.
   */
  public function testAssigneeLabelViewAccessMirrorsAssigneeViewAccess(): void {
    $allowed_access = $this->request->get('assignee_label')->access('view', $this->jurMember, TRUE);
    $this->assertTrue($allowed_access->isAllowed());
    $this->assertContains('user:' . $this->orgMember->id(), $allowed_access->getCacheTags());

    $forbidden_access = $this->request->get('assignee_label')->access('view', $this->foreignUser, TRUE);
    $this->assertTrue($forbidden_access->isForbidden());
    $this->assertNotContains('user:' . $this->orgMember->id(), $forbidden_access->getCacheTags());
    $this->assertFalse($this->request->get('assignee_label')->access('view', new AnonymousUserSession()));
  }

  /**
   * Assignee label exposes the assigned user's display name.
   */
  public function testAssigneeLabelValueUsesAssigneeDisplayName(): void {
    $this->assertTrue($this->request->hasField('assignee_label'));
    $this->assertSame('org-member', $this->request->get('assignee_label')->value);

    $unassigned = Node::create([
      'type' => 'service_request',
      'title' => 'Unassigned request',
      'status' => 1,
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_organisation' => [$this->organisation->id()],
    ]);
    $this->assertTrue($unassigned->get('assignee_label')->isEmpty());
  }

  /**
   * Assignee label is computed and cannot be edited.
   */
  public function testAssigneeLabelEditAccessIsAlwaysDenied(): void {
    $this->assertFalse($this->request->get('assignee_label')->access('edit', $this->assigner));
    $this->assertFalse($this->request->get('assignee_label')->access('edit', $this->jurMember));
  }

  /**
   * Assignment edits require permission and an enabled tier.
   */
  public function testAssigneeEditAccessRequiresPermissionAndTier(): void {
    $this->assertFalse($this->request->get('field_assignee')->access('edit', $this->jurMember));
    $this->assertFalse($this->request->get('field_assignee')->access('edit', $this->foreignAssigner));
    $this->assertTrue($this->request->get('field_assignee')->access('edit', $this->assigner));

    $this->jurisdiction->set('field_tier', 'starter');
    $this->jurisdiction->save();
    $this->reloadRequest();

    $this->assertFalse($this->request->get('field_assignee')->access('edit', $this->assigner));
  }

  /**
   * Assignee validation enforces org/jurisdiction membership and allows empty.
   */
  public function testAssigneeConstraintValidatesMembershipScope(): void {
    $this->request->set('field_assignee', $this->foreignUser->id());
    $violations = $this->request->validate();
    $this->assertGreaterThan(0, $violations->count());
    $this->assertSame(
      'The selected assignee must be a member of the assigned organisation or jurisdiction.',
      (string) $violations->get(0)->getMessage(),
    );

    $this->request->set('field_assignee', $this->orgMember->id());
    $this->assertSame(0, $this->request->validate()->count());

    $this->request->set('field_assignee', $this->blockedOrgMember->id());
    $this->assertGreaterThan(0, $this->request->validate()->count());

    $this->request->set('field_assignee', NULL);
    $this->assertSame(0, $this->request->validate()->count());
  }

  /**
   * The request-assignees route denies accounts without the assign permission.
   */
  public function testRequestAssigneesRouteRequiresAssignPermission(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('markaspot_group.request_assignees');

    $this->assertSame('assign service requests', $route->getRequirement('_permission'));
    $this->assertSame('node.view', $route->getRequirement('_entity_access'));

    $access = $this->container->get('access_manager')
      ->checkNamedRoute(
        'markaspot_group.request_assignees',
        ['node' => $this->request->id()],
        $this->foreignUser,
        TRUE,
      );
    $this->assertFalse($access->isAllowed());
  }

  /**
   * JSON:API field_assignee filters require assignment permission.
   */
  public function testAssigneeJsonApiFilterAccessRequiresAssignPermission(): void {
    $field_definition = $this->request->get('field_assignee')->getFieldDefinition();

    $allowed_access = markaspot_group_jsonapi_entity_field_filter_access(
      $field_definition,
      $this->assigner,
    );
    $this->assertTrue($allowed_access->isAllowed());
    $this->assertContains('user.permissions', $allowed_access->getCacheContexts());

    $forbidden_access = markaspot_group_jsonapi_entity_field_filter_access(
      $field_definition,
      $this->foreignUser,
    );
    $this->assertTrue($forbidden_access->isForbidden());
    $this->assertContains('user.permissions', $forbidden_access->getCacheContexts());

    $anonymous_access = markaspot_group_jsonapi_entity_field_filter_access(
      $field_definition,
      new AnonymousUserSession(),
    );
    $this->assertTrue($anonymous_access->isForbidden());
    $this->assertContains('user.permissions', $anonymous_access->getCacheContexts());
  }

  /**
   * JSON:API filter fallback access fails closed without entity context.
   */
  public function testAssigneeFieldAccessWithoutItemsForJsonApiFilterFallbackIsForbidden(): void {
    $field_definition = $this->request->get('field_assignee')->getFieldDefinition();

    // Guards the JSON:API filter fallback path; field_permissions public would
    // otherwise open it.
    $access = markaspot_group_entity_field_access(
      'view',
      $field_definition,
      new AnonymousUserSession(),
      NULL,
    );

    $this->assertTrue($access->isForbidden());
  }

  /**
   * The request-assignees controller returns current and scoped candidates.
   */
  public function testRequestAssigneesControllerReturnsCandidateShape(): void {
    $this->container->get('current_user')->setAccount($this->foreignAssigner);
    $controller = RequestAssigneesController::create($this->container);
    $response = $controller->assignees($this->request);
    $this->assertSame(403, $response->getStatusCode());

    $this->container->get('current_user')->setAccount($this->assigner);
    $response = $controller->assignees($this->request);
    $this->assertSame(200, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame((int) $this->orgMember->id(), $data['current']['uid']);
    $this->assertSame($this->orgMember->uuid(), $data['current']['id']);
    $this->assertSame('org-member', $data['current']['label']);

    $this->assertSame([
      [
        'id' => $this->assigner->uuid(),
        'uid' => (int) $this->assigner->id(),
        'label' => 'assigner',
        'scope' => 'jur',
      ],
      [
        'id' => $this->jurMember->uuid(),
        'uid' => (int) $this->jurMember->id(),
        'label' => 'jur-member',
        'scope' => 'jur',
      ],
      [
        'id' => $this->orgMember->uuid(),
        'uid' => (int) $this->orgMember->id(),
        'label' => 'org-member',
        'scope' => 'org',
      ],
    ], $data['candidates']);
  }

  /**
   * Creates a config field when missing.
   */
  private function createField(string $entity_type, string $bundle, string $field_name, string $type, array $settings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $settings,
      ])->save();
    }

    if (!FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $field_name,
        'required' => FALSE,
        'settings' => $settings,
      ])->save();
    }
  }

  /**
   * Ensures a group relationship type exists for a plugin.
   */
  private function ensureRelationshipType(string $group_type, string $plugin_id): void {
    $id = $group_type . '-' . str_replace(':', '-', $plugin_id);
    $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if ($storage->load($id)) {
      return;
    }
    $storage->createFromPlugin(GroupType::load($group_type), $plugin_id)->save();
  }

  /**
   * Reloads the service request under test.
   */
  private function reloadRequest(): void {
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([(int) $this->request->id()]);
    $this->request = Node::load($this->request->id());
    $this->assertInstanceOf(Node::class, $this->request);
  }

}
