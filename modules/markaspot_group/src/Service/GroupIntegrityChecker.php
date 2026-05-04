<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Detects and optionally repairs group integrity drift.
 */
class GroupIntegrityChecker {

  /**
   * Constructs a GroupIntegrityChecker.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?ConfigFactoryInterface $configFactory = NULL,
  ) {}

  /**
   * Returns the configured jurisdiction group bundle.
   */
  protected function jurisdictionGroupType(): string {
    $config = $this->configFactory?->get('markaspot_open311.settings');
    $configured = $config ? $config->get('jurisdiction_group_type') : NULL;

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

  /**
   * Runs all integrity checks.
   *
   * @return array<string, array{rows:array<int, array<string, mixed>>, description:string}>
   *   Check results keyed by check ID.
   */
  public function check(): array {
    return [
      'relationship_missing_group' => [
        'description' => 'Group relationships whose gid no longer exists.',
        'rows' => $this->relationshipMissingGroupRows(),
      ],
      'relationship_missing_node' => [
        'description' => 'Service request group relationships whose node no longer exists.',
        'rows' => $this->relationshipMissingNodeRows(),
      ],
      'relationship_missing_user' => [
        'description' => 'Group membership relationships whose user no longer exists.',
        'rows' => $this->relationshipMissingUserRows(),
      ],
      'field_organisation_missing_group' => [
        'description' => 'service_request field_organisation values pointing at missing or non-org groups.',
        'rows' => $this->fieldOrganisationMissingGroupRows(),
      ],
      'field_organisation_missing_node' => [
        'description' => 'service_request field_organisation rows whose owning node no longer exists.',
        'rows' => $this->fieldOrganisationMissingNodeRows(),
      ],
      'field_jurisdiction_missing_group' => [
        'description' => 'service_request field_jurisdiction values pointing at missing or non-jurisdiction groups.',
        'rows' => $this->fieldJurisdictionMissingGroupRows(),
      ],
      'field_jurisdiction_missing_node' => [
        'description' => 'service_request field_jurisdiction rows whose owning node no longer exists.',
        'rows' => $this->fieldJurisdictionMissingNodeRows(),
      ],
      'org_missing_jurisdiction' => [
        'description' => 'Organisation groups with missing or invalid field_jurisdiction values.',
        'rows' => $this->orgMissingJurisdictionRows(),
      ],
      'org_child_jurisdiction' => [
        'description' => 'Organisation groups whose field_jurisdiction points at a child jurisdiction.',
        'rows' => $this->orgChildJurisdictionRows(),
      ],
      'jur_parent_missing_group' => [
        'description' => 'Jurisdiction parent references pointing at missing or non-jurisdiction groups.',
        'rows' => $this->jurParentMissingGroupRows(),
      ],
      'jur_parent_self_reference' => [
        'description' => 'Jurisdiction groups whose parent reference points to themselves.',
        'rows' => $this->jurParentSelfReferenceRows(),
      ],
      'jur_parent_cycle' => [
        'description' => 'Jurisdiction parent references that form multi-node cycles.',
        'rows' => $this->jurParentCycleRows(),
      ],
      'jur_field_missing_relationship' => [
        'description' => 'service_request field_jurisdiction values without matching jurisdiction group relationship.',
        'rows' => $this->jurFieldMissingRelationshipRows(),
      ],
      'jur_relationship_missing_field' => [
        'description' => 'Jurisdiction group relationships not mirrored by node field_jurisdiction.',
        'rows' => $this->jurRelationshipMissingFieldRows(),
      ],
      'org_relationship_missing_field' => [
        'description' => 'Organisation group relationships not mirrored by node field_organisation.',
        'rows' => $this->orgRelationshipMissingFieldRows(),
      ],
    ];
  }

  /**
   * Returns a count summary for all checks.
   *
   * @return array<string, int>
   *   Counts keyed by check ID.
   */
  public function summary(): array {
    $summary = [];
    foreach ($this->check() as $id => $result) {
      $summary[$id] = count($result['rows']);
    }
    return $summary;
  }

  /**
   * Checks whether service requests are attached to their expected groups.
   *
   * This is stricter than the denormalized field mirror checks in ::check().
   * It derives expected jurisdiction and organisation memberships from each
   * service request's field_jurisdiction and field_category values, then
   * compares them with actual group_node:service_request relationships.
   *
   * @return array<string, array{rows:array<int, array<string, mixed>>, description:string}>
   *   Correctness results keyed by check ID.
   */
  public function correctness(): array {
    $diff = $this->correctnessDiffRows();

    return [
      'missing_context' => [
        'description' => 'Service requests missing field_category or field_jurisdiction, so expected groups cannot be derived.',
        'rows' => $diff['missing_context'],
      ],
      'unresolved_expected_org' => [
        'description' => 'Service requests whose expected organisation cannot be derived from field_category and field_jurisdiction.',
        'rows' => $diff['unresolved_expected_org'],
      ],
      'expected_relationship_missing' => [
        'description' => 'Expected jurisdiction or organisation group relationships missing from service requests.',
        'rows' => $diff['expected_relationship_missing'],
      ],
      'unexpected_relationship' => [
        'description' => 'Service request group relationships not expected from field_category and field_jurisdiction.',
        'rows' => $diff['unexpected_relationship'],
      ],
    ];
  }

  /**
   * Deletes relationship entities whose content entity owner is missing.
   *
   * Relationships with a missing group are reported but intentionally skipped:
   * deleting those entities can trigger group-dependent delete hooks while the
   * group can no longer be loaded.
   *
   * @param bool $dryRun
   *   TRUE to report only, FALSE to delete.
   *
   * @return array<string, int>
   *   Planned or applied repair counts.
   */
  public function repairOrphanRelationships(bool $dryRun = TRUE): array {
    $results = [
      'relationship_missing_group_skipped' => 0,
      'relationship_missing_node' => 0,
      'relationship_missing_user' => 0,
    ];

    $ids = [];
    $missingGroupRows = $this->relationshipMissingGroupRows();
    $missingGroupIds = array_fill_keys(
      array_map(static fn(array $row): int => (int) $row['id'], $missingGroupRows),
      TRUE,
    );
    $results['relationship_missing_group_skipped'] = count($missingGroupRows);
    foreach ([
      'relationship_missing_node',
      'relationship_missing_user',
    ] as $checkId) {
      $rows = $this->getRowsForCheck($checkId);
      foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (isset($missingGroupIds[$id])) {
          continue;
        }
        $results[$checkId]++;
        $ids[$id] = $id;
      }
    }

    if (!$dryRun && !empty($ids)) {
      $transaction = $this->database->startTransaction();
      $storage = $this->entityTypeManager->getStorage('group_relationship');
      try {
        foreach ($storage->loadMultiple($ids) as $relationship) {
          $relationship->delete();
        }
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    }

    return $results;
  }

  /**
   * Repairs denormalized service request field and relationship mirror drift.
   *
   * The repair is intentionally conservative. It removes invalid field values
   * and materializes missing relationships from valid fields. Reverse mirroring
   * from relationships back to fields only happens when the node state is
   * unambiguous and the organisation belongs to the node jurisdiction.
   *
   * @param bool $dryRun
   *   TRUE to report only, FALSE to apply.
   *
   * @return array<string, int>
   *   Planned or applied repair counts.
   */
  public function repairMirrorDrift(bool $dryRun = TRUE): array {
    $results = [
      'field_organisation_invalid_removed' => 0,
      'jur_field_missing_relationship_created' => 0,
      'jur_relationship_missing_field_mirrored' => 0,
      'jur_relationship_missing_field_skipped' => 0,
      'org_relationship_missing_field_mirrored' => 0,
      'org_relationship_missing_field_skipped' => 0,
    ];

    $invalidOrganisationRows = $this->fieldOrganisationMissingGroupRows();
    $missingJurRelationshipRows = $this->jurFieldMissingRelationshipRows();
    $jurMirrorRows = [];
    $orgMirrorRows = [];

    foreach ($this->jurRelationshipMissingFieldRows() as $row) {
      if ($this->canMirrorJurisdictionRelationship($row)) {
        $jurMirrorRows[] = $row;
      }
      else {
        $results['jur_relationship_missing_field_skipped']++;
      }
    }

    foreach ($this->orgRelationshipMissingFieldRows() as $row) {
      if ($this->canMirrorOrganisationRelationship($row)) {
        $orgMirrorRows[] = $row;
      }
      else {
        $results['org_relationship_missing_field_skipped']++;
      }
    }

    if ($dryRun) {
      $results['field_organisation_invalid_removed'] = count($invalidOrganisationRows);
      $results['jur_field_missing_relationship_created'] = count($missingJurRelationshipRows);
      $results['jur_relationship_missing_field_mirrored'] = count($jurMirrorRows);
      $results['org_relationship_missing_field_mirrored'] = count($orgMirrorRows);
      return $results;
    }

    $transaction = $this->database->startTransaction();
    try {
      foreach ($invalidOrganisationRows as $row) {
        if ($this->removeNodeFieldTarget(
          (int) $row['entity_id'],
          'field_organisation',
          (int) $row['field_organisation_target_id'],
        )) {
          $results['field_organisation_invalid_removed']++;
        }
      }
      foreach ($missingJurRelationshipRows as $row) {
        if (!$this->isJurFieldMissingRelationshipRow($row)) {
          continue;
        }
        if ($this->createServiceRequestRelationship(
          (int) $row['field_jurisdiction_target_id'],
          (int) $row['entity_id'],
        )) {
          $results['jur_field_missing_relationship_created']++;
        }
      }
      foreach ($jurMirrorRows as $row) {
        if (!$this->canMirrorJurisdictionRelationship($row)) {
          $results['jur_relationship_missing_field_skipped']++;
          continue;
        }
        if ($this->setNodeFieldTargets(
          (int) $row['entity_id'],
          'field_jurisdiction',
          [(int) $row['gid']],
        )) {
          $results['jur_relationship_missing_field_mirrored']++;
        }
      }
      foreach ($orgMirrorRows as $row) {
        if (!$this->canMirrorOrganisationRelationship($row)) {
          $results['org_relationship_missing_field_skipped']++;
          continue;
        }
        $nid = (int) $row['entity_id'];
        $targetIds = $this->nodeFieldTargetIds(
          $nid,
          'node__field_organisation',
          'field_organisation_target_id',
        );
        $targetIds[] = (int) $row['gid'];
        if ($this->setNodeFieldTargets($nid, 'field_organisation', $targetIds)) {
          $results['org_relationship_missing_field_mirrored']++;
        }
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }

    return $results;
  }

  /**
   * Backfills expected organisation assignments for existing requests.
   *
   * This repair covers tenants where organisation handling was enabled after
   * service requests already existed. It derives the expected organisation from
   * each request's category and jurisdiction, then materializes the missing
   * denormalized field value and group relationship without removing existing
   * manual organisation assignments.
   *
   * @param bool $dryRun
   *   TRUE to report only, FALSE to apply.
   *
   * @return array<string, int>
   *   Planned or applied repair counts.
   */
  public function repairExpectedOrganisationAssignments(bool $dryRun = TRUE): array {
    $results = [
      'expected_org_field_backfilled' => 0,
      'expected_org_relationship_created' => 0,
      'expected_org_skipped' => 0,
    ];

    $rows = [];
    foreach ($this->correctnessDiffRows()['expected_relationship_missing'] as $row) {
      if (($row['expected_group_type'] ?? NULL) !== 'org') {
        continue;
      }
      if (!$this->canBackfillExpectedOrganisationAssignment($row)) {
        $results['expected_org_skipped']++;
        continue;
      }
      $rows[] = $row;
    }

    if ($dryRun) {
      foreach ($rows as $row) {
        $nid = (int) $row['entity_id'];
        $gid = (int) $row['expected_group_id'];
        if (!in_array($gid, $this->nodeFieldTargetIds($nid, 'node__field_organisation', 'field_organisation_target_id'), TRUE)) {
          $results['expected_org_field_backfilled']++;
        }
        if (!in_array($gid, $this->serviceRequestRelationshipGroupIds($nid, 'org'), TRUE)) {
          $results['expected_org_relationship_created']++;
        }
      }
      return $results;
    }

    foreach ($rows as $row) {
      $nid = (int) $row['entity_id'];
      $ran = $this->withServiceRequestSyncLock($nid, function () use ($row, &$results): void {
        if (!$this->canBackfillExpectedOrganisationAssignment($row)) {
          $results['expected_org_skipped']++;
          return;
        }

        $transaction = $this->database->startTransaction();
        try {
          $nid = (int) $row['entity_id'];
          $gid = (int) $row['expected_group_id'];
          $targetIds = $this->nodeFieldTargetIds(
            $nid,
            'node__field_organisation',
            'field_organisation_target_id',
          );
          if (!in_array($gid, $targetIds, TRUE)) {
            $targetIds[] = $gid;
            if ($this->setNodeFieldTargets($nid, 'field_organisation', $targetIds)) {
              $results['expected_org_field_backfilled']++;
            }
          }

          if (!in_array($gid, $this->serviceRequestRelationshipGroupIds($nid, 'org'), TRUE)
            && $this->createServiceRequestRelationship($gid, $nid)) {
            $results['expected_org_relationship_created']++;
          }
          unset($transaction);
        }
        catch (\Throwable $e) {
          $transaction->rollBack();
          throw $e;
        }
      });
      if (!$ran) {
        $results['expected_org_skipped']++;
      }
    }

    return $results;
  }

  /**
   * Gets rows for a supported repair check.
   *
   * @param string $checkId
   *   The check ID.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function getRowsForCheck(string $checkId): array {
    return match ($checkId) {
      'relationship_missing_node' => $this->relationshipMissingNodeRows(),
      'relationship_missing_user' => $this->relationshipMissingUserRows(),
      default => [],
    };
  }

  /**
   * Checks whether a jur relationship can be mirrored to field_jurisdiction.
   *
   * @param array<string, mixed> $row
   *   Relationship drift row.
   *
   * @return bool
   *   TRUE when the node has no conflicting jurisdiction field and exactly one
   *   jurisdiction relationship.
   */
  protected function canMirrorJurisdictionRelationship(array $row): bool {
    $nid = (int) $row['entity_id'];
    $gid = (int) $row['gid'];
    if ($this->nodeFieldTargetIds($nid, 'node__field_jurisdiction', 'field_jurisdiction_target_id') !== []) {
      return FALSE;
    }

    $relationshipGroupIds = $this->serviceRequestRelationshipGroupIds($nid, $this->jurisdictionGroupType());
    return count($relationshipGroupIds) === 1 && reset($relationshipGroupIds) === $gid;
  }

  /**
   * Checks whether an org relationship can be mirrored to field_organisation.
   *
   * @param array<string, mixed> $row
   *   Relationship drift row.
   *
   * @return bool
   *   TRUE when the org is valid for the node jurisdiction.
   */
  protected function canMirrorOrganisationRelationship(array $row): bool {
    $nid = (int) $row['entity_id'];
    $gid = (int) $row['gid'];
    $jurisdictionIds = $this->nodeFieldTargetIds($nid, 'node__field_jurisdiction', 'field_jurisdiction_target_id');
    if (count($jurisdictionIds) !== 1) {
      return FALSE;
    }

    $categoryIds = $this->nodeFieldTargetIds($nid, 'node__field_category', 'field_category_target_id');
    if (count($categoryIds) !== 1) {
      return FALSE;
    }

    $jurisdictionId = reset($jurisdictionIds);
    return $this->deriveExpectedOrganisationId(reset($categoryIds), $jurisdictionId) === $gid
      && $this->orgGroupMatchesJurisdiction($gid, $jurisdictionId);
  }

  /**
   * Checks whether an expected organisation assignment is safe to backfill.
   *
   * @param array<string, mixed> $row
   *   Correctness row from expected_relationship_missing.
   *
   * @return bool
   *   TRUE when category and jurisdiction are still unambiguous and resolve to
   *   the expected organisation group.
   */
  protected function canBackfillExpectedOrganisationAssignment(array $row): bool {
    if (($row['expected_group_type'] ?? NULL) !== 'org') {
      return FALSE;
    }

    $nid = (int) ($row['entity_id'] ?? 0);
    $gid = (int) ($row['expected_group_id'] ?? 0);
    $categoryId = (int) ($row['field_category_target_id'] ?? 0);
    $jurisdictionId = (int) ($row['field_jurisdiction_target_id'] ?? 0);
    if ($nid <= 0 || $gid <= 0 || $categoryId <= 0 || $jurisdictionId <= 0) {
      return FALSE;
    }

    $jurisdictionIds = $this->nodeFieldTargetIds(
      $nid,
      'node__field_jurisdiction',
      'field_jurisdiction_target_id',
    );
    if (count($jurisdictionIds) !== 1 || reset($jurisdictionIds) !== $jurisdictionId) {
      return FALSE;
    }

    $categoryIds = $this->nodeFieldTargetIds(
      $nid,
      'node__field_category',
      'field_category_target_id',
    );
    if (count($categoryIds) !== 1 || reset($categoryIds) !== $categoryId) {
      return FALSE;
    }

    $fieldOrgIds = $this->nodeFieldTargetIds(
      $nid,
      'node__field_organisation',
      'field_organisation_target_id',
    );
    if (array_diff($fieldOrgIds, [$gid]) !== []) {
      return FALSE;
    }

    $relationshipOrgIds = $this->serviceRequestRelationshipGroupIds($nid, 'org');
    if (array_diff($relationshipOrgIds, [$gid]) !== []) {
      return FALSE;
    }

    return $this->deriveUniqueExpectedOrganisationId($categoryId, $jurisdictionId) === $gid
      && $this->orgGroupMatchesJurisdiction($gid, $jurisdictionId);
  }

  /**
   * Derives a unique expected organisation for category and jurisdiction.
   *
   * @param int $categoryId
   *   Service category term ID.
   * @param int $jurisdictionId
   *   Jurisdiction group ID from the service request.
   *
   * @return int
   *   The unique expected organisation group ID, or 0 when none or ambiguous.
   */
  protected function deriveUniqueExpectedOrganisationId(int $categoryId, int $jurisdictionId): int {
    $query = $this->database->select('groups', 'g');
    $query->join('group__field_jurisdiction', 'gj', "gj.entity_id = g.id AND gj.deleted = 0 AND gj.field_jurisdiction_target_id = :jurisdiction_id", [
      ':jurisdiction_id' => $jurisdictionId,
    ]);
    $query->join('group__field_service_categories', 'gsc', "gsc.entity_id = g.id AND gsc.deleted = 0 AND gsc.field_service_categories_target_id = :category_id", [
      ':category_id' => $categoryId,
    ]);
    $query->fields('g', ['id']);
    $query->condition('g.type', 'org');
    $directIds = array_values(array_unique(array_map('intval', $query->execute()->fetchCol())));
    if (count($directIds) === 1) {
      return reset($directIds);
    }
    if (count($directIds) > 1) {
      return 0;
    }

    $fallback = $this->database->select('taxonomy_term__field_category_gid', 'cg');
    $fallback->join('groups', 'g', "g.id = cg.field_category_gid_target_id AND g.type = 'org'");
    $fallback->addField('cg', 'field_category_gid_target_id');
    $fallback->condition('cg.deleted', 0);
    $fallback->condition('cg.bundle', 'service_category');
    $fallback->condition('cg.entity_id', $categoryId);
    $fallbackIds = array_values(array_unique(array_map('intval', $fallback->execute()->fetchCol())));

    return count($fallbackIds) === 1 ? reset($fallbackIds) : 0;
  }

  /**
   * Runs a callback under the service request group sync lock.
   *
   * @param int $nid
   *   Node ID.
   * @param callable $callback
   *   Callback to run.
   *
   * @return bool
   *   TRUE when the callback ran, FALSE when the lock could not be acquired.
   */
  protected function withServiceRequestSyncLock(int $nid, callable $callback): bool {
    if (function_exists('_markaspot_group_with_service_request_sync_lock')) {
      return _markaspot_group_with_service_request_sync_lock($nid, $callback);
    }

    $callback();
    return TRUE;
  }

  /**
   * Gets active node field target IDs.
   *
   * @param int $nid
   *   Node ID.
   * @param string $table
   *   Field data table.
   * @param string $column
   *   Target ID column.
   *
   * @return array<int, int>
   *   Target IDs.
   */
  protected function nodeFieldTargetIds(int $nid, string $table, string $column): array {
    $query = $this->database->select($table, 'f');
    $query->fields('f', [$column]);
    $query->condition('f.deleted', 0);
    $query->condition('f.bundle', 'service_request');
    $query->condition('f.entity_id', $nid);

    return array_values(array_unique(array_map('intval', $query->execute()->fetchCol())));
  }

  /**
   * Gets service request relationship group IDs for the requested group type.
   *
   * @param int $nid
   *   Node ID.
   * @param string $groupType
   *   Group bundle.
   *
   * @return array<int, int>
   *   Group IDs.
   */
  protected function serviceRequestRelationshipGroupIds(int $nid, string $groupType): array {
    $query = $this->database->select('group_relationship_field_data', 'gr');
    $query->join('groups', 'g', 'g.id = gr.gid');
    $query->fields('gr', ['gid']);
    $query->condition('gr.entity_id', $nid);
    $query->condition('gr.plugin_id', 'group_node:service_request');
    $query->condition('g.type', $groupType);

    return array_values(array_unique(array_map('intval', $query->execute()->fetchCol())));
  }

  /**
   * Checks whether an org group can be assigned to a jurisdiction.
   *
   * @param int $orgGroupId
   *   Organisation group ID.
   * @param int $jurisdictionId
   *   Service request jurisdiction ID.
   *
   * @return bool
   *   TRUE when the org references the same jurisdiction or its root.
   */
  protected function orgGroupMatchesJurisdiction(int $orgGroupId, int $jurisdictionId): bool {
    $jurisdictionType = $this->jurisdictionGroupType();
    if (!$this->groupHasType($jurisdictionId, $jurisdictionType)) {
      return FALSE;
    }

    $orgJurisdictionId = $this->orgJurisdictionId($orgGroupId);
    if ($orgJurisdictionId === NULL || !$this->groupHasType($orgJurisdictionId, $jurisdictionType)) {
      return FALSE;
    }
    if ($orgJurisdictionId === $jurisdictionId) {
      return TRUE;
    }

    $rootJurisdictionId = $this->rootJurisdictionId($jurisdictionId);
    return $rootJurisdictionId !== NULL && $orgJurisdictionId === $rootJurisdictionId;
  }

  /**
   * Checks whether a group exists with the expected bundle.
   *
   * @param int $groupId
   *   Group ID.
   * @param string $type
   *   Group bundle.
   *
   * @return bool
   *   TRUE when the group exists with that bundle.
   */
  protected function groupHasType(int $groupId, string $type): bool {
    $query = $this->database->select('groups', 'g');
    $query->fields('g', ['id']);
    $query->condition('g.id', $groupId);
    $query->condition('g.type', $type);
    $query->range(0, 1);

    return $query->execute()->fetchField() !== FALSE;
  }

  /**
   * Gets the jurisdiction assigned to an org group.
   *
   * @param int $orgGroupId
   *   Organisation group ID.
   *
   * @return int|null
   *   Jurisdiction group ID, or NULL when unresolved.
   */
  protected function orgJurisdictionId(int $orgGroupId): ?int {
    $query = $this->database->select('group__field_jurisdiction', 'fj');
    $query->join('groups', 'g', "g.id = fj.entity_id AND g.type = 'org'");
    $query->addField('fj', 'field_jurisdiction_target_id');
    $query->condition('fj.deleted', 0);
    $query->condition('fj.entity_id', $orgGroupId);
    $query->range(0, 1);
    $value = $query->execute()->fetchField();

    return $value === FALSE ? NULL : (int) $value;
  }

  /**
   * Resolves the root jurisdiction ID using field_parent_jurisdiction.
   *
   * @param int $jurisdictionId
   *   Jurisdiction group ID.
   *
   * @return int|null
   *   Root jurisdiction ID, or NULL when a cycle is detected.
   */
  protected function rootJurisdictionId(int $jurisdictionId): ?int {
    $jurisdictionType = $this->jurisdictionGroupType();
    $parentMap = [];
    $query = $this->database->select('group__field_parent_jurisdiction', 'fp');
    $query->join('groups', 'child', 'child.id = fp.entity_id AND child.type = :jurisdiction_type', [
      ':jurisdiction_type' => $jurisdictionType,
    ]);
    $query->join('groups', 'parent', 'parent.id = fp.field_parent_jurisdiction_target_id AND parent.type = :jurisdiction_parent_type', [
      ':jurisdiction_parent_type' => $jurisdictionType,
    ]);
    $query->fields('fp', ['entity_id', 'field_parent_jurisdiction_target_id']);
    $query->condition('fp.deleted', 0);
    foreach ($this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)) as $row) {
      $parentMap[(int) $row['entity_id']] = (int) $row['field_parent_jurisdiction_target_id'];
    }

    $currentId = $jurisdictionId;
    $visited = [];
    while (isset($parentMap[$currentId])) {
      if (isset($visited[$currentId])) {
        return NULL;
      }
      $visited[$currentId] = TRUE;
      $currentId = $parentMap[$currentId];
    }

    return $currentId;
  }

