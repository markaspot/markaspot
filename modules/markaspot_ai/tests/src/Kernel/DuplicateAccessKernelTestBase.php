<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Access\DuplicateMatchAccessPolicy;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Shared fixture for duplicate match access tests.
 *
 * Real Group/gnode node access and real match rows; only the tenant AI
 * opt-in (field_nuxt_config parsing) is replaced by a per-jurisdiction map.
 * Tenant A has a reviewer with view and update, and one with view only;
 * tenant B has its own reviewer.
 */
abstract class DuplicateAccessKernelTestBase extends KernelTestBase {

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
  protected UserInterface $adminA;

  /**
   * Tenant A administrator with view only.
   */
  protected UserInterface $viewerA;

  /**
   * Tenant A and B jurisdictions.
   */
  protected Group $tenantA;

  /**
   * Tenant B jurisdiction.
   */
  protected Group $tenantB;

  /**
   * Jurisdiction IDs whose duplicate detection is opted in.
   *
   * @var int[]
   */
  protected array $optedIn = [];

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
   * Sets the global duplicate toggle as a settings override.
   *
   * The AI module is not installed here, so its config has no schema; a
   * settings override needs none and is what the policy reads at runtime.
   */
  protected function setDuplicateDetection(bool $enabled): void {
    $GLOBALS['config']['markaspot_ai.settings']['duplicate_detection']['enabled'] = $enabled;
    $this->container->get('config.factory')->reset('markaspot_ai.settings');
  }

  /**
   * Asserts the stored match is still pending and unreviewed.
   */
  protected function assertUnreviewed(int $matchId): void {
    $row = $this->matchRow($matchId);
    $this->assertSame('pending', $row['status']);
    $this->assertEmpty($row['reviewed_by']);
  }

  /**
   * Creates a pending match row.
   */
  protected function createMatch(NodeInterface $source, NodeInterface $match): int {
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
  protected function matchRow(int $matchId): array {
    return (array) $this->container->get('database')->select('markaspot_ai_duplicate_matches', 'd')
      ->fields('d')->condition('d.id', $matchId)->execute()->fetchAssoc();
  }

  /**
   * Creates a grouped service request in a jurisdiction.
   */
  protected function request(Group $jurisdiction, string $title, bool $published = TRUE): NodeInterface {
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
  protected function actAs(UserInterface $account): void {
    $this->container->get('current_user')->setAccount($account);
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
  }

  /**
   * Builds the duplicate detection service with a stub embedding client.
   */
  protected function detection(): DuplicateDetectionService {
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
  protected function policy(): DuplicateMatchAccessPolicy {
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

}
