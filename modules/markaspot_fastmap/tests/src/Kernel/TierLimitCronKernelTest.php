<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the hourly over-limit enforcement of published reports.
 *
 * @covers ::markaspot_fastmap_cron
 * @covers \Drupal\markaspot_fastmap\Service\TierConfigService::getProvisionedDemoRequestIds
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class TierLimitCronKernelTest extends KernelTestBase {

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
   * The free-tier workspace under test.
   */
  private GroupInterface $workspace;

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
    // The cron's later tasks (pending cleanup, AI usage pruning) need these.
    $this->installSchema('markaspot_fastmap', ['markaspot_fastmap_pending', 'markaspot_ai_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);

    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

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
  }

  /**
   * The cron unpublishes the oldest citizen reports, never demo requests.
   */
  public function testCronUnpublishesCitizenReportsNotDemoRequests(): void {
    $this->setFreeLimit(100);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $citizen = $this->createUser('citizen');
    $demo = [];
    foreach ([30, 29, 28] as $daysAgo) {
      $demo[] = $this->createRequest($this->workspace, 1, "Demo.\n\n[demo-content]", $now - $daysAgo * 86400);
    }
    $reports = [];
    foreach ([10, 9, 8, 7] as $daysAgo) {
      $reports[] = $this->createRequest($this->workspace, (int) $citizen->id(), 'A real report.', $now - $daysAgo * 86400);
    }

    $tierConfig = $this->container->get('markaspot_fastmap.tier_config');
    $this->assertSame(4, $tierConfig->countRequests((int) $this->workspace->id(), 'published'));

    // A downgrade leaves the workspace two published reports over the limit.
    $this->setFreeLimit(2);
    markaspot_fastmap_cron();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    foreach ($demo as $node) {
      $this->assertTrue($storage->load($node->id())->isPublished(), 'Demo requests are never unpublished by the cron.');
    }
    $this->assertFalse($storage->load($reports[0]->id())->isPublished(), 'The oldest citizen report is unpublished.');
    $this->assertFalse($storage->load($reports[1]->id())->isPublished());
    $this->assertTrue($storage->load($reports[2]->id())->isPublished());
    $this->assertTrue($storage->load($reports[3]->id())->isPublished());
    $this->assertSame(2, $tierConfig->countRequests((int) $this->workspace->id(), 'published'));
  }

  /**
   * Sets the free tier to a published-report limit.
   */
  private function setFreeLimit(int $limit): void {
    $this->config('markaspot_fastmap.settings')->set('tier_limits', [
      'free' => ['limit' => $limit, 'period' => 'published'],
      'starter' => ['limit' => 500, 'period' => 'monthly'],
      'pro' => ['limit' => 2000, 'period' => 'monthly'],
      'heart' => ['limit' => NULL, 'period' => 'published'],
    ])->save();
  }

  /**
   * Creates a published, permanent free-tier jurisdiction workspace.
   */
  private function createWorkspace(string $label, string $slug): GroupInterface {
    $group = Group::create(['type' => 'jur', 'label' => $label, 'field_slug' => $slug, 'field_tier' => 'free']);
    $group->save();
    return $group;
  }

  /**
   * Creates a service request in a workspace.
   */
  private function createRequest(GroupInterface $workspace, int $uid, string $body, int $created): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'uid' => $uid,
      'created' => $created,
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
