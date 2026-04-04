<?php

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests multi-org diff and sync logic in markaspot_group.
 *
 * Since the group sync functions are procedural and depend on Drupal's
 * service container, these tests validate the core data transformations
 * (diff detection, deduplication, append, remove) using the same array
 * operations that the module's hooks perform.
 *
 * @group markaspot_group
 */
class GroupSyncMultiOrgTest extends UnitTestCase {

  // =========================================================================
  // Presave diff detection tests.
  // These mirror the logic in markaspot_group_node_presave().
  // =========================================================================

  /**
   * Tests that added orgs are correctly detected.
   *
   * Scenario: original [1,2], new [1,2,3] -> added=[3], removed=[].
   */
  public function testPresaveDetectsAddedOrgs(): void {
    $original = [1, 2];
    $new = [1, 2, 3];

    $added = array_values(array_diff($new, $original));
    $removed = array_values(array_diff($original, $new));

    $this->assertEquals([3], $added);
    $this->assertEquals([], $removed);
  }

  /**
   * Tests that removed orgs are correctly detected.
   *
   * Scenario: original [1,2,3], new [1] -> added=[], removed=[2,3].
   */
  public function testPresaveDetectsRemovedOrgs(): void {
    $original = [1, 2, 3];
    $new = [1];

    $added = array_values(array_diff($new, $original));
    $removed = array_values(array_diff($original, $new));

    $this->assertEquals([], $added);
    $this->assertEquals([2, 3], $removed);
  }

  /**
   * Tests that swapped orgs are correctly detected as both add and remove.
   *
   * Scenario: original [1,2], new [2,3] -> added=[3], removed=[1].
   */
  public function testPresaveDetectsSwappedOrgs(): void {
    $original = [1, 2];
    $new = [2, 3];

    $added = array_values(array_diff($new, $original));
    $removed = array_values(array_diff($original, $new));

    $this->assertEquals([3], $added);
    $this->assertEquals([1], $removed);
  }

  /**
   * Tests that duplicate IDs are deduplicated.
   *
   * Scenario: raw field values [1,1,2] -> deduplicated to [1,2].
   * Mirrors the array_unique(array_filter(array_map(...))) pattern in presave.
   */
  public function testPresaveDeduplicatesIds(): void {
    $raw = [
      ['target_id' => '1'],
      ['target_id' => '1'],
      ['target_id' => '2'],
    ];

    $ids = array_unique(array_filter(
      array_map('intval', array_column($raw, 'target_id'))
    ));

    $this->assertEqualsCanonicalizing([1, 2], array_values($ids));
    $this->assertCount(2, $ids);
  }

  /**
   * Tests that no-change scenario is detected (empty diff).
   *
   * Scenario: original [1,2], new [1,2] -> added=[], removed=[], skip sync.
   */
  public function testPresaveDetectsNoChange(): void {
    $original = [1, 2];
    $new = [1, 2];

    $added = array_diff($new, $original);
    $removed = array_diff($original, $new);

    $this->assertEmpty($added);
    $this->assertEmpty($removed);
  }

  /**
   * Tests order-independent comparison (same IDs, different order).
   *
   * Scenario: original [2,1], new [1,2] -> no change detected.
   */
  public function testPresaveIgnoresOrderDifference(): void {
    $original = [2, 1];
    $new = [1, 2];

    $added = array_diff($new, $original);
    $removed = array_diff($original, $new);

    $this->assertEmpty($added);
    $this->assertEmpty($removed);
  }

  // =========================================================================
  // Relationship-to-field sync tests.
  // These mirror _markaspot_group_sync_relationship_to_field().
  // =========================================================================

  /**
   * Tests that insert appends a new org to existing field values.
   *
   * Scenario: existing [1,2], insert org 3 -> result [1,2,3].
   */
  public function testSyncRelationshipToFieldAppendsOnInsert(): void {
    $currentIds = [1, 2];
    $insertGroupId = 3;

    // Mirror the insert logic from the module.
    if (!in_array($insertGroupId, $currentIds, TRUE)) {
      $currentIds[] = $insertGroupId;
    }

    $result = array_map(
      static fn(int $id) => ['target_id' => $id],
      $currentIds
    );

    $this->assertEquals([
      ['target_id' => 1],
      ['target_id' => 2],
      ['target_id' => 3],
    ], $result);
  }

  /**
   * Tests that insert does not duplicate an already-present org.
   *
   * Scenario: existing [1,2], insert org 2 -> result [1,2] (no duplicate).
   */
  public function testSyncRelationshipToFieldSkipsDuplicateOnInsert(): void {
    $currentIds = [1, 2];
    $insertGroupId = 2;

    if (!in_array($insertGroupId, $currentIds, TRUE)) {
      $currentIds[] = $insertGroupId;
    }

    $this->assertEquals([1, 2], $currentIds);
  }

  /**
   * Tests that delete removes a specific org from the field.
   *
   * Scenario: existing [1,2,3], delete org 2 -> result [1,3].
   */
  public function testSyncRelationshipToFieldRemovesOnDelete(): void {
    $currentIds = [1, 2, 3];
    $deleteGroupId = 2;

    // Mirror the delete logic from the module.
    $updatedIds = array_values(array_diff($currentIds, [$deleteGroupId]));

    $this->assertEquals([1, 3], $updatedIds);

    // Verify the field value format.
    $value = array_map(
      static fn(int $id) => ['target_id' => $id],
      $updatedIds
    );

    $this->assertEquals([
      ['target_id' => 1],
      ['target_id' => 3],
    ], $value);
  }

  /**
   * Tests that deleting the last org results in NULL (empty field).
   *
   * Scenario: existing [1], delete org 1 -> result NULL.
   */
  public function testSyncRelationshipToFieldClearsOnLastDelete(): void {
    $currentIds = [1];
    $deleteGroupId = 1;

    $updatedIds = array_values(array_diff($currentIds, [$deleteGroupId]));
    $value = empty($updatedIds) ? NULL : array_map(
      static fn(int $id) => ['target_id' => $id],
      $updatedIds
    );

    $this->assertNull($value);
  }

  /**
   * Tests that deleting a non-existent org leaves the field unchanged.
   *
   * Scenario: existing [1,2], delete org 5 -> no change.
   */
  public function testSyncRelationshipToFieldNoOpWhenOrgNotPresent(): void {
    $currentIds = [1, 2];
    $deleteGroupId = 5;

    $updatedIds = array_values(array_diff($currentIds, [$deleteGroupId]));

    // Count unchanged means no save needed.
    $this->assertCount(count($currentIds), $updatedIds);
    $this->assertEquals([1, 2], $updatedIds);
  }

}
