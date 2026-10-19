<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that provisioned demo requests pass the demo-state publication gate.
 *
 * A new workspace is in demo state (expiry set, no Stripe subscription), and
 * the gate in markaspot_fastmap_node_presave() unpublishes every new report
 * there. Only the synthetic demo requests that provisioning saves are exempt.
 *
 * @covers \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService::provisionWorkspace
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class DemoRequestPublicationKernelTest extends KernelTestBase {

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
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_fastmap',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypes = [
      'user', 'node', 'file', 'taxonomy_term',
      'group', 'group_relationship', 'group_config_wrapper',
    ];
    foreach ($entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Demo requests are published, later reports in the workspace are not.
   */
  public function testDemoRequestsPublishedWhileCitizenReportsStayGated(): void {
    $result = $this->container->get('markaspot_fastmap.workspace_provisioning')->provisionWorkspace([
      'name' => 'Lindenhof School Routes',
      'slug' => 'lindenhof',
      'email' => 'parents@example.test',
      'categories' => ['Dangerous crossing', 'Blocked sidewalk'],
      'language' => 'en',
      'lat' => 50.83,
      'lng' => 6.9,
      'zoom' => 15,
      'template' => 'safe-routes',
    ]);
    $group = Group::load($result['group_id']);
    $this->assertInstanceOf(GroupInterface::class, $group);
    $this->assertTrue(_markaspot_fastmap_workspace_in_demo_state($group), 'A new workspace starts in demo state.');
    $this->assertFalse(drupal_static(WorkspaceProvisioningService::DEMO_REQUEST_SEEDING, FALSE), 'The seeding flag is reset after provisioning.');

    $demoRequests = $this->reports((int) $group->id());
    $this->assertCount(2, $demoRequests);
    foreach ($demoRequests as $demo) {
      $this->assertTrue($demo->isPublished(), 'Provisioned demo requests are published.');
      $this->assertSame(1, (int) $demo->getOwnerId());
      $this->assertStringContainsString('[demo-content]', (string) $demo->get('body')->value);
    }

    $citizen = User::create(['name' => 'citizen', 'mail' => 'citizen@example.test', 'status' => 1]);
    $citizen->save();
    $citizenReport = $this->createReport($group, (int) $citizen->id(), 'Cars park on the sidewalk.');
    $this->assertFalse($citizenReport->isPublished(), 'A citizen report in a demo-state workspace stays gated.');

    // Owner and marker alone do not exempt a report: only the provisioning
    // save loop does.
    $lookalike = $this->createReport($group, 1, "Looks like demo content.\n\n[demo-content]");
    $this->assertFalse($lookalike->isPublished(), 'A report that only looks like demo content stays gated.');
  }

  /**
   * Loads the service requests of a jurisdiction, oldest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The reports.
   */
  private function reports(int $groupId): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_jurisdiction', $groupId)
      ->sort('nid')
      ->execute();
    $storage->resetCache();
    return array_values($storage->loadMultiple($nids));
  }

  /**
   * Saves a new, published report and reloads it.
   */
  private function createReport(GroupInterface $group, int $uid, string $body): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'uid' => $uid,
      'status' => 1,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'field_jurisdiction' => $group->id(),
    ]);
    $node->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());
    $this->assertInstanceOf(NodeInterface::class, $reloaded);
    return $reloaded;
  }

  /**
   * Creates the node and taxonomy model provisioning writes to.
   */
  private function createContentModel(): void {
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    foreach (['plain_text' => 'Plain text', 'basic_html' => 'Basic HTML'] as $format => $name) {
      if (!FilterFormat::load($format)) {
        FilterFormat::create(['format' => $format, 'name' => $name])->save();
      }
    }

    foreach (['service_category', 'service_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
      $this->createField('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('taxonomy_term', 'service_category', 'field_service_code', 'string');
    $this->createField('taxonomy_term', 'service_category', 'field_category_hex', 'color_field_type', ['format' => '#HEXHEX']);
    $this->createField('taxonomy_term', 'service_category', 'field_category_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_status_hex', 'color_field_type', ['format' => '#HEXHEX']);
    $this->createField('taxonomy_term', 'service_status', 'field_status_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');

    foreach (['service_request', 'page'] as $bundle) {
      $this->createField('node', $bundle, 'body', 'text_with_summary');
      $this->createField('node', $bundle, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_geolocation', 'geolocation');
    $this->createField('node', 'service_request', 'field_address', 'string');
  }

  /**
   * Creates the jurisdiction group type, roles and fields.
   */
  private function createJurisdictionModel(): void {
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    foreach (['tenant_admin' => 'Tenant Admin', 'member' => 'Member'] as $role => $label) {
      GroupRole::create([
        'id' => 'jur-' . $role,
        'label' => $label,
        'group_type' => 'jur',
        'scope' => 'individual',
      ])->save();
    }

    $relationshipStorage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      $relationshipTypeId = 'jur-' . str_replace(':', '-', $pluginId);
      if (!$relationshipStorage->load($relationshipTypeId)) {
        $relationshipStorage->createFromPlugin(GroupType::load('jur'), $pluginId)->save();
      }
    }

    $this->createField('group', 'jur', 'field_slug', 'string');
    $this->createField('group', 'jur', 'field_platform_name', 'string');
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'jur', 'field_boundary', 'text_long');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_expiry_date', 'timestamp');
    $this->createField('group', 'jur', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
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
      'settings' => $storageSettings,
    ])->save();
  }

}
