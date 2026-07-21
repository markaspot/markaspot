<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Database\Database;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests dashboard preloading for absent and unrelated sparse fieldsets.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
final class DashboardPreloadFieldsetKernelTest extends KernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/markaspot_nuxt.module';

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
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
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
    $this->createField('node', 'service_request', 'field_request_media', 'entity_reference', ['target_type' => 'media']);
    $this->createField('paragraph', 'status', 'field_status_note', 'text_long');
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_status_notes', 'entity_reference_revisions', ['target_type' => 'paragraph']);
    $this->createField('node', 'service_request', 'field_assignee', 'entity_reference', ['target_type' => 'user']);
    $this->createField('node', 'service_request', 'field_assigned_team', 'entity_reference', ['target_type' => 'group']);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * All computed fields are preloaded when no sparse fieldset is given.
   */
  public function testAllComputedFieldsArePreloadedWithoutFieldset(): void {
    $node = $this->createReferencedRequest();

    $queries = $this->invokeNodeLoad($node, []);

    $this->assertQueryForTable($queries, 'file_managed');
    $this->assertQueryForTable($queries, 'paragraphs_item_revision_field_data');
    $this->assertQueryForTable($queries, 'users_field_data');
  }

  /**
   * A fieldset naming no computed field does not trigger preloading.
   *
   * An explicitly empty fieldset belongs here rather than with the absent
   * parameter: JSON:API then serializes no attributes at all, so preloading
   * would load references that never reach the response.
   */
  #[DataProvider('skippedFieldsetProvider')]
  public function testFieldsetWithoutComputedFieldSkipsPreload(string $fieldset): void {
    $node = $this->createReferencedRequest();
    $queries = $this->invokeNodeLoad($node, [
      'fields' => ['node--service_request' => $fieldset],
    ]);

    foreach (['file_managed', 'paragraphs_item_revision_field_data', 'users_field_data'] as $table) {
      $this->assertNoQueryForTable($queries, $table);
    }
  }

  /**
   * Provides sparse fieldsets that must not trigger preloading.
   */
  public static function skippedFieldsetProvider(): array {
    return [
      'unrelated field' => ['request_id'],
      'empty string' => [''],
    ];
  }

  /**
   * Creates a request referencing data for every computed field.
   */
  private function createReferencedRequest(): Node {
    $file = File::create([
      'uri' => 'public://preload-fieldset.jpg',
      'status' => 1,
    ]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image',
      'name' => 'Preload fieldset image',
      'status' => 1,
      'field_media_image' => ['target_id' => $file->id()],
    ]);
    $media->save();
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
    ]);
    $paragraph->save();
    $assignee = User::create([
      'name' => 'dashboard-assignee',
      'status' => 1,
    ]);
    $assignee->save();
    $this->container->get('current_user')->setAccount(User::load(1));

    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Dashboard preload request',
      'status' => 1,
      'field_request_media' => $media->id(),
      'field_status_notes' => [[
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ]],
      'field_assignee' => $assignee->id(),
    ]);

    foreach (['media', 'file', 'image_style', 'paragraph', 'taxonomy_term', 'user', 'group'] as $entity_type_id) {
      $this->container->get('entity_type.manager')->getStorage($entity_type_id)->resetCache();
    }
    return $node;
  }

  /**
   * Invokes the node load hook on a JSON:API collection request.
   */
  private function invokeNodeLoad(Node $node, array $parameters): array {
    $request = Request::create('/jsonapi/node/service_request', 'GET', $parameters);
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection');
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      Database::startLog('dashboard-fieldset-preload');
      markaspot_nuxt_node_load([$node]);
      return Database::getLog('dashboard-fieldset-preload');
    }
    finally {
      $request_stack->pop();
    }
  }

  /**
   * Creates a minimal field instance.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'type' => $type,
      'settings' => $settings,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'public'],
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
   * Asserts that the query log contains a table name.
   */
  private function assertQueryForTable(array $queries, string $table): void {
    $this->assertTrue($this->hasQueryForTable($queries, $table), sprintf('A query loaded %s.', $table));
  }

  /**
   * Asserts that the query log does not contain a table name.
   */
  private function assertNoQueryForTable(array $queries, string $table): void {
    $this->assertFalse($this->hasQueryForTable($queries, $table), sprintf('No query loaded %s.', $table));
  }

  /**
   * Checks whether the query log contains a table name.
   */
  private function hasQueryForTable(array $queries, string $table): bool {
    foreach ($queries as $entry) {
      if (str_contains((string) ($entry['query'] ?? ''), $table)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
