<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Entity\ProtectedGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Tests tenant group delete refusal guards.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
class GroupDeleteRefuseTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * Tests jurisdiction delete is refused while tenant references remain.
   */
  public function testJurisdictionDeleteIsRefusedWithReferences(): void {
    $this->createFixtureTables();
    $database = $this->container->get('database');

    $database->insert('groups')
      ->fields(['id', 'type'])
      ->values([10, 'jur'])
      ->values([11, 'org'])
      ->values([12, 'jur'])
      ->execute();

    $database->insert('node_field_data')
      ->fields(['nid', 'type'])
      ->values([100, 'service_request'])
      ->values([101, 'page'])
      ->values([102, 'service_request'])
      ->execute();

    $database->insert('node__field_jurisdiction')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_jurisdiction_target_id'])
      ->values(['service_request', 0, 100, 10])
      ->values(['page', 0, 101, 10])
      ->execute();

    $database->insert('node_revision__field_jurisdiction')
      ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'field_jurisdiction_target_id'])
      ->values(['service_request', 0, 100, 900, 10])
      ->values(['page', 0, 101, 901, 10])
      ->execute();

    $database->insert('node__field_escalation')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_escalation_target_id'])
      ->values(['service_request', 0, 102, 10])
      ->execute();

    $database->insert('node_revision__field_escalation')
      ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'field_escalation_target_id'])
      ->values(['service_request', 0, 102, 902, 10])
      ->execute();

    $database->insert('group__field_jurisdiction')
      ->fields(['deleted', 'entity_id', 'field_jurisdiction_target_id'])
      ->values([0, 11, 10])
      ->execute();

    $database->insert('group_revision__field_jurisdiction')
      ->fields(['deleted', 'entity_id', 'revision_id', 'field_jurisdiction_target_id'])
      ->values([0, 11, 910, 10])
      ->execute();

    $database->insert('group__field_parent_jurisdiction')
      ->fields(['deleted', 'entity_id', 'field_parent_jurisdiction_target_id'])
      ->values([0, 12, 10])
      ->execute();

    $database->insert('group_revision__field_parent_jurisdiction')
      ->fields(['deleted', 'entity_id', 'revision_id', 'field_parent_jurisdiction_target_id'])
      ->values([0, 12, 912, 10])
      ->execute();

    $database->insert('taxonomy_term__field_jurisdiction')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_jurisdiction_target_id'])
      ->values(['service_category', 0, 300, 10])
      ->execute();

    $database->insert('taxonomy_term_revision__field_jurisdiction')
      ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'field_jurisdiction_target_id'])
      ->values(['service_category', 0, 300, 930, 10])
      ->execute();

    $database->insert('taxonomy_term__field_escalation_target')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_escalation_target_id'])
      ->values(['service_category', 0, 301, 10])
      ->execute();

    $database->insert('taxonomy_term_revision__field_escalation_target')
      ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'field_escalation_target_id'])
      ->values(['service_category', 0, 301, 931, 10])
      ->execute();

    $database->insert('group_relationship_field_data')
      ->fields(['id', 'gid', 'entity_id', 'plugin_id'])
      ->values([1, 10, 100, 'group_node:service_request'])
      ->values([2, 10, 5, 'group_membership'])
      ->execute();

    try {
      markaspot_group_group_predelete($this->mockGroup(10, 'jur', 'Demo Jurisdiction'));
      $this->fail('Expected jurisdiction delete to be refused.');
    }
    catch (EntityStorageException $exception) {
      $message = $exception->getMessage();
      $this->assertStringContainsString('service requests via field_jurisdiction', $message);
      $this->assertStringContainsString('service request revisions via field_jurisdiction', $message);
      $this->assertStringContainsString('other content via field_jurisdiction', $message);
      $this->assertStringContainsString('other content revisions via field_jurisdiction', $message);
      $this->assertStringContainsString('service requests via field_escalation', $message);
      $this->assertStringContainsString('service request revisions via field_escalation', $message);
      $this->assertStringContainsString('organisation groups', $message);
      $this->assertStringContainsString('organisation group revisions', $message);
      $this->assertStringContainsString('child jurisdictions', $message);
      $this->assertStringContainsString('child jurisdiction revisions', $message);
      $this->assertStringContainsString('taxonomy terms via field_jurisdiction', $message);
      $this->assertStringContainsString('taxonomy term revisions via field_jurisdiction', $message);
      $this->assertStringContainsString('taxonomy terms via field_escalation_target', $message);
      $this->assertStringContainsString('taxonomy term revisions via field_escalation_target', $message);
      $this->assertStringNotContainsString('group content relationships', $message);
      $this->assertStringNotContainsString('group_membership', $message);
    }
  }

  /**
   * Tests organisation delete is refused while request references remain.
   */
  public function testOrganisationDeleteIsRefusedWithReferences(): void {
    $this->createFixtureTables();
    $database = $this->container->get('database');

    $database->insert('groups')
      ->fields(['id', 'type'])
      ->values([20, 'org'])
      ->execute();

    $database->insert('node_field_data')
      ->fields(['nid', 'type'])
      ->values([200, 'service_request'])
      ->values([201, 'boilerplate'])
      ->execute();

    $database->insert('node__field_organisation')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_organisation_target_id'])
      ->values(['service_request', 0, 200, 20])
      ->values(['boilerplate', 0, 201, 20])
      ->execute();

    $database->insert('node_revision__field_organisation')
      ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'field_organisation_target_id'])
      ->values(['service_request', 0, 200, 920, 20])
      ->values(['boilerplate', 0, 201, 921, 20])
      ->execute();

    $database->insert('taxonomy_term__field_category_gid')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_category_gid_target_id'])
      ->values(['service_category', 0, 400, 20])
      ->execute();

    $database->insert('taxonomy_term_revision__field_category_gid')
      ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'field_category_gid_target_id'])
      ->values(['service_category', 0, 400, 940, 20])
      ->execute();

    $database->insert('group_relationship_field_data')
      ->fields(['id', 'gid', 'entity_id', 'plugin_id'])
      ->values([1, 20, 200, 'group_node:service_request'])
      ->execute();

    try {
      markaspot_group_group_predelete($this->mockGroup(20, 'org', 'Demo Organisation'));
      $this->fail('Expected organisation delete to be refused.');
    }
    catch (EntityStorageException $exception) {
      $message = $exception->getMessage();
      $this->assertStringContainsString('service requests via field_organisation', $message);
      $this->assertStringContainsString('service request revisions via field_organisation', $message);
      $this->assertStringContainsString('other content via field_organisation', $message);
      $this->assertStringContainsString('other content revisions via field_organisation', $message);
      $this->assertStringContainsString('category mappings via field_category_gid', $message);
      $this->assertStringContainsString('category mapping revisions via field_category_gid', $message);
      $this->assertStringNotContainsString('group content relationships', $message);
    }
  }

  /**
   * Tests deleting an unreferenced tenant group is not blocked.
   */
  public function testUnreferencedGroupDeleteIsAllowed(): void {
    $this->createFixtureTables();

    markaspot_group_group_predelete($this->mockGroup(30, 'jur', 'Empty Jurisdiction'));
    markaspot_group_group_predelete($this->mockGroup(31, 'org', 'Empty Organisation'));

    $this->assertTrue(TRUE);
  }

  /**
   * Tests delete access is refused before Group preDelete removes relationships.
   */
  public function testDeleteAccessBlocksRelationshipOnlyReferences(): void {
    $this->createFixtureTables();
    $database = $this->container->get('database');

    $database->insert('groups')
      ->fields(['id', 'type'])
      ->values([40, 'jur'])
      ->execute();

    $database->insert('group_relationship_field_data')
      ->fields(['id', 'gid', 'entity_id', 'plugin_id'])
      ->values([1, 40, 400, 'group_node:service_request'])
      ->values([2, 40, 5, 'group_membership'])
      ->execute();

    $access = markaspot_group_group_access($this->mockGroup(40, 'jur', 'Relationship Only'), 'delete', $this->createMock(AccountInterface::class));

    $this->assertTrue($access->isForbidden());
    $this->assertStringContainsString('group content relationships', $access->getReason());
    $this->assertStringNotContainsString('group_membership', $access->getReason());
  }

  /**
   * Tests the entity class backstop runs before Group deletes relationships.
   */
  public function testProtectedGroupPreDeleteBlocksRelationshipOnlyReferences(): void {
    $this->createFixtureTables();
    $database = $this->container->get('database');

    $database->insert('groups')
      ->fields(['id', 'type'])
      ->values([50, 'org'])
      ->execute();

    $database->insert('group_relationship_field_data')
      ->fields(['id', 'gid', 'entity_id', 'plugin_id'])
      ->values([1, 50, 500, 'group_node:service_request'])
      ->execute();

    try {
      ProtectedGroup::preDelete($this->createMock(EntityStorageInterface::class), [
        $this->mockGroup(50, 'org', 'Programmatic Delete'),
      ]);
      $this->fail('Expected protected group preDelete to refuse deletion.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('group content relationships', $exception->getMessage());
    }
  }

  /**
   * Tests the group entity class is altered to the protected subclass.
   */
  public function testEntityTypeAlterUsesProtectedGroupClass(): void {
    $entityTypes = [
      'group' => new ContentEntityType([
        'id' => 'group',
        'class' => Group::class,
      ]),
    ];

    markaspot_group_entity_type_alter($entityTypes);

    $this->assertSame(ProtectedGroup::class, $entityTypes['group']->getClass());
  }

  /**
   * Creates the minimal tables used by delete guard queries.
   */
  private function createFixtureTables(): void {
    $schema = $this->container->get('database')->schema();

    $schema->createTable('groups', [
      'fields' => [
        'id' => ['type' => 'int', 'not null' => TRUE],
        'type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
      ],
      'primary key' => ['id'],
    ]);
    $schema->createTable('node_field_data', [
      'fields' => [
        'nid' => ['type' => 'int', 'not null' => TRUE],
        'type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
      ],
      'primary key' => ['nid'],
    ]);
    $schema->createTable('node__field_jurisdiction', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_jurisdiction_target_id'],
    ]);
    $schema->createTable('node_revision__field_jurisdiction', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'not null' => TRUE],
        'field_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['revision_id', 'field_jurisdiction_target_id'],
    ]);
    $schema->createTable('node__field_organisation', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_organisation_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_organisation_target_id'],
    ]);
    $schema->createTable('node_revision__field_organisation', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'not null' => TRUE],
        'field_organisation_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['revision_id', 'field_organisation_target_id'],
    ]);
    $schema->createTable('node__field_escalation', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_escalation_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_escalation_target_id'],
    ]);
    $schema->createTable('node_revision__field_escalation', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'not null' => TRUE],
        'field_escalation_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['revision_id', 'field_escalation_target_id'],
    ]);
    $schema->createTable('group__field_jurisdiction', [
      'fields' => [
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_jurisdiction_target_id'],
    ]);
    $schema->createTable('group_revision__field_jurisdiction', [
      'fields' => [
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'not null' => TRUE],
        'field_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['revision_id', 'field_jurisdiction_target_id'],
    ]);
    $schema->createTable('group__field_parent_jurisdiction', [
      'fields' => [
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_parent_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_parent_jurisdiction_target_id'],
    ]);
    $schema->createTable('group_revision__field_parent_jurisdiction', [
      'fields' => [
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'not null' => TRUE],
        'field_parent_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['revision_id', 'field_parent_jurisdiction_target_id'],
    ]);
    $schema->createTable('taxonomy_term__field_jurisdiction', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_jurisdiction_target_id'],
    ]);
    $schema->createTable('taxonomy_term_revision__field_jurisdiction', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'not null' => TRUE],
        'field_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['revision_id', 'field_jurisdiction_target_id'],
    ]);
    $schema->createTable('taxonomy_term__field_category_gid', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_category_gid_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_category_gid_target_id'],
    ]);
    $schema->createTable('taxonomy_term_revision__field_category_gid', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'not null' => TRUE],
        'field_category_gid_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['revision_id', 'field_category_gid_target_id'],
    ]);
    $schema->createTable('taxonomy_term__field_escalation_target', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_escalation_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_escalation_target_id'],
    ]);
    $schema->createTable('taxonomy_term_revision__field_escalation_target', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'revision_id' => ['type' => 'int', 'not null' => TRUE],
        'field_escalation_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['revision_id', 'field_escalation_target_id'],
    ]);
    $schema->createTable('group_relationship_field_data', [
      'fields' => [
        'id' => ['type' => 'int', 'not null' => TRUE],
        'gid' => ['type' => 'int', 'not null' => TRUE],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'plugin_id' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
      ],
      'primary key' => ['id'],
    ]);
  }

  /**
   * Creates a group test double.
   */
  private function mockGroup(int $id, string $bundle, string $label): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label);

    return $group;
  }

}
