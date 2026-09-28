<?php

declare(strict_types=1);

namespace Drupal\markaspot\Geolocation;

use Drupal\group\Entity\GroupMembership;

/**
 * Resolves the map center of the current tenant for backend location widgets.
 *
 * Used when a location field has no default value and the widget has no
 * center of its own. Reads markaspot_nuxt and jurisdiction group config
 * defensively, so the widget modules do not depend on either.
 */
final class TenantMapCenter {

  /**
   * Returns [lat, lng], or NULL when the tenant defines no center.
   *
   * Order: the instance center in markaspot_nuxt.settings, then the map
   * center of the user's only jurisdiction, then of the site's only one.
   */
  public static function resolve(): ?array {
    $settings = \Drupal::config('markaspot_nuxt.settings');
    $center = self::point($settings->get('center_lat'), $settings->get('center_lng'));
    if ($center !== NULL) {
      return $center;
    }
    return self::jurisdictionCenter();
  }

  /**
   * Returns the map center of a single applicable jurisdiction group.
   */
  private static function jurisdictionCenter(): ?array {
    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->hasDefinition('group')) {
      return NULL;
    }
    $storage = $entity_type_manager->getStorage('group');
    $type = \Drupal::config('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur';

    $ids = [];
    if (class_exists(GroupMembership::class)) {
      foreach (GroupMembership::loadByUser(\Drupal::currentUser()) as $membership) {
        $group = $membership->getGroup();
        if ($group->bundle() === $type) {
          $ids[] = $group->id();
        }
      }
    }
    if (count($ids) !== 1) {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $type)->range(0, 2)->execute();
    }
    if (count($ids) !== 1) {
      return NULL;
    }

    $group = $storage->load(reset($ids));
    if (!$group || !$group->hasField('field_nuxt_config')) {
      return NULL;
    }
    $config = json_decode((string) $group->get('field_nuxt_config')->value, TRUE);
    // Stored as [lng, lat], like MapLibre.
    $lng_lat = is_array($config) ? ($config['map']['center'] ?? NULL) : NULL;
    return is_array($lng_lat) ? self::point($lng_lat[1] ?? NULL, $lng_lat[0] ?? NULL) : NULL;
  }

  /**
   * Returns a valid [lat, lng] pair, or NULL for missing or 0/0 values.
   */
  private static function point(mixed $lat, mixed $lng): ?array {
    if (!is_numeric($lat) || !is_numeric($lng)) {
      return NULL;
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    if (($lat === 0.0 && $lng === 0.0) || abs($lat) > 90 || abs($lng) > 180) {
      return NULL;
    }
    return [$lat, $lng];
  }

}
