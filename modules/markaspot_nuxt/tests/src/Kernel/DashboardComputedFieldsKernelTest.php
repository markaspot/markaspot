<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Database\Database;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_nuxt\Field\DashboardReferenceData;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests computed fields used by the zero-include dashboard list.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
final class DashboardComputedFieldsKernelTest extends KernelTestBase {

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
    'file',
    'image',
    'node',
    'taxonomy',
    'media',
    'entity_reference_revisions',
    'paragraphs',
    'field_permissions',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('media');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'image']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create([
      'uid' => 1,
      'name' => 'root',
      'status' => 1,
    ])->save();

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Service status',
    ])->save();
    ParagraphsType::create([
      'id' => 'status',
      'label' => 'Status',
    ])->save();
    MediaType::create([
      'id' => 'request_image',
      'label' => 'Request image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    $this->createField('media', 'request_image', 'field_media_image', 'image', ['uri_scheme' => 'public']);
    $this->createField('node', 'service_request', 'field_request_media', 'entity_reference', ['target_type' => 'media'], -1, 'custom');
    $this->createField('media', 'request_image', 'field_ai_hazard_category', 'string');
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField(
      'node',
      'service_request',
      'field_status_notes',
      'entity_reference_revisions',
      ['target_type' => 'paragraph'],
      -1,
      'custom'
    );
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * The media field returns thumbnail metadata and handles missing targets.
   */
  public function testDashboardMediaValueAndMissingReferences(): void {
    $file = File::create([
      'uri' => 'public://dashboard-photo.jpg',
      'status' => 1,
    ]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Dashboard photo',
      'status' => 1,
      'field_ai_hazard_category' => 'fire',
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => 'Broken streetlight',
      ],
    ]);
    $media->save();
    $node = $this->createRequest([
      'field_request_media' => [$media->id()],
    ]);
    $viewer = $this->createUser(['access content', 'view media', 'view field_request_media']);
    $this->container->get('current_user')->setAccount($viewer);

    $request = Request::create('/jsonapi/node/service_request', 'GET', [
      'fields' => [
        'node--service_request' => 'dashboard_media,field_hazard_category',
      ],
    ]);
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $value = json_decode((string) $node->get('dashboard_media')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    finally {
      $request_stack->pop();
    }
    $this->assertSame($media->uuid(), $value[0]['uuid']);
    $this->assertStringContainsString('/styles/thumbnail/public/dashboard-photo.jpg', $value[0]['url']);
    $this->assertSame($file->uuid(), $value[0]['cache_key']);
    $this->assertSame('Broken streetlight', $value[0]['alt']);
    $this->assertSame('fire', $value[0]['hazard_category']);
    $this->assertTrue($node->get('dashboard_media')->access('view', $viewer));
    $this->assertFalse($node->get('dashboard_media')->access('edit', $viewer));

    $media->delete();
    $node = $this->createRequest([
      'field_request_media' => [['target_id' => $media->id()]],
    ]);
    $this->assertTrue($node->get('dashboard_media')->isEmpty());

    $missing = $this->createRequest([
      'field_request_media' => [['target_id' => 999999]],
    ]);
    $this->assertTrue($missing->get('dashboard_media')->isEmpty());
  }

  /**
   * Hazard metadata does not vary with the node sparse fieldset.
   */
  public function testDashboardMediaHazardCategoryIgnoresSparseFieldset(): void {
    $file = File::create([
      'uri' => 'public://hazard-dashboard-photo.jpg',
      'status' => 1,
    ]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Hazard dashboard photo',
      'status' => 1,
      'field_ai_hazard_category' => 'fire',
      'field_media_image' => ['target_id' => $file->id()],
    ]);
    $media->save();
    $node = $this->createRequest([
      'field_request_media' => [$media->id()],
    ]);
    $viewer = $this->createUser(['access content', 'view media', 'view field_request_media']);
    $this->container->get('current_user')->setAccount($viewer);
    $request_stack = $this->container->get('request_stack');
    $values = [];

    $requests = [
      Request::create('/jsonapi/node/service_request'),
      Request::create('/jsonapi/node/service_request', 'GET', [
        'fields' => ['node--service_request' => 'dashboard_media'],
      ]),
    ];
    foreach ($requests as $request) {
      $request_stack->push($request);
      try {
        $values[] = DashboardReferenceData::media($node, $viewer);
      }
      finally {
        $request_stack->pop();
      }
    }

    $this->assertSame($values[0], $values[1]);
    $this->assertSame('fire', $values[0][0]['hazard_category']);
  }

  /**
   * Unpublished media withheld by entity access never produces a URL.
   */
  public function testDashboardMediaFiltersUnpublishedTargets(): void {
    $file = File::create([
      'uri' => 'public://unpublished-photo.jpg',
      'status' => 1,
    ]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Unpublished photo',
      'status' => 0,
      'field_media_image' => ['target_id' => $file->id()],
    ]);
    $media->save();
    $node = $this->createRequest([
      'field_request_media' => [$media->id()],
    ]);
    $viewer = $this->createUser(['access content', 'view field_request_media']);
    $this->container->get('current_user')->setAccount($viewer);

    $this->assertFalse($media->access('view', $viewer));
    $this->assertTrue($node->get('dashboard_media')->isEmpty());
  }

  /**
   * Media summary access mirrors the protected source field.
   */
  public function testDashboardMediaAccessMirrorsSourceField(): void {
    $node = $this->createRequest();
    $allowed = $this->createUser(['access content', 'view field_request_media']);
    $denied = $this->createUser(['access content']);

    $this->assertSame(
      $node->get('field_request_media')->access('view', $allowed),
      $node->get('dashboard_media')->access('view', $allowed)
    );
    $this->assertSame(
      $node->get('field_request_media')->access('view', $denied),
      $node->get('dashboard_media')->access('view', $denied)
    );
    $this->assertFalse($node->get('dashboard_media')->access('view', $denied));
  }

  /**
   * Reference preloading follows the route name, not the JSON:API path prefix.
   */
  public function testDashboardPreloadSupportsRandomJsonApiPaths(): void {
    $nodes = [];
    foreach (['first', 'second'] as $name) {
      $file = File::create([
        'uri' => sprintf('public://%s-dashboard-photo.jpg', $name),
        'status' => 1,
      ]);
      $file->save();
      $media = Media::create([
        'bundle' => 'request_image',
        'name' => ucfirst($name) . ' photo',
        'status' => 1,
        'field_media_image' => ['target_id' => $file->id()],
      ]);
      $media->save();
      $nodes[] = $this->createRequest([
        'field_request_media' => [$media->id()],
      ]);
    }
    $viewer = $this->createUser(['access content', 'view media', 'view field_request_media']);
    $this->container->get('current_user')->setAccount($viewer);
    foreach (['media', 'file', 'image_style'] as $entity_type_id) {
      $this->container->get('entity_type.manager')->getStorage($entity_type_id)->resetCache();
    }

    $request = Request::create('/opaque-api-prefix/node/service_request', 'GET', [
      'fields' => [
        'node--service_request' => 'dashboard_media',
      ],
    ]);
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection');
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      Database::startLog('dashboard-preload');
      markaspot_nuxt_node_load($nodes);
      $preload_queries = Database::getLog('dashboard-preload');
      $this->assertNotEmpty($this->referenceEntityQueries($preload_queries));

      Database::startLog('dashboard-render');
      foreach ($nodes as $node) {
        $this->assertFalse($node->get('dashboard_media')->isEmpty());
      }
      $render_queries = Database::getLog('dashboard-render');
      $this->assertSame([], $this->referenceEntityQueries($render_queries));
    }
    finally {
      $request_stack->pop();
    }
  }

  /**
   * Status summaries preserve note values and omit hidden status terms.
   */
  public function testDashboardStatusNotesValueAndMissingReferences(): void {
    $term = Term::create([
      'vid' => 'service_status',
      'name' => 'In progress',
      'status' => 1,
    ]);
    $term->save();
    $paragraph = Paragraph::create([
      'type' => 'status',
      'field_status_note' => ['value' => 'Crew assigned.', 'format' => 'plain_text'],
      'field_status_term' => $term->id(),
      'created' => 1_720_000_000,
    ]);
    $paragraph->save();
    $node = $this->createRequest([
      'field_status_notes' => [[
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ]],
    ]);
    $viewer = $this->createUser(['access content', 'view field_status_notes']);
    $this->container->get('current_user')->setAccount($viewer);

    $value = json_decode((string) $node->get('dashboard_status_notes')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('Crew assigned.', $value[0]['status_note']);
    $this->assertSame($term->uuid(), $value[0]['status_uuid']);
    $this->assertSame((int) $term->id(), $value[0]['status_tid']);
    $this->assertSame(gmdate(\DateTimeInterface::RFC3339, 1_720_000_000), $value[0]['updated_datetime']);
    $this->assertTrue($node->get('dashboard_status_notes')->access('view', $viewer));
    $this->assertFalse($node->get('dashboard_status_notes')->access('edit', $viewer));

    $term->delete();
    $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('paragraph')->resetCache();
    $node = $this->createRequest([
      'field_status_notes' => [[
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ]],
    ]);
    $value = json_decode((string) $node->get('dashboard_status_notes')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('Crew assigned.', $value[0]['status_note']);
    $this->assertArrayNotHasKey('status_uuid', $value[0]);
    $this->assertArrayNotHasKey('status_tid', $value[0]);

    $paragraph->delete();
    $node = $this->createRequest([
      'field_status_notes' => [[
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ]],
    ]);
    $this->assertTrue($node->get('dashboard_status_notes')->isEmpty());

    $missing = $this->createRequest([
      'field_status_notes' => [[
        'target_id' => 999999,
        'target_revision_id' => 999999,
      ]],
    ]);
    $this->assertTrue($missing->get('dashboard_status_notes')->isEmpty());
  }

  /**
   * Status summary access mirrors the protected source field.
   */
  public function testDashboardStatusNotesAccessMirrorsSourceField(): void {
    $node = $this->createRequest();
    $allowed = $this->createUser(['access content', 'view field_status_notes']);
    $denied = $this->createUser(['access content']);

    $this->assertSame(
      $node->get('field_status_notes')->access('view', $allowed),
      $node->get('dashboard_status_notes')->access('view', $allowed)
    );
    $this->assertSame(
      $node->get('field_status_notes')->access('view', $denied),
      $node->get('dashboard_status_notes')->access('view', $denied)
    );
    $this->assertFalse($node->get('dashboard_status_notes')->access('view', $denied));
  }

  /**
   * Anonymous source access never exposes the dashboard status summary.
   */
  public function testDashboardStatusNotesRejectsAnonymousSourceAccess(): void {
    $node = $this->createRequest();
    $anonymous_role = Role::load(RoleInterface::ANONYMOUS_ID);
    $this->assertNotNull($anonymous_role);
    $anonymous_role->grantPermission('view field_status_notes')->save();
    $anonymous = User::load(0);
    $this->assertNotNull($anonymous);

    $this->assertTrue($node->get('field_status_notes')->access('view', $anonymous));
    $this->assertFalse($node->get('dashboard_status_notes')->access('view', $anonymous));
  }

  /**
   * Creates a minimal field instance.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $settings = [], int $cardinality = 1, string $permissionType = 'public'): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => $permissionType],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
      'settings' => $settings,
    ])->save();
  }

  /**
   * Creates a service request with optional field values.
   */
  private function createRequest(array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'service_request',
      'title' => 'Dashboard request',
      'status' => 1,
    ]);
    return $node;
  }

  /**
   * Filters query-log entries for media and file entity loads.
   *
   * @param array<int, array<string, mixed>> $queries
   *   Database query log.
   *
   * @return array<int, array<string, mixed>>
   *   Reference-entity SELECT queries.
   */
  private function referenceEntityQueries(array $queries): array {
    return array_values(array_filter(
      $queries,
      static function (array $entry): bool {
        $query = (string) ($entry['query'] ?? '');
        return str_contains($query, 'media_field_data') || str_contains($query, 'file_managed');
      }
    ));
  }

}
