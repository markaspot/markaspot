<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Service\GroupIntegrityChecker;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests group integrity checker SQL behavior.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
class GroupIntegrityCheckerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * Tests checker queries against representative drift fixtures.
   */
  public function testCheckReportsExpectedDriftCounts(): void {
    $this->createFixtureTables();
    $this->insertFixtureRows();

    $checker = new GroupIntegrityChecker(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
    );

    $summary = $checker->summary();
    $checks = $checker->check();

    $this->assertSame(1, $summary['relationship_missing_group']);
    $this->assertSame(1, $summary['relationship_missing_node']);
    $this->assertSame(1, $summary['relationship_missing_user']);
    $this->assertSame(1, $summary['field_organisation_missing_group']);
    $this->assertSame(1, $summary['field_organisation_missing_node']);
    $this->assertSame(1, $summary['field_jurisdiction_missing_group']);
    $this->assertSame(1, $summary['field_jurisdiction_missing_node']);
    $this->assertSame(2, $summary['org_missing_jurisdiction']);
    $this->assertSame(1, $summary['org_child_jurisdiction']);
    $this->assertSame(1, $summary['jur_parent_missing_group']);
    $this->assertSame(1, $summary['jur_parent_self_reference']);
    $this->assertSame(2, $summary['jur_parent_cycle']);
    $this->assertSame(3, $summary['jur_field_missing_relationship']);
    $this->assertSame(1, $summary['jur_relationship_missing_field']);
    $this->assertSame(3, $summary['org_relationship_missing_field']);
    $correctness = $checker->correctness();
    $this->assertNotEmpty($correctness['missing_context']['rows']);
    $this->assertNotEmpty($correctness['unresolved_expected_org']['rows']);
    $this->assertNotEmpty($correctness['expected_relationship_missing']['rows']);
    $this->assertNotEmpty($correctness['unexpected_relationship']['rows']);
    $this->assertNotFalse(array_search(17, array_column($correctness['expected_relationship_missing']['rows'], 'expected_group_id'), TRUE));
    $this->assertNotFalse(array_search(18, array_column($correctness['unexpected_relationship']['rows'], 'actual_group_id'), TRUE));
    $this->assertSame([
      'field_organisation_invalid_removed' => 1,
      'jur_field_missing_relationship_created' => 3,
      'jur_relationship_missing_field_mirrored' => 1,
      'jur_relationship_missing_field_skipped' => 0,
      'org_relationship_missing_field_mirrored' => 1,
      'org_relationship_missing_field_skipped' => 2,
    ], $checker->repairMirrorDrift(TRUE));
    $this->assertSame([
      'expected_org_field_backfilled' => 1,
      'expected_org_relationship_created' => 1,
      'expected_org_skipped' => 2,
    ], $checker->repairExpectedOrganisationAssignments(TRUE));
    $this->assertSame('node', $checks['jur_field_missing_relationship']['rows'][0]['entity_type']);
    $this->assertSame('service_request', $checks['jur_field_missing_relationship']['rows'][0]['entity_bundle']);
    $this->assertSame('group_relationship', $checks['jur_field_missing_relationship']['rows'][0]['missing_entity_type']);
    $this->assertSame('group_relationship', $checks['org_relationship_missing_field']['rows'][0]['entity_type']);
    $this->assertSame('node', $checks['org_relationship_missing_field']['rows'][0]['related_entity_type']);
    $this->assertSame('field_organisation', $checks['org_relationship_missing_field']['rows'][0]['missing_field']);
    $this->assertSame('field_jurisdiction', $checks['org_missing_jurisdiction']['rows'][0]['missing_field']);
    $this->assertSame('field_jurisdiction', $checks['org_child_jurisdiction']['rows'][0]['field_name']);
    $this->assertSame('field_parent_jurisdiction', $checks['jur_parent_missing_group']['rows'][0]['field_name']);
    $this->assertSame('field_parent_jurisdiction', $checks['jur_parent_cycle']['rows'][0]['field_name']);
    $this->assertNotContains(35, array_column($checks['jur_parent_cycle']['rows'], 'entity_id'));
  }

  /**
   * Creates the minimal tables used by GroupIntegrityChecker queries.
   */
  protected function createFixtureTables(): void {
    $schema = $this->container->get('database')->schema();

    $schema->createTable('groups', [
      'fields' => [
        'id' => ['type' => 'int', 'not null' => TRUE],
        'type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
      ],
      'primary key' => ['id'],
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
    $schema->createTable('node_field_data', [
      'fields' => [
        'nid' => ['type' => 'int', 'not null' => TRUE],
        'type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
      ],
      'primary key' => ['nid'],
    ]);
    $schema->createTable('users_field_data', [
      'fields' => [
        'uid' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['uid'],
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
    $schema->createTable('node__field_jurisdiction', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_jurisdiction_target_id'],
    ]);
    $schema->createTable('node__field_category', [
      'fields' => [
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_category_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_category_target_id'],
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
    $schema->createTable('group__field_jurisdiction', [
      'fields' => [
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_jurisdiction_target_id'],
    ]);
    $schema->createTable('group__field_parent_jurisdiction', [
      'fields' => [
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_parent_jurisdiction_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_parent_jurisdiction_target_id'],
    ]);
    $schema->createTable('group__field_service_categories', [
      'fields' => [
        'deleted' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_service_categories_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['entity_id', 'field_service_categories_target_id'],
    ]);
  }

  /**
   * Inserts representative clean and drifting rows.
   */
  protected function insertFixtureRows(): void {
    $database = $this->container->get('database');

    $database->insert('groups')
      ->fields(['id', 'type'])
      ->values([1, 'jur'])
      ->values([2, 'org'])
      ->values([4, 'jur'])
      ->values([17, 'org'])
      ->values([18, 'org'])
      ->values([30, 'jur'])
      ->values([31, 'jur'])
      ->values([32, 'jur'])
      ->values([33, 'jur'])
      ->values([35, 'jur'])
      ->values([34, 'org'])
      ->values([36, 'jur'])
      ->values([37, 'org'])
      ->execute();

    $database->insert('node_field_data')
      ->fields(['nid', 'type'])
      ->values([10, 'service_request'])
      ->values([12, 'service_request'])
      ->values([11, 'service_request'])
      ->values([14, 'service_request'])
      ->values([15, 'service_request'])
      ->values([16, 'service_request'])
      ->values([20, 'service_request'])
      ->values([21, 'service_request'])
      ->values([22, 'service_request'])
      ->values([23, 'service_request'])
      ->values([24, 'service_request'])
      ->execute();

    $database->insert('users_field_data')
      ->fields(['uid'])
      ->values([7])
      ->execute();

    $database->insert('group_relationship_field_data')
      ->fields(['id', 'gid', 'entity_id', 'plugin_id'])
      ->values([1, 999, 7, 'group_membership'])
      ->values([2, 2, 999, 'group_node:service_request'])
      ->values([3, 2, 10, 'group_node:service_request'])
      ->values([4, 1, 10, 'group_node:service_request'])
      ->values([5, 1, 999, 'group_membership'])
      ->values([6, 1, 15, 'group_node:service_request'])
      ->values([7, 2, 16, 'group_node:service_request'])
      ->values([8, 1, 20, 'group_node:service_request'])
      ->values([9, 1, 21, 'group_node:service_request'])
      ->values([10, 17, 21, 'group_node:service_request'])
      ->values([11, 1, 22, 'group_node:service_request'])
      ->values([12, 18, 22, 'group_node:service_request'])
      ->execute();

    $database->insert('node__field_organisation')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_organisation_target_id'])
      ->values(['service_request', 0, 10, 2])
      ->values(['service_request', 0, 12, 999])
      ->values(['service_request', 0, 13, 1])
      ->execute();

    $database->insert('node__field_jurisdiction')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_jurisdiction_target_id'])
      ->values(['service_request', 0, 10, 1])
      ->values(['service_request', 0, 11, 2])
      ->values(['service_request', 0, 14, 4])
      ->values(['service_request', 0, 20, 1])
      ->values(['service_request', 0, 21, 1])
      ->values(['service_request', 0, 22, 1])
      ->values(['service_request', 0, 23, 1])
      ->values(['service_request', 0, 24, 1])
      ->values(['service_request', 0, 999, 1])
      ->execute();

    $database->insert('node__field_category')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_category_target_id'])
      ->values(['service_request', 0, 10, 100])
      ->values(['service_request', 0, 21, 100])
      ->values(['service_request', 0, 22, 100])
      ->values(['service_request', 0, 23, 999])
      ->values(['service_request', 0, 24, 100])
      ->execute();

    $database->insert('taxonomy_term__field_category_gid')
      ->fields(['bundle', 'deleted', 'entity_id', 'field_category_gid_target_id'])
      ->values(['service_category', 0, 100, 17])
      ->execute();

    $database->insert('group__field_jurisdiction')
      ->fields(['deleted', 'entity_id', 'field_jurisdiction_target_id'])
      ->values([0, 17, 1])
      ->values([0, 18, 1])
      ->values([0, 34, 999])
      ->values([0, 37, 36])
      ->execute();

    $database->insert('group__field_parent_jurisdiction')
      ->fields(['deleted', 'entity_id', 'field_parent_jurisdiction_target_id'])
      ->values([0, 30, 999])
      ->values([0, 31, 31])
      ->values([0, 32, 33])
      ->values([0, 33, 32])
      ->values([0, 35, 32])
      ->values([0, 36, 1])
      ->execute();

    $database->insert('group__field_service_categories')
      ->fields(['deleted', 'entity_id', 'field_service_categories_target_id'])
      ->values([0, 17, 100])
      ->execute();
  }

}
