<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Builds frontend metadata for organisation group picker rows.
 */
class OrganisationMetadataBuilder {

  /**
   * Constructs an OrganisationMetadataBuilder.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OrgHierarchyResolverInterface $orgHierarchyResolver,
  ) {}

  /**
   * Builds the shared organisation metadata response shape.
   *
   * @param \Drupal\group\Entity\GroupInterface $organisation
   *   The organisation group.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheMetadata
   *   Optional cache metadata to enrich with groups used for derived fields.
   *
   * @return array{code: string, level: int, path_labels: string[], child_count: int}
   *   Metadata used by assignment and delegation pickers.
   */
  public function build(GroupInterface $organisation, ?CacheableMetadata $cacheMetadata = NULL): array {
    if ($organisation->bundle() !== 'org') {
      return [
        'code' => $this->getCode($organisation),
        'level' => 0,
        'path_labels' => [],
        'child_count' => 0,
      ];
    }

    $groupId = (int) $organisation->id();
    $ancestorIds = array_reverse($this->orgHierarchyResolver->getAncestorIds($groupId));
    $childIds = $this->orgHierarchyResolver->getChildIds($groupId);
    $this->addCacheableGroupDependencies($childIds, $cacheMetadata);

    return [
      'code' => $this->getCode($organisation),
      'level' => count($ancestorIds),
      'path_labels' => $this->loadLabels($ancestorIds, $cacheMetadata),
      'child_count' => count($childIds),
    ];
  }

  /**
   * Reads the trimmed administrative organisation code.
   */
  private function getCode(GroupInterface $organisation): string {
    if (!$organisation->hasField('field_org_code')
      || $organisation->get('field_org_code')->isEmpty()) {
      return '';
    }

    return trim((string) $organisation->get('field_org_code')->value);
  }

  /**
   * Loads group labels in the given ID order.
   *
   * @param int[] $groupIds
   *   Group IDs to load.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheMetadata
   *   Optional cache metadata to enrich with loaded groups.
   *
   * @return string[]
   *   Labels keyed as a list in input order.
   */
  private function loadLabels(array $groupIds, ?CacheableMetadata $cacheMetadata): array {
    if ($groupIds === []) {
      return [];
    }

    $groups = $this->entityTypeManager
      ->getStorage('group')
      ->loadMultiple($groupIds);

    $labels = [];
    foreach ($groupIds as $groupId) {
      $group = $groups[$groupId] ?? NULL;
      if ($group instanceof GroupInterface) {
        $cacheMetadata?->addCacheableDependency($group);
        $labels[] = (string) $group->label();
      }
    }

    return $labels;
  }

  /**
   * Adds loaded group dependencies for derived child counts.
   *
   * @param int[] $groupIds
   *   Group IDs to load.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheMetadata
   *   Optional cache metadata to enrich with loaded groups.
   */
  private function addCacheableGroupDependencies(array $groupIds, ?CacheableMetadata $cacheMetadata): void {
    if ($cacheMetadata === NULL || $groupIds === []) {
      return;
    }

    $groups = $this->entityTypeManager
      ->getStorage('group')
      ->loadMultiple($groupIds);

    foreach ($groups as $group) {
      if ($group instanceof GroupInterface) {
        $cacheMetadata->addCacheableDependency($group);
      }
    }
  }

}
