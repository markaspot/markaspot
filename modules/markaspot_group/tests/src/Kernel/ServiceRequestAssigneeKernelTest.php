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
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
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
    'entity_reference_revisions',
    // Paragraphs (audit remark coverage) requires the file module's
    // file.usage service at container build time.
    'file',
    'paragraphs',
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
   * Second organisation group under test.
   */
  private Group $parksOrganisation;

  /**
   * Third organisation group under test.
   */
  private Group $trafficOrganisation;

  /**
   * Service request under test.
   */
  private Node $request;

  /**
   * The Drupal superuser (uid 1), reserved and excluded from candidates.
   */
  private UserInterface $superUser;

  /**
   * Organisation member under test.
   */
  private UserInterface $orgMember;

  /**
   * Second organisation member under test.
   */
  private UserInterface $parksMember;

  /**
   * Multi-organisation member under test.
   */
  private UserInterface $multiOrgMember;

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
    $this->installEntitySchema('paragraph');
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
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('node', 'service_request', 'field_organisation', 'entity_reference', ['target_type' => 'group'], -1);
    $this->createField('node', 'service_request', 'field_assignee', 'entity_reference', ['target_type' => 'user']);
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    ParagraphsType::create(['id' => 'internal_remark', 'label' => 'Internal remark'])->save();
    $this->createField('paragraph', 'internal_remark', 'field_internal_remark_text', 'text_long');
    $this->createField('node', 'service_request', 'field_internal_remark', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    // Reserve uid 1 for the Drupal superuser: it is auto-joined to every group
    // and must never surface as an assignment candidate. Creating it first
    // keeps the real fixtures on uid >= 2, mirroring production.
    $this->superUser = $this->createUser([], 'root', FALSE, ['mail' => 'root@example.test']);
    $this->orgMember = $this->createUser([], 'org-member', FALSE, ['mail' => 'org@example.test']);
    $this->parksMember = $this->createUser([], 'parks-member', FALSE, ['mail' => 'parks@example.test']);
    $this->multiOrgMember = $this->createUser([], 'multi-org-member', FALSE, ['mail' => 'multi-org@example.test']);
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
    $this->parksOrganisation = Group::create([
      'type' => 'org',
      'label' => 'Parks',
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $this->parksOrganisation->save();
    $this->trafficOrganisation = Group::create([
      'type' => 'org',
      'label' => 'Traffic',
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $this->trafficOrganisation->save();

    $this->jurisdiction->addMember($this->jurMember);
    $this->jurisdiction->addMember($this->assigner);
    $this->organisation->addMember($this->orgMember);
    $this->organisation->addMember($this->multiOrgMember);
    $this->parksOrganisation->addMember($this->parksMember);
    $this->parksOrganisation->addMember($this->multiOrgMember);
    $this->organisation->addMember($this->blockedOrgMember);
    // The superuser is a member of the org too (mirrors the auto-join on group
    // creation in production) but must be filtered out of the candidate list.
    $this->organisation->addMember($this->superUser);

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
        'organisations' => [],
      ],
      [
        'id' => $this->jurMember->uuid(),
        'uid' => (int) $this->jurMember->id(),
        'label' => 'jur-member',
        'scope' => 'jur',
        'organisations' => [],
      ],
      [
        'id' => $this->multiOrgMember->uuid(),
        'uid' => (int) $this->multiOrgMember->id(),
        'label' => 'multi-org-member',
        'scope' => 'org',
        'organisations' => [
          [
            'id' => $this->parksOrganisation->uuid(),
            'gid' => (int) $this->parksOrganisation->id(),
            'label' => 'Parks',
          ],
          [
            'id' => $this->organisation->uuid(),
            'gid' => (int) $this->organisation->id(),
            'label' => 'Public Works',
          ],
        ],
      ],
      [
        'id' => $this->orgMember->uuid(),
        'uid' => (int) $this->orgMember->id(),
        'label' => 'org-member',
        'scope' => 'org',
        'organisations' => [
          [
            'id' => $this->organisation->uuid(),
            'gid' => (int) $this->organisation->id(),
            'label' => 'Public Works',
          ],
        ],
      ],
      // parks-member is auto-joined to the jurisdiction via the org->jur hook
      // and therefore a legitimate jur-scope candidate with their Parks org.
      [
        'id' => $this->parksMember->uuid(),
        'uid' => (int) $this->parksMember->id(),
        'label' => 'parks-member',
        'scope' => 'jur',
        'organisations' => [
          [
            'id' => $this->parksOrganisation->uuid(),
            'gid' => (int) $this->parksOrganisation->id(),
            'label' => 'Parks',
          ],
        ],
      ],
    ], $data['candidates']);

    // The superuser (uid 1) is an org member but must never be a candidate.
    $candidate_uids = array_map(static fn(array $c): int => (int) $c['uid'], $data['candidates']);
    $this->assertNotContains(1, $candidate_uids);
  }

  /**
   * AssignmentSyncsOrganisation moves responsibility for a single-org user.
   */
  public function testAssignmentSyncsOrganisationFollowsSingleAssigneeOrganisation(): void {
    $this->setAssignmentSyncsOrganisation(TRUE);
    $request = $this->createServiceRequest($this->organisation, $this->orgMember);

    $request->set('field_assignee', $this->parksMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame([(int) $this->parksOrganisation->id()], $this->organisationIds($request));
    $this->assertSame([(int) $this->parksOrganisation->id()], $this->organisationRelationshipIds($request));
    $this->assertSame(
      'Zuständigkeit folgt Zuweisung an parks-member: Parks.',
      $this->latestInternalRemarkText($request),
    );
  }

  /**
   * AssignmentSyncsOrganisation keeps responsibility when already compatible.
   */
  public function testAssignmentSyncsOrganisationKeepsCurrentAssigneeOrganisation(): void {
    $this->setAssignmentSyncsOrganisation(TRUE);
    $request = $this->createServiceRequest($this->organisation);

    $request->set('field_assignee', $this->orgMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * AssignmentSyncsOrganisation does not guess between multiple orgs.
   */
  public function testAssignmentSyncsOrganisationLeavesMultipleAssigneeOrganisationsUnchanged(): void {
    $this->setAssignmentSyncsOrganisation(TRUE);
    $request = $this->createServiceRequest($this->trafficOrganisation);

    $request->set('field_assignee', $this->multiOrgMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame([(int) $this->trafficOrganisation->id()], $this->organisationIds($request));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * AssignmentSyncsOrganisation ignores sibling child-jurisdiction orgs.
   */
  public function testAssignmentSyncsOrganisationLeavesSiblingChildOrganisationUnchanged(): void {
    $this->setAssignmentSyncsOrganisation(TRUE);

    $child = Group::create([
      'type' => 'jur',
      'label' => 'Child Jurisdiction',
      'field_parent_jurisdiction' => $this->jurisdiction->id(),
      'field_tier' => 'pro',
    ]);
    $child->save();
    // A foreign ROOT jurisdiction: orgs may only reference root jurisdictions
    // (OrgParentReference constraint), so the foreign-tree scenario must live
    // under its own root, not under a child of ours.
    $sibling = Group::create([
      'type' => 'jur',
      'label' => 'Sibling Jurisdiction',
      'field_tier' => 'pro',
    ]);
    $sibling->save();
    $siblingOrganisation = Group::create([
      'type' => 'org',
      'label' => 'Sibling Org',
      'field_jurisdiction' => $sibling->id(),
    ]);
    $siblingOrganisation->save();
    $siblingMember = $this->createUser([], 'sibling-member', FALSE, ['mail' => 'sibling@example.test']);
    $siblingOrganisation->addMember($siblingMember);

    $request = Node::create([
      'type' => 'service_request',
      'title' => 'Child request',
      'status' => 1,
      'field_jurisdiction' => $child->id(),
      'field_organisation' => [$this->organisation->id()],
    ]);
    $request->save();
    $request = $this->reloadNode($request);

    $request->set('field_assignee', $siblingMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * AssignmentSyncsOrganisation leaves responsibility unchanged when disabled.
   */
  public function testAssignmentSyncsOrganisationDisabledLeavesOrganisationUnchanged(): void {
    $this->setAssignmentSyncsOrganisation(FALSE);
    $request = $this->createServiceRequest($this->organisation, $this->orgMember);

    $request->set('field_assignee', $this->parksMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * Unassigning a request never changes responsibility.
   */
  public function testAssignmentSyncsOrganisationUnassignLeavesOrganisationUnchanged(): void {
    $this->setAssignmentSyncsOrganisation(TRUE);
    $request = $this->createServiceRequest($this->organisation, $this->orgMember);

    $request->set('field_assignee', NULL);
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * Client-supplied assignee and organisation changes still leave an audit.
   */
  public function testAssignmentSyncsOrganisationAuditsClientSuppliedOrganisationChange(): void {
    $this->setAssignmentSyncsOrganisation(TRUE);
    $request = $this->createServiceRequest($this->organisation, $this->orgMember);

    $request->set('field_assignee', $this->parksMember->id());
    $request->set('field_organisation', ['target_id' => (int) $this->parksOrganisation->id()]);
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame([(int) $this->parksOrganisation->id()], $this->organisationIds($request));
    $this->assertSame(
      'Zuständigkeit folgt Zuweisung an parks-member: Parks.',
      $this->latestInternalRemarkText($request),
    );
  }

  /**
   * Sets the tenant assignmentSyncsOrganisation feature flag.
   */
  private function setAssignmentSyncsOrganisation(bool $enabled): void {
    $this->jurisdiction->set('field_nuxt_config', json_encode([
      'features' => [
        'assignmentSyncsOrganisation' => $enabled,
      ],
    ]));
    $this->jurisdiction->save();
    $this->container->get('entity_type.manager')
      ->getStorage('group')
      ->resetCache([(int) $this->jurisdiction->id()]);
    $this->jurisdiction = Group::load($this->jurisdiction->id());
    $this->assertInstanceOf(Group::class, $this->jurisdiction);
  }

  /**
   * Creates a service request with one responsible organisation.
   */
  private function createServiceRequest(Group $organisation, ?UserInterface $assignee = NULL): Node {
    $values = [
      'type' => 'service_request',
      'title' => 'Assignment sync request',
      'status' => 1,
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_organisation' => [$organisation->id()],
    ];
    if ($assignee instanceof UserInterface) {
      $values['field_assignee'] = $assignee->id();
    }

    $request = Node::create($values);
    $request->save();

    return $this->reloadNode($request);
  }

  /**
   * Reloads a node from storage.
   */
  private function reloadNode(Node $node): Node {
    $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->resetCache([(int) $node->id()]);
    $reloaded = Node::load($node->id());
    $this->assertInstanceOf(Node::class, $reloaded);

    return $reloaded;
  }

  /**
   * Returns the node field_organisation group IDs.
   *
   * @return int[]
   *   Organisation group IDs.
   */
  private function organisationIds(Node $node): array {
    $ids = array_map('intval', array_column($node->get('field_organisation')->getValue(), 'target_id'));
    sort($ids, SORT_NUMERIC);

    return $ids;
  }

  /**
   * Returns org group relationships for a request.
   *
   * @return int[]
   *   Organisation group IDs.
   */
  private function organisationRelationshipIds(Node $node): array {
    $relationships = $this->container->get('entity_type.manager')
      ->getStorage('group_relationship')
      ->loadByProperties([
        'entity_id' => $node->id(),
        'plugin_id' => 'group_node:service_request',
      ]);

    $ids = [];
    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if ($group instanceof Group && $group->bundle() === 'org') {
        $ids[] = (int) $group->id();
      }
    }
    sort($ids, SORT_NUMERIC);

    return $ids;
  }

  /**
   * Returns the latest internal remark text.
   */
  private function latestInternalRemarkText(Node $node): ?string {
    $items = $node->get('field_internal_remark')->getValue();
    if ($items === []) {
      return NULL;
    }

    $last = end($items);
    $paragraph = Paragraph::load((int) $last['target_id']);
    $this->assertInstanceOf(Paragraph::class, $paragraph);

    return (string) $paragraph->get('field_internal_remark_text')->value;
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
