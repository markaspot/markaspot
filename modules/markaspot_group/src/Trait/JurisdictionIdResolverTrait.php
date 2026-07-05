<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Trait;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Resolves jurisdiction identifiers from slug or numeric ID.
 *
 * Accepts both numeric group IDs (e.g. 14) and URL slugs (e.g. "amsterdam").
 * Slugs are resolved via field_slug on configured jurisdiction group entities.
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
   * @param string|null $group_type
   *   The group type machine name. Defaults to configured jurisdiction type.
   *
   * @return int|null
   *   The numeric group ID, or NULL if not found or input is empty.
   */
  protected function resolveJurisdictionId(string|int|null $value, ?string $group_type = NULL): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    // Numeric = direct positive group ID.
    if (is_int($value) || ctype_digit($value)) {
      $id = (int) $value;
      if ($id <= 0) {
        return NULL;
      }

      $group_type ??= $this->getJurisdictionGroupType();
      $storage = method_exists($this, 'entityTypeManager')
        ? $this->entityTypeManager()->getStorage('group')
        : $this->entityTypeManager->getStorage('group');

      $group = $storage->load($id);
      return $group instanceof GroupInterface
        && $group->bundle() === $group_type
        && $group->isPublished()
        ? (int) $group->id()
        : NULL;
    }

    // Validate slug format: alphanumeric, hyphens, underscores, max 64 chars.
    if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $value)) {
      return NULL;
    }

    $group_type ??= $this->getJurisdictionGroupType();

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

  /**
   * Gets the configured jurisdiction group type.
   *
   * @return string
   *   The configured jurisdiction group type machine name.
   */
  protected function getJurisdictionGroupType(): string {
    if (
      property_exists($this, 'configFactory')
      && $this->configFactory instanceof ConfigFactoryInterface
    ) {
      return $this->configFactory
        ->get('markaspot_open311.settings')
        ->get('jurisdiction_group_type') ?: 'jur';
    }

    try {
      if (\Drupal::hasService('config.factory')) {
        $configured = \Drupal::config('markaspot_open311.settings')
          ->get('jurisdiction_group_type');
        return is_string($configured) && $configured !== '' ? $configured : 'jur';
      }
    }
    catch (\Throwable) {
      // Unit tests may instantiate consumers without a Drupal container.
    }

    return 'jur';
  }

  /**
   * Checks whether a group is a configured jurisdiction group.
   *
   * @param mixed $group
   *   The candidate group entity.
   *
   * @return bool
   *   TRUE when the group uses the configured jurisdiction type.
   */
  protected function isJurisdictionGroup(mixed $group): bool {
    return $group instanceof GroupInterface
      && $group->bundle() === $this->getJurisdictionGroupType();
  }

  /**
   * Checks whether a role ID grants a jurisdiction role.
   *
   * @param mixed $role
   *   The candidate group role entity.
   * @param string $role_name
   *   The group role suffix, for example "tenant_admin".
   *
   * @return bool
   *   TRUE when the role matches the configured or legacy jur role ID.
   */
  protected function isJurisdictionRole(mixed $role, string $role_name): bool {
    if (!is_object($role) || !method_exists($role, 'id')) {
      return FALSE;
    }

    $role_id = $role->id();
    return $role_id === $this->getJurisdictionGroupType() . '-' . $role_name
      || $role_id === 'jur-' . $role_name;
  }

  /**
   * Returns configured plus legacy jurisdiction role IDs for membership loads.
   *
   * @param string $role_name
   *   The group role suffix, for example "tenant_admin".
   *
   * @return string[]
   *   Role IDs ordered with the configured role first.
   */
  protected function jurisdictionRoleIds(string $role_name): array {
    return array_values(array_unique([
      $this->getJurisdictionGroupType() . '-' . $role_name,
      'jur-' . $role_name,
    ]));
  }

  /**
   * Maps configured jurisdiction role IDs to the canonical frontend contract.
   *
   * @param string $role_id
   *   The raw group role ID.
   *
   * @return string
   *   The canonical jurisdiction role ID for auth payloads.
   */
  protected function canonicalizeJurisdictionRoleId(string $role_id): string {
    $jurisdiction_group_type = $this->getJurisdictionGroupType();
    if (
      $jurisdiction_group_type !== 'jur'
      && str_starts_with($role_id, $jurisdiction_group_type . '-')
    ) {
      return 'jur-' . substr($role_id, strlen($jurisdiction_group_type) + 1);
    }

    return $role_id;
  }

  /**
   * Maps canonical frontend jurisdiction role IDs to stored group role IDs.
   *
   * @param string $role_id
   *   The requested group role ID.
   *
   * @return string
   *   The stored role ID for the configured jurisdiction group type.
   */
  protected function storageJurisdictionRoleId(string $role_id): string {
    $jurisdiction_group_type = $this->getJurisdictionGroupType();
    if (
      $jurisdiction_group_type !== 'jur'
      && str_starts_with($role_id, 'jur-')
    ) {
      return $jurisdiction_group_type . '-' . substr($role_id, 4);
    }

    return $role_id;
  }

  /**
   * Maps multiple canonical jurisdiction role IDs to storage IDs.
   *
   * @param array $role_ids
   *   Requested group role IDs.
   *
   * @return array
   *   Stored group role IDs. Non-string values are preserved for later
   *   validation or filtering by the caller.
   */
  protected function storageJurisdictionRoleIds(array $role_ids): array {
    return array_map(
      fn(mixed $role_id): mixed => is_string($role_id)
        ? $this->storageJurisdictionRoleId($role_id)
        : $role_id,
      $role_ids
    );
  }

}