  /**
   * Removes a target from a node entity reference field.
   *
   * @param int $nid
   *   Node ID.
   * @param string $fieldName
   *   Field name.
   * @param int $targetId
   *   Target ID to remove.
   */
  protected function removeNodeFieldTarget(int $nid, string $fieldName, int $targetId): bool {
    $table = 'node__' . $fieldName;
    $column = $fieldName . '_target_id';
    $deleted = $this->database->delete($table)
      ->condition('entity_id', $nid)
      ->condition('bundle', 'service_request')
      ->condition('deleted', 0)
      ->condition($column, $targetId)
      ->execute();
    if ($deleted > 0) {
      $this->entityTypeManager->getStorage('node')->resetCache([$nid]);
    }

    return $deleted > 0;
  }

  /**
   * Sets node entity reference field targets through the entity API.
   *
   * @param int $nid
   *   Node ID.
   * @param string $fieldName
   *   Field name.
   * @param array<int, int> $targetIds
   *   Target IDs.
   */
  protected function setNodeFieldTargets(int $nid, string $fieldName, array $targetIds): bool {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || !$node->hasField($fieldName)) {
      throw new \RuntimeException(sprintf('Cannot load node %d with field %s.', $nid, $fieldName));
    }

    $targetIds = array_values(array_unique(array_map('intval', $targetIds)));
    $currentTargetIds = array_values(array_unique(array_map(
      'intval',
      array_column($node->get($fieldName)->getValue(), 'target_id'),
    )));
    sort($targetIds);
    sort($currentTargetIds);
    if ($targetIds === $currentTargetIds) {
      return FALSE;
    }

