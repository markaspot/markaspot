<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\KeyValueStore\DatabaseStorage;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\markaspot_dashboard\Migration\LegacyNotesMigration;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Symfony\Component\DependencyInjection\Container;

/**
 * Proves offline migration revisions, idempotency and lifecycle isolation.
 *
 * @group markaspot_dashboard
 */
final class LegacyNotesMigrationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'file',
    'entity_reference_revisions', 'paragraphs', 'legacy_notes_migration_test',
  ];

  /**
   * The offline migration under test.
   */
  private LegacyNotesMigration $migration;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('database')->schema()->createTable('key_value', DatabaseStorage::schemaDefinition());
    foreach (['user', 'node', 'paragraph', 'file'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);
    User::create(['uid' => 0, 'name' => ''])->save();
    User::create(['uid' => 1, 'name' => 'admin'])->save();
    User::create(['uid' => 2, 'name' => 'legacy-owner'])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    ParagraphsType::create(['id' => 'internal_remark', 'label' => 'Remark'])->save();
    $this->field('node', 'service_request', 'field_notes', 'text_long');
    $this->field('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'node']);
    $this->field('node', 'service_request', 'field_internal_remark', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    foreach (['field_status', 'field_organisation', 'field_assigned', 'field_notification'] as $name) {
      $this->field('node', 'service_request', $name, 'integer');
    }
    $this->field('paragraph', 'internal_remark', 'field_internal_remark_text', 'text_long');
    $this->field('paragraph', 'internal_remark', 'field_author', 'entity_reference', ['target_type' => 'user']);
    $this->migration = new LegacyNotesMigration($this->container, $this->container->get('database'), $this->container->get('entity_type.manager'));
  }

  /**
   * Adds a fixture field.
   */
  private function field(string $entity_type, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'entity_type' => $entity_type, 'field_name' => $name, 'type' => $type,
      'settings' => $settings, 'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entity_type, 'bundle' => $bundle, 'field_name' => $name,
    ])->save();
  }

  /**
   * Creates a report with two historical revisions and an existing remark.
   */
  private function fixture(?string $format = 'plain_text', string $text = "Old note.\nSecond line."): Node {
    $remark = Paragraph::create([
      'type' => 'internal_remark', 'field_author' => 2,
      'field_internal_remark_text' => ['value' => 'Existing remark', 'format' => 'plain_text'],
    ]);
    $remark->save();
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Synthetic migration test', 'uid' => 2,
      'status' => 0, 'created' => 1600000000, 'changed' => 1700000000,
      'field_notes' => ['value' => $text, 'format' => $format],
      'field_jurisdiction' => 69, 'field_organisation' => 71,
      'field_status' => 5, 'field_assigned' => 22, 'field_notification' => 1,
      'field_internal_remark' => [['target_id' => $remark->id(), 'target_revision_id' => $remark->getRevisionId()]],
    ]);
    $node->save();
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Existing historical revision');
    $node->save();
    return $node;
  }

  /**
   * Captures table contents including revisions and access grants.
   */
  private function tables(): array {
    $database = $this->container->get('database');
    $result = [];
    foreach ($database->schema()->findTables('%') as $table) {
      if (str_starts_with($table, 'node') || str_starts_with($table, 'paragraph') || $table === 'key_value') {
        $rows = $database->select($table, 't')->fields('t')->execute()->fetchAll();
        $encoded = array_map('serialize', $rows);
        sort($encoded);
        $result[$table] = $encoded;
      }
    }
    return $result;
  }

  /**
   * Dry-run and rerun write nothing; apply preserves records without hooks.
   */
  public function testPreservationAndIdempotency(): void {
    $node = $this->fixture();
    $nid = (int) $node->id();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $original = $storage->loadUnchanged($nid)->toArray();
    $old_vid = (int) $node->getRevisionId();
    $old_revision = $storage->loadRevision($old_vid)->toArray();
    $old_refs = $node->get('field_internal_remark')->getValue();
    $this->container->get('state')->set('legacy_notes_test.tripwire', TRUE);
    $before = $this->tables();
    $this->assertNotEmpty($before);
    $this->migration->preparePlanBatch([$nid]);
    $this->assertSame('would_migrate', $this->migration->process($nid, 69)['status']);
    $this->assertSame($before, $this->tables());
    $this->assertSame([$nid], $this->migration->candidates(69, 0, 100));
    $this->assertSame([], $this->migration->candidates(70, 0, 100));
    $result = $this->migration->process($nid, 69, TRUE);
    $this->assertSame('migrated', $result['status']);
    $fresh = $storage->loadUnchanged($nid);
    $this->assertGreaterThan($old_vid, (int) $fresh->getRevisionId());
    $this->assertSame($old_revision, $storage->loadRevision($old_vid)->toArray());
    $refs = $fresh->get('field_internal_remark')->getValue();
    $this->assertCount(2, $refs);
    $this->assertSame($old_refs[0], $refs[0]);
    foreach ($original as $name => $value) {
      if (!in_array($name, [
        'vid', 'revision_timestamp', 'revision_uid', 'revision_log',
        'revision_default', 'revision_translation_affected', 'field_internal_remark',
      ], TRUE)) {
        $this->assertSame($value, $fresh->get($name)->getValue(), $name);
      }
    }
    $paragraph = Paragraph::load($refs[1]['target_id']);
    $this->assertSame((int) $paragraph->getRevisionId(), (int) $refs[1]['target_revision_id']);
    $this->assertSame((string) $nid, $paragraph->get('parent_id')->value);
    $this->assertSame('node', $paragraph->get('parent_type')->value);
    $this->assertSame('field_internal_remark', $paragraph->get('parent_field_name')->value);
    $this->assertTrue($paragraph->get('field_author')->isEmpty());
    $this->assertTrue($paragraph->getBehaviorSetting('markaspot_legacy_notes', 'exclude_from_ai'));
    $this->assertStringContainsString("Old note.\nSecond line.", $paragraph->get('field_internal_remark_text')->value);
    $after = $this->tables();
    $this->assertSame($before['node_access'], $after['node_access']);
    foreach ($before as $table => $rows) {
      if (str_contains($table, 'revision')) {
        $this->assertSame([], array_values(array_diff($rows, $after[$table])), $table . ': historical rows preserved');
      }
    }
    $this->assertSame('already_migrated', $this->migration->process($nid, 69, TRUE)['status']);
    $this->assertSame($after, $this->tables());
  }

  /**
   * HTML becomes safe readable text; raw source and its format remain intact.
   */
  public function testHtmlAndNullFormats(): void {
    foreach (['basic_html', NULL] as $format) {
      $raw = '<p>Ältere Notiz &amp; Inhalt</p><p><a href="https://example.invalid/info">Details</a></p>';
      $node = $this->fixture($format, $raw);
      $this->migration->process((int) $node->id(), 69, TRUE);
      $fresh = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
      $this->assertSame($raw, $fresh->get('field_notes')->value);
      $this->assertSame($format, $fresh->get('field_notes')->format);
      $remark = $fresh->get('field_internal_remark')->get(1)->entity;
      $text = $remark->get('field_internal_remark_text')->value;
      $this->assertSame('plain_text', $remark->get('field_internal_remark_text')->format);
      if ($format === 'basic_html') {
        $this->assertStringContainsString('Ältere Notiz & Inhalt', $text);
        $this->assertStringContainsString('https://example.invalid/info', $text);
        $this->assertStringNotContainsString('<p>', $text);
      }
      else {
        $this->assertStringContainsString($raw, $text);
      }
    }
  }

  /**
   * Failure after paragraph insertion rolls back all rows; retry succeeds.
   */
  public function testRollbackAfterParagraphInsert(): void {
    $node = $this->fixture();
    $this->container->get('state')->set('legacy_notes_test.fail_revision', TRUE);
    $before = $this->tables();
    try {
      $this->migration->process((int) $node->id(), 69, TRUE);
      $this->fail('Expected the injected failure.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Injected revision failure.', $exception->getMessage());
    }
    $this->assertSame($before, $this->tables());
    $this->container->get('state')->set('legacy_notes_test.fail_revision', FALSE);
    $this->assertSame('migrated', $this->migration->process((int) $node->id(), 69, TRUE)['status']);
  }

  /**
   * A changed source fails closed rather than creating another paragraph.
   */
  public function testChangedSourceConflicts(): void {
    $node = $this->fixture();
    $this->migration->process((int) $node->id(), 69, TRUE);
    $fresh = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $fresh->set('field_notes', ['value' => 'Changed after migration', 'format' => 'plain_text']);
    $fresh->save();
    $before = $this->tables();
    try {
      $this->migration->process((int) $node->id(), 69, TRUE);
      $this->fail('Expected source conflict.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('Migration conflict', $exception->getMessage());
    }
    $this->assertSame($before, $this->tables());
  }

  /**
   * A different tenant cannot be processed even by an explicit node ID.
   */
  public function testWrongJurisdictionDenied(): void {
    $node = $this->fixture();
    $this->expectExceptionMessage('outside the exclusive selected jurisdiction');
    $this->migration->process((int) $node->id(), 70, TRUE);
  }

  /**
   * Pending revisions must not be superseded by a migration snapshot.
   */
  public function testPendingRevisionDenied(): void {
    $node = $this->fixture();
    $node->setNewRevision(TRUE);
    $node->isDefaultRevision(FALSE);
    $node->save();
    $this->expectExceptionMessage('pending revisions require individual review');
    $this->migration->process((int) $node->id(), 69, TRUE);
  }

  /**
   * Covers the CLI preflight without installing the full group dependency tree.
   */
  public function testPreflightScopeSchemaAndReaders(): void {
    $manager = $this->container->get('entity_field.manager');
    $node_fields = $manager->getFieldDefinitions('node', 'service_request');
    $paragraph_fields = $manager->getFieldDefinitions('paragraph', 'internal_remark');
    $source = FieldStorageConfig::load('node.field_notes');
    $source->setThirdPartySetting('field_permissions', 'permission_type', 'custom');
    $cases = [
      ['jur', TRUE, 'moderator', 'custom', ['view field_notes', 'view field_internal_remark'], NULL],
      ['jur', TRUE, 'editorial_board', 'custom', ['view field_notes', 'view field_internal_remark'], NULL],
      ['jur', TRUE, 'contractor', 'custom', [], NULL],
      ['org', TRUE, 'moderator', 'custom', [], 'Select an existing jurisdiction'],
      ['jur', FALSE, 'moderator', 'custom', [], 'Unsupported legacy or internal remark field schema'],
      ['jur', TRUE, 'contractor', 'custom', ['view field_internal_remark'], 'Target readership is broader'],
      ['jur', TRUE, 'authenticated', 'custom', ['view field_internal_remark'], 'Target readership is broader'],
      [
        'jur', TRUE, 'anonymous', 'custom',
        ['view field_notes', 'view field_internal_remark'], 'Target readership is broader',
      ],
      ['jur', TRUE, 'moderator', 'private', [], 'individual access-policy review'],
    ];
    foreach ($cases as [$bundle, $valid_schema, $role_id, $permission_type, $permissions, $expected]) {
      $source->setThirdPartySetting('field_permissions', 'permission_type', $permission_type);
      $fields = $this->createMock(EntityFieldManagerInterface::class);
      $fields->method('getFieldDefinitions')->willReturnCallback(
        static fn(string $type): array => $type === 'node' ? $node_fields : ($valid_schema ? $paragraph_fields : [])
      );
      $group = $this->createMock(EntityInterface::class);
      $group->method('bundle')->willReturn($bundle);
      $group_storage = $this->createMock(EntityStorageInterface::class);
      $group_storage->method('load')->willReturn($group);
      $source_storage = $this->createMock(EntityStorageInterface::class);
      $source_storage->method('load')->willReturn($source);
      $role_storage = $this->createMock(EntityStorageInterface::class);
      $role_storage->method('loadMultiple')->willReturn([
        Role::create(['id' => $role_id, 'permissions' => $permissions]),
      ]);
      $entities = $this->createMock(EntityTypeManagerInterface::class);
      $entities->method('getStorage')->willReturnMap([
        ['group', $group_storage], ['field_storage_config', $source_storage], ['user_role', $role_storage],
      ]);
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturn(TRUE);
      $container = new Container();
      $container->set('module_handler', $modules);
      $container->set('entity_field.manager', $fields);
      $migration = new LegacyNotesMigration($container, $this->container->get('database'), $entities);
      try {
        $migration->preflight(69);
        $this->assertNull($expected);
      }
      catch (\RuntimeException | \InvalidArgumentException $exception) {
        $this->assertNotNull($expected);
        $this->assertStringContainsString($expected, $exception->getMessage());
      }
    }
  }

  /**
   * Removed references must not cause duplicate imports on subsequent runs.
   */
  public function testRemovedReferenceConflicts(): void {
    $node = $this->fixture();
    $this->migration->process((int) $node->id(), 69, TRUE);
    $fresh = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $fresh->get('field_internal_remark')->removeItem(1);
    $fresh->save();
    $before = $this->tables();
    try {
      $this->migration->process((int) $node->id(), 69, TRUE);
      $this->fail('Expected reference conflict.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('Migration conflict', $exception->getMessage());
    }
    $this->assertSame($before, $this->tables());
  }

  /**
   * Target timestamp or publication edits invalidate the marker too.
   */
  public function testChangedTargetMetadataConflicts(): void {
    $node = $this->fixture();
    $this->migration->process((int) $node->id(), 69, TRUE);
    $fresh = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $paragraph = $fresh->get('field_internal_remark')->get(1)->entity;
    $paragraph->set('created', 42);
    $paragraph->save();
    $before = $this->tables();
    try {
      $this->migration->process((int) $node->id(), 69, TRUE);
      $this->fail('Expected target metadata conflict.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('Migration conflict', $exception->getMessage());
    }
    $this->assertSame($before, $this->tables());
  }

  /**
   * Invalid arguments and unacknowledged applies fail before any mutation.
   */
  public function testCliGuards(): void {
    $cases = [
      [],
      ['--jurisdiction=69', '--unknown'],
      ['--jurisdiction=69', '--limit=1001'],
      ['--jurisdiction=69', '--apply'],
      ['--jurisdiction=69', '--apply', '--write-freeze-confirmed'],
    ];
    $before = $this->tables();
    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- The included Drush script consumes $extra.
    foreach ($cases as $extra) {
      try {
        include dirname(__DIR__, 5) . '/scripts/migrate-legacy-notes.php';
        $this->fail('Expected invalid arguments or missing maintenance window.');
      }
      catch (\RuntimeException | \InvalidArgumentException $exception) {
        $this->assertNotEmpty($exception->getMessage());
      }
      $this->assertSame($before, $this->tables());
    }
  }

}
