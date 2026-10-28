<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

/**
 * Field-to-relation organisation drift of one service request.
 *
 * Field_organisation is the authority. A relation whose organisation the
 * field does not list is removed, and so is every duplicate relation to the
 * same organisation. A field organisation without a relation is added only
 * when it is a valid organisation of the request's jurisdiction; otherwise it
 * is reported as skipped with a reason.
 */
final class OrganisationRelationDiff {

  /**
   * Constructs the diff.
   *
   * @param int $nid
   *   Service request node ID.
   * @param bool $published
   *   Whether the request is published.
   * @param int|null $jurisdictionId
   *   First field_jurisdiction value, NULL when empty.
   * @param list<int> $fieldIds
   *   Sorted distinct field_organisation target IDs.
   * @param array<int, int> $relations
   *   Organisation group IDs keyed by relationship entity ID.
   * @param list<int> $add
   *   Organisation group IDs that get a relation.
   * @param array<int, int> $remove
   *   Organisation group IDs keyed by the relationship entity ID to delete.
   * @param array<int, string> $skipped
   *   Reasons keyed by field organisation ID that does not get a relation.
   * @param string|null $blocked
   *   Reason why the request is left untouched, NULL otherwise.
   */
  public function __construct(
    public readonly int $nid,
    public readonly bool $published,
    public readonly ?int $jurisdictionId,
    public readonly array $fieldIds,
    public readonly array $relations,
    public readonly array $add,
    public readonly array $remove,
    public readonly array $skipped,
    public readonly ?string $blocked,
  ) {}

  /**
   * Builds the diff from the stored state of one request.
   *
   * @param int $nid
   *   Service request node ID.
   * @param bool $published
   *   Whether the request is published.
   * @param int|null $jurisdictionId
   *   First field_jurisdiction value.
   * @param list<int> $fieldIds
   *   field_organisation target IDs.
   * @param array<int, int> $relations
   *   Organisation group IDs keyed by relationship entity ID.
   * @param array<int, string> $additionProblems
   *   Reasons keyed by field organisation ID that must not get a relation.
   * @param string|null $blocked
   *   Reason to leave the request untouched.
   */
  public static function fromState(int $nid, bool $published, ?int $jurisdictionId, array $fieldIds, array $relations, array $additionProblems, ?string $blocked = NULL): self {
    $fieldIds = array_values(array_unique(array_map('intval', $fieldIds)));
    sort($fieldIds);
    ksort($relations);

    $add = [];
    $remove = [];
    $skipped = [];
    if ($blocked === NULL) {
      $kept = [];
      foreach ($relations as $relationshipId => $gid) {
        if (!in_array($gid, $fieldIds, TRUE) || isset($kept[$gid])) {
          $remove[$relationshipId] = $gid;
          continue;
        }
        $kept[$gid] = TRUE;
      }
      foreach ($fieldIds as $gid) {
        if (isset($kept[$gid])) {
          continue;
        }
        if (isset($additionProblems[$gid])) {
          $skipped[$gid] = $additionProblems[$gid];
          continue;
        }
        $add[] = $gid;
      }
    }

    return new self($nid, $published, $jurisdictionId, $fieldIds, $relations, $add, $remove, $skipped, $blocked);
  }

  /**
   * Returns the sorted distinct organisation IDs of the relations.
   *
   * @return list<int>
   *   Group IDs.
   */
  public function relationIds(): array {
    $ids = array_values(array_unique($this->relations));
    sort($ids);
    return $ids;
  }

  /**
   * Whether field and relations disagree, duplicates included.
   *
   * RequestIndexVerifier::drift() applies the same definition, so both
   * report the same requests.
   */
  public function isDrift(): bool {
    return $this->fieldIds !== $this->relationIds()
      || count($this->relations) !== count($this->relationIds());
  }

  /**
   * Whether applying the diff writes anything.
   */
  public function hasChanges(): bool {
    return $this->add !== [] || $this->remove !== [];
  }

  /**
   * Returns the duplicate relation count included in the removals.
   */
  public function duplicateCount(): int {
    return count($this->relations) - count($this->relationIds());
  }

  /**
   * Describes the drift, e.g. "field=53 relations=1 -> add 53, remove 1".
   */
  public function pattern(): string {
    $relations = array_values($this->relations);
    sort($relations);
    $actions = [];
    if ($this->blocked !== NULL) {
      $actions[] = 'blocked (' . $this->blocked . ')';
    }
    if ($this->add !== []) {
      $actions[] = 'add ' . self::ids($this->add);
    }
    $extra = array_values(array_unique(array_diff($this->remove, $this->fieldIds)));
    if ($extra !== []) {
      $actions[] = 'remove ' . self::ids($extra);
    }
    $duplicates = array_values(array_unique(array_intersect($this->remove, $this->fieldIds)));
    if ($duplicates !== []) {
      $actions[] = 'remove duplicate ' . self::ids($duplicates);
    }
    foreach ($this->skipped as $gid => $reason) {
      $actions[] = sprintf('skip %d (%s)', $gid, $reason);
    }

    return sprintf(
      'field=%s relations=%s -> %s',
      self::ids($this->fieldIds),
      self::ids($relations),
      $actions === [] ? 'nothing' : implode(', ', $actions),
    );
  }

  /**
   * Formats IDs as a comma list, "-" when empty.
   *
   * @param array<int, int> $ids
   *   Group IDs.
   */
  private static function ids(array $ids): string {
    $ids = array_values($ids);
    sort($ids);
    return $ids === [] ? '-' : implode(',', $ids);
  }

}
