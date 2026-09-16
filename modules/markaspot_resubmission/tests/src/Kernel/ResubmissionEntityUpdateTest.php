<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_resubmission\Kernel;

use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_resubmission\Entity\ResubmissionReminder;

/**
 * Tests recovery of migrated reminder tables without installed definitions.
 *
 * @group markaspot_resubmission
 */
final class ResubmissionEntityUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'markaspot_resubmission',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $module = $this->container->get('module_handler')->getModule('markaspot_resubmission');
    $this->assertSame(realpath(dirname(__DIR__, 3)), realpath($module->getPath()));
    $this->container->get('module_handler')
      ->loadInclude('markaspot_resubmission', 'install');
  }

  /**
   * Existing rows survive registration and queries work after cache clearing.
   */
  public function testExistingTableRepair(): void {
    $record = $this->createMigratedTable();
    // Prime the storage handler before repair to exercise cache invalidation.
    $this->container->get('entity_type.manager')->getStorage('resubmission_reminder');

    _markaspot_resubmission_repair_entity_definition();

    $this->assertRepaired($record);
    $this->assertSame(
      'Resubmission reminder entity already installed.',
      markaspot_resubmission_update_9008(),
    );
    $this->assertRepaired($record);
  }

  /**
   * Older tables acquire reminder_scope without changing their audit data.
   */
  public function testMissingScopeRepair(): void {
    $record = $this->createMigratedTable();
    $database = $this->container->get('database');
    $database->schema()->dropField('resubmission_reminder', 'reminder_scope');
    $this->container->get('keyvalue')->get('entity.storage_schema.sql')
      ->delete('resubmission_reminder.field_schema_data.reminder_scope');

    markaspot_resubmission_update_9008();

    $record['reminder_scope'] = ResubmissionReminder::REMINDER_SCOPE_ORG;
    $this->assertRepaired($record);
  }

  /**
   * A missing table follows normal installation and supports entity writes.
   */
  public function testMissingTableInstallation(): void {
    $this->assertFalse($this->container->get('database')->schema()
      ->tableExists('resubmission_reminder'));
    markaspot_resubmission_update_9008();
    $this->assertNotNull($this->container->get('entity.definition_update_manager')
      ->getEntityType('resubmission_reminder'));
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('resubmission_reminder');
    $entity = $storage->create([
      'nid' => 42,
      'sent_timestamp' => 1700000000,
      'recipient_email' => 'reminder@example.com',
      'status' => 'sent',
    ]);
    $entity->save();
    $this->assertSame([$entity->id() => $entity->id()], $storage->getQuery()
      ->accessCheck(FALSE)->condition('nid', 42)->execute());
  }

  /**
   * An installed type is left untouched, including its field definitions.
   */
  public function testInstalledTypeIsUnchanged(): void {
    $this->installEntitySchema('resubmission_reminder');
    $repository = $this->container->get('entity.last_installed_schema.repository');
    $before = serialize($repository->getLastInstalledFieldStorageDefinitions('resubmission_reminder'));
    $this->assertSame(
      'Resubmission reminder entity already installed.',
      markaspot_resubmission_update_9008(),
    );
    $this->assertSame($before, serialize($repository->getLastInstalledFieldStorageDefinitions('resubmission_reminder')));
  }

  /**
   * Creates a migrated audit row and removes only installed definitions.
   *
   * @return array<string, mixed>
   *   The original database row, including its generated identifier.
   */
  private function createMigratedTable(): array {
    $this->installEntitySchema('resubmission_reminder');
    $database = $this->container->get('database');
    $database->insert('resubmission_reminder')->fields([
      'uuid' => 'c764b264-6c79-45a1-9aa3-fac1a05a456a',
      'nid' => 42,
      'sent_timestamp' => 1700000000,
      'recipient_email' => 'reminder@example.com',
      'status' => 'sent',
      'reminder_count' => 3,
      'reminder_scope' => ResubmissionReminder::REMINDER_SCOPE_USER,
      'node_status' => 'open',
      'error_message' => NULL,
    ])->execute();
    $record = $database->select('resubmission_reminder', 'r')
      ->fields('r')->execute()->fetchAssoc();
    $this->container->get('entity.last_installed_schema.repository')
      ->deleteLastInstalledDefinition('resubmission_reminder');
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $updates = $this->container->get('entity.definition_update_manager');
    $this->assertNull($updates->getEntityType('resubmission_reminder'));
    $this->assertSame(EntityDefinitionUpdateManagerInterface::DEFINITION_CREATED,
      $updates->getChangeList()['resubmission_reminder']['entity_type']);
    $this->assertTrue($database->schema()->tableExists('resubmission_reminder'));
    return $record;
  }

  /**
   * Checks registration, queryability and preservation of every stored value.
   *
   * @param array<string, mixed> $record
   *   The expected audit row.
   */
  private function assertRepaired(array $record): void {
    $updates = $this->container->get('entity.definition_update_manager');
    $this->assertNotNull($updates->getEntityType('resubmission_reminder'));
    $this->assertNotNull($updates->getFieldStorageDefinition('nid', 'resubmission_reminder'));
    $this->assertArrayNotHasKey('resubmission_reminder', $updates->getChangeList());
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('resubmission_reminder');
    $this->assertEquals([$record['id'] => $record['id']], $storage->getQuery()
      ->accessCheck(FALSE)->condition('nid', 42)->execute());
    $rows = $this->container->get('database')
      ->select('resubmission_reminder', 'r')->fields('r')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $this->assertCount(1, $rows);
    // Adding a column can change SELECT * column order, but never its values.
    ksort($record);
    ksort($rows[0]);
    $this->assertSame($record, $rows[0]);
    $this->assertSame($record['uuid'], $storage->load($record['id'])->uuid());
  }

}
