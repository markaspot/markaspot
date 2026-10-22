<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\service_request\RevisionLog;
use Drupal\Core\Entity\EntityStorageInterface;
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
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the demo requests a provisioned workspace starts with.
 *
 * A new workspace is in demo state (expiry set, no Stripe subscription), and
 * the gate in markaspot_fastmap_node_presave() unpublishes every new report
 * there. Only the synthetic demo requests that provisioning saves are exempt.
 * They also show a spread of statuses although every new report starts on
 * the initial one.
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
    'markaspot_fastmap',
    // After markaspot_group, like the alphabetical order in production: the
    // initial status is forced before the creation note is written.
    'service_request',
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
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    $this->createContentModel();
    $this->createJurisdictionModel();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->stubProcessor();
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
    // Published demo requests do not eat the published-report quota.
    $tierConfig = $this->container->get('markaspot_fastmap.tier_config');
    $this->assertSame(0, $tierConfig->countRequests((int) $group->id(), 'published'));
    $this->assertSame(0, $tierConfig->countRequests((int) $group->id(), 'monthly'));
    $this->assertSame(2, $tierConfig->getReportStats((int) $group->id())['demo_request_count']);

    $citizen = User::create(['name' => 'citizen', 'mail' => 'citizen@example.test', 'status' => 1]);
    $citizen->save();
    $citizenReport = $this->createReport($group, (int) $citizen->id(), 'Cars park on the sidewalk.');
    $this->assertFalse($citizenReport->isPublished(), 'A citizen report in a demo-state workspace stays gated.');

    // Owner and marker alone do not exempt a report: only the provisioning
    // save loop does.
    $lookalike = $this->createReport($group, 1, "Looks like demo content.\n\n[demo-content]");
    $this->assertFalse($lookalike->isPublished(), 'A report that only looks like demo content stays gated.');

    // Once the workspace is out of demo state, a published citizen report
    // that contains the marker counts against the quota; demo requests still
    // do not.
    $group->set('field_expiry_date', NULL)->save();
    $citizenWithMarker = $this->createReport($group, (int) $citizen->id(), 'I copied [demo-content] here.');
    $this->assertTrue($citizenWithMarker->isPublished());
    $this->assertSame(1, $tierConfig->countRequests((int) $group->id(), 'published'));
  }

  /**
   * Demo requests spread over the statuses without a fake history or mail.
   */
  public function testDemoRequestsSpreadOverStatuses(): void {
    $result = $this->container->get('markaspot_fastmap.workspace_provisioning')->provisionWorkspace([
      'name' => 'Lindenhof School Routes',
      'slug' => 'lindenhof',
      'email' => 'parents@example.test',
      'categories' => [
        'Dangerous crossing', 'Blocked sidewalk', 'Poor visibility', 'Speeding',
        'Dark spot', 'Missing crossing guard', 'Bus stop', 'Parking at the gate',
      ],
      'language' => 'en',
      'lat' => 50.83,
      'lng' => 6.9,
      'zoom' => 15,
      'template' => 'safe-routes',
    ]);
    $groupId = (int) $result['group_id'];
    $statuses = [];
    foreach (Term::loadMultiple($this->container->get('entity_type.manager')->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_status')
      ->condition('field_jurisdiction', $groupId)
      ->execute()) as $term) {
      $statuses[(int) $term->id()] = $term;
    }
    $question = array_filter($statuses, static fn(Term $term): bool => $term->label() === 'Question to reporter');
    $this->assertCount(1, $question, 'The safe-routes template has a question status.');

    $demoRequests = $this->reports($groupId);
    $this->assertCount(8, $demoRequests);
    $mappings = [];
    foreach ($demoRequests as $demo) {
      $statusId = (int) $demo->get('field_status')->target_id;
      $this->assertArrayHasKey($statusId, $statuses);
      $this->assertArrayNotHasKey($statusId, $question, 'No demo request waits for an answer to a question nobody asked.');
      $mapping = (string) $statuses[$statusId]->get('field_open311_mapping')->value;
      $mappings[] = $mapping;
      $this->assertFalse((bool) $demo->get('field_notification')->value, 'Demo requests never notify anyone.');

      // The history: the creation note, plus one entry for the status the
      // request was moved to. No generic "Status changed." text.
      $notes = $demo->get('field_status_notes')->referencedEntities();
      $this->assertCount($mapping === 'initial' ? 1 : 2, $notes);
      $this->assertSame($statusId, (int) end($notes)->get('field_status_term')->target_id);
      $this->assertSame('initial', $statuses[(int) $notes[0]->get('field_status_term')->target_id]->get('field_open311_mapping')->value);
      if (count($notes) === 2) {
        $this->assertTrue($notes[1]->get('field_status_note')->isEmpty());
        $this->assertGreaterThan((int) $notes[0]->get('created')->value, (int) $notes[1]->get('created')->value);
      }
      $revisions = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->allRevisions()
        ->condition('nid', $demo->id())
        ->count()
        ->execute();
      // Post-insert scope normalisation may itself save a technical revision.
      // A later status mutation must retain the initial state as well.
      $this->assertGreaterThanOrEqual($mapping === 'initial' ? 1 : 2, (int) $revisions);
      $revision_ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
        ->accessCheck(FALSE)->allRevisions()->condition('nid', $demo->id())->sort('vid')->execute();
      $creation = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision(array_key_first($revision_ids));
      $creation_status = (int) $creation->get('field_status')->target_id;
      $this->assertSame('initial', $statuses[$creation_status]->get('field_open311_mapping')->value);
      if ($mapping !== 'initial') {
        $this->assertNotSame($creation_status, $statusId);
      }
      foreach (array_slice(array_keys($revision_ids), 1) as $vid) {
        $revision = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($vid);
        $this->assertSame(0, (int) $revision->getRevisionUserId());
        $this->assertSame('', RevisionLog::message((string) $revision->getRevisionLogMessage()));
      }
    }
    $this->assertSame(['initial', 'initial', 'initial', 'open', 'open', 'closed', 'closed', 'initial'], $mappings);
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []), 'Provisioning the demo requests sends no mail.');
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
   * Stubs the Open311 processor the report hooks consult.
   *
   * Like production, markaspot_group forces a new report to the
   * jurisdiction's initial status, and service_request writes the creation
   * note through the processor.
   */
  private function stubProcessor(): void {
    $termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $this->container->set('markaspot_open311.processor', new class($termStorage) {

      public function __construct(
        private readonly EntityStorageInterface $termStorage,
      ) {}

      /**
       * Returns the lowest-weight initial status of a jurisdiction.
       */
      public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
        $tids = $this->termStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', 'service_status')
          ->condition('field_jurisdiction', $jurisdictionId)
          ->condition('field_open311_mapping', 'initial')
          ->sort('weight')
          ->range(0, 1)
          ->execute();
        return $tids ? (int) reset($tids) : NULL;
      }

      /**
       * No boilerplates in this test.
       */
      public function getInitialBoilerplateId(?int $jurisdictionId = NULL): ?int {
        return NULL;
      }

      /**
       * Creates a status note like the real processor, without boilerplates.
       */
      public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph {
        $paragraph = Paragraph::create(['type' => 'status']);
        if (!empty($fields['status_term_id'])) {
          $paragraph->set('field_status_term', $fields['status_term_id']);
        }
        $paragraph->save();
        return $paragraph;
      }

    });
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
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_notification', 'boolean');

    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
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
