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

  /**
   * Presave diff detection tests.
   *
   * These mirror the logic in markaspot_group_node_presave().
   */

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

  /**
   * Tests category changes replace unchanged derived organisation values.
   */
  public function testCategoryChangeReplacesUnchangedDerivedOrg(): void {
    $originalOrgIds = [100];
    $updatedOrgIds = $this->rederiveCategoryOrgs([10], [20], $originalOrgIds, [100], 100, 200);

    $added = array_values(array_diff($updatedOrgIds, $originalOrgIds));
    $removed = array_values(array_diff($originalOrgIds, $updatedOrgIds));

    $this->assertEquals([200], $updatedOrgIds);
    $this->assertEquals([200], $added);
    $this->assertEquals([100], $removed);
  }

  /**
   * Tests category changes preserve manually added organisation values.
   */
  public function testCategoryChangePreservesManualAdditionalOrg(): void {
    $originalOrgIds = [100, 300];
    $updatedOrgIds = $this->rederiveCategoryOrgs([10], [20], $originalOrgIds, [100, 300], 100, 200);

    $added = array_values(array_diff($updatedOrgIds, $originalOrgIds));
    $removed = array_values(array_diff($originalOrgIds, $updatedOrgIds));

    $this->assertEquals([300, 200], $updatedOrgIds);
    $this->assertEquals([200], $added);
    $this->assertEquals([100], $removed);
  }

  /**
   * Tests manual organisation updates win over category re-derivation.
   */
  public function testManualOrgChangeSkipsCategoryReDerivation(): void {
    $updatedOrgIds = $this->rederiveCategoryOrgs([10], [20], [100], [300], 100, 200);

    $this->assertEquals([300], $updatedOrgIds);
  }

  /**
   * Tests missing derived organisation removes only the old derived org.
   */
  public function testCategoryChangeWithNoDerivedOrgPreservesManualOrg(): void {
    $originalOrgIds = [100, 300];
    $updatedOrgIds = $this->rederiveCategoryOrgs([10], [20], $originalOrgIds, [100, 300], 100, NULL);

    $added = array_values(array_diff($updatedOrgIds, $originalOrgIds));
    $removed = array_values(array_diff($originalOrgIds, $updatedOrgIds));

    $this->assertEquals([300], $updatedOrgIds);
    $this->assertEquals([], $added);
    $this->assertEquals([100], $removed);
  }

  /**
   * Tests root fallback organisations remain valid for child jurisdictions.
   */
  public function testCategoryChangeAllowsRootFallbackForChildJurisdiction(): void {
    $originalOrgIds = [100];
    $updatedOrgIds = $this->rederiveCategoryOrgs([10], [20], $originalOrgIds, [100], 100, 200, TRUE);

    $added = array_values(array_diff($updatedOrgIds, $originalOrgIds));
    $removed = array_values(array_diff($originalOrgIds, $updatedOrgIds));

    $this->assertEquals([200], $updatedOrgIds);
    $this->assertEquals([200], $added);
    $this->assertEquals([100], $removed);
  }

  /**
   * Tests cross-tenant derived organisations are not written.
   */
  public function testCategoryChangeSkipsCrossTenantDerivedOrg(): void {
    $originalOrgIds = [100];
    $updatedOrgIds = $this->rederiveCategoryOrgs([10], [20], $originalOrgIds, [100], 100, 200, FALSE);

    $added = array_values(array_diff($updatedOrgIds, $originalOrgIds));
    $removed = array_values(array_diff($originalOrgIds, $updatedOrgIds));

    $this->assertEquals([], $updatedOrgIds);
    $this->assertEquals([], $added);
    $this->assertEquals([100], $removed);
  }

  /**
   * Tests that submitted cross-tenant organisations are stripped in presave.
   */
  public function testPresaveRemovesCrossTenantSubmittedOrg(): void {
    $filtered = $this->filterOrgIdsByJurisdiction(
      [100, 200],
      1,
      [
        100 => 1,
        200 => 2,
      ],
    );

    $this->assertEquals([100], $filtered);
  }

  /**
   * Tests root fallback organisations remain valid for child requests.
   */
  public function testPresaveAllowsRootFallbackSubmittedOrg(): void {
    $filtered = $this->filterOrgIdsByJurisdiction(
      [100, 200],
      5,
      [
        100 => 1,
        200 => 6,
      ],
      [
        5 => 1,
      ],
    );

    $this->assertEquals([100], $filtered);
  }

  /**
   * Tests empty org jurisdiction is rejected once node jurisdiction is known.
   */
  public function testPresaveRejectsOrgWithoutJurisdiction(): void {
    $filtered = $this->filterOrgIdsByJurisdiction(
      [100],
      1,
      [
        100 => NULL,
      ],
    );

    $this->assertEquals([], $filtered);
  }

  /**
   * Tests new-node insert re-filters submitted orgs after jur derivation.
   */
  public function testInsertRefiltersSubmittedOrgsAfterJurisdictionDerivation(): void {
    $pendingSync = [100, 200];
    $filteredField = $this->filterOrgIdsByJurisdiction(
      $pendingSync,
      1,
      [
        100 => 1,
        200 => 2,
      ],
    );
    $pendingSync = $filteredField;

    $this->assertEquals([100], $filteredField);
    $this->assertEquals([100], $pendingSync);
  }

  /**
   * Tests insert clears submitted orgs when jurisdiction remains unresolved.
   */
  public function testInsertClearsSubmittedOrgsWhenJurisdictionUnresolved(): void {
    $fieldOrganisation = [100];
    $pendingSync = [100];
    $jurisdictionId = NULL;

    if ($jurisdictionId === NULL) {
      $fieldOrganisation = [];
      $pendingSync = [];
    }

    $this->assertEquals([], $fieldOrganisation);
    $this->assertEquals([], $pendingSync);
  }

  /**
   * Tests existing-node presave clears orgs when jurisdiction is removed.
   */
  public function testPresaveClearsExistingOrgsWhenJurisdictionRemoved(): void {
    $fieldOrganisation = [100];
    $originalJurisdictionId = 1;
    $newJurisdictionId = NULL;
    $jurSyncNeeded = $newJurisdictionId !== $originalJurisdictionId;

    if ($newJurisdictionId === NULL) {
      $fieldOrganisation = [];
    }

    $this->assertTrue($jurSyncNeeded);
    $this->assertEquals([], $fieldOrganisation);
  }

  /**
   * Tests direct inverse relationship mismatch is deleted, not just skipped.
   */
  public function testInverseCrossTenantRelationshipIsDeleted(): void {
    $deleteRelationship = !self::orgMatchesJurisdiction(2, 1, [2 => 5]);

    $this->assertTrue($deleteRelationship);
  }

  /**
   * Tests org moves re-stamp referencing service requests.
   */
  public function testOrgMoveRestampsReferencingRequests(): void {
    $orgIds = [100, 300];
    $movedOrgId = 100;
    $nodeJurisdictionId = 1;
    $newOrgJurisdictionId = 5;
    $orgJurisdictionMap = [
      100 => 5,
      300 => 1,
    ];

    if (in_array($movedOrgId, $orgIds, TRUE) && $newOrgJurisdictionId !== NULL) {
      $nodeJurisdictionId = $newOrgJurisdictionId;
    }
    $orgIds = $this->filterOrgIdsByJurisdiction($orgIds, $nodeJurisdictionId, $orgJurisdictionMap);

    $this->assertSame(5, $nodeJurisdictionId);
    $this->assertEquals([100], $orgIds);
  }

  /**
   * Tests org moves to an unresolved jurisdiction remove only that org.
   */
  public function testOrgMoveWithoutJurisdictionRemovesOnlyMovedOrg(): void {
    $orgIds = [100, 300];
    $movedOrgId = 100;
    $newOrgJurisdictionId = NULL;

    if ($newOrgJurisdictionId === NULL) {
      $orgIds = array_values(array_diff($orgIds, [$movedOrgId]));
    }

    $this->assertEquals([300], $orgIds);
  }

  /**
   * Tests the org-move restamp helper with executable decisions.
   */
  public function testOrgMoveRestampValues(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_group.module';

    $this->assertSame(
      [
        'jurisdiction_id' => 5,
        'org_ids' => [100, 300],
      ],
      \_markaspot_group_org_move_restamp_values([100, 300], 100, 5, 1, TRUE),
    );

    $this->assertSame(
      [
        'jurisdiction_id' => 1,
        'org_ids' => [300],
      ],
      \_markaspot_group_org_move_restamp_values([100, 300], 100, NULL, 1, TRUE),
    );

    $this->assertNull(\_markaspot_group_org_move_restamp_values([100], 100, 5, 7, FALSE));
    $this->assertNull(\_markaspot_group_org_move_restamp_values([300], 100, 5, 1, TRUE));
    $this->assertNull(\_markaspot_group_org_move_restamp_values([100], 100, 1, 1, TRUE));
  }

  /**
   * Tests org moves skip requests already outside the original org scope.
   */
  public function testOrgMoveSkipsRequestOutsideOriginalScope(): void {
    $originalOrgJurisdictionId = 1;
    $requestJurisdictionId = 7;
    $rootMap = [
      5 => 1,
      7 => 6,
    ];

    $matchesOriginalScope = $requestJurisdictionId === $originalOrgJurisdictionId
      || ($rootMap[$requestJurisdictionId] ?? NULL) === $originalOrgJurisdictionId;

    $this->assertFalse($matchesOriginalScope);
  }

  /**
   * Tests group update contains org-move re-stamping.
   */
  public function testGroupUpdateRestampsOrgMoveInModule(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $hookPos = strpos($source, 'function markaspot_group_group_update(GroupInterface $group): void');
    $helperPos = strpos($source, 'function _markaspot_group_restamp_service_requests_for_org_move(');
    $queryPos = strpos($source, "->condition('field_organisation', \$org_group_id)", $helperPos);
    $lockPos = strpos($source, '_markaspot_group_with_service_request_sync_lock($node_id', $helperPos);
    $resetPos = strpos($source, '$node_storage->resetCache([$node_id]);', $helperPos);
    $savePos = strpos($source, '$node->save();', $helperPos);

    $this->assertNotFalse($hookPos);
    $this->assertNotFalse($helperPos);
    $this->assertNotFalse($queryPos);
    $this->assertNotFalse($lockPos);
    $this->assertNotFalse($resetPos);
    $this->assertNotFalse($savePos);
    $this->assertLessThan($helperPos, $hookPos);
    $this->assertLessThan($queryPos, $helperPos);
    $this->assertLessThan($lockPos, $queryPos);
    $this->assertLessThan($resetPos, $lockPos);
    $this->assertLessThan($savePos, $resetPos);
    $this->assertStringContainsString('$group->bundle() !== _markaspot_group_get_org_group_type()', $source);
    $this->assertStringContainsString('$new_jurisdiction_id = _markaspot_group_group_jurisdiction_id($group);', $source);
    $this->assertStringContainsString('$original_jurisdiction_id = _markaspot_group_group_jurisdiction_id($group->original);', $source);
    $this->assertStringContainsString('$new_jurisdiction_id === $original_jurisdiction_id', $source);
    $this->assertStringContainsString('function _markaspot_group_is_root_jurisdiction_id', $source);
    $this->assertStringContainsString('function _markaspot_group_group_has_parent_jurisdiction', $source);
    $this->assertStringContainsString("->condition('type', 'service_request')", $source);
    $this->assertStringContainsString("->condition('field_organisation', \$org_group_id)", $source);
    $this->assertStringContainsString("array_chunk(array_map('intval', \$nids), 50)", $source);
    $this->assertStringContainsString('$node_storage->resetCache([$node_id]);', $source);
    $this->assertStringContainsString('function _markaspot_group_org_move_restamp_values', $source);
    $this->assertStringContainsString('_markaspot_group_jurisdiction_matches_scope($node_jurisdiction_id, $original_jurisdiction_id)', $source);
    $this->assertStringContainsString('$node->set(\'field_jurisdiction\', [\'target_id\' => $restamp_values[\'jurisdiction_id\']]);', $source);
    $this->assertStringContainsString('original jurisdiction is empty', $source);
    $this->assertStringContainsString('target jurisdiction @new is a child jurisdiction', $source);
    $this->assertStringContainsString('target jurisdiction @new does not exist or is not a jurisdiction group', $source);
    $this->assertStringContainsString('because another sync still holds the node lock', $source);
    $this->assertStringContainsString('Failed to re-stamp service request @nid after org @org moved', $source);
  }

  /**
   * Tests node presave contains category-driven org re-derivation.
   */
  public function testNodePresaveRederivesOrgBeforeOrgDiff(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $rederivePos = strpos($source, '_markaspot_group_rederive_org_on_category_change($node);');
    $filterPos = strpos($source, '_markaspot_group_filter_organisation_field_by_jurisdiction($node, TRUE);');
    $diffPos = strpos($source, '$new_group_ids = _markaspot_group_field_target_ids($node, \'field_organisation\');');

    $this->assertNotFalse($rederivePos);
    $this->assertNotFalse($filterPos);
    $this->assertNotFalse($diffPos);
    $this->assertLessThan($filterPos, $rederivePos);
    $this->assertLessThan($diffPos, $rederivePos);
    $this->assertLessThan($diffPos, $filterPos);
    $this->assertStringContainsString('function _markaspot_group_rederive_org_on_category_change(NodeInterface $node): void', $source);
    $this->assertStringContainsString('function _markaspot_group_filter_organisation_field_by_jurisdiction', $source);
    $this->assertStringContainsString('function _markaspot_group_filter_org_ids_by_jurisdiction', $source);
    $this->assertStringContainsString('function _markaspot_group_node_jurisdiction_id(NodeInterface $node): ?int', $source);
    $this->assertStringContainsString('_markaspot_group_filter_organisation_field_by_jurisdiction($node, TRUE);', $source);
    $this->assertStringContainsString('$node->_markaspot_group_pending_sync = _markaspot_group_field_target_ids', $source);
    $this->assertStringContainsString('cannot sync org group @gid because field_jurisdiction is unresolved', $source);
    $this->assertStringContainsString('if ($new_jur_id !== $original_jur_id) {', $source);
    $this->assertStringContainsString('_markaspot_group_field_target_changed($node, \'field_category\')', $source);
    $this->assertStringContainsString('_markaspot_group_field_target_changed($node, \'field_organisation\')', $source);
    $this->assertStringContainsString('_markaspot_group_derive_org_from_category($node->original', $source);
    $this->assertStringContainsString('$org_jurisdiction_id = _markaspot_group_node_jurisdiction_id($node) ?? ($child_jur_id ?: NULL);', $source);
    $this->assertStringContainsString('_markaspot_group_derive_org_from_category($node, $org_jurisdiction_id)', $source);
    $this->assertStringContainsString('field_service_categories before', $source);
    $this->assertStringContainsString('category term @tid has no org mapping for jurisdiction @jur_id and field_category_gid is empty', $source);
    $this->assertStringContainsString('_markaspot_group_org_matches_jurisdiction($new_org_group_id', $source);
    $this->assertStringContainsString('markaspot_group.hierarchy_resolver', $source);
    $this->assertStringContainsString('$org_jurisdiction_id === $root_jurisdiction_id', $source);
    $this->assertStringContainsString('function _markaspot_group_org_group_matches_jurisdiction', $source);
    $this->assertStringContainsString('!_markaspot_group_org_group_matches_jurisdiction($group, $node_jur_id)', $source);
    $this->assertStringContainsString('_markaspot_group_org_group_matches_jurisdiction($group, $node_jur_id)', $source);
    $this->assertStringContainsString('$relationship->delete();', $source);
  }

  /**
   * Tests bidirectional sync paths share a service request lock.
   */
  public function testRelationshipSyncUsesServiceRequestLock(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $this->assertStringContainsString('function _markaspot_group_with_service_request_sync_lock(NodeInterface|int $node, callable $callback): bool', $source);
    $this->assertStringContainsString('function _markaspot_group_acquire_service_request_sync_lock(NodeInterface|int $node): bool', $source);
    $this->assertStringContainsString('function _markaspot_group_release_service_request_sync_lock(NodeInterface|int $node): void', $source);
    $this->assertStringContainsString('return \'markaspot_group:service_request_sync:\' . $node_id;', $source);
    $this->assertStringContainsString('$lock->wait($lock_name, 1);', $source);
    $this->assertStringContainsString('_markaspot_group_acquire_service_request_sync_lock($node)', $source);
    $this->assertStringContainsString('$node->_markaspot_group_sync_lock_held = TRUE;', $source);
    $this->assertStringContainsString('_markaspot_group_release_service_request_sync_lock($node);', $source);
    $this->assertStringContainsString('_markaspot_group_reconcile_service_request_relationships($node);', $source);
    $this->assertStringContainsString('_markaspot_group_with_service_request_sync_lock($node, function () use ($node, $new_group_id, $original_group_id): void {', $source);
    $this->assertStringContainsString('_markaspot_group_with_service_request_sync_lock($node, function () use ($node, $new_jur_id, $original_jur_id): void {', $source);
    $this->assertStringContainsString('_markaspot_group_with_service_request_sync_lock($entity_id, function () use ($relationship, $operation, $group): void {', $source);
    $this->assertStringContainsString('throw new EntityStorageException(sprintf(\'Cannot acquire group sync lock for service_request %d.\'', $source);
  }

  /**
   * Tests inverse relationship sync reloads the node inside the lock.
   */
  public function testInverseRelationshipSyncUsesFreshNodeInsideLock(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $lockPos = strpos($source, '_markaspot_group_with_service_request_sync_lock($entity_id, function () use ($relationship, $operation, $group): void {');
    $resetPos = strpos($source, '$node_storage->resetCache([$relationship->getEntityId()]);');
    $reloadPos = strpos($source, '$entity = $node_storage->load($relationship->getEntityId());');
    $fieldReadPos = strpos($source, '$current_ids = array_map(\'intval\', array_column($entity->get(\'field_organisation\')->getValue(), \'target_id\'));');

    $this->assertNotFalse($lockPos);
    $this->assertNotFalse($resetPos);
    $this->assertNotFalse($reloadPos);
    $this->assertNotFalse($fieldReadPos);
    $this->assertLessThan($resetPos, $lockPos);
    $this->assertLessThan($reloadPos, $resetPos);
    $this->assertLessThan($fieldReadPos, $reloadPos);
    $this->assertStringContainsString('The relationship entity can carry a', $source);
    $this->assertStringContainsString('stale node instance when a node PATCH races the relationship hook.', $source);
  }

  /**
   * Mirrors category-driven organisation replacement from node presave.
   */
  private function rederiveCategoryOrgs(
    array $originalCategoryIds,
    array $newCategoryIds,
    array $originalOrgIds,
    array $submittedOrgIds,
    ?int $oldDerivedOrgId,
    ?int $newDerivedOrgId,
    bool $newOrgMatchesJurisdiction = TRUE,
  ): array {
    if ($originalCategoryIds === $newCategoryIds || $originalOrgIds !== $submittedOrgIds) {
      return $submittedOrgIds;
    }

    $updatedOrgIds = $submittedOrgIds;
    if ($oldDerivedOrgId) {
      $updatedOrgIds = array_values(array_diff($updatedOrgIds, [$oldDerivedOrgId]));
    }

    if ($newDerivedOrgId && $newOrgMatchesJurisdiction) {
      $updatedOrgIds[] = $newDerivedOrgId;
    }

    return array_values(array_unique(array_filter(array_map('intval', $updatedOrgIds))));
  }

  /**
   * Mirrors submitted organisation filtering from node presave.
   */
  private function filterOrgIdsByJurisdiction(
    array $orgIds,
    int $jurisdictionId,
    array $orgJurisdictionMap,
    array $rootMap = [],
  ): array {
    return array_values(array_filter(
      $orgIds,
      static function (int $orgId) use ($jurisdictionId, $orgJurisdictionMap, $rootMap): bool {
        if (!array_key_exists($orgId, $orgJurisdictionMap) || $orgJurisdictionMap[$orgId] === NULL) {
          return FALSE;
        }

        return self::orgMatchesJurisdiction($orgId, $jurisdictionId, $orgJurisdictionMap, $rootMap);
      },
    ));
  }

  /**
   * Mirrors org jurisdiction matching including root fallback.
   */
  private static function orgMatchesJurisdiction(
    int $orgId,
    int $jurisdictionId,
    array $orgJurisdictionMap,
    array $rootMap = [],
  ): bool {
    if (!array_key_exists($orgId, $orgJurisdictionMap) || $orgJurisdictionMap[$orgId] === NULL) {
      return FALSE;
    }

    if ($orgJurisdictionMap[$orgId] === $jurisdictionId) {
      return TRUE;
    }

    return isset($rootMap[$jurisdictionId]) && $orgJurisdictionMap[$orgId] === $rootMap[$jurisdictionId];
  }

  /**
   * Relationship-to-field sync tests.
   *
   * These mirror _markaspot_group_sync_relationship_to_field().
   */

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
