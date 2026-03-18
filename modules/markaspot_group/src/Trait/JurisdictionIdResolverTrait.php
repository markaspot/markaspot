<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Trait;

/**
 * Resolves jurisdiction identifiers from slug or numeric ID.
 *
 * Accepts both numeric group IDs (e.g. 14) and URL slugs (e.g. "amsterdam").
 * Slugs are resolved via field_slug on group entities of type "jur".
 *
 * Usage: classes using this trait must have access to the entity type manager
 * via $this->entityTypeManager() (ControllerBase) or $this->entityTypeManager
 * (injected property).
 */
trait JurisdictionIdResolverTrait {

  /**
   * Resolves a jurisdiction value (numeric ID or slug) to a group ID.
   *
   * @param string|int|null $value
   *   The jurisdiction identifier: numeric group ID, slug string, or NULL.
   * @param string $group_type
   *   The group type machine name. Defaults to 'jur'.
   *
   * @return int|null
   *   The numeric group ID, or NULL if not found or input is empty.
   */
  protected function resolveJurisdictionId(string|int|null $value, string $group_type = 'jur'): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    // Numeric = direct group ID.
    if (is_numeric($value)) {
      return (int) $value;
    }

    // Validate slug format: alphanumeric, hyphens, underscores, max 64 chars.
    if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $value)) {
      return NULL;
    }

    // Lookup by slug.
    $storage = method_exists($this, 'entityTypeManager')
      ? $this->entityTypeManager()->getStorage('group')
      : $this->entityTypeManager->getStorage('group');

    $groups = $storage->loadByProperties([
      'type' => $group_type,
      'field_slug' => $value,
      'status' => 1,
    ]);

    $group = reset($groups);
    return $group ? (int) $group->id() : NULL;
  }

}
