<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Symfony\Component\HttpFoundation\Response;
use Drupal\service_request\RevisionLog;
use Symfony\Component\Routing\Route;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\service_request\Plugin\Action\OrganisationAwareEmailAction;
use Drupal\Core\Form\FormState;
use Drupal\service_request\OrganisationNotificationPolicy;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Controller\RequestAssigneesController;
use Drupal\markaspot_group\Controller\RequestResponsibilityController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

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
    // Paragraphs (internal remark separation coverage) requires the file
    // module's file.usage service at container build time.
    'file',
    'paragraphs',
    'markaspot_validation',
    'markaspot_group',
    'service_request',
    'serialization',
    'jsonapi',
    'jsonapi_extras',
    'jsonapi_resources',
    'basic_auth',
    'markaspot_nuxt',
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
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
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
    $this->createField('group', 'org', 'field_parent_org', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'org', 'field_org_code', 'string', [
      'max_length' => 16,
      'is_ascii' => FALSE,
      'case_sensitive' => FALSE,
    ]);
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('node', 'service_request', 'field_organisation', 'entity_reference', ['target_type' => 'group'], -1);
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'node']);
    $this->createField('node', 'service_request', 'field_assignee', 'entity_reference', ['target_type' => 'user']);
    $this->createField('node', 'service_request', 'field_assigned_team', 'entity_reference', ['target_type' => 'group']);
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
      'field_org_code' => '66',
    ]);
    $this->organisation->save();
    $this->parksOrganisation = Group::create([
      'type' => 'org',
      'label' => 'Parks',
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_parent_org' => $this->organisation->id(),
    ]);
    $this->parksOrganisation->save();
    $this->trafficOrganisation = Group::create([
      'type' => 'org',
      'label' => 'Traffic',
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_parent_org' => $this->parksOrganisation->id(),
    ]);
    $this->trafficOrganisation->save();

    $this->jurisdiction->addMember($this->jurMember);
    $this->jurisdiction->addMember($this->assigner);
    $this->organisation->addMember($this->orgMember);
    $this->organisation->addMember($this->multiOrgMember);
    $this->parksOrganisation->addMember($this->parksMember);
    $this->parksOrganisation->addMember($this->multiOrgMember);
    $this->trafficOrganisation->addMember($this->multiOrgMember);
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
   * Real JSON:API history enforces current and historical organisation scope.
   */
  public function testJsonApiSnapshotsCannotCrossSiblingOrganisations(): void {
    $this->createField('node', 'service_request', 'field_e_mail', 'email');
    $email = FieldStorageConfig::loadByName('node', 'field_e_mail');
    $email->setThirdPartySetting('field_permissions', 'permission_type', 'custom')->save();
    FieldStorageConfig::loadByName('node', 'field_organisation')->setThirdPartySetting('field_permissions', 'permission_type', 'custom')->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->request = $this->reloadNode($this->request);
    foreach (['jur', 'org'] as $bundle) {
      foreach (['insider', 'outsider'] as $scope) {
        GroupRole::create([
          'id' => $bundle . '-http-' . $scope, 'label' => 'Published report reader',
          'group_type' => $bundle, 'scope' => $scope, 'global_role' => 'authenticated',
          'permissions' => ['view group_node:service_request entity'],
        ])->save();
      }
    }
    $permissions = [
      'access content', 'edit any service_request content',
      'assign service requests', 'view field_e_mail', 'view field_organisation',
    ];
    $a = $this->createUser($permissions, 'http-org-a');
    $b = $this->createUser($permissions, 'http-org-b');
    $both = $this->createUser($permissions, 'http-both-orgs');
    $elevated = $this->createUser($permissions, 'http-jur-moderator');
    $this->organisation->addMember($a);
    $this->parksOrganisation->addMember($b);
    $this->organisation->addMember($both);
    $this->parksOrganisation->addMember($both);
    GroupRole::create(['id' => 'jur-moderator', 'label' => 'Moderator', 'group_type' => 'jur', 'scope' => 'individual'])->save();
    $this->jurisdiction->addMember($elevated, ['group_roles' => ['jur-moderator']]);
    foreach ([$a, $b, $both, $elevated] as $staff) {
      $staff->setPassword('synthetic-org-scope-password')->save();
    }
    $this->container->get('current_user')->setAccount($this->superUser);
    $this->request->set('field_e_mail', 'org-a-citizen@example.invalid');
    RevisionLog::setMessage($this->request, 'ORG-A-PRIVATE-REASON');
    $this->request->save();
    $old_vid = (int) $this->request->getRevisionId();
    $this->request->setTitle('Later A state')->save();
    $current_vid = (int) $this->request->getRevisionId();
    $this->container->get('router.builder')->rebuild();
    $base = '/jsonapi/node/service_request/' . $this->request->uuid();
    $fetch = function (UserInterface $staff, string $path): Response {
      $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
      return $this->container->get('http_kernel')->handle(Request::create($path, 'GET', server: [
        'HTTP_ACCEPT' => 'application/vnd.api+json',
        'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($staff->getAccountName() . ':synthetic-org-scope-password'),
        'PHP_AUTH_USER' => $staff->getAccountName(), 'PHP_AUTH_PW' => 'synthetic-org-scope-password',
      ]));
    };
    foreach ([$old_vid, $current_vid] as $vid) {
      $denied = $fetch($b, $base . '?resourceVersion=id:' . $vid);
      $this->assertSame(403, $denied->getStatusCode(), (string) $denied->getContent());
      $this->assertStringNotContainsString('org-a-citizen@example.invalid', (string) $denied->getContent());
      foreach ([$a, $elevated] as $reader) {
        $snapshot = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($vid);
        $this->assertTrue($snapshot->get('field_assignee')->access('view', $reader), $reader->getAccountName() . ' assignment scope');
        $access = $this->container->get('markaspot_nuxt.version_history_access_check')->accessNode($snapshot, $reader);
        $this->assertTrue($access->isAllowed(), $reader->getAccountName() . ' history scope: ' . json_encode([
          'permission' => $reader->hasPermission('edit any service_request content'),
          'enterprise' => $this->container->get('markaspot_nuxt.feature_scope_resolver')->allowsEnterpriseFeatures(),
          'current_view' => $this->reloadNode($this->request)->access('view', $reader),
          'current_assignee' => $this->reloadNode($this->request)->get('field_assignee')->access('view', $reader),
        ]));
        $allowed = $fetch($reader, $base . '?resourceVersion=id:' . $vid);
        $this->assertSame(200, $allowed->getStatusCode(), $reader->getAccountName() . ' VID ' . $vid . ': ' . $allowed->getContent());
      }
    }
    $this->assertSame(403, $fetch($b, $base . '/version-history')->getStatusCode());
    $probe = $fetch($b, '/jsonapi/node/service_request?fields[node--service_request]=title&filter[field_organisation.meta.drupal_internal__target_id]=' . $this->organisation->id());
    $this->assertSame(200, $probe->getStatusCode(), (string) $probe->getContent());
    $this->assertSame([], json_decode((string) $probe->getContent(), TRUE)['data']);
    $assignee_probe = $fetch($b, '/jsonapi/node/service_request?fields[node--service_request]=title&filter[field_assignee.meta.drupal_internal__target_id]=' . $this->orgMember->id());
    $this->assertSame(200, $assignee_probe->getStatusCode(), (string) $assignee_probe->getContent());
    $this->assertSame([], json_decode((string) $assignee_probe->getContent(), TRUE)['data']);
    $public = $fetch($b, $base . '?fields[node--service_request]=title');
    $this->assertSame(200, $public->getStatusCode(), (string) $public->getContent());
    $this->assertStringNotContainsString('org-a-citizen@example.invalid', (string) $public->getContent());
    $private = $fetch($b, $base . '?fields[node--service_request]=field_e_mail');
    $this->assertStringNotContainsString('org-a-citizen@example.invalid', (string) $private->getContent());
    $this->container->get('current_user')->setAccount($this->superUser);
    $this->request->set('field_organisation', $this->parksOrganisation->id())->save();
    foreach ([$a, $b] as $reader) {
      $this->assertSame(403, $fetch($reader, $base . '?resourceVersion=id:' . $old_vid)->getStatusCode());
    }
    foreach ([$both, $elevated] as $reader) {
      $response = $fetch($reader, $base . '?resourceVersion=id:' . $old_vid);
      $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }
    $history = $fetch($b, $base . '/version-history');
    $this->assertSame(200, $history->getStatusCode(), (string) $history->getContent());
    $this->assertStringNotContainsString('ORG-A-PRIVATE-REASON', (string) $history->getContent());
    $items = json_decode((string) $history->getContent(), TRUE)['data'];
    $this->assertNotContains($old_vid, array_column(array_column($items, 'attributes'), 'vid'));
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
   * The personal opt-out preserves teams, existing assignments and removals.
   */
  public function testPersonalAssignmentOptOut(): void {
    $this->container->get('current_user')->setAccount($this->assigner);
    $this->assertTrue(\_markaspot_group_personal_assignment_enabled($this->request));
    $this->jurisdiction->set('field_nuxt_config', json_encode(['features' => ['personalAssignment' => FALSE]]))->save();
    $this->request = $this->reloadNode($this->request);
    $this->assertFalse(\_markaspot_group_personal_assignment_enabled($this->request));
    $response = RequestAssigneesController::create($this->container)->assignees($this->request);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertFalse($data['personal_assignment_enabled']);
    $this->assertSame([], $data['candidates']);
    $this->assertNotEmpty($data['units']);
    $this->assertSame($this->orgMember->uuid(), $data['current']['id']);

    $this->request->setTitle('Unrelated edit keeps the historic assignment');
    $this->request->save();
    $this->request->set('field_assignee', NULL);
    $this->request->set('field_assigned_team', $this->parksOrganisation->id());
    $this->request->save();
    $this->assertTrue($this->request->get('field_assignee')->isEmpty());
    $this->assertSame((int) $this->parksOrganisation->id(), (int) $this->request->get('field_assigned_team')->target_id);
    $this->request->set('field_assignee', $this->orgMember->id());
    $this->assertStringContainsString('Personal assignment is disabled', (string) $this->request->validate());
    $this->expectException(EntityStorageException::class);
    $this->request->save();
  }

  /**
   * An existing assignee is not grandfathered across tenant roots.
   */
  public function testPersonalAssignmentExistingPersonCannotCrossTenantRoot(): void {
    $this->assertFalse($this->request->get('field_assignee')->isEmpty());
    $this->assertTrue(\_markaspot_group_personal_assignment_is_unchanged($this->request));
    $other = Group::create([
      'type' => 'jur',
      'label' => 'Other tenant root',
      'field_nuxt_config' => json_encode(['features' => ['personalAssignment' => FALSE]]),
    ]);
    $other->save();
    $this->request->set('field_jurisdiction', $other->id());
    $this->assertFalse(\_markaspot_group_personal_assignment_is_unchanged($this->request));
    $this->assertFalse(\_markaspot_group_personal_assignment_enabled($this->request));
  }

  /**
   * A persisted orphan assignment remains editable without widening its scope.
   */
  public function testUnresolvedLegacyScopeKeepsOnlyUnchangedAssignment(): void {
    $db = $this->container->get('database');
    foreach (['node__field_jurisdiction', 'node__field_organisation'] as $table) {
      $db->delete($table)->condition('entity_id', $this->request->id())->execute();
    }
    $request = $this->reloadNode($this->request);
    $this->assertTrue(\_markaspot_group_personal_assignment_is_unchanged($request));
    $request->setTitle('Unrelated legacy correction');
    $request->save();
    $this->assertSame((int) $this->orgMember->id(), (int) $request->get('field_assignee')->target_id);
    $request->set('field_assignee', $this->parksMember->id());
    $this->assertFalse(\_markaspot_group_personal_assignment_is_unchanged($request));
    $this->expectException(EntityStorageException::class);
    $request->save();
  }

  /**
   * An organisation below a root cannot filter private sibling assignments.
   */
  public function testPrivateFilterUsesTheSameChildScopeAsFieldAccess(): void {
    $child = Group::create([
      'type' => 'jur',
      'label' => 'Child A',
      'field_parent_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $child->save();
    $sibling = Group::create([
      'type' => 'jur',
      'label' => 'Sibling B',
      'field_parent_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $sibling->save();
    $org = Group::create(['type' => 'org', 'label' => 'Child department', 'field_jurisdiction' => $child->id()]);
    $org->save();
    // Current writes normalise organisations to their root. Reproduce an
    // imported legacy child reference directly, without those repair hooks.
    $this->container->get('database')->update('group__field_jurisdiction')
      ->fields(['field_jurisdiction_target_id' => $child->id()])
      ->condition('entity_id', $org->id())->execute();
    $org = $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($org->id());
    $storage = FieldStorageConfig::loadByName('node', 'field_organisation');
    $storage->setThirdPartySetting('field_permissions', 'permission_type', 'custom')->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    drupal_static_reset('_markaspot_group_jurisdiction_group_count');
    $worker = $this->createUser(['access content', 'view field_organisation'], 'child-worker');
    $org->addMember($worker);
    // Legacy memberships may lack the automatically derived root relation.
    // The guard must still match the field-view boundary in that state.
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_relationship');
    $derived = $relationships->loadByProperties([
      'gid' => $this->jurisdiction->id(),
      'entity_id' => $worker->id(),
      'plugin_id' => 'group_membership',
    ]);
    $relationships->delete($derived);
    $relationships->resetCache();
    $this->container->get('cache.group_memberships_chained')->deleteAll();
    $broad_scope = \_markaspot_group_account_scope_jurisdiction_ids((int) $worker->id());
    $this->assertContains((int) $sibling->id(), $broad_scope, 'The old candidate helper included sibling scopes.');
    $private_scope = \_markaspot_group_private_field_scope_jurisdiction_ids($worker);
    $this->assertContains((int) $child->id(), $private_scope);
    $this->assertNotContains((int) $sibling->id(), $private_scope);
    $this->assertNotContains((int) $this->jurisdiction->id(), $private_scope);
    $published = Node::create([
      'type' => 'service_request',
      'title' => 'Public sibling report',
      'status' => 1,
      'field_jurisdiction' => $sibling->id(),
    ]);
    $published->save();
    $this->assertTrue($published->isPublished());
    $this->assertFalse($published->get('field_organisation')->access('view', $worker));
  }

  /**
   * The tenant opt-out also rejects initial personal assignments on creation.
   */
  public function testPersonalAssignmentDisabledOnCreate(): void {
    $this->jurisdiction->set('field_nuxt_config', json_encode(['features' => ['personalAssignment' => FALSE]]))->save();
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache();
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Disabled personal create',
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_organisation' => $this->organisation->id(),
      'field_assignee' => $this->orgMember->id(),
    ]);
    $this->assertStringContainsString('Personal assignment is disabled', (string) $node->validate());
    $this->expectException(EntityStorageException::class);
    $node->save();
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
    $this->assertSame((int) $this->orgMember->id(), (int) $this->request->get('field_assignee')->target_id);
    $this->assertSame(0, $allowed_access->getCacheMaxAge());
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

    $teamAssigned = Node::create([
      'type' => 'service_request',
      'title' => 'Team assigned request',
      'status' => 1,
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_organisation' => [$this->organisation->id()],
      'field_assigned_team' => $this->parksOrganisation->id(),
    ]);
    $teamAssigned->save();
    $teamAssigned = $this->reloadNode($teamAssigned);
    $this->assertSame('Parks', $teamAssigned->get('assignee_label')->value);
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
    $this->assertFalse($this->request->get('field_assigned_team')->access('edit', $this->jurMember));
    $this->assertFalse($this->request->get('field_assignee')->access('edit', $this->foreignAssigner));
    $this->assertFalse($this->request->get('field_assigned_team')->access('edit', $this->foreignAssigner));
    $this->assertTrue($this->request->get('field_assignee')->access('edit', $this->assigner));
    $this->assertTrue($this->request->get('field_assigned_team')->access('edit', $this->assigner));

    $this->jurisdiction->set('field_tier', 'starter');
    $this->jurisdiction->save();
    $this->reloadRequest();

    $this->assertFalse($this->request->get('field_assignee')->access('edit', $this->assigner));
    $this->assertFalse($this->request->get('field_assigned_team')->access('edit', $this->assigner));
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
   * Assigned team validation enforces request-jurisdiction scope.
   */
  public function testAssignedTeamConstraintRejectsForeignJurisdiction(): void {
    $foreignJurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Foreign Jurisdiction',
      'field_tier' => 'pro',
    ]);
    $foreignJurisdiction->save();
    $foreignOrganisation = Group::create([
      'type' => 'org',
      'label' => 'Foreign Works',
      'field_jurisdiction' => $foreignJurisdiction->id(),
    ]);
    $foreignOrganisation->save();

    $jurisdictionMessage = 'The selected team must belong to the service request jurisdiction.';

    // A team from a foreign jurisdiction triggers the jurisdiction constraint.
    $this->request->set('field_assigned_team', $foreignOrganisation->id());
    $foreignMessages = [];
    foreach ($this->request->validate() as $violation) {
      $foreignMessages[] = (string) $violation->getMessage();
    }
    $this->assertContains($jurisdictionMessage, $foreignMessages);

    // A team from the request's own jurisdiction does NOT. This bare kernel
    // setup installs no group view grants, so the orthogonal core
    // ValidReference constraint fires for any *new* org reference (the handler
    // returns no referenceable org groups) — field_organisation behaves
    // identically, and production grants make both referenceable. We therefore
    // assert on the jurisdiction constraint under test, not the total count.
    $this->request->set('field_assigned_team', $this->parksOrganisation->id());
    $validMessages = [];
    foreach ($this->request->validate() as $violation) {
      $validMessages[] = (string) $violation->getMessage();
    }
    $this->assertNotContains($jurisdictionMessage, $validMessages);
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
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $request->attributes->set('_route_object', new Route('/jsonapi/node/service_request', [
      '_is_jsonapi' => TRUE, 'resource_type' => 'node--service_request',
      '_controller' => 'jsonapi.entity_resource:getCollection',
    ]));
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
   * JSON:API field_assigned_team filters fail closed without request context.
   */
  public function testAssignedTeamJsonApiFilterAccessFailsClosed(): void {
    $field_definition = $this->request->get('field_assigned_team')->getFieldDefinition();

    $access = markaspot_group_jsonapi_entity_field_filter_access(
      $field_definition,
      $this->assigner,
    );

    $this->assertTrue($access->isForbidden());
    $this->assertContains('user.permissions', $access->getCacheContexts());
  }

  /**
   * JSON:API filter fallback access fails closed without entity context.
   */
  public function testAssigneeFieldAccessWithoutItemsForJsonApiFilterFallbackIsForbidden(): void {
    foreach (['field_assignee', 'field_assigned_team'] as $field_name) {
      $field_definition = $this->request->get($field_name)->getFieldDefinition();

      // Guards the JSON:API filter fallback path; field_permissions public
      // would otherwise open it.
      $access = markaspot_group_entity_field_access(
        'view',
        $field_definition,
        new AnonymousUserSession(),
        NULL,
      );

      $this->assertTrue($access->isForbidden());
    }
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
    $this->assertNull($data['current_team']);

    $this->assertSame([
      [
        'id' => $this->parksOrganisation->uuid(),
        'gid' => (int) $this->parksOrganisation->id(),
        'label' => 'Parks',
        'code' => '',
        'level' => 1,
        'path_labels' => ['Public Works'],
        'child_count' => 1,
      ],
      [
        'id' => $this->organisation->uuid(),
        'gid' => (int) $this->organisation->id(),
        'label' => 'Public Works',
        'code' => '66',
        'level' => 0,
        'path_labels' => [],
        'child_count' => 1,
      ],
      [
        'id' => $this->trafficOrganisation->uuid(),
        'gid' => (int) $this->trafficOrganisation->id(),
        'label' => 'Traffic',
        'code' => '',
        'level' => 2,
        'path_labels' => ['Public Works', 'Parks'],
        'child_count' => 0,
      ],
    ], $data['units']);

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
            'code' => '',
            'level' => 1,
            'path_labels' => ['Public Works'],
            'child_count' => 1,
          ],
          [
            'id' => $this->organisation->uuid(),
            'gid' => (int) $this->organisation->id(),
            'label' => 'Public Works',
            'code' => '66',
            'level' => 0,
            'path_labels' => [],
            'child_count' => 1,
          ],
          [
            'id' => $this->trafficOrganisation->uuid(),
            'gid' => (int) $this->trafficOrganisation->id(),
            'label' => 'Traffic',
            'code' => '',
            'level' => 2,
            'path_labels' => ['Public Works', 'Parks'],
            'child_count' => 0,
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
            'code' => '66',
            'level' => 0,
            'path_labels' => [],
            'child_count' => 1,
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
            'code' => '',
            'level' => 1,
            'path_labels' => ['Public Works'],
            'child_count' => 1,
          ],
        ],
      ],
    ], $data['candidates']);

    // The superuser (uid 1) is an org member but must never be a candidate.
    $candidate_uids = array_map(static fn(array $c): int => (int) $c['uid'], $data['candidates']);
    $this->assertNotContains(1, $candidate_uids);

    $teamRequest = $this->createServiceRequest($this->organisation);
    $teamRequest->set('field_assigned_team', $this->parksOrganisation->id());
    $teamRequest->save();
    $teamRequest = $this->reloadNode($teamRequest);
    $response = $controller->assignees($teamRequest);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame([
      'id' => $this->parksOrganisation->uuid(),
      'gid' => (int) $this->parksOrganisation->id(),
      'label' => 'Parks',
      'code' => '',
    ], $data['current_team']);
  }

  /**
   * Assigning a team clears the person assignment only.
   */
  public function testAssignedTeamClearsAssigneeAndKeepsOrganisation(): void {
    $request = $this->createServiceRequest($this->organisation, $this->orgMember);
    $previous_revision_id = (int) $request->getRevisionId();
    $this->container->get('current_user')->setAccount($this->assigner);

    $request->set('field_assigned_team', $this->parksOrganisation->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertTrue($request->get('field_assignee')->isEmpty());
    $this->assertSame((int) $this->parksOrganisation->id(), (int) $request->get('field_assigned_team')->target_id);
    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
    $this->assertSame([(int) $this->organisation->id()], $this->organisationRelationshipIds($request));
    $this->assertNotSame($previous_revision_id, (int) $request->getRevisionId());
    $this->assertSame((int) $this->assigner->id(), (int) $request->getRevisionUserId());
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * Switching teams records only the newly assigned team.
   */
  public function testAssignedTeamSwitchCreatesAttributedRevision(): void {
    $request = $this->createServiceRequest($this->organisation);
    $request->set('field_assigned_team', $this->parksOrganisation->id());
    $request->save();
    $request = $this->reloadNode($request);
    $previous_revision_id = (int) $request->getRevisionId();
    $this->container->get('current_user')->setAccount($this->assigner);

    $request->set('field_assigned_team', $this->trafficOrganisation->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertNotSame($previous_revision_id, (int) $request->getRevisionId());
    $this->assertSame((int) $this->assigner->id(), (int) $request->getRevisionUserId());
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * Removing a team assignment creates an attributed revision.
   */
  public function testAssignedTeamUnassignCreatesAttributedRevision(): void {
    $request = $this->createServiceRequest($this->organisation);
    $request->set('field_assigned_team', $this->parksOrganisation->id());
    $request->save();
    $request = $this->reloadNode($request);
    $previous_revision_id = (int) $request->getRevisionId();
    $this->container->get('current_user')->setAccount($this->assigner);

    $request->set('field_assigned_team', NULL);
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertNotSame($previous_revision_id, (int) $request->getRevisionId());
    $this->assertSame((int) $this->assigner->id(), (int) $request->getRevisionUserId());
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * Team assignment sends one mail and unchanged saves do not send another.
   */
  public function testAssignedTeamNotificationIsSentOnce(): void {
    $request = $this->createServiceRequest($this->organisation);
    $this->clearCollectedMails();

    $request->set('field_assigned_team', $this->trafficOrganisation->id());
    $request->set('field_organisation', $this->trafficOrganisation->id());
    $request->save();

    $mails = $this->collectedMails();
    $this->assertCount(1, $mails);
    $this->assertSame('markaspot_group', $mails[0]['module']);
    $this->assertSame('org_notification', $mails[0]['key']);
    $this->assertSame('multi-org@example.test', $mails[0]['to']);

    $request = $this->reloadNode($request);
    $request->save();
    $this->assertCount(1, $this->collectedMails());
  }

  /**
   * Legacy ECA does not suppress team mail without responsibility sync.
   */
  public function testAssignedTeamNotificationWithEcaAndNoResponsibilitySync(): void {
    $request = $this->createServiceRequest($this->organisation);
    $this->clearCollectedMails();
    $this->installLegacyOrganisationNotificationEca();

    $request->set('field_assigned_team', $this->trafficOrganisation->id());
    $request->save();

    $mails = $this->collectedMails();
    $this->assertCount(1, $mails);
    $this->assertSame('multi-org@example.test', $mails[0]['to']);
  }

  /**
   * Unrelated responsibility changes do not suppress assigned-team mail.
   */
  public function testAssignedTeamNotificationWithEcaAndDifferentOrganisation(): void {
    $request = $this->createServiceRequest($this->organisation);
    $this->clearCollectedMails();
    $this->setAssignmentSyncsOrganisation(TRUE);
    $this->installLegacyOrganisationNotificationEca();

    $request->set('field_assigned_team', $this->trafficOrganisation->id());
    $request->set('field_organisation', $this->parksOrganisation->id());
    $request->save();

    $mails = $this->collectedMails();
    $this->assertCount(1, $mails);
    $this->assertSame('multi-org@example.test', $mails[0]['to']);
  }

  /**
   * Legacy ECA owns mail when assignment responsibility sync is enabled.
   */
  public function testAssignedTeamNotificationDefersToEcaWithResponsibilitySync(): void {
    $request = $this->createServiceRequest($this->organisation);
    $this->clearCollectedMails();
    $this->setAssignmentSyncsOrganisation(TRUE);
    $this->installLegacyOrganisationNotificationEca();

    $request->set('field_assigned_team', $this->trafficOrganisation->id());
    $request->set('field_organisation', $this->trafficOrganisation->id());
    $request->save();

    $this->assertSame([], $this->collectedMails());
    $request = $this->reloadNode($request);
    $this->assertSame([(int) $this->trafficOrganisation->id()], $this->organisationIds($request));
  }

  /**
   * Assigning a person clears the team assignment.
   */
  public function testAssigneeClearsAssignedTeam(): void {
    $request = $this->createServiceRequest($this->organisation);
    $request->set('field_assigned_team', $this->parksOrganisation->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->container->get('current_user')->setAccount($this->assigner);
    $request->set('field_assignee', $this->orgMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame((int) $this->orgMember->id(), (int) $request->get('field_assignee')->target_id);
    $this->assertTrue($request->get('field_assigned_team')->isEmpty());
    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
    $this->assertSame((int) $this->assigner->id(), (int) $request->getRevisionUserId());
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * Switching assignees records only the newly assigned person.
   */
  public function testAssigneeSwitchCreatesAttributedRevision(): void {
    $request = $this->createServiceRequest($this->organisation, $this->orgMember);
    $previous_revision_id = (int) $request->getRevisionId();
    $this->container->get('current_user')->setAccount($this->assigner);

    $request->set('field_assignee', $this->multiOrgMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertNotSame($previous_revision_id, (int) $request->getRevisionId());
    $this->assertSame((int) $this->assigner->id(), (int) $request->getRevisionUserId());
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * The production stack retains old paragraph values before child presave.
   */
  public function testMandatoryRevisionPreservesInternalRemarkHistory(): void {
    $request = $this->createServiceRequest($this->organisation);
    $remark = Paragraph::create([
      'type' => 'internal_remark',
      'field_internal_remark_text' => 'Original internal reason',
    ]);
    $request->set('field_internal_remark', [$remark]);
    $request->save();
    $request = $this->reloadNode($request);
    $old_vid = (int) $request->getRevisionId();
    $old_paragraph_vid = (int) $request->get('field_internal_remark')->target_revision_id;
    $request->get('field_internal_remark')->entity->set('field_internal_remark_text', 'Revised internal reason');
    $request->setNewRevision(FALSE);
    $request->save();
    $this->assertNotSame($old_vid, (int) $request->getRevisionId());
    $this->assertNotSame($old_paragraph_vid, (int) $request->get('field_internal_remark')->target_revision_id);
    $old = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($old_vid);
    $this->assertSame('Original internal reason', $old->get('field_internal_remark')->entity->get('field_internal_remark_text')->value);
    $this->assertSame('Revised internal reason', $request->get('field_internal_remark')->entity->get('field_internal_remark_text')->value);
  }

  /**
   * A title-only JSON:API style revision must not repeat an assignment event.
   */
  public function testTitleRevisionDoesNotRepeatAssignmentLog(): void {
    $request = $this->createServiceRequest($this->organisation);
    $this->container->get('current_user')->setAccount($this->assigner);
    $request->set('field_assignee', $this->orgMember->id());
    $request->save();
    $request = $this->reloadNode($request);
    $assignment_revision = (int) $request->getRevisionId();
    $assignment_log = (string) $request->getRevisionLogMessage();
    $this->assertSame('', RevisionLog::message($assignment_log));
    $entry = RevisionLog::decode($assignment_log);
    $this->assertSame('person', $entry['changes'][0]['field']);
    $this->assertSame((string) $this->orgMember->id(), $entry['changes'][0]['after'][0]['id']);

    $this->container->get('current_user')->setAccount($this->jurMember);
    $request->setTitle('Title-only edit by a different user');
    // Match EntityResource::patchIndividual revision metadata handling.
    $http_request = Request::create('/jsonapi/node/service_request/' . $request->uuid(), 'PATCH');
    $http_request->attributes->set('_route_object', new Route('/jsonapi/node/service_request/{uuid}', ['_is_jsonapi' => TRUE]));
    $this->container->get('request_stack')->push($http_request);
    $request->setNewRevision(TRUE);
    $request->setRevisionUserId($this->jurMember->id());
    $request->setRevisionCreationTime($this->container->get('datetime.time')->getRequestTime());
    $request->save();
    $this->container->get('request_stack')->pop();
    $request = $this->reloadNode($request);

    $this->assertNotSame($assignment_revision, (int) $request->getRevisionId());
    $this->assertSame((int) $this->jurMember->id(), (int) $request->getRevisionUserId());
    $this->assertSame((int) $this->orgMember->id(), (int) $request->get('field_assignee')->target_id);
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
    $this->assertSame([], RevisionLog::decode((string) $request->getRevisionLogMessage())['changes']);
    $old_revision = $this->container->get('entity_type.manager')
      ->getStorage('node')->loadRevision($assignment_revision);
    $this->assertSame($assignment_log, $old_revision->getRevisionLogMessage());
    $this->assertSame((int) $this->assigner->id(), (int) $old_revision->getRevisionUserId());
  }

  /**
   * Trusted reasons survive an assignment's generated audit message.
   */
  public function testAssignmentPreservesExplicitReason(): void {
    $request = $this->createServiceRequest($this->organisation);
    $this->container->get('current_user')->setAccount($this->assigner);
    $request->set('field_assignee', $this->orgMember->id());
    RevisionLog::setMessage($request, 'Fachlich begründete Weitergabe.');
    $request->save();
    $request = $this->reloadNode($request);
    $this->assertSame('Fachlich begründete Weitergabe.', RevisionLog::message((string) $request->getRevisionLogMessage()));
  }

  /**
   * A new caller log survives an unrelated revision after an assignment event.
   */
  public function testTitleRevisionPreservesNewCallerLog(): void {
    $request = $this->createServiceRequest($this->organisation);
    $this->container->get('current_user')->setAccount($this->assigner);
    $request->set('field_assignee', $this->orgMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $request->setTitle('Corrected title');
    $request->setNewRevision(TRUE);
    $request->setRevisionLogMessage('Corrected a typo in the title.');
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame('Corrected a typo in the title.', RevisionLog::message((string) $request->getRevisionLogMessage()));
  }

  /**
   * Assignment audit text cannot be supplied by the caller.
   */
  public function testAssignmentRevisionLogOverridesCallerMessage(): void {
    $request = $this->createServiceRequest($this->organisation, $this->orgMember);
    $this->container->get('current_user')->setAccount($this->assigner);

    $request->setRevisionLogMessage('Arbeit der Person forged-user zugewiesen.');
    $request->set('field_assignee', $this->multiOrgMember->id());
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
    $this->assertStringNotContainsString('forged-user', (string) $request->getRevisionLogMessage());
    $this->assertNull($this->latestInternalRemarkText($request));
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
      '',
      RevisionLog::message((string) $request->getRevisionLogMessage()),
    );
    $this->assertNull($this->latestInternalRemarkText($request));
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
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
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
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
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
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
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
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * Unassigning a request never changes responsibility.
   */
  public function testAssignmentSyncsOrganisationUnassignLeavesOrganisationUnchanged(): void {
    $this->setAssignmentSyncsOrganisation(TRUE);
    $request = $this->createServiceRequest($this->organisation, $this->orgMember);
    $previous_revision_id = (int) $request->getRevisionId();
    $this->container->get('current_user')->setAccount($this->assigner);

    $request->set('field_assignee', NULL);
    $request->save();
    $request = $this->reloadNode($request);

    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
    $this->assertNotSame($previous_revision_id, (int) $request->getRevisionId());
    $this->assertSame((int) $this->assigner->id(), (int) $request->getRevisionUserId());
    $this->assertSame('', RevisionLog::message((string) $request->getRevisionLogMessage()));
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
      '',
      RevisionLog::message((string) $request->getRevisionLogMessage()),
    );
    $this->assertNull($this->latestInternalRemarkText($request));
  }

  /**
   * Responsibility updates bypass unrelated legacy required-field violations.
   */
  public function testResponsibilityControllerReplacesOrganisationAtomically(): void {
    $this->container->get('current_user')->setAccount($this->assigner);
    $this->createField('node', 'service_request', 'field_e_mail', 'email');
    $email_field = FieldConfig::loadByName('node', 'service_request', 'field_e_mail');
    $this->assertInstanceOf(FieldConfig::class, $email_field);
    $email_field->setRequired(TRUE)->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->container->get('config.factory')
      ->getEditable('markaspot_group.settings')
      ->set('single_organisation_assignment', TRUE)
      ->save();
    $request = $this->createServiceRequest($this->organisation);
    $this->assertTrue($request->get('field_e_mail')->isEmpty());

    $controller = RequestResponsibilityController::create($this->container);
    $http_request = Request::create(
      '/api/request-responsibility/' . $request->id(),
      'PATCH',
      content: json_encode([
        'organisation_uuids' => [$this->parksOrganisation->uuid()],
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->update($request, $http_request);

    $this->assertSame(200, $response->getStatusCode());
    $request = $this->reloadNode($request);
    $this->assertSame([(int) $this->parksOrganisation->id()], $this->organisationIds($request));
    $this->assertSame([(int) $this->parksOrganisation->id()], $this->organisationRelationshipIds($request));
  }

  /**
   * Responsibility updates enforce the effective single-org setting.
   */
  public function testResponsibilityControllerRejectsMultipleOrganisationsInSingleMode(): void {
    $this->container->get('current_user')->setAccount($this->assigner);
    $this->container->get('config.factory')
      ->getEditable('markaspot_group.settings')
      ->set('single_organisation_assignment', TRUE)
      ->save();
    $request = $this->createServiceRequest($this->organisation);
    $controller = RequestResponsibilityController::create($this->container);
    $http_request = Request::create(
      '/api/request-responsibility/' . $request->id(),
      'PATCH',
      content: json_encode([
        'organisation_uuids' => [
          $this->parksOrganisation->uuid(),
          $this->trafficOrganisation->uuid(),
        ],
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->update($request, $http_request);

    $this->assertSame(422, $response->getStatusCode());
    $request = $this->reloadNode($request);
    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
  }

  /**
   * Responsibility updates reject oversized bodies in Drupal itself.
   */
  public function testResponsibilityControllerRejectsOversizedBody(): void {
    $this->container->get('current_user')->setAccount($this->assigner);
    $request = $this->createServiceRequest($this->organisation);
    $controller = RequestResponsibilityController::create($this->container);
    $http_request = Request::create(
      '/api/request-responsibility/' . $request->id(),
      'PATCH',
      content: json_encode([
        'organisation_uuids' => [$this->parksOrganisation->uuid()],
        'padding' => str_repeat('x', 8192),
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->update($request, $http_request);

    $this->assertSame(413, $response->getStatusCode());
    $request = $this->reloadNode($request);
    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
  }

  /**
   * Responsibility updates reject inactive organisation targets.
   */
  public function testResponsibilityControllerRejectsInactiveOrganisation(): void {
    $this->parksOrganisation->set('status', FALSE)->save();
    $this->container->get('current_user')->setAccount($this->assigner);
    $request = $this->createServiceRequest($this->organisation);
    $controller = RequestResponsibilityController::create($this->container);
    $http_request = Request::create(
      '/api/request-responsibility/' . $request->id(),
      'PATCH',
      content: json_encode([
        'organisation_uuids' => [$this->parksOrganisation->uuid()],
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->update($request, $http_request);

    $this->assertSame(422, $response->getStatusCode());
    $request = $this->reloadNode($request);
    $this->assertSame([(int) $this->organisation->id()], $this->organisationIds($request));
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
   * Tests update repeatability and independent mail choices on real entities.
   */
  public function testOrganisationNotificationPreferencesPreserveResponsibility(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_group.install';
    markaspot_group_update_11951();
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache();
    $enabled = Group::load($this->organisation->id());
    $disabled = Group::load($this->parksOrganisation->id());
    $this->assertTrue(OrganisationNotificationPolicy::isEnabled($enabled));
    // Existing empty storage must render enabled in the standard Drupal widget.
    $enabled->set('field_assignment_notifications', NULL);
    $items = $enabled->get('field_assignment_notifications');
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $items->getFieldDefinition(),
      'form_mode' => 'default',
      'configuration' => ['type' => 'boolean_checkbox'],
    ]);
    $form = [];
    $form_state = new FormState();
    $element = $widget->formElement($items, 0, [], $form, $form_state);
    markaspot_group_field_widget_single_element_form_alter($element, $form_state, ['items' => $items]);
    $this->assertTrue($element['value']['#default_value']);
    $enabled->set('field_assignment_notifications', FALSE);
    $element = $widget->formElement($items, 0, [], $form, $form_state);
    markaspot_group_field_widget_single_element_form_alter($element, $form_state, ['items' => $items]);
    $this->assertFalse($element['value']['#default_value']);
    $enabled->set('field_assignment_notifications', TRUE);
    $disabled->set('field_assignment_notifications', FALSE)->save();
    markaspot_group_update_11951();
    $storage->resetCache();
    $disabled = Group::load($disabled->id());
    $this->assertFalse(OrganisationNotificationPolicy::isEnabled($disabled));

    $this->clearCollectedMails();
    $request = $this->createServiceRequest($disabled);
    $this->assertSame([], $this->collectedMails());
    $this->assertSame([(int) $disabled->id()], $this->organisationIds($request));
    $this->assertSame([(int) $disabled->id()], $this->organisationRelationshipIds($request));

    $request->set('field_organisation', [$enabled->id()])->save();
    $request = $this->reloadNode($request);
    $this->assertNotEmpty($this->collectedMails());
    $this->assertSame([(int) $enabled->id()], $this->organisationRelationshipIds($request));

    $this->clearCollectedMails();
    $request->set('field_organisation', [$disabled->id()])->save();
    $request = $this->reloadNode($request);
    $this->assertSame([], $this->collectedMails());
    $this->assertSame([(int) $disabled->id()], $this->organisationRelationshipIds($request));
  }

  /**
   * Tests legacy mail intent survives ECA token expansion.
   */
  public function testLegacyOrganisationEmailActionHonorsPreference(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_group.install';
    markaspot_group_update_11951();
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache();
    $organisation = Group::load($this->organisation->id());
    $organisation->set('field_assignment_notifications', FALSE)->save();
    $request = $this->createServiceRequest($organisation);
    $this->clearCollectedMails();
    $manager = $this->container->get('plugin.manager.action');
    $config = [
      'recipient' => '[node:field_organisation:entity:field_head_organisation_e_mail]',
      'subject' => 'Legacy assignment',
      'message' => 'A report was assigned.',
    ];
    $action = $manager->createInstance('action_send_email_action', $config);
    $this->assertInstanceOf(OrganisationAwareEmailAction::class, $action);
    // EcaAction replaces all configuration tokens before calling execute().
    $expanded = $config;
    $expanded['recipient'] = 'organisation@example.test';
    $action->setConfiguration($expanded);
    $action->execute($request);
    $this->assertSame([], $this->collectedMails());

    // Preserve core's first-reference semantics on multi-organisation reports.
    $request->set('field_organisation', [$organisation->id(), $this->parksOrganisation->id()]);
    $action->execute($request);
    $this->assertSame([], $this->collectedMails());
    $request->set('field_organisation', [$this->parksOrganisation->id(), $organisation->id()]);
    $action->execute($request);
    $this->assertCount(1, $this->collectedMails());
    $this->clearCollectedMails();
    $request->set('field_organisation', [$organisation->id()]);

    // The same address used for a different purpose must not be suppressed.
    $reporter = $manager->createInstance('action_send_email_action', [
      'recipient' => '[node:field_e_mail:value]',
      'subject' => 'Reporter confirmation',
      'message' => 'Confirmation',
    ]);
    $reporter->setConfiguration($expanded);
    $reporter->execute($request);
    $this->assertCount(1, $this->collectedMails());

    $this->clearCollectedMails();
    $organisation->set('field_assignment_notifications', TRUE)->save();
    $storage->resetCache();
    $request = $this->reloadNode($request);
    $action = $manager->createInstance('action_send_email_action', $config);
    $action->setConfiguration($expanded);
    $action->execute($request);
    $this->assertCount(1, $this->collectedMails());
    $this->assertSame([(int) $organisation->id()], $this->organisationRelationshipIds($request));
  }

  /**
   * Clears mail collected by the kernel test mail backend.
   */
  private function clearCollectedMails(): void {
    $this->container->get('state')->set('system.test_mail_collector', []);
  }

  /**
   * Returns mail collected by the kernel test mail backend.
   *
   * @return array<int, array<string, mixed>>
   *   Collected mail messages.
   */
  private function collectedMails(): array {
    return $this->container->get('state')->get('system.test_mail_collector') ?? [];
  }

  /**
   * Installs active legacy organisation notification config for the test.
   */
  private function installLegacyOrganisationNotificationEca(): void {
    $config_name = 'eca.eca.legacy_org_notification';
    $this->container->get('config.storage')->write($config_name, [
      'status' => TRUE,
      'actions' => [
        [
          'plugin' => 'service_request_sync_organisations',
          'configuration' => [
            'subject' => 'Assigned',
            'message' => 'A request was assigned.',
          ],
        ],
      ],
    ]);
    $this->container->get('config.factory')->reset($config_name);
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
