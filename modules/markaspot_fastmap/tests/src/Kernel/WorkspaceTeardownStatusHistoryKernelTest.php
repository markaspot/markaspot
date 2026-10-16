<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests teardown of a workspace with status history and a minimal status set.
 *
 * Both used to roll the whole teardown back: the status paragraphs of deleted
 * reports still referenced the workspace's status terms, and the last status
 * of each required Open311 mapping may never be deleted while its
 * jurisdiction lives.
 *
 * @covers \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService::teardownWorkspace
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class WorkspaceTeardownStatusHistoryKernelTest extends KernelTestBase {

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
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    // Organisation memberships are mirrored into the jurisdiction as this.
    GroupRole::create([
      'id' => 'jur-org_member',
      'label' => 'Organisation member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    GroupRole::create([
      'id' => 'jur-member',
      'label' => 'Member',
      'group_type' => 'jur',
      'scope' => 'individual',
    ])->save();
    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      if (!$relationshipTypes->load('jur-' . str_replace(':', '-', $pluginId))) {
        $relationshipTypes->createFromPlugin(GroupType::load('jur'), $pluginId)->save();
      }
    }
    if (!$relationshipTypes->load('org-group_membership')) {
      $relationshipTypes->createFromPlugin(GroupType::load('org'), 'group_membership')->save();
    }
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $groupFields = [
      'field_slug' => 'string',
      'field_boundary' => 'text_long',
      'field_nuxt_config' => 'text_long',
      'field_expiry_date' => 'timestamp',
    ];
    foreach ($groupFields as $name => $type) {
      $this->createField('group', 'jur', $name, $type);
    }
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);

    Vocabulary::create(['vid' => 'service_category', 'name' => 'Categories'])->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Statuses'])->save();
    Vocabulary::create(['vid' => 'internal_status', 'name' => 'Internal statuses'])->save();
    foreach (['service_category', 'service_status', 'internal_status'] as $vid) {
      $this->createField('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');

    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);

    foreach (['service_request', 'page'] as $bundle) {
      $this->createField('node', $bundle, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * A workspace with history and one status per mapping is fully removed.
   */
  public function testTeardownRemovesWorkspaceWithStatusHistory(): void {
    $workspace = Group::create(['type' => 'jur', 'label' => 'Demo', 'field_slug' => 'demo-x']);
    $workspace->save();
    // A second workspace keeps its own minimal status set untouched.
    $other = Group::create(['type' => 'jur', 'label' => 'Other', 'field_slug' => 'other-x']);
    $other->save();

    $statuses = [];
    foreach ([$workspace, $other] as $group) {
      foreach (['initial', 'open', 'closed'] as $mapping) {
        $term = Term::create([
          'vid' => 'service_status',
          'name' => ucfirst($mapping),
          'field_open311_mapping' => $mapping,
          'field_jurisdiction' => $group->id(),
        ]);
        $term->save();
        $statuses[$group->id()][$mapping] = $term;
      }
    }
    $category = Term::create([
      'vid' => 'service_category',
      'name' => 'Pothole',
      'field_jurisdiction' => $workspace->id(),
    ]);
    $category->save();

    $history = [];
    foreach (['initial', 'open'] as $mapping) {
      $paragraph = Paragraph::create([
        'type' => 'status',
        'field_status_term' => $statuses[$workspace->id()][$mapping]->id(),
      ]);
      $paragraph->save();
      $history[] = $paragraph;
    }
    $report = Node::create([
      'type' => 'service_request',
      'title' => 'Pothole',
      'field_jurisdiction' => $workspace->id(),
      'field_status' => $statuses[$workspace->id()]['open']->id(),
      'field_status_notes' => array_map(static fn(Paragraph $p) => [
        'target_id' => $p->id(),
        'target_revision_id' => $p->getRevisionId(),
      ], $history),
    ]);
    $report->save();

    // Content and structures outside the report/category/status core.
    $page = Node::create([
      'type' => 'page',
      'title' => 'About',
      'field_jurisdiction' => $workspace->id(),
    ]);
    $page->save();
    $internal = Term::create([
      'vid' => 'internal_status',
      'name' => 'Checking',
      'field_jurisdiction' => $workspace->id(),
    ]);
    $internal->save();
    $organisation = Group::create([
      'type' => 'org',
      'label' => 'Road crew',
      'field_jurisdiction' => $workspace->id(),
    ]);
    $organisation->save();
    $orgMember = User::create(['name' => 'crew', 'mail' => 'crew@example.test', 'status' => 1]);
    $orgMember->save();
    $organisation->addMember($orgMember);

    $this->container->get('markaspot_fastmap.workspace_provisioning')
      ->teardownWorkspace((int) $workspace->id());

    $entityTypeManager = $this->container->get('entity_type.manager');
    foreach (['group', 'node', 'paragraph', 'taxonomy_term', 'user'] as $entityType) {
      $entityTypeManager->getStorage($entityType)->resetCache();
    }
    $this->assertNull(Node::load($page->id()));
    $this->assertNull(Term::load($internal->id()));
    $this->assertNull(Group::load($organisation->id()));
    $this->assertNull(User::load($orgMember->id()), 'A member of no other group goes with the workspace.');
    $this->assertNull(Group::load($workspace->id()));
    $this->assertNull(Node::load($report->id()));
    foreach ($history as $paragraph) {
      $this->assertNull(Paragraph::load($paragraph->id()));
    }
    foreach ($statuses[$workspace->id()] as $term) {
      $this->assertNull(Term::load($term->id()));
    }
    $this->assertNull(Term::load($category->id()));

    $this->assertNotNull(Group::load($other->id()));
    foreach ($statuses[$other->id()] as $term) {
      $this->assertNotNull(Term::load($term->id()));
    }
  }

  /**
   * Outside a teardown the last status of a mapping stays protected.
   */
  public function testMinimalStatusSetStaysProtectedOutsideTeardown(): void {
    $workspace = Group::create(['type' => 'jur', 'label' => 'Live', 'field_slug' => 'live-x']);
    $workspace->save();
    $initial = Term::create([
      'vid' => 'service_status',
      'name' => 'New',
      'field_open311_mapping' => 'initial',
      'field_jurisdiction' => $workspace->id(),
    ]);
    $initial->save();

    $this->expectExceptionMessage('At least one status for each required Open311 mapping must remain.');
    $initial->delete();
  }

  /**
   * Creates one configurable field and its storage.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = [], int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
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