    $this->withGroupSyncGuard(function () use ($node, $fieldName, $targetIds): void {
      $node->set($fieldName, array_map(static fn(int $id): array => ['target_id' => $id], $targetIds));
      $node->save();
    });

    return TRUE;
  }

  /**
   * Creates a service request group relationship through the group API.
   *
   * @param int $gid
   *   Group ID.
   * @param int $nid
   *   Node ID.
   */
  protected function createServiceRequestRelationship(int $gid, int $nid): bool {
    $existing = $this->entityTypeManager->getStorage('group_relationship')->loadByProperties([
      'entity_id' => $nid,
      'gid' => $gid,
      'plugin_id' => 'group_node:service_request',
    ]);
    if (!empty($existing)) {
      return FALSE;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($gid);
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$group instanceof GroupInterface || !$node instanceof NodeInterface) {
      throw new \RuntimeException(sprintf('Cannot load group %d or node %d.', $gid, $nid));
    }

    $this->withGroupSyncGuard(static function () use ($group, $node): void {
      $group->addRelationship($node, 'group_node:service_request');
    });

    return TRUE;
  }

  /**
   * Checks whether a field_jurisdiction row still misses its relationship.
   *
   * @param array<string, mixed> $row
   *   Drift row.
   *
   * @return bool
   *   TRUE when the row still needs repair.
   */
  protected function isJurFieldMissingRelationshipRow(array $row): bool {
    $nid = (int) $row['entity_id'];
    $gid = (int) $row['field_jurisdiction_target_id'];
    $query = $this->database->select('node__field_jurisdiction', 'fj');
    $query->join('node_field_data', 'n', "n.nid = fj.entity_id AND n.type = 'service_request'");
    $query->join('groups', 'g', 'g.id = fj.field_jurisdiction_target_id AND g.type = :jurisdiction_type', [
      ':jurisdiction_type' => $this->jurisdictionGroupType(),
    ]);
    $query->leftJoin('group_relationship_field_data', 'gr', "gr.entity_id = fj.entity_id AND gr.gid = fj.field_jurisdiction_target_id AND gr.plugin_id = 'group_node:service_request'");
    $query->fields('fj', ['entity_id']);
    $query->condition('fj.deleted', 0);
    $query->condition('fj.bundle', 'service_request');
    $query->condition('fj.entity_id', $nid);
    $query->condition('fj.field_jurisdiction_target_id', $gid);
    $query->isNull('gr.id');
    $query->range(0, 1);

    return $query->execute()->fetchField() !== FALSE;
  }

  /**
   * Executes an entity write with the markaspot_group sync hook guard enabled.
   *
   * @param callable $callback
   *   Callback performing the write.
   */
  protected function withGroupSyncGuard(callable $callback): void {
    if (!function_exists('_markaspot_group_set_syncing')) {
      $callback();
      return;
    }

    $wasSyncing = function_exists('_markaspot_group_is_syncing')
      ? _markaspot_group_is_syncing()
      : FALSE;
    _markaspot_group_set_syncing(TRUE);
    try {
      $callback();
    }
    finally {
      _markaspot_group_set_syncing($wasSyncing);
    }
  }

  /**
   * Gets group relationships whose gid is missing.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function relationshipMissingGroupRows(): array {
    $query = $this->database->select('group_relationship_field_data', 'gr');
    $query->leftJoin('groups', 'g', 'g.id = gr.gid');
    $query->fields('gr', ['id', 'gid', 'entity_id', 'plugin_id']);
    $query->isNull('g.id');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAllAssoc('id', FetchAs::Associative)),
      [
        'entity_type' => 'group_relationship',
        'target_entity_type' => 'group',
      ],
    );
  }

  /**
   * Gets service request relationships whose node is missing.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function relationshipMissingNodeRows(): array {
    $query = $this->database->select('group_relationship_field_data', 'gr');
    $query->leftJoin('node_field_data', 'n', "n.nid = gr.entity_id AND n.type = 'service_request'");
    $query->fields('gr', ['id', 'gid', 'entity_id', 'plugin_id']);
    $query->condition('gr.plugin_id', 'group_node:service_request');
    $query->isNull('n.nid');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAllAssoc('id', FetchAs::Associative)),
      [
        'entity_type' => 'group_relationship',
        'related_entity_type' => 'node',
        'related_entity_bundle' => 'service_request',
      ],
    );
  }

  /**
   * Gets membership relationships whose user is missing.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function relationshipMissingUserRows(): array {
    $query = $this->database->select('group_relationship_field_data', 'gr');
    $query->leftJoin('users_field_data', 'u', 'u.uid = gr.entity_id');
    $query->fields('gr', ['id', 'gid', 'entity_id', 'plugin_id']);
    $query->condition('gr.plugin_id', 'group_membership');
    $query->isNull('u.uid');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAllAssoc('id', FetchAs::Associative)),
      [
        'entity_type' => 'group_relationship',
        'related_entity_type' => 'user',
      ],
    );
  }

  /**
   * Gets field_organisation values pointing at missing or non-org groups.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function fieldOrganisationMissingGroupRows(): array {
    $query = $this->database->select('node__field_organisation', 'fo');
    $query->join('node_field_data', 'n', "n.nid = fo.entity_id AND n.type = 'service_request'");
    $query->leftJoin('groups', 'g', "g.id = fo.field_organisation_target_id AND g.type = 'org'");
    $query->fields('fo', ['entity_id', 'field_organisation_target_id']);
    $query->condition('fo.deleted', 0);
    $query->condition('fo.bundle', 'service_request');
    $query->isNull('g.id');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'node',
        'entity_bundle' => 'service_request',
        'target_entity_type' => 'group',
        'target_entity_bundle' => 'org',
      ],
    );
  }

  /**
   * Gets field_organisation rows whose service request is missing.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function fieldOrganisationMissingNodeRows(): array {
    $query = $this->database->select('node__field_organisation', 'fo');
    $query->leftJoin('node_field_data', 'n', "n.nid = fo.entity_id AND n.type = 'service_request'");
    $query->fields('fo', ['entity_id', 'field_organisation_target_id']);
    $query->condition('fo.deleted', 0);
    $query->condition('fo.bundle', 'service_request');
    $query->isNull('n.nid');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'node',
        'entity_bundle' => 'service_request',
        'field_name' => 'field_organisation',
        'missing_entity_type' => 'node',
      ],
    );
  }

  /**
   * Gets field_jurisdiction values pointing at missing or non-jurisdiction groups.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function fieldJurisdictionMissingGroupRows(): array {
    $jurisdictionType = $this->jurisdictionGroupType();
    $query = $this->database->select('node__field_jurisdiction', 'fj');
    $query->join('node_field_data', 'n', "n.nid = fj.entity_id AND n.type = 'service_request'");
    $query->leftJoin('groups', 'g', 'g.id = fj.field_jurisdiction_target_id AND g.type = :jurisdiction_type', [
      ':jurisdiction_type' => $jurisdictionType,
    ]);
    $query->fields('fj', ['entity_id', 'field_jurisdiction_target_id']);
    $query->condition('fj.deleted', 0);
    $query->condition('fj.bundle', 'service_request');
    $query->isNull('g.id');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'node',
        'entity_bundle' => 'service_request',
        'target_entity_type' => 'group',
        'target_entity_bundle' => $jurisdictionType,
      ],
    );
  }

  /**
   * Gets field_jurisdiction rows whose service request is missing.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function fieldJurisdictionMissingNodeRows(): array {
    $query = $this->database->select('node__field_jurisdiction', 'fj');
    $query->leftJoin('node_field_data', 'n', "n.nid = fj.entity_id AND n.type = 'service_request'");
    $query->fields('fj', ['entity_id', 'field_jurisdiction_target_id']);
    $query->condition('fj.deleted', 0);
    $query->condition('fj.bundle', 'service_request');
    $query->isNull('n.nid');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'node',
        'entity_bundle' => 'service_request',
        'field_name' => 'field_jurisdiction',
        'missing_entity_type' => 'node',
      ],
    );
  }

  /**
   * Gets organisation groups without a jurisdiction assignment.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function orgMissingJurisdictionRows(): array {
    $jurisdictionType = $this->jurisdictionGroupType();
    $query = $this->database->select('groups', 'g');
    $query->leftJoin('group__field_jurisdiction', 'fj', 'fj.entity_id = g.id AND fj.deleted = 0');
    $query->leftJoin('groups', 'jur', 'jur.id = fj.field_jurisdiction_target_id AND jur.type = :jurisdiction_type', [
      ':jurisdiction_type' => $jurisdictionType,
    ]);
    $query->fields('g', ['id', 'type']);
    $query->addField('fj', 'field_jurisdiction_target_id');
    $query->condition('g.type', 'org');
    $or = $query->orConditionGroup()
      ->isNull('fj.entity_id')
      ->isNull('jur.id');
    $query->condition($or);

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'group',
        'entity_bundle' => 'org',
        'missing_field' => 'field_jurisdiction',
      ],
    );
  }

  /**
   * Gets organisation groups referencing child jurisdictions.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function orgChildJurisdictionRows(): array {
    $jurisdictionType = $this->jurisdictionGroupType();
    $query = $this->database->select('groups', 'g');
    $query->join('group__field_jurisdiction', 'fj', 'fj.entity_id = g.id AND fj.deleted = 0');
    $query->join('groups', 'jur', 'jur.id = fj.field_jurisdiction_target_id AND jur.type = :jurisdiction_type', [
      ':jurisdiction_type' => $jurisdictionType,
    ]);
    $query->join('group__field_parent_jurisdiction', 'p', 'p.entity_id = jur.id AND p.deleted = 0');
    $query->fields('g', ['id', 'type']);
    $query->addField('fj', 'field_jurisdiction_target_id');
    $query->addField('p', 'field_parent_jurisdiction_target_id');
    $query->condition('g.type', 'org');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'group',
        'entity_bundle' => 'org',
        'field_name' => 'field_jurisdiction',
      ],
    );
  }

  /**
   * Gets jurisdiction parent references pointing at invalid groups.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function jurParentMissingGroupRows(): array {
    $jurisdictionType = $this->jurisdictionGroupType();
    $query = $this->database->select('group__field_parent_jurisdiction', 'fp');
    $query->join('groups', 'child', 'child.id = fp.entity_id AND child.type = :jurisdiction_type', [
      ':jurisdiction_type' => $jurisdictionType,
    ]);
    $query->leftJoin('groups', 'parent', 'parent.id = fp.field_parent_jurisdiction_target_id AND parent.type = :jurisdiction_parent_type', [
      ':jurisdiction_parent_type' => $jurisdictionType,
    ]);
    $query->fields('fp', ['entity_id', 'field_parent_jurisdiction_target_id']);
    $query->condition('fp.deleted', 0);
    $query->isNull('parent.id');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'group',
        'entity_bundle' => $jurisdictionType,
        'field_name' => 'field_parent_jurisdiction',
        'target_entity_type' => 'group',
        'target_entity_bundle' => $jurisdictionType,
      ],
    );
  }

  /**
   * Gets jurisdiction groups that reference themselves as parent.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function jurParentSelfReferenceRows(): array {
    $jurisdictionType = $this->jurisdictionGroupType();
    $query = $this->database->select('group__field_parent_jurisdiction', 'fp');
    $query->join('groups', 'g', 'g.id = fp.entity_id AND g.type = :jurisdiction_type', [
      ':jurisdiction_type' => $jurisdictionType,
    ]);
    $query->fields('fp', ['entity_id', 'field_parent_jurisdiction_target_id']);
    $query->condition('fp.deleted', 0);
    $query->where('[fp].[entity_id] = [fp].[field_parent_jurisdiction_target_id]');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'group',
        'entity_bundle' => $jurisdictionType,
        'field_name' => 'field_parent_jurisdiction',
      ],
    );
  }

  /**
   * Gets jurisdiction groups involved in multi-node parent cycles.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function jurParentCycleRows(): array {
    $jurisdictionType = $this->jurisdictionGroupType();
    $query = $this->database->select('group__field_parent_jurisdiction', 'fp');
    $query->join('groups', 'child', 'child.id = fp.entity_id AND child.type = :jurisdiction_type', [
      ':jurisdiction_type' => $jurisdictionType,
    ]);
    $query->join('groups', 'parent', 'parent.id = fp.field_parent_jurisdiction_target_id AND parent.type = :jurisdiction_parent_type', [
      ':jurisdiction_parent_type' => $jurisdictionType,
    ]);
    $query->fields('fp', ['entity_id', 'field_parent_jurisdiction_target_id']);
    $query->condition('fp.deleted', 0);

    $parentMap = [];
    foreach ($this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)) as $row) {
      $childId = (int) $row['entity_id'];
      $parentId = (int) $row['field_parent_jurisdiction_target_id'];
      if ($childId !== $parentId) {
        $parentMap[$childId] = $parentId;
      }
    }

    $rows = [];
    $reportedCycles = [];
    foreach (array_keys($parentMap) as $startId) {
      $path = [];
      $pathOrder = [];
      $currentId = $startId;
      while (isset($parentMap[$currentId])) {
        if (isset($path[$currentId])) {
          $cycleNodes = array_slice($pathOrder, $path[$currentId]);
          $cycleKeyNodes = $cycleNodes;
          sort($cycleKeyNodes);
          $cycleKey = implode(',', $cycleKeyNodes);
          if (!isset($reportedCycles[$cycleKey])) {
            $cyclePath = implode(',', $cycleNodes) . ',' . $currentId;
            foreach ($cycleNodes as $cycleNodeId) {
              $rows[] = [
                'entity_id' => $cycleNodeId,
                'field_parent_jurisdiction_target_id' => $parentMap[$cycleNodeId],
                'cycle_at' => $currentId,
                'cycle_path' => $cyclePath,
              ];
            }
            $reportedCycles[$cycleKey] = TRUE;
          }
          break;
        }
        $path[$currentId] = count($pathOrder);
        $pathOrder[] = $currentId;
        $currentId = $parentMap[$currentId];
      }
    }

    return $this->withRowContext(
      $rows,
      [
        'entity_type' => 'group',
        'entity_bundle' => $jurisdictionType,
        'field_name' => 'field_parent_jurisdiction',
      ],
    );
  }

  /**
   * Gets field_jurisdiction values without matching jurisdiction relationships.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function jurFieldMissingRelationshipRows(): array {
    $jurisdictionType = $this->jurisdictionGroupType();
    $query = $this->database->select('node__field_jurisdiction', 'fj');
    $query->join('node_field_data', 'n', "n.nid = fj.entity_id AND n.type = 'service_request'");
    $query->join('groups', 'g', 'g.id = fj.field_jurisdiction_target_id AND g.type = :jurisdiction_type', [
      ':jurisdiction_type' => $jurisdictionType,
    ]);
    $query->leftJoin('group_relationship_field_data', 'gr', "gr.entity_id = fj.entity_id AND gr.gid = fj.field_jurisdiction_target_id AND gr.plugin_id = 'group_node:service_request'");
    $query->fields('fj', ['entity_id', 'field_jurisdiction_target_id']);
    $query->condition('fj.deleted', 0);
    $query->condition('fj.bundle', 'service_request');
    $query->isNull('gr.id');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)),
      [
        'entity_type' => 'node',
        'entity_bundle' => 'service_request',
        'target_entity_type' => 'group',
        'target_entity_bundle' => $jurisdictionType,
        'missing_entity_type' => 'group_relationship',
      ],
    );
  }

  /**
   * Gets jurisdiction relationships not mirrored by field_jurisdiction.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function jurRelationshipMissingFieldRows(): array {
    $query = $this->database->select('group_relationship_field_data', 'gr');
    $query->join('node_field_data', 'n', "n.nid = gr.entity_id AND n.type = 'service_request'");
    $query->join('groups', 'g', 'g.id = gr.gid AND g.type = :jurisdiction_type', [
      ':jurisdiction_type' => $this->jurisdictionGroupType(),
    ]);
    $query->leftJoin('node__field_jurisdiction', 'fj', "fj.entity_id = gr.entity_id AND fj.field_jurisdiction_target_id = gr.gid AND fj.deleted = 0");
    $query->fields('gr', ['id', 'gid', 'entity_id', 'plugin_id']);
    $query->condition('gr.plugin_id', 'group_node:service_request');
    $query->isNull('fj.entity_id');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAllAssoc('id', FetchAs::Associative)),
      [
        'entity_type' => 'group_relationship',
        'related_entity_type' => 'node',
        'related_entity_bundle' => 'service_request',
        'missing_field' => 'field_jurisdiction',
      ],
    );
  }

  /**
   * Gets org relationships not mirrored by field_organisation.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function orgRelationshipMissingFieldRows(): array {
    $query = $this->database->select('group_relationship_field_data', 'gr');
    $query->join('node_field_data', 'n', "n.nid = gr.entity_id AND n.type = 'service_request'");
    $query->join('groups', 'g', "g.id = gr.gid AND g.type = 'org'");
    $query->leftJoin('node__field_organisation', 'fo', "fo.entity_id = gr.entity_id AND fo.field_organisation_target_id = gr.gid AND fo.deleted = 0");
    $query->fields('gr', ['id', 'gid', 'entity_id', 'plugin_id']);
    $query->condition('gr.plugin_id', 'group_node:service_request');
    $query->isNull('fo.entity_id');

    return $this->withRowContext(
      $this->fetchRows($query->execute()->fetchAllAssoc('id', FetchAs::Associative)),
      [
        'entity_type' => 'group_relationship',
        'related_entity_type' => 'node',
        'related_entity_bundle' => 'service_request',
        'missing_field' => 'field_organisation',
      ],
    );
  }

  /**
   * Builds expected-vs-actual group relationship correctness rows.
   *
   * @return array<string, array<int, array<string, mixed>>>
   *   Rows keyed by correctness check ID.
   */
  protected function correctnessDiffRows(): array {
    $jurisdictionType = $this->jurisdictionGroupType();
    $rows = [
      'missing_context' => [],
      'unresolved_expected_org' => [],
      'expected_relationship_missing' => [],
      'unexpected_relationship' => [],
    ];

    foreach ($this->serviceRequestContextRows() as $context) {
      $nid = (int) $context['entity_id'];
      $categoryId = isset($context['field_category_target_id'])
        ? (int) $context['field_category_target_id'] : 0;
      $jurisdictionId = isset($context['field_jurisdiction_target_id'])
        ? (int) $context['field_jurisdiction_target_id'] : 0;

      if ($categoryId <= 0 || $jurisdictionId <= 0) {
        $rows['missing_context'][] = [
          'entity_id' => $nid,
          'entity_type' => 'node',
          'entity_bundle' => 'service_request',
          'field_category_target_id' => $categoryId ?: NULL,
          'field_jurisdiction_target_id' => $jurisdictionId ?: NULL,
        ];
        continue;
      }

      $expected = [
        $jurisdictionId => [
          'expected_group_id' => $jurisdictionId,
          'expected_group_type' => $jurisdictionType,
          'expected_source' => 'field_jurisdiction',
        ],
      ];

      $expectedOrgId = $this->deriveExpectedOrganisationId($categoryId, $jurisdictionId);
      if ($expectedOrgId > 0) {
        $expected[$expectedOrgId] = [
          'expected_group_id' => $expectedOrgId,
          'expected_group_type' => 'org',
          'expected_source' => 'field_category_and_field_jurisdiction',
        ];
      }
      else {
        $rows['unresolved_expected_org'][] = [
          'entity_id' => $nid,
          'entity_type' => 'node',
          'entity_bundle' => 'service_request',
          'field_category_target_id' => $categoryId,
          'field_jurisdiction_target_id' => $jurisdictionId,
        ];
      }

      $actual = $this->actualGroupRelationshipsForServiceRequest($nid);

      foreach ($expected as $gid => $expectedRow) {
        if (!isset($actual[$gid])) {
          $rows['expected_relationship_missing'][] = [
            'entity_id' => $nid,
            'entity_type' => 'node',
            'entity_bundle' => 'service_request',
            'field_category_target_id' => $categoryId,
            'field_jurisdiction_target_id' => $jurisdictionId,
          ] + $expectedRow;
        }
      }

      foreach ($actual as $gid => $actualRow) {
        if (!isset($expected[$gid])) {
          $rows['unexpected_relationship'][] = [
            'entity_id' => $nid,
            'entity_type' => 'group_relationship',
            'related_entity_type' => 'node',
            'related_entity_bundle' => 'service_request',
            'relationship_id' => $actualRow['id'],
            'actual_group_id' => $gid,
            'actual_group_type' => $actualRow['group_type'],
            'field_category_target_id' => $categoryId,
            'field_jurisdiction_target_id' => $jurisdictionId,
            'expected_group_ids' => implode(',', array_keys($expected)),
          ];
        }
      }
    }

    return $rows;
  }

  /**
   * Gets service request category and jurisdiction context.
   *
   * @return array<int, array<string, mixed>>
   *   Rows keyed numerically.
   */
  protected function serviceRequestContextRows(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('node__field_category', 'fc', "fc.entity_id = n.nid AND fc.deleted = 0 AND fc.bundle = 'service_request'");
    $query->leftJoin('node__field_jurisdiction', 'fj', "fj.entity_id = n.nid AND fj.deleted = 0 AND fj.bundle = 'service_request'");
    $query->addField('n', 'nid', 'entity_id');
    $query->addField('fc', 'field_category_target_id');
    $query->addField('fj', 'field_jurisdiction_target_id');
    $query->condition('n.type', 'service_request');

    return $this->fetchRows($query->execute()->fetchAll(FetchAs::Associative));
  }

  /**
   * Derives the expected organisation for category and jurisdiction.
   *
   * @param int $categoryId
   *   Service category term ID.
   * @param int $jurisdictionId
   *   Jurisdiction group ID from the service request.
   *
   * @return int
   *   Expected organisation group ID, or 0 if none can be derived.
   */
  protected function deriveExpectedOrganisationId(int $categoryId, int $jurisdictionId): int {
    $query = $this->database->select('groups', 'g');
    $query->join('group__field_jurisdiction', 'gj', "gj.entity_id = g.id AND gj.deleted = 0 AND gj.field_jurisdiction_target_id = :jurisdiction_id", [
      ':jurisdiction_id' => $jurisdictionId,
    ]);
    $query->join('group__field_service_categories', 'gsc', "gsc.entity_id = g.id AND gsc.deleted = 0 AND gsc.field_service_categories_target_id = :category_id", [
      ':category_id' => $categoryId,
    ]);
    $query->fields('g', ['id']);
    $query->condition('g.type', 'org');
    $query->range(0, 1);
    $orgId = $query->execute()->fetchField();
    if ($orgId !== FALSE) {
      return (int) $orgId;
    }

    $fallback = $this->database->select('taxonomy_term__field_category_gid', 'cg');
    $fallback->join('groups', 'g', "g.id = cg.field_category_gid_target_id AND g.type = 'org'");
    $fallback->addField('cg', 'field_category_gid_target_id');
    $fallback->condition('cg.deleted', 0);
    $fallback->condition('cg.bundle', 'service_category');
    $fallback->condition('cg.entity_id', $categoryId);
    $fallback->range(0, 1);
    $fallbackOrgId = $fallback->execute()->fetchField();

    return $fallbackOrgId === FALSE ? 0 : (int) $fallbackOrgId;
  }

  /**
   * Gets actual service request group relationships keyed by group ID.
   *
   * @param int $nid
   *   Service request node ID.
   *
   * @return array<int, array{id:int, group_type:string}>
   *   Actual relationships keyed by group ID.
   */
  protected function actualGroupRelationshipsForServiceRequest(int $nid): array {
    $query = $this->database->select('group_relationship_field_data', 'gr');
    $query->join('groups', 'g', 'g.id = gr.gid');
    $query->fields('gr', ['id', 'gid']);
    $query->addField('g', 'type', 'group_type');
    $query->condition('gr.entity_id', $nid);
    $query->condition('gr.plugin_id', 'group_node:service_request');
    $query->condition('g.type', array_values(array_unique([$this->jurisdictionGroupType(), 'org'])), 'IN');

    $rows = [];
    foreach ($this->fetchRows($query->execute()->fetchAll(FetchAs::Associative)) as $row) {
      $rows[(int) $row['gid']] = [
        'id' => (int) $row['id'],
        'group_type' => (string) $row['group_type'],
      ];
    }

    return $rows;
  }

  /**
   * Normalizes database rows to plain arrays with scalar values.
   *
   * @param array<int|string, array<string, mixed>> $rows
   *   Raw database rows.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized rows.
   */
  protected function fetchRows(array $rows): array {
    return array_values(array_map(static function (array $row): array {
      foreach ($row as $key => $value) {
        if (is_numeric($value)) {
          $row[$key] = (int) $value;
        }
      }
      return $row;
    }, $rows));
  }

  /**
   * Adds static entity context to result rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Result rows.
   * @param array<string, string> $context
   *   Context values to add when the row does not already contain the key.
   *
   * @return array<int, array<string, mixed>>
   *   Result rows with entity context.
   */
  protected function withRowContext(array $rows, array $context): array {
    return array_map(static fn(array $row): array => $row + $context, $rows);
  }

}
