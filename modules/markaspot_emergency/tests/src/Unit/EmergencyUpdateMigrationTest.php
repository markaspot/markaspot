<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Core\Utility\UpdateException;
use Drupal\markaspot_emergency\Service\EmergencySubmissionIdempotencyLedger;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/markaspot_emergency.install';

/**
 * Tests isolated selection of legacy emergency category snapshots.
 *
 * @group markaspot_emergency
 */
class EmergencyUpdateMigrationTest extends UnitTestCase {

  /**
   * New installs receive the same bounded ledger schema as updated sites.
   */
  public function testEmergencySubmissionIdempotencySchemaIsDeclared(): void {
    $schema = \markaspot_emergency_schema();
    $this->assertArrayHasKey(EmergencySubmissionIdempotencyLedger::TABLE, $schema);
    $definition = $schema[EmergencySubmissionIdempotencyLedger::TABLE];
    $this->assertSame(['idempotency_key'], $definition['primary key']);
    $this->assertSame(36, $definition['fields']['idempotency_key']['length']);
    $this->assertSame(64, $definition['fields']['request_hash']['length']);
    $this->assertArrayHasKey('expires', $definition['indexes']);
  }

  /**
   * Malformed jurisdiction hierarchies stop before update-hook mutations.
   */
  public function testMissingRootsWithJurisdictionGroupsAreRejected(): void {
    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('no root jurisdiction can be resolved');
    _markaspot_emergency_assert_root_discovery_safe([], TRUE);
  }

  /**
   * A genuine legacy scope-zero install remains supported.
   */
  public function testMissingRootsWithoutJurisdictionGroupsRemainScopeZero(): void {
    _markaspot_emergency_assert_root_discovery_safe([], FALSE);
    $this->addToAssertionCount(1);
  }

  /**
   * Duplicate identical snapshots are accepted and normalized.
   */
  public function testIdenticalExactAndGlobalSnapshotsAreAccepted(): void {
    $prefix = 'markaspot_emergency.original_published_tids';
    $this->assertSame([4, 5], _markaspot_emergency_select_legacy_snapshot([
      $prefix => [5, 4],
      $prefix . '.7' => ['4', 5, 5],
    ], 7));
  }

  /**
   * Exact root data must not hide a different global or child snapshot.
   */
  public function testDifferentExactAndGlobalSnapshotsAreRejected(): void {
    $prefix = 'markaspot_emergency.original_published_tids';
    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Several different legacy emergency snapshots');
    _markaspot_emergency_select_legacy_snapshot([
      $prefix => [1, 2],
      $prefix . '.7' => [4, 5],
    ], 7);
  }

  /**
   * A scoped empty snapshot remains distinguishable from a missing snapshot.
   */
  public function testEmptyScopedSnapshotIsPreserved(): void {
    $this->assertSame([], _markaspot_emergency_select_legacy_snapshot([
      'markaspot_emergency.original_published_tids.7' => [],
    ], 7));
  }

  /**
   * Missing snapshots remain missing so the update hook can stop safely.
   */
  public function testMissingSnapshotReturnsNull(): void {
    $this->assertNull(_markaspot_emergency_select_legacy_snapshot([], 7));
  }

  /**
   * Several child-scoped snapshots cannot be assigned by guesswork.
   */
  public function testAmbiguousScopedSnapshotsAreRejected(): void {
    $prefix = 'markaspot_emergency.original_published_tids.';
    $this->expectException(UpdateException::class);
    _markaspot_emergency_select_legacy_snapshot([
      $prefix . '8' => [1],
      $prefix . '9' => [2],
    ], 7);
  }

  /**
   * One child snapshot is still incomplete for a root with siblings.
   */
  public function testSingleNonRootScopedSnapshotIsRejected(): void {
    $this->expectException(UpdateException::class);
    _markaspot_emergency_select_legacy_snapshot([
      'markaspot_emergency.original_published_tids.9' => [1, 2],
    ], 7);
  }

  /**
   * Uninstall recovery includes active records missing from a stale index.
   */
  public function testActiveStateRecordIsRecoveredWithoutIndexEntry(): void {
    $prefix = 'markaspot_emergency.jurisdiction.';

    $this->assertSame([7, 9], _markaspot_emergency_active_root_ids_from_records([
      $prefix . '7' => ['status' => 'active', 'snapshot' => [1, 2]],
      $prefix . '8' => ['status' => 'off'],
      $prefix . '9' => ['status' => 'active', 'snapshot' => []],
    ], [9]));
  }

  /**
   * Pre-update global active State is included even without index or records.
   */
  public function testLegacyGlobalActiveStateIsRecoveredForSingleRoot(): void {
    $this->assertSame([7], _markaspot_emergency_active_root_ids_from_records(
      [],
      [],
      'active',
      [7],
    ));
  }

  /**
   * Global legacy State cannot be guessed across several tenant roots.
   */
  public function testLegacyGlobalActiveStateRejectsMultipleRoots(): void {
    $this->expectException(UpdateException::class);
    _markaspot_emergency_active_root_ids_from_records(
      [],
      [],
      'active',
      [7, 9],
    );
  }

}
