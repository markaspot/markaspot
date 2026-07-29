<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

/**
 * Builds idempotent status selection and copy plans without entity writes.
 */
class StatusTermSeedPlanner {

  /**
   * Plans selecting an effective status set inside one jurisdiction tree.
   *
   * @param list<array{source_tid: int, name: string}> $sourceTerms
   *   Source term identifiers and default-language names.
   *
   * @return list<array{source_tid: int, name: string, action: string, target_tid: int, reason: string}>
   *   Ordered selection plan rows.
   */
  public function planSelection(array $sourceTerms): array {
    return array_map(
      static fn(array $sourceTerm): array => [
        'source_tid' => $sourceTerm['source_tid'],
        'name' => $sourceTerm['name'],
        'action' => 'select',
        'target_tid' => $sourceTerm['source_tid'],
        'reason' => '',
      ],
      $sourceTerms,
    );
  }

  /**
   * Plans status term copies between different root jurisdictions.
   *
   * @param list<array{source_tid: int, name: string}> $sourceTerms
   *   Source term identifiers and default-language names.
   * @param list<array{target_tid: int, name: string}> $existingTargetTerms
   *   Existing terms in the target root pool.
   *
   * @return array
   *   Ordered create or skip rows, including matched target or source IDs.
   */
  public function planCopies(array $sourceTerms, array $existingTargetTerms): array {
    $occupiedNames = [];
    foreach ($existingTargetTerms as $term) {
      $occupiedNames[$this->normalizeName($term['name'])] = [
        'target_tid' => $term['target_tid'],
        'source_tid' => NULL,
      ];
    }

    $plan = [];
    foreach ($sourceTerms as $sourceTerm) {
      $normalizedName = $this->normalizeName($sourceTerm['name']);
      $skip = array_key_exists($normalizedName, $occupiedNames);
      $match = $skip ? $occupiedNames[$normalizedName] : [
        'target_tid' => NULL,
        'source_tid' => NULL,
      ];
      $plan[] = [
        'source_tid' => $sourceTerm['source_tid'],
        'name' => $sourceTerm['name'],
        'action' => $skip ? 'skip' : 'create',
        'target_tid' => $match['target_tid'],
        'match_source_tid' => $match['source_tid'],
        'reason' => $skip ? 'Name already exists on target.' : '',
      ];

      // A repeated source name must not produce duplicate target terms.
      $occupiedNames[$normalizedName] ??= [
        'target_tid' => NULL,
        'source_tid' => $sourceTerm['source_tid'],
      ];
    }

    return $plan;
  }

  /**
   * Plans removal of target selections absent from the replacement set.
   *
   * @param list<array{target_tid: int, name: string}> $currentTargetTerms
   *   Terms currently selected on the target jurisdiction.
   * @param int[] $replacementTargetIds
   *   Term IDs in the replacement selection.
   *
   * @return list<array{target_tid: int, name: string, action: string, reason: string}>
   *   Ordered deselection plan rows.
   */
  public function planDeselections(
    array $currentTargetTerms,
    array $replacementTargetIds,
  ): array {
    $replacement = array_fill_keys(
      array_map('intval', $replacementTargetIds),
      TRUE,
    );

    $plan = [];
    foreach ($currentTargetTerms as $term) {
      if (isset($replacement[$term['target_tid']])) {
        continue;
      }
      $plan[] = [
        'target_tid' => $term['target_tid'],
        'name' => $term['name'],
        'action' => 'deselect',
        'reason' => 'Not present in the source effective status set.',
      ];
    }

    return $plan;
  }

  /**
   * Normalizes a term name for Unicode-aware case-insensitive comparison.
   *
   * @param string $name
   *   Default-language term name.
   *
   * @return string
   *   Comparison key.
   */
  protected function normalizeName(string $name): string {
    return mb_strtolower($name, 'UTF-8');
  }

}
