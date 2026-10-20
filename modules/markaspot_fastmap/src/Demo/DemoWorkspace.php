<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Demo;

use Drupal\group\Entity\GroupInterface;

/**
 * Contract helpers for template demo workspaces.
 *
 * A template demo is a permanent jur group whose field_nuxt_config carries a
 * "demo" block. This is unrelated to the self-service "demo state" (a trial
 * workspace with field_expiry_date and no Stripe subscription), which
 * markaspot_fastmap_node_presave() gates. Template demos never carry an
 * expiry date.
 *
 * Seeded entities are recognised by their UUID, never by a content marker:
 * every seeded entity gets a UUID derived from the site UUID, the demo slug
 * and its seed key. Nothing is written into visible fields, and a visitor
 * cannot create an entity with one of these UUIDs because the seeded entity
 * already holds it.
 */
final class DemoWorkspace {

  /**
   * Key of the demo block in field_nuxt_config.
   */
  public const CONFIG_KEY = 'demo';

  /**
   * Slug prefix reserved for template demos; customer signups may not use it.
   */
  public const SLUG_PREFIX = 'demo-';

  /**
   * Valid template demo slug: the reserved prefix plus 1 to 25 characters.
   */
  public const SLUG_PATTERN = '/^demo-[a-z0-9-]{1,25}$/D';

  /**
   * Upper bounds for the public demo block.
   *
   * A hand-edited field_nuxt_config must not turn the anonymous settings
   * endpoint into a bulk text store.
   */
  private const MAX_TRANSLATED_REPORTS = 50;
  private const MAX_STATUS_NOTES = 20;
  private const MAX_TEXT_LENGTH = 4000;

  /**
   * Derives the deterministic UUID of a seeded entity.
   *
   * @param string $siteUuid
   *   The system.site UUID, so two installations never share seed UUIDs.
   * @param string $slug
   *   The demo workspace slug.
   * @param string $kind
   *   The entity kind ("group", "report", "status", ...).
   * @param string $key
   *   The seed key within that kind.
   */
  public static function uuid(string $siteUuid, string $slug, string $kind, string $key): string {
    $hex = hash('sha256', 'markaspot_fastmap.demo:' . $siteUuid . ':' . $slug . ':' . $kind . ':' . $key);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
      . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
  }

  /**
   * Decodes a group's field_nuxt_config.
   *
   * @return array
   *   The decoded config, empty when absent or malformed.
   */
  public static function nuxtConfig(GroupInterface $group): array {
    if (!$group->hasField('field_nuxt_config') || $group->get('field_nuxt_config')->isEmpty()) {
      return [];
    }
    $decoded = json_decode((string) $group->get('field_nuxt_config')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Returns the raw demo block of a group, or NULL for regular workspaces.
   */
  public static function block(GroupInterface $group): ?array {
    $block = self::nuxtConfig($group)[self::CONFIG_KEY] ?? NULL;
    return is_array($block) && is_string($block['template'] ?? NULL) ? $block : NULL;
  }

  /**
   * Whether a group is flagged as a template demo workspace.
   */
  public static function isDemo(GroupInterface $group): bool {
    return self::block($group) !== NULL;
  }

  /**
   * Returns the public demo block of an allowlisted demo workspace.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The workspace.
   * @param array $allowlist
   *   Slugs from markaspot_fastmap.settings:demo_workspaces.
   *
   * @return array|null
   *   The sanitized block, NULL unless the group carries the demo block AND
   *   its slug is allowlisted.
   */
  public static function deliverable(GroupInterface $group, array $allowlist): ?array {
    $slug = $group->hasField('field_slug') ? $group->get('field_slug')->value : NULL;
    if (!is_string($slug) || !in_array($slug, $allowlist, TRUE)) {
      return NULL;
    }
    return self::sanitizeForDelivery(self::block($group));
  }

  /**
   * Reduces a stored demo block to the public contract shape.
   *
   * Shape: {template, seedVersion, translations: {<service_request_id>:
   * {<lang>: {title, description, statusNotes: [<text>]}}}, task: {<lang>:
   * <text>}, showcase_request_id: <service_request_id>|null}. The showcase
   * request is the first seeded report, the deep link target of the
   * solution pages. Every leaf is plain text: tags are stripped, so the block can
   * never carry markup even if field_nuxt_config was edited by hand; anything
   * that is not a string is dropped.
   *
   * @return array|null
   *   The sanitized block, NULL when the input is not a demo block.
   */
  public static function sanitizeForDelivery(mixed $block): ?array {
    if (!is_array($block) || !is_string($block['template'] ?? NULL)
      || !preg_match('/^[a-z0-9-]{1,40}$/D', $block['template'])) {
      return NULL;
    }
    $result = [
      'template' => $block['template'],
      'seedVersion' => is_int($block['seedVersion'] ?? NULL) ? $block['seedVersion'] : 0,
      'translations' => [],
      'task' => [],
      'showcase_request_id' => self::isRequestId($block['showcase_request_id'] ?? NULL) ? $block['showcase_request_id'] : NULL,
    ];

    $translations = is_array($block['translations'] ?? NULL) ? $block['translations'] : [];
    foreach (array_slice($translations, 0, self::MAX_TRANSLATED_REPORTS, TRUE) as $requestId => $languages) {
      if (!is_string($requestId) && !is_int($requestId)) {
        continue;
      }
      $requestId = (string) $requestId;
      if (!self::isRequestId($requestId) || !is_array($languages)) {
        continue;
      }
      foreach ($languages as $langcode => $texts) {
        if (!is_string($langcode) || !self::isLangcode($langcode) || !is_array($texts)) {
          continue;
        }
        $entry = [];
        foreach (['title', 'description'] as $key) {
          if (self::isText($texts[$key] ?? NULL)) {
            $entry[$key] = self::plain($texts[$key]);
          }
        }
        $notes = is_array($texts['statusNotes'] ?? NULL) ? array_values($texts['statusNotes']) : [];
        $entry['statusNotes'] = array_map(
          static fn(mixed $note): string => self::isText($note) ? self::plain($note) : '',
          array_slice($notes, 0, self::MAX_STATUS_NOTES),
        );
        $result['translations'][$requestId][$langcode] = $entry;
      }
    }

    $task = is_array($block['task'] ?? NULL) ? $block['task'] : [];
    foreach ($task as $langcode => $text) {
      if (is_string($langcode) && self::isLangcode($langcode) && self::isText($text)) {
        $result['task'][$langcode] = self::plain($text);
      }
    }

    // Empty maps must stay JSON objects for the frontend contract.
    if ($result['translations'] === []) {
      $result['translations'] = new \stdClass();
    }
    if ($result['task'] === []) {
      $result['task'] = new \stdClass();
    }
    return $result;
  }

  /**
   * Reduces a string to plain text.
   */
  private static function plain(string $value): string {
    return trim(strip_tags($value));
  }

  /**
   * Whether a value is a bounded string.
   */
  private static function isText(mixed $value): bool {
    return is_string($value) && mb_strlen($value) <= self::MAX_TEXT_LENGTH;
  }

  /**
   * Whether a value looks like an Open311 service_request_id.
   */
  private static function isRequestId(mixed $value): bool {
    return is_string($value) && (bool) preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $value);
  }

  /**
   * Whether a value looks like a Drupal langcode.
   */
  private static function isLangcode(string $value): bool {
    return (bool) preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/D', $value);
  }

}
