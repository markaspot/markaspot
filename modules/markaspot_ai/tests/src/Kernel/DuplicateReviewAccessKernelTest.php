<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Access\DuplicateMatchAccessPolicy;
use Drupal\markaspot_ai\Controller\DuplicateController;
use Drupal\markaspot_ai\Form\DuplicateReviewForm;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tenant and report access for the duplicate review form and API.
 *
 * Uses real Group/gnode node access and real match rows; only the tenant
 * AI opt-in (field_nuxt_config parsing) is replaced by a per-jurisdiction map.
 */
#[TestGroup('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class DuplicateReviewAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file',
    'entity', 'flexible_permissions', 'group', 'gnode', 'markaspot_group',
    'serialization', 'jsonapi',
  ];

  /**
   * Tenant A administrator with view and update.
   */
  private UserInterface $adminA;

  /**
   * Tenant A administrator with view only.
   */
  private UserInterface $viewerA;

  /**
   * Tenant A and B jurisdictions.
   */
  private Group $tenantA;

  /**
   * Tenant B jurisdiction.
   */
  private Group $tenantB;

  /**
   * Jurisdiction IDs whose duplicate detection is opted in.
   *
   * @var int[]
   */
  private array $optedIn = [];

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
    require_once dirname(__DIR__, 3) . '/markaspot_ai.install';
    $this->container->get('database')->schema()->createTable(
      'markaspot_ai_duplicate_matches',
      markaspot_ai_schema()['markaspot_ai_duplicate_matches'],
    );
    $this->setDuplicateDetection(TRUE);

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $plugin) {
      $type = $relationships->createFromPlugin(GroupType::load('jur'), $plugin);
      if (!$relationships->load($type->id())) {
        $type->save();
      }
    }
    GroupRole::create([
      'id' => 'jur-tenant_admin',
      'label' => 'Tenant administrator',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => ['view group', 'view group_node:service_request entity'],
    ])->save();
    GroupRole::create([
      'id' => 'jur-editor',
      'label' => 'Report editor',
      'group_type' => 'jur',
      'scope' => 'individual',
      'permissions' => ['update any group_node:service_request entity'],
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
    // Organisations reference their jurisdiction; markaspot_group's
    // permission calculator queries that field.
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    foreach (['node' => 'service_request', 'group' => 'org'] as $entityType => $bundle) {
      FieldStorageConfig::create([
        'field_name' => 'field_jurisdiction',
        'entity_type' => $entityType,
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'group'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_jurisdiction',
        'entity_type' => $entityType,
        'bundle' => $bundle,
        'label' => 'Jurisdiction',
      ])->save();
    }

    Role::load('authenticated')->grantPermission('access content')->save();
    Role::create(['id' => 'tenant_admin', 'label' => 'Tenant admin'])->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);

    $this->tenantA = Group::create(['type' => 'jur', 'label' => 'Tenant A']);
    $this->tenantA->save();
    $this->tenantB = Group::create(['type' => 'jur', 'label' => 'Tenant B']);
    $this->tenantB->save();
    $this->optedIn = [(int) $this->tenantA->id(), (int) $this->tenantB->id()];

    $this->adminA = User::create(['name' => 'admin-a', 'status' => 1, 'roles' => ['tenant_admin']]);
    $this->adminA->save();
    $this->tenantA->addMember($this->adminA, ['group_roles' => ['jur-tenant_admin', 'jur-editor']]);
    $this->viewerA = User::create(['name' => 'viewer-a', 'status' => 1, 'roles' => ['tenant_admin']]);
    $this->viewerA->save();
    $this->tenantA->addMember($this->viewerA, ['group_roles' => ['jur-tenant_admin']]);
    $adminB = User::create(['name' => 'admin-b', 'status' => 1, 'roles' => ['tenant_admin']]);
    $adminB->save();
    $this->tenantB->addMember($adminB, ['group_roles' => ['jur-tenant_admin', 'jur-editor']]);

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Same-tenant review renders both reports and stores the decision.
   */
  public function testSameTenantReviewIsAllowed(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'Pothole A1'), $this->request($this->tenantA, 'Pothole A2'));
    $this->actAs($this->adminA);

    $this->assertTrue($this->policy()->accessReviewRoute((string) $matchId, $this->adminA)->isAllowed());
    $build = $this->form()->buildForm([], new FormState(), $matchId);
    $rendered = $this->renderTitles($build);
    $this->assertStringContainsString('Pothole A1', $rendered);
    $this->assertStringContainsString('Pothole A2', $rendered);
    $this->assertTrue($build['actions']['confirm']['#access']);

    $this->submit($matchId, 'confirm');
    $row = $this->matchRow($matchId);
    $this->assertSame('confirmed', $row['status']);
    $this->assertSame((int) $this->adminA->id(), (int) $row['reviewed_by']);
  }

  /**
   * Tenant A cannot read or review tenant B's match through form or API.
   */
  public function testForeignTenantMatchIsDeniedWithoutMetadataOrMutation(): void {
    $matchId = $this->createMatch($this->request($this->tenantB, 'Foreign B1'), $this->request($this->tenantB, 'Foreign B2'));
    $this->actAs($this->adminA);

    $this->assertFalse($this->policy()->accessReviewRoute((string) $matchId, $this->adminA)->isAllowed());
    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);

    $response = $this->controller()->reviewMatch(
      Request::create('/', 'POST', [], [], [], [], json_encode(['status' => 'confirmed'])),
      $matchId,
    );
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringNotContainsString('Foreign', (string) $response->getContent());
    $this->assertUnreviewed($matchId);
  }

  /**
   * A match that spans two tenants is denied even to one tenant's admin.
   */
  public function testMixedScopeMatchIsDenied(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'Own A'), $this->request($this->tenantB, 'Foreign B'));
    $this->actAs($this->adminA);

    $this->assertFalse($this->policy()->matchIdAccess($matchId, 'view', $this->adminA)->isAllowed());
    $this->assertFalse($this->policy()->matchIdAccess($matchId, 'update', $this->adminA)->isAllowed());
    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);
  }

  /**
   * An unpublished report the reviewer cannot view denies the match.
   */
  public function testUnpublishedReportIsDenied(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'Published'), $this->request($this->tenantA, 'Draft', FALSE));
    $this->actAs($this->adminA);

    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);
  }

  /**
   * Deleted reports and unknown match IDs are denied the same way.
   */
  public function testMissingReportAndUnknownMatchAreDenied(): void {
    $deleted = $this->request($this->tenantA, 'Gone');
    $matchId = $this->createMatch($this->request($this->tenantA, 'Kept'), $deleted);
    $deleted->delete();
    $this->actAs($this->adminA);

    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);
    $this->assertFalse($this->policy()->accessReviewRoute('999999', $this->adminA)->isAllowed());
    $this->assertFalse($this->policy()->accessReviewRoute('not-a-number', $this->adminA)->isAllowed());
    $this->assertFormDenied(999999);
  }

  /**
   * A tenant that has not opted into duplicate detection exposes nothing.
   */
  public function testTenantOptOutAndGlobalToggleDeny(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'A1'), $this->request($this->tenantA, 'A2'));
    $this->actAs($this->adminA);

    $this->optedIn = [(int) $this->tenantB->id()];
    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);

    $this->optedIn = [(int) $this->tenantA->id()];
    $this->setDuplicateDetection(FALSE);
    $this->assertFormDenied($matchId);
    $this->assertSubmitDeniedWithoutMutation($matchId);
  }

  /**
   * View access renders the comparison but reviewing needs update access.
   */
  public function testViewOnlyReviewerCannotSubmit(): void {
    $matchId = $this->createMatch($this->request($this->tenantA, 'A1'), $this->request($this->tenantA, 'A2'));
    $this->actAs($this->viewerA);

    $build = $this->form()->buildForm([], new FormState(), $matchId);
    $this->assertFalse($build['actions']['confirm']['#access']);
    $this->assertFalse($build['actions']['reject']['#access']);
    $this->assertSubmitDeniedWithoutMutation($matchId);
  }

  /**
   * Sets the global duplicate toggle as a settings override.
   *
   * The AI module is not installed here, so its config has no schema; a
   * settings override needs none and is what the policy reads at runtime.
   */
  private function setDuplicateDetection(bool $enabled): void {
    $GLOBALS['config']['markaspot_ai.settings']['duplicate_detection']['enabled'] = $enabled;
    $this->container->get('config.factory')->reset('markaspot_ai.settings');
  }

  /**
   * Asserts that the form refuses to build for a match.
   */
  private function assertFormDenied(int $matchId): void {
    try {
      $this->form()->buildForm([], new FormState(), $matchId);
      $this->fail('Duplicate review form rendered an inaccessible match.');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * Asserts that validation and submission refuse and nothing is written.
   */
  private function assertSubmitDeniedWithoutMutation(int $matchId): void {
    $form = [];
    $state = (new FormState())->setValue('match_id', $matchId);
    $this->form()->validateForm($form, $state);
    $this->assertNotEmpty($state->getErrors());
    try {
      $this->submit($matchId, 'confirm');
      $this->fail('Duplicate review submission reached reviewMatch().');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
    $this->assertUnreviewed($matchId);
  }

  /**
   * Asserts the stored match is still pending and unreviewed.
   */
  private function assertUnreviewed(int $matchId): void {
    $row = $this->matchRow($matchId);
    $this->assertSame('pending', $row['status']);
    $this->assertEmpty($row['reviewed_by']);
  }

  /**
   * Submits the review form with the given action.
   */
  private function submit(int $matchId, string $action): void {
    $form = [];
    $state = (new FormState())
      ->setValue('match_id', $matchId)
      ->setTriggeringElement(['#name' => $action]);
    $this->form()->submitForm($form, $state);
  }

  /**
   * Collects the rendered report titles from the comparison tables.
   */
  private function renderTitles(array $build): string {
    $out = '';
    foreach (['source', 'match'] as $side) {
      foreach ($build['comparison'][$side]['table']['#rows'] ?? [] as $row) {
        $out .= ' ' . strip_tags((string) $row[1]);
      }
    }
    return $out;
  }

  /**
   * Creates a pending match row.
   */
  private function createMatch(NodeInterface $source, NodeInterface $match): int {
    return (int) $this->container->get('database')->insert('markaspot_ai_duplicate_matches')
      ->fields([
        'source_nid' => $source->id(),
        'match_nid' => $match->id(),
        'similarity_score' => 0.93,
        'status' => 'pending',
        'created' => 1760000000,
      ])
      ->execute();
  }

  /**
   * Loads a match row.
   */
  private function matchRow(int $matchId): array {
    return (array) $this->container->get('database')->select('markaspot_ai_duplicate_matches', 'd')
      ->fields('d')->condition('d.id', $matchId)->execute()->fetchAssoc();
  }

  /**
   * Creates a grouped service request in a jurisdiction.
   */
  private function request(Group $jurisdiction, string $title, bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => $title,
      'uid' => 1,
      'status' => (int) $published,
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    $node->save();
    $jurisdiction->addRelationship($node, 'group_node:service_request');
    return $node;
  }

  /**
   * Switches the current user and resets cached entity access.
   */
  private function actAs(UserInterface $account): void {
    $this->container->get('current_user')->setAccount($account);
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
  }

  /**
   * Builds the duplicate detection service with a stub embedding client.
   */
  private function detection(): DuplicateDetectionService {
    return new DuplicateDetectionService(
      $this->createMock(EmbeddingService::class),
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
    );
  }

  /**
   * Builds the policy with the tenant opt-in replaced by the test map.
   */
  private function policy(): DuplicateMatchAccessPolicy {
    $optedIn = &$this->optedIn;
    return new class($this->container->get('entity_type.manager'), $this->container->get('config.factory'), $this->detection(), $optedIn) extends DuplicateMatchAccessPolicy {

      /**
       * Opted-in jurisdiction IDs, shared by reference with the test.
       *
       * @var int[]
       */
      private array $optedIn;

      public function __construct($entityTypeManager, $configFactory, $detection, array &$optedIn) {
        parent::__construct($entityTypeManager, $configFactory, $detection);
        $this->optedIn = &$optedIn;
      }

      /**
       * {@inheritdoc}
       */
      protected function getJurisdictionIdForNode(NodeInterface $node): ?int {
        $target = $node->get('field_jurisdiction')->target_id;
        return $target === NULL ? NULL : (int) $target;
      }

      /**
       * {@inheritdoc}
       */
      protected function isDuplicateDetectionEnabledForNode(NodeInterface $node): bool {
        return in_array($this->getJurisdictionIdForNode($node), $this->optedIn, TRUE);
      }

    };
  }

  /**
   * Builds the review form with the test policy.
   */
  private function form(): DuplicateReviewForm {
    $form = new DuplicateReviewForm(
      $this->detection(),
      $this->container->get('entity_type.manager'),
      $this->container->get('date.formatter'),
      $this->policy(),
    );
    $form->setStringTranslation($this->container->get('string_translation'));
    $form->setMessenger($this->container->get('messenger'));
    return $form;
  }

  /**
   * Builds the API controller with the same test policy.
   */
  private function controller(): DuplicateController {
    $controller = new DuplicateController(
      $this->detection(),
      $this->container->get('entity_type.manager'),
      $this->container->get('database'),
      NULL,
      $this->policy(),
    );
    $controller->setStringTranslation($this->container->get('string_translation'));
    return $controller;
  }

}
