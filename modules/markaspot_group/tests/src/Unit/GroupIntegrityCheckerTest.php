<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_group\Service\GroupIntegrityChecker;
use Drupal\Tests\UnitTestCase;

/**
 * Tests group integrity checker aggregation behavior.
 *
 * @group markaspot_group
 */
class GroupIntegrityCheckerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests that summary reports row counts for every integrity check.
   */
  public function testSummaryCountsRowsByCheck(): void {
    $checker = new TestGroupIntegrityChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $summary = $checker->summary();

    $this->assertSame(2, $summary['relationship_missing_group']);
    $this->assertSame(1, $summary['relationship_missing_node']);
    $this->assertSame(0, $summary['relationship_missing_user']);
    $this->assertSame(1, $summary['field_organisation_missing_group']);
    $this->assertSame(1, $summary['field_organisation_missing_node']);
    $this->assertSame(0, $summary['field_jurisdiction_missing_group']);
    $this->assertSame(1, $summary['field_jurisdiction_missing_node']);
    $this->assertSame(1, $summary['org_missing_jurisdiction']);
    $this->assertSame(1, $summary['org_child_jurisdiction']);
    $this->assertSame(1, $summary['jur_parent_missing_group']);
    $this->assertSame(1, $summary['jur_parent_self_reference']);
    $this->assertSame(2, $summary['jur_parent_cycle']);
    $this->assertSame(1, $summary['jur_field_missing_relationship']);
    $this->assertSame(1, $summary['jur_relationship_missing_field']);
    $this->assertSame(1, $summary['org_relationship_missing_field']);
  }

  /**
   * Tests that dry-run repair reports deletable orphan relationship counts.
   */
  public function testDryRunRepairReportsOnlyOrphanRelationshipCounts(): void {
    $checker = new TestGroupIntegrityChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $checker->repairOrphanRelationships(TRUE);

    $this->assertSame([
      'relationship_missing_group_skipped' => 2,
      'relationship_missing_node' => 0,
      'relationship_missing_user' => 0,
    ], $result);
  }

  /**
   * Tests that dry-run mirror repair reports conservative repair counts.
   */
  public function testDryRunMirrorRepairReportsRepairableDriftCounts(): void {
    $checker = new TestGroupIntegrityChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $checker->repairMirrorDrift(TRUE);

    $this->assertSame([
      'field_organisation_invalid_removed' => 1,
      'jur_field_missing_relationship_created' => 1,
      'jur_relationship_missing_field_mirrored' => 1,
      'jur_relationship_missing_field_skipped' => 0,
      'org_relationship_missing_field_mirrored' => 1,
      'org_relationship_missing_field_skipped' => 0,
    ], $result);
  }

  /**
   * Tests that dry-run expected org backfill reports repairable writes.
   */
  public function testDryRunExpectedOrganisationBackfillReportsRepairableWrites(): void {
    $checker = new TestGroupIntegrityChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $checker->repairExpectedOrganisationAssignments(TRUE);

    $this->assertSame([
      'expected_org_field_backfilled' => 1,
      'expected_org_relationship_created' => 1,
      'expected_org_skipped' => 0,
    ], $result);
  }

  /**
   * Tests expected org backfill skips existing manual organisation values.
   */
  public function testExpectedOrganisationBackfillSkipsManualOrganisation(): void {
    $checker = new TestGroupIntegrityManualOrgChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $checker->repairExpectedOrganisationAssignments(TRUE);

    $this->assertSame([
      'expected_org_field_backfilled' => 0,
      'expected_org_relationship_created' => 0,
      'expected_org_skipped' => 1,
    ], $result);
  }

  /**
   * Tests expected org backfill skips ambiguous organisation derivation.
   */
  public function testExpectedOrganisationBackfillSkipsAmbiguousDerivation(): void {
    $checker = new TestGroupIntegrityAmbiguousOrgChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $checker->repairExpectedOrganisationAssignments(TRUE);

    $this->assertSame([
      'expected_org_field_backfilled' => 0,
      'expected_org_relationship_created' => 0,
      'expected_org_skipped' => 1,
    ], $result);
  }

  /**
   * Tests that applied repair excludes rows whose group is missing.
   */
  public function testAppliedRepairDoesNotDeleteMissingGroupRows(): void {
    $connection = $this->createMock(Connection::class);
    $connection->expects($this->once())
      ->method('startTransaction')
      ->willReturn(new class {

        /**
         * Rolls back the fake transaction.
         */
        public function rollBack(): void {}

      });

    $nodeRelationship = $this->createMock(GroupRelationshipInterface::class);
    $nodeRelationship->expects($this->once())->method('delete');
    $userRelationship = $this->createMock(GroupRelationshipInterface::class);
    $userRelationship->expects($this->once())->method('delete');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadMultiple')
      ->with([
        12 => 12,
        13 => 13,
      ])
      ->willReturn([
        12 => $nodeRelationship,
        13 => $userRelationship,
      ]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('group_relationship')
      ->willReturn($storage);

    $checker = new TestGroupIntegrityRepairChecker($connection, $entityTypeManager);

    $result = $checker->repairOrphanRelationships(FALSE);

    $this->assertSame([
      'relationship_missing_group_skipped' => 2,
      'relationship_missing_node' => 1,
      'relationship_missing_user' => 1,
    ], $result);
  }

  /**
   * Tests that applied mirror repair reports actual write counts.
   */
  public function testAppliedMirrorRepairCountsAppliedWrites(): void {
    $connection = $this->createMock(Connection::class);
    $connection->expects($this->once())
      ->method('startTransaction')
      ->willReturn(new class {

        /**
         * Rolls back the fake transaction.
         */
        public function rollBack(): void {}

      });

    $checker = new TestGroupIntegrityMirrorApplyChecker(
      $connection,
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $checker->repairMirrorDrift(FALSE);

    $this->assertSame([
      'field_organisation_invalid_removed' => 1,
      'jur_field_missing_relationship_created' => 1,
      'jur_relationship_missing_field_mirrored' => 1,
      'jur_relationship_missing_field_skipped' => 0,
      'org_relationship_missing_field_mirrored' => 1,
      'org_relationship_missing_field_skipped' => 0,
    ], $result);
    $this->assertSame([
      'remove:3:field_organisation:99',
      'create:1:4',
      'set:5:field_jurisdiction:1',
      'set:6:field_organisation:2',
    ], $checker->writes);
  }

  /**
   * Tests that expected org backfill applies field and relationship writes.
   */
  public function testAppliedExpectedOrganisationBackfillCountsWrites(): void {
    // The apply path wraps writes in the service_request sync lock, which
    // resolves \Drupal::lock() from the global container. Provide a minimal
    // container so this test does not depend on leftovers from sibling tests.
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('lock', $lock);
    \Drupal::setContainer($container);

    $connection = $this->createMock(Connection::class);
    $connection->expects($this->once())
      ->method('startTransaction')
      ->willReturn(new class {

        /**
         * Rolls back the fake transaction.
         */
        public function rollBack(): void {}

      });

    $checker = new TestGroupIntegrityMirrorApplyChecker(
      $connection,
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $checker->repairExpectedOrganisationAssignments(FALSE);

    $this->assertSame([
      'expected_org_field_backfilled' => 1,
      'expected_org_relationship_created' => 1,
      'expected_org_skipped' => 0,
    ], $result);
    $this->assertSame([
      'set:6:field_organisation:2',
      'create:2:6',
    ], $checker->writes);
  }

  /**
   * Tests that correctness groups expected-vs-actual diff rows.
   */
  public function testCorrectnessReportsDiffRows(): void {
    $checker = new TestGroupIntegrityChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $checker->correctness();

    $this->assertSame(1, count($result['missing_context']['rows']));
    $this->assertSame(1, count($result['unresolved_expected_org']['rows']));
    $this->assertSame(1, count($result['expected_relationship_missing']['rows']));
    $this->assertSame(1, count($result['unexpected_relationship']['rows']));
  }

}

/**
 * Test double for deterministic checker rows.
 */
class TestGroupIntegrityChecker extends GroupIntegrityChecker {

  /**
   * {@inheritdoc}
   */
  protected function relationshipMissingGroupRows(): array {
    return [
      ['id' => 10, 'gid' => 99, 'entity_id' => 1, 'plugin_id' => 'group_membership'],
      ['id' => 11, 'gid' => 100, 'entity_id' => 2, 'plugin_id' => 'group_node:service_request'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function relationshipMissingNodeRows(): array {
    return [
      ['id' => 11, 'gid' => 100, 'entity_id' => 2, 'plugin_id' => 'group_node:service_request'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function relationshipMissingUserRows(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function fieldOrganisationMissingGroupRows(): array {
    return [
      ['entity_id' => 3, 'field_organisation_target_id' => 99],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function fieldOrganisationMissingNodeRows(): array {
    return [
      ['entity_id' => 999, 'field_organisation_target_id' => 2],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function fieldJurisdictionMissingGroupRows(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function fieldJurisdictionMissingNodeRows(): array {
    return [
      ['entity_id' => 999, 'field_jurisdiction_target_id' => 1],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function orgMissingJurisdictionRows(): array {
    return [
      ['id' => 2, 'type' => 'org'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function orgChildJurisdictionRows(): array {
    return [
      [
        'id' => 3,
        'type' => 'org',
        'field_jurisdiction_target_id' => 4,
        'field_parent_jurisdiction_target_id' => 1,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function jurParentMissingGroupRows(): array {
    return [
      ['entity_id' => 5, 'field_parent_jurisdiction_target_id' => 999],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function jurParentSelfReferenceRows(): array {
    return [
      ['entity_id' => 6, 'field_parent_jurisdiction_target_id' => 6],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function jurParentCycleRows(): array {
    return [
      ['entity_id' => 7, 'field_parent_jurisdiction_target_id' => 8],
      ['entity_id' => 8, 'field_parent_jurisdiction_target_id' => 7],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function jurFieldMissingRelationshipRows(): array {
    return [
      ['entity_id' => 4, 'field_jurisdiction_target_id' => 1],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function jurRelationshipMissingFieldRows(): array {
    return [
      ['id' => 12, 'gid' => 1, 'entity_id' => 5, 'plugin_id' => 'group_node:service_request'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function orgRelationshipMissingFieldRows(): array {
    return [
      ['id' => 13, 'gid' => 2, 'entity_id' => 6, 'plugin_id' => 'group_node:service_request'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function correctnessDiffRows(): array {
    return [
      'missing_context' => [
        ['entity_id' => 1],
      ],
      'unresolved_expected_org' => [
        ['entity_id' => 2],
      ],
      'expected_relationship_missing' => [
        [
          'entity_id' => 6,
          'entity_type' => 'node',
          'entity_bundle' => 'service_request',
          'field_category_target_id' => 10,
          'field_jurisdiction_target_id' => 1,
          'expected_group_id' => 2,
          'expected_group_type' => 'org',
          'expected_source' => 'field_category_and_field_jurisdiction',
        ],
      ],
      'unexpected_relationship' => [
        ['entity_id' => 5, 'actual_group_id' => 6],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function nodeFieldTargetIds(int $nid, string $table, string $column): array {
    if ($table === 'node__field_jurisdiction' && $nid === 6) {
      return [1];
    }
    if ($table === 'node__field_category' && $nid === 6) {
      return [10];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function serviceRequestRelationshipGroupIds(int $nid, string $groupType): array {
    if ($nid === 5 && $groupType === 'jur') {
      return [1];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function orgGroupMatchesJurisdiction(int $orgGroupId, int $jurisdictionId): bool {
    return $orgGroupId === 2 && $jurisdictionId === 1;
  }

  /**
   * {@inheritdoc}
   */
  protected function deriveExpectedOrganisationId(int $categoryId, int $jurisdictionId): int {
    return $categoryId === 10 && $jurisdictionId === 1 ? 2 : 0;
  }

  /**
   * {@inheritdoc}
   */
  protected function deriveUniqueExpectedOrganisationId(int $categoryId, int $jurisdictionId): int {
    return $categoryId === 10 && $jurisdictionId === 1 ? 2 : 0;
  }

}

/**
 * Test double with an existing manual organisation assignment.
 */
class TestGroupIntegrityManualOrgChecker extends TestGroupIntegrityChecker {

  /**
   * {@inheritdoc}
   */
  protected function nodeFieldTargetIds(int $nid, string $table, string $column): array {
    if ($table === 'node__field_organisation' && $nid === 6) {
      return [9];
    }

    return parent::nodeFieldTargetIds($nid, $table, $column);
  }

}

/**
 * Test double with ambiguous expected organisation resolution.
 */
class TestGroupIntegrityAmbiguousOrgChecker extends TestGroupIntegrityChecker {

  /**
   * {@inheritdoc}
   */
  protected function deriveUniqueExpectedOrganisationId(int $categoryId, int $jurisdictionId): int {
    return 0;
  }

}

/**
 * Test double for deterministic repair rows.
 */
class TestGroupIntegrityRepairChecker extends TestGroupIntegrityChecker {

  /**
   * {@inheritdoc}
   */
  protected function relationshipMissingGroupRows(): array {
    return [
      ['id' => 10, 'gid' => 99, 'entity_id' => 1, 'plugin_id' => 'group_membership'],
      ['id' => 11, 'gid' => 100, 'entity_id' => 2, 'plugin_id' => 'group_node:service_request'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function relationshipMissingNodeRows(): array {
    return [
      ['id' => 11, 'gid' => 100, 'entity_id' => 2, 'plugin_id' => 'group_node:service_request'],
      ['id' => 12, 'gid' => 2, 'entity_id' => 3, 'plugin_id' => 'group_node:service_request'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function relationshipMissingUserRows(): array {
    return [
      ['id' => 10, 'gid' => 99, 'entity_id' => 1, 'plugin_id' => 'group_membership'],
      ['id' => 13, 'gid' => 1, 'entity_id' => 4, 'plugin_id' => 'group_membership'],
    ];
  }

}

/**
 * Test double for deterministic mirror apply writes.
 */
class TestGroupIntegrityMirrorApplyChecker extends TestGroupIntegrityChecker {

  /**
   * Recorded write operations.
   *
   * @var array<int, string>
   */
  public array $writes = [];

  /**
   * {@inheritdoc}
   */
  protected function removeNodeFieldTarget(int $nid, string $fieldName, int $targetId): bool {
    $this->writes[] = sprintf('remove:%d:%s:%d', $nid, $fieldName, $targetId);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function isJurFieldMissingRelationshipRow(array $row): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function createServiceRequestRelationship(int $gid, int $nid): bool {
    $this->writes[] = sprintf('create:%d:%d', $gid, $nid);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function setNodeFieldTargets(int $nid, string $fieldName, array $targetIds): bool {
    $this->writes[] = sprintf('set:%d:%s:%s', $nid, $fieldName, implode(',', $targetIds));
    return TRUE;
  }

}
