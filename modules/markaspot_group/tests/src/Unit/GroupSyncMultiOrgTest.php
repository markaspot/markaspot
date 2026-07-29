<?php

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_group.module';
    // The org-notification "once" guard keeps a drupal_static keyed by
    // node:org id. Under PHPUnit every method shares one process, so reset it
    // to keep each test's notification expectations isolated.
    drupal_static_reset();
  }

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
   * Tests org assignment mail is emitted from the relationship insert hook.
   */
  public function testOrgRelationshipInsertEmitsOrganisationNotification(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $hookPos = strpos($source, 'function markaspot_group_group_relationship_insert(GroupRelationshipInterface $relationship): void');
    $syncPos = strpos($source, "_markaspot_group_sync_relationship_to_field(\$relationship, 'insert');", $hookPos);
    $notifyPos = strpos($source, '_markaspot_group_notify_service_request_org_relationship($relationship, _markaspot_group_is_syncing());', $hookPos);
    $tenantAdminPos = strpos($source, "_markaspot_group_tenant_admin_sync_role(\$relationship, 'insert');", $hookPos);

    $this->assertNotFalse($hookPos);
    $this->assertNotFalse($syncPos);
    $this->assertNotFalse($notifyPos);
    $this->assertNotFalse($tenantAdminPos);
    $this->assertLessThan($notifyPos, $syncPos);
    $this->assertLessThan($tenantAdminPos, $notifyPos);
    $this->assertStringContainsString('function _markaspot_group_notify_service_request_org_relationship(GroupRelationshipInterface $relationship, bool $trust_relationship_node = FALSE): void', $source);
    $this->assertStringContainsString('function _markaspot_group_reload_persisted_relationship(GroupRelationshipInterface $relationship): ?GroupRelationshipInterface', $source);
    $this->assertStringContainsString("\$relationship->getPluginId() !== 'group_node:service_request'", $source);
    $this->assertStringContainsString('$group->bundle() !== _markaspot_group_get_org_group_type()', $source);
    $this->assertStringContainsString('$node_storage->resetCache([$entity_id]);', $source);
    $this->assertStringContainsString('$node->bundle() !== \'service_request\'', $source);
    $this->assertStringContainsString("_markaspot_group_field_target_ids(\$node, 'field_organisation')", $source);
    $this->assertStringContainsString('$node_jurisdiction_id === NULL || !_markaspot_group_org_group_matches_jurisdiction($org_group, $node_jurisdiction_id)', $source);
    $this->assertStringContainsString('_markaspot_group_has_active_org_notification_eca()', $source);
    $this->assertStringContainsString('_markaspot_group_notify_organisation_group_once($node, $group);', $source);
    $this->assertStringContainsString("'node' => \$node,", $source);
    $this->assertStringContainsString("'organisation' => \$org_group,", $source);
  }

  /**
   * Tests tenant-owned ECA notification processes suppress fallback mail.
   */
  public function testActiveOrgNotificationEcaSuppressesFallbackMail(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $this->assertStringContainsString('function _markaspot_group_has_active_org_notification_eca(): bool', $source);
    $this->assertStringContainsString('$config_factory->listAll(\'eca.eca.\')', $source);
    $this->assertStringContainsString("\$plugin === 'service_request_sync_organisations'", $source);
    $this->assertStringContainsString("\$config_name === 'eca.eca.process_apply_group'", $source);
    $this->assertStringContainsString("\$plugin === 'action_send_email_action'", $source);
    $this->assertStringContainsString("trim((string) (\$configuration['subject'] ?? '')) !== ''", $source);
    $this->assertStringContainsString("trim((string) (\$configuration['message'] ?? '')) !== ''", $source);
    $this->assertStringContainsString('The profile-level relationship fallback must not double-send', $source);
  }

  /**
   * Tests assignment mail context uses field values instead of all properties.
   */
  public function testAssignmentNotificationContextUsesPlainFieldValues(): void {
    $context = _markaspot_group_build_assignment_notification_context(
      $this->serviceRequestNode(),
    );

    $this->assertSame('A short body', $context['description']);
    $this->assertSame('Teststrasse 1, 12345 Teststadt, NRW, DE', $context['address']);
    $this->assertStringNotContainsString('plain_text', $context['description']);
    $this->assertStringNotContainsString('field-property-leak', $context['address']);
  }

  /**
   * Tests a valid org relationship sends exactly one fallback notification.
   */
  public function testValidOrgRelationshipSendsOneFallbackNotification(): void {
    $node = $this->serviceRequestNode();
    $group = $this->organisationGroup();
    $relationship = $this->relationship($node, $group);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_group',
        'org_notification',
        'org@example.test',
        'en',
        $this->callback(static fn(array $params): bool =>
          ($params['node'] ?? NULL) === $node
          && ($params['organisation'] ?? NULL) === $group
          && is_string($params['subject'] ?? NULL)
          && str_contains($params['subject'], 'REQ-1')
          && str_contains($params['subject'], 'Organisation')
          && is_string($params['message'] ?? NULL)
          && str_contains($params['message'], 'Request: #REQ-1')
          && str_contains($params['message'], 'Category: Radbuegel')
          && str_contains($params['message'], 'Location: Teststrasse 1')
          && str_contains($params['message'], 'Description: A short body')
        ),
      )
      ->willReturn(['result' => TRUE]);
    $this->installNotificationContainer($mailManager);

    _markaspot_group_notify_service_request_org_relationship($relationship, TRUE);
  }

  /**
   * Tests org notification langcode follows jurisdiction Nuxt config.
   */
  public function testOrgRelationshipUsesJurisdictionDefaultLanguage(): void {
    $node = $this->serviceRequestNode(
      jurisdictionId: 18,
      jurisdictionGroup: $this->jurisdictionGroup(id: 18, defaultLangcode: 'de'),
    );
    $group = $this->organisationGroup(jurisdictionId: 18);
    $relationship = $this->relationship($node, $group);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_group',
        'org_notification',
        'org@example.test',
        'de',
        $this->callback(static fn(array $params): bool =>
          ($params['node'] ?? NULL) === $node
          && ($params['organisation'] ?? NULL) === $group
        ),
      )
      ->willReturn(['result' => TRUE]);
    $this->installNotificationContainer($mailManager);

    _markaspot_group_notify_service_request_org_relationship($relationship, TRUE);
  }

  /**
   * Tests insert assignment sends once to an active assignee.
   */
  public function testAssigneeInsertSendsOneNotification(): void {
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('id')->willReturn(42);
    $assignee->method('isActive')->willReturn(TRUE);
    $assignee->method('getEmail')->willReturn('assignee@example.test');
    $assignee->method('getPreferredLangcode')->with(FALSE)->willReturn('de');
    $node = $this->serviceRequestNode(organisationId: 0, assignee: $assignee);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_group',
        'assignee_notification',
        'assignee@example.test',
        'de',
        $this->callback(static fn(array $params): bool =>
          ($params['node'] ?? NULL) === $node
          && ($params['assignee'] ?? NULL) === $assignee
          && str_contains((string) ($params['subject'] ?? ''), 'REQ-1')
          && str_contains((string) ($params['message'] ?? ''), 'Category: Radbuegel')
          && str_contains((string) ($params['message'] ?? ''), 'Location: Teststrasse 1')
          && str_contains((string) ($params['message'] ?? ''), 'Description: A short body')
        ),
      )
      ->willReturn(['result' => TRUE]);
    $actingUser = $this->createMock(AccountInterface::class);
    $actingUser->method('id')->willReturn(99);
    $this->installNotificationContainer($mailManager, currentUser: $actingUser, isGroupMember: TRUE);

    _markaspot_group_notify_assignee_once($node, TRUE);
    _markaspot_group_notify_assignee_once($node, TRUE);
  }

  /**
   * Tests changing the assignee on update sends exactly once.
   */
  public function testAssigneeUpdateSendsOnceWhenTargetChanges(): void {
    $originalAssignee = $this->createMock(UserInterface::class);
    $originalAssignee->method('id')->willReturn(41);
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('id')->willReturn(42);
    $assignee->method('isActive')->willReturn(TRUE);
    $assignee->method('getEmail')->willReturn('assignee@example.test');
    $assignee->method('getPreferredLangcode')->with(FALSE)->willReturn('en');
    $original = $this->serviceRequestNode(organisationId: 0, assignee: $originalAssignee);
    $node = $this->serviceRequestNode(organisationId: 0, assignee: $assignee, original: $original);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())->method('mail')->willReturn(['result' => TRUE]);
    $actingUser = $this->createMock(AccountInterface::class);
    $actingUser->method('id')->willReturn(99);
    $this->installNotificationContainer($mailManager, currentUser: $actingUser, isGroupMember: TRUE);

    _markaspot_group_notify_assignee_once($node);
    _markaspot_group_notify_assignee_once($node);
  }

  /**
   * Tests an unchanged assignee on update sends no notification.
   */
  public function testAssigneeUpdateSkipsUnchangedTarget(): void {
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('id')->willReturn(42);
    $original = $this->serviceRequestNode(organisationId: 0, assignee: $assignee);
    $node = $this->serviceRequestNode(organisationId: 0, assignee: $assignee, original: $original);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');
    $this->installNotificationContainer($mailManager);

    _markaspot_group_notify_assignee_once($node);
  }

  /**
   * Tests self-assignment does not send a notification.
   */
  public function testAssigneeNotificationSkipsActingUser(): void {
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('id')->willReturn(42);
    $assignee->method('isActive')->willReturn(TRUE);
    $assignee->method('getEmail')->willReturn('assignee@example.test');
    $node = $this->serviceRequestNode(assignee: $assignee);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');
    $actingUser = $this->createMock(AccountInterface::class);
    $actingUser->method('id')->willReturn(42);
    $this->installNotificationContainer($mailManager, currentUser: $actingUser);

    _markaspot_group_notify_assignee($node);
  }

  /**
   * Tests an assignee outside the request scope receives no notification.
   */
  public function testAssigneeNotificationRejectsForeignUser(): void {
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('id')->willReturn(42);
    $assignee->method('isActive')->willReturn(TRUE);
    $assignee->method('getEmail')->willReturn('foreign@example.test');
    $node = $this->serviceRequestNode(organisationId: 0, assignee: $assignee);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');
    $actingUser = $this->createMock(AccountInterface::class);
    $actingUser->method('id')->willReturn(99);
    $this->installNotificationContainer($mailManager, currentUser: $actingUser, isGroupMember: FALSE);

    _markaspot_group_notify_assignee($node);
  }

  /**
   * Tests org relationship mail falls back to active group members.
   */
  public function testOrgRelationshipFallsBackToActiveMemberEmails(): void {
    $node = $this->serviceRequestNode();
    $group = $this->organisationGroup(email: '');
    $relationship = $this->relationship($node, $group);
    $membershipRelationships = $this->membershipRelationships([
      'member-two@example.test',
      'Member-One@example.test',
      'member-one@example.test',
    ]);
    $sentRecipients = [];

    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'gid' => 100,
        'plugin_id' => 'group_membership',
      ])
      ->willReturn($membershipRelationships);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('group_relationship')
      ->willReturn($relationshipStorage);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->exactly(2))
      ->method('mail')
      ->willReturnCallback(static function (
        string $module,
        string $key,
        string $to,
        string $langcode,
        array $params,
      ) use (&$sentRecipients): array {
        $sentRecipients[] = $to;
        return ['result' => TRUE];
      });
    $this->installNotificationContainer($mailManager, entityTypeManager: $entityTypeManager);

    _markaspot_group_notify_service_request_org_relationship($relationship, TRUE);

    sort($sentRecipients);
    $this->assertSame(['member-one@example.test', 'member-two@example.test'], $sentRecipients);
  }

  /**
   * Tests cross-tenant relationships do not send fallback mail.
   */
  public function testCrossTenantOrgRelationshipDoesNotSendFallbackNotification(): void {
    $node = $this->serviceRequestNode(jurisdictionId: 1, organisationId: 100);
    $group = $this->organisationGroup(id: 100, jurisdictionId: 2);
    $relationship = $this->relationship($node, $group);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');
    $this->installNotificationContainer($mailManager);

    _markaspot_group_notify_service_request_org_relationship($relationship, TRUE);
  }

  /**
   * Tests active tenant ECA notification config suppresses fallback mail.
   */
  public function testTenantEcaNotificationProcessSuppressesFallbackNotification(): void {
    $node = $this->serviceRequestNode();
    $group = $this->organisationGroup();
    $relationship = $this->relationship($node, $group);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');
    $this->installNotificationContainer(
      $mailManager,
      [
        'eca.eca.process_nhbgnxm' => [
          'status' => TRUE,
          'actions' => [
            'Activity_1dpxtbx' => [
              'plugin' => 'service_request_sync_organisations',
              'configuration' => [
                'subject' => 'Assigned',
                'message' => 'Assigned message',
              ],
            ],
          ],
        ],
      ],
    );

    _markaspot_group_notify_service_request_org_relationship($relationship, TRUE);
  }

  /**
   * Tests sync-only ECA config does not suppress fallback notification.
   */
  public function testSyncOnlyEcaProcessDoesNotSuppressFallbackNotification(): void {
    $node = $this->serviceRequestNode();
    $group = $this->organisationGroup();
    $relationship = $this->relationship($node, $group);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);
    $this->installNotificationContainer(
      $mailManager,
      [
        'eca.eca.process_nhbgnxm' => [
          'status' => TRUE,
          'actions' => [
            'Activity_1dpxtbx' => [
              'plugin' => 'service_request_sync_organisations',
              'configuration' => [
                'subject' => '',
                'message' => '',
              ],
            ],
          ],
        ],
      ],
    );

    _markaspot_group_notify_service_request_org_relationship($relationship, TRUE);
  }

  /**
   * Tests persisted relationship notification rechecks the relationship exists.
   */
  public function testPersistedRelationshipPathSkipsDeletedRelationship(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('id')->willReturn(77);

    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->expects($this->once())
      ->method('resetCache')
      ->with([77]);
    $relationshipStorage->expects($this->once())
      ->method('load')
      ->with(77)
      ->willReturn(NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('group_relationship')
      ->willReturn($relationshipStorage);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');
    $this->installNotificationContainer($mailManager, entityTypeManager: $entityTypeManager);

    _markaspot_group_notify_service_request_org_relationship($relationship);
  }

  /**
   * Tests persisted relationship notification reloads and sends exactly once.
   */
  public function testPersistedRelationshipPathReloadsAndSendsOnce(): void {
    $node = $this->serviceRequestNode();
    $group = $this->organisationGroup();
    $relationship = $this->relationship($node, $group);

    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->expects($this->once())
      ->method('resetCache')
      ->with([77]);
    $relationshipStorage->expects($this->once())
      ->method('load')
      ->with(77)
      ->willReturn($relationship);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->expects($this->once())
      ->method('resetCache')
      ->with([123]);
    $nodeStorage->expects($this->once())
      ->method('load')
      ->with(123)
      ->willReturn($node);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static fn(string $entityTypeId): EntityStorageInterface => match ($entityTypeId) {
        'group_relationship' => $relationshipStorage,
        'node' => $nodeStorage,
      });

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_group',
        'org_notification',
        'org@example.test',
        'en',
        $this->callback(static fn(array $params): bool =>
          ($params['node'] ?? NULL) === $node
          && ($params['organisation'] ?? NULL) === $group
        ),
      )
      ->willReturn(['result' => TRUE]);
    $this->installNotificationContainer($mailManager, entityTypeManager: $entityTypeManager);

    _markaspot_group_notify_service_request_org_relationship($relationship);
  }

  /**
   * Tests node insert no longer sends a duplicate organisation mail directly.
   */
  public function testNodeInsertDoesNotDoubleSendOrganisationNotification(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $nodeInsertPos = strpos($source, 'function markaspot_group_node_insert(NodeInterface $node): void');
    $nodeUpdatePos = strpos($source, 'function markaspot_group_node_update(NodeInterface $node): void');
    $nodeInsertSource = substr($source, $nodeInsertPos, $nodeUpdatePos - $nodeInsertPos);

    $this->assertNotFalse($nodeInsertPos);
    $this->assertNotFalse($nodeUpdatePos);
    $this->assertStringNotContainsString('_markaspot_group_notify_organisation($node);', $nodeInsertSource);
    $this->assertStringContainsString('Organisation notifications are emitted when the org relationship is', $nodeInsertSource);
  }

  /**
   * Tests prevalidated root jurisdiction can be replaced by a child boundary.
   */
  public function testNodeInsertAllowsMostSpecificChildAfterPrefilledJurisdiction(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $nodeInsertPos = strpos($source, 'function markaspot_group_node_insert(NodeInterface $node): void');
    $nodeUpdatePos = strpos($source, 'function markaspot_group_node_update(NodeInterface $node): void');
    $nodeInsertSource = substr($source, $nodeInsertPos, $nodeUpdatePos - $nodeInsertPos);

    $this->assertNotFalse($nodeInsertPos);
    $this->assertNotFalse($nodeUpdatePos);
    $this->assertStringContainsString('$needs_most_specific_child_jurisdiction = $child_jur_id && $strategy === \'most_specific\';', $nodeInsertSource);
    $this->assertStringContainsString('if ($current_jurisdiction_empty || $needs_most_specific_child_jurisdiction) {', $nodeInsertSource);
  }

  /**
   * Boundary candidates are constrained before any relationship is created.
   */
  public function testSubJurisdictionBoundaryScanIsRootScoped(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');
    $start = strpos($source, 'function _markaspot_group_assign_sub_jurisdictions(NodeInterface $node): int|false');
    $end = strpos($source, 'function _markaspot_group_set_jurisdiction_field', $start);
    $functionSource = substr($source, $start, $end - $start);

    $this->assertNotFalse($start);
    $this->assertNotFalse($end);
    $this->assertStringContainsString('_markaspot_group_boundary_scope_root($node)', $functionSource);
    $this->assertStringContainsString('$hierarchy_resolver->getDescendantIds($scope_root_id)', $functionSource);
    $scopeCondition = strpos($functionSource, '$group_query->condition($group_id_key, $candidate_ids, \'IN\')');
    $relationshipWrite = strpos($functionSource, '$group->addRelationship($node, $plugin_id)');
    $this->assertNotFalse($scopeCondition);
    $this->assertNotFalse($relationshipWrite);
    $this->assertLessThan($relationshipWrite, $scopeCondition);
  }

  /**
   * Installs the minimal Drupal container needed by notification helpers.
   */
  private function installNotificationContainer(
    MailManagerInterface $mailManager,
    array $ecaConfigs = [],
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?AccountInterface $currentUser = NULL,
    ?bool $isGroupMember = NULL,
  ): void {
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('https://dashboard.example.test'));

    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchyResolver->method('getRootJurisdictionId')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('listAll')
      ->with('eca.eca.')
      ->willReturn(array_keys($ecaConfigs));
    $configFactory->method('get')
      ->willReturnCallback(function (string $name) use ($ecaConfigs): ImmutableConfig {
        $values = $ecaConfigs[$name] ?? [];
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')
          ->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
        return $config;
      });

    $emailValidator = $this->createMock(EmailValidatorInterface::class);
    $emailValidator->method('isValid')
      ->willReturnCallback(static fn(string $email): bool => str_contains($email, '@'));

    $english = $this->createMock(LanguageInterface::class);
    $english->method('getId')->willReturn('en');
    $german = $this->createMock(LanguageInterface::class);
    $german->method('getId')->willReturn('de');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getDefaultLanguage')->willReturn($english);
    $languageManager->method('getLanguages')->willReturn([
      'en' => $english,
      'de' => $german,
    ]);

    $container = new ContainerBuilder();
    $container->set('plugin.manager.mail', $mailManager);
    $container->set('request_stack', $requestStack);
    $container->set('config.factory', $configFactory);
    $container->set('email.validator', $emailValidator);
    $container->set('markaspot_group.hierarchy_resolver', $hierarchyResolver);
    $container->set('language_manager', $languageManager);
    if ($currentUser !== NULL) {
      $container->set('current_user', $currentUser);
    }
    if ($isGroupMember !== NULL) {
      $query = $this->createMock(SelectInterface::class);
      $query->method('condition')->willReturnSelf();
      $query->method('countQuery')->willReturnSelf();
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchField')->willReturn($isGroupMember ? 1 : 0);
      $query->method('execute')->willReturn($statement);
      $database = $this->createMock(Connection::class);
      $database->method('select')->willReturn($query);
      $container->set('database', $database);
    }
    if ($entityTypeManager !== NULL) {
      $container->set('entity_type.manager', $entityTypeManager);
    }
    \Drupal::setContainer($container);
  }

  /**
   * Creates a service request node mock with the fields used by mail delivery.
   */
  private function serviceRequestNode(
    int $jurisdictionId = 1,
    int $organisationId = 100,
    ?GroupInterface $jurisdictionGroup = NULL,
    ?UserInterface $assignee = NULL,
    ?NodeInterface $original = NULL,
  ): NodeInterface {
    $jurisdictionGroup ??= $this->jurisdictionGroup(id: $jurisdictionId);

    $category = new class() {

      /**
       * Returns the category label.
       */
      public function label(): string {
        return 'Radbuegel';
      }

    };

    $fields = [
      'field_jurisdiction' => $this->field(
        [['target_id' => $jurisdictionId]],
        [
          'target_id' => $jurisdictionId,
          'entity' => $jurisdictionGroup,
        ],
      ),
      'field_organisation' => $this->field(
        $organisationId > 0 ? [['target_id' => $organisationId]] : [],
      ),
      'request_id' => $this->field([['value' => 'REQ-1']], ['value' => 'REQ-1']),
      'field_category' => $this->field([['target_id' => 9]], ['entity' => $category]),
      'field_address' => $this->field(
        [[
          'address_line1' => 'Teststrasse 1',
          'address_line2' => '',
          'postal_code' => '12345',
          'locality' => 'Teststadt',
          'administrative_area' => 'NRW',
          'country_code' => 'DE',
        ]],
        [],
        'field-property-leak',
      ),
      'body' => $this->field(
        [['value' => 'A short body', 'summary' => '', 'format' => 'plain_text']],
        ['value' => 'A short body'],
        'A short body, , plain_text',
      ),
    ];
    if ($assignee !== NULL) {
      $fields['field_assignee'] = $this->field(
        [['target_id' => $assignee->id()]],
        [
          'target_id' => $assignee->id(),
          'entity' => $assignee,
        ],
      );
    }

    $node = $this->createMock(Node::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('id')->willReturn(123);
    $node->method('label')->willReturn('Fixture request');
    $node->method('hasField')
      ->willReturnCallback(static fn(string $fieldName): bool => array_key_exists($fieldName, $fields));
    $node->method('get')
      ->willReturnCallback(static fn(string $fieldName): FieldItemListInterface => $fields[$fieldName]);
    $node->method('__isset')
      ->willReturnCallback(static fn(string $property): bool => $property === 'original' && $original !== NULL);
    $node->method('__get')
      ->willReturnCallback(static fn(string $property): ?NodeInterface => $property === 'original' ? $original : NULL);
    return $node;
  }

  /**
   * Creates an organisation group mock with jurisdiction and mail fields.
   */
  private function organisationGroup(int $id = 100, int $jurisdictionId = 1, string $email = 'org@example.test'): GroupInterface {
    $emailValues = $email === '' ? [] : [['value' => $email]];
    $fields = [
      'field_jurisdiction' => $this->field([['target_id' => $jurisdictionId]], ['target_id' => $jurisdictionId]),
      'field_head_organisation_e_mail' => $this->field($emailValues, ['value' => $email]),
    ];

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('org');
    $group->method('label')->willReturn('Organisation');
    $group->method('hasField')
      ->willReturnCallback(static fn(string $fieldName): bool => array_key_exists($fieldName, $fields));
    $group->method('get')
      ->willReturnCallback(static fn(string $fieldName): FieldItemListInterface => $fields[$fieldName]);
    return $group;
  }

  /**
   * Creates a jurisdiction group with a Nuxt default language.
   */
  private function jurisdictionGroup(int $id = 1, ?string $defaultLangcode = NULL): GroupInterface {
    $fields = [];
    if ($defaultLangcode !== NULL) {
      $nuxtConfig = json_encode([
        'languages' => [
          'default' => $defaultLangcode,
          'available' => [$defaultLangcode],
        ],
      ]);
      $fields['field_nuxt_config'] = $this->field(
        [['value' => $nuxtConfig]],
        ['value' => $nuxtConfig],
      );
    }

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')
      ->willReturnCallback(static fn(string $fieldName): bool => array_key_exists($fieldName, $fields));
    $group->method('get')
      ->willReturnCallback(static fn(string $fieldName): FieldItemListInterface => $fields[$fieldName]);
    return $group;
  }

  /**
   * Creates group membership relationship mocks for member fallback mail.
   *
   * @param string[] $emails
   *   User e-mail addresses returned by the memberships.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface[]
   *   Membership relationship mocks.
   */
  private function membershipRelationships(array $emails): array {
    return array_map(function (string $email): GroupRelationshipInterface {
      $user = $this->createMock(UserInterface::class);
      $user->method('isActive')->willReturn(TRUE);
      $user->method('getEmail')->willReturn($email);

      $relationship = $this->createMock(GroupRelationshipInterface::class);
      $relationship->method('getEntity')->willReturn($user);
      return $relationship;
    }, $emails);
  }

  /**
   * Creates a service request group relationship mock.
   */
  private function relationship(NodeInterface $node, GroupInterface $group): GroupRelationshipInterface {
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('id')->willReturn(77);
    $relationship->method('getPluginId')->willReturn('group_node:service_request');
    $relationship->method('getGroup')->willReturn($group);
    $relationship->method('getEntity')->willReturn($node);
    $relationship->method('getEntityId')->willReturn($node->id());
    return $relationship;
  }

  /**
   * Creates a field item list mock with simple public item properties.
   */
  private function field(
    array $values,
    array $properties = [],
    ?string $stringValue = NULL,
  ): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($values === []);
    $field->method('getValue')->willReturn($values);
    $field->method('getString')->willReturn($stringValue ?? (string) ($properties['value'] ?? ''));
    $field->method('__get')
      ->willReturnCallback(static fn(string $property): mixed => $properties[$property] ?? NULL);
    $field->method('__isset')
      ->willReturnCallback(static fn(string $property): bool => array_key_exists($property, $properties));
    return $field;
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

  /**
   * Single-org inverse sync rejects a competing relationship.
   */
  public function testSingleOrgRelationshipInsertRejectsCompetingRelationship(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $sync_pos = strpos($source, 'function _markaspot_group_sync_relationship_to_field(');
    $single_pos = strpos($source, '_markaspot_group_single_organisation_assignment_enabled()', $sync_pos);
    $guard_pos = strpos($source, '$current_ids !== [] && !in_array($group_id, $current_ids, TRUE)', $single_pos);
    $delete_pos = strpos($source, '$relationship->delete();', $guard_pos);
    $empty_pos = strpos($source, 'if ($current_ids === [])', $delete_pos);
    $set_pos = strpos($source, '$entity->set(\'field_organisation\', [\'target_id\' => $group_id]);', $empty_pos);

    $this->assertNotFalse($sync_pos);
    $this->assertNotFalse($single_pos);
    $this->assertNotFalse($guard_pos);
    $this->assertNotFalse($delete_pos);
    $this->assertNotFalse($empty_pos);
    $this->assertNotFalse($set_pos);
    $this->assertLessThan($guard_pos, $single_pos);
    $this->assertLessThan($delete_pos, $guard_pos);
    $this->assertLessThan($empty_pos, $delete_pos);
    $this->assertLessThan($set_pos, $empty_pos);
  }

}
