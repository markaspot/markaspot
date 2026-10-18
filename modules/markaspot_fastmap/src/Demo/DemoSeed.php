<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Demo;

use Drupal\Component\Serialization\Yaml;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningService;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;

/**
 * A validated template demo seed (format documented in demo_seeds/README.md).
 *
 * Parsing is strict on purpose: a seed is curated showcase content in four
 * languages, so a missing translation or an unknown key is an authoring error
 * that must stop the seed instead of surfacing as an English fallback in the
 * demo. Every visible text must be present in every listed language.
 */
final class DemoSeed {

  /**
   * Maximum size of a seed YAML file.
   */
  private const MAX_FILE_BYTES = 1024 * 1024;

  /**
   * Maximum size of one seed photo.
   */
  private const MAX_PHOTO_BYTES = 10 * 1024 * 1024;

  /**
   * Identifier pattern for seed keys (statuses, categories, reports, ...).
   */
  private const KEY_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/D';

  /**
   * Allowed top-level keys.
   */
  private const TOP_LEVEL_KEYS = [
    'version', 'slug', 'template', 'seed_version', 'default_language',
    'languages', 'wording', 'name', 'claim', 'map', 'boundary', 'theme',
    'operator', 'statuses', 'categories', 'organisations', 'start_page',
    'task', 'reports',
  ];

  /**
   * Allowed keys per report.
   */
  private const REPORT_KEYS = [
    'key', 'category', 'lat', 'lng', 'address', 'organisation', 'photo',
    'title', 'description', 'history',
  ];

  /**
   * Address components accepted for operator and report addresses.
   */
  private const ADDRESS_KEYS = [
    'organization', 'address_line1', 'postal_code', 'locality', 'country_code',
  ];

  /**
   * Constructs a validated seed. Use fromFile() or fromArray().
   *
   * @param array $data
   *   The normalized seed document.
   * @param string $baseDir
   *   Absolute directory photo paths are resolved against.
   */
  private function __construct(
    public readonly array $data,
    public readonly string $baseDir,
  ) {}

  /**
   * Parses and validates a seed YAML file.
   */
  public static function fromFile(string $path): self {
    if (is_link($path) || !is_file($path) || !is_readable($path)) {
      throw new \InvalidArgumentException(sprintf('Seed file "%s" is not a readable regular file.', $path));
    }
    if ((int) filesize($path) > self::MAX_FILE_BYTES) {
      throw new \InvalidArgumentException('Seed file exceeds 1 MiB.');
    }
    $decoded = Yaml::decode((string) file_get_contents($path));
    if (!is_array($decoded)) {
      throw new \InvalidArgumentException('Seed file must contain a YAML mapping.');
    }
    return self::fromArray($decoded, (string) realpath(dirname($path)));
  }

  /**
   * Validates a decoded seed document.
   *
   * @param array $raw
   *   The decoded YAML document.
   * @param string $baseDir
   *   Directory relative photo paths are resolved against.
   * @param bool $requirePhotos
   *   Whether referenced photo files must exist. Reset re-applies a stored
   *   seed whose photo directory may no longer be mounted; media created by
   *   the first run are reused then and the files are not needed.
   */
  public static function fromArray(array $raw, string $baseDir, bool $requirePhotos = TRUE): self {
    $unknown = array_diff(array_keys($raw), self::TOP_LEVEL_KEYS);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Unknown seed keys: ' . implode(', ', $unknown));
    }
    if (($raw['version'] ?? NULL) !== 1) {
      throw new \InvalidArgumentException('Seed "version" must be 1.');
    }

    $slug = $raw['slug'] ?? NULL;
    // The demo- prefix keeps a seed from ever addressing a customer slug.
    if (!is_string($slug) || !preg_match('/^demo-[a-z0-9-]{1,25}$/D', $slug)) {
      throw new \InvalidArgumentException('Seed "slug" must match demo-[a-z0-9-]{1,25}.');
    }
    $template = $raw['template'] ?? NULL;
    if (!is_string($template) || !preg_match('/^[a-z0-9-]{1,40}$/D', $template)) {
      throw new \InvalidArgumentException('Seed "template" must be a template slug.');
    }
    $seedVersion = $raw['seed_version'] ?? NULL;
    if (!is_int($seedVersion) || $seedVersion < 1) {
      throw new \InvalidArgumentException('Seed "seed_version" must be a positive integer.');
    }

    $languages = $raw['languages'] ?? NULL;
    if (!is_array($languages) || !array_is_list($languages) || $languages === []) {
      throw new \InvalidArgumentException('Seed "languages" must be a non-empty list.');
    }
    foreach ($languages as $langcode) {
      if (!in_array($langcode, WorkspaceProvisioningService::ALLOWED_LANGS, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Unsupported seed language "%s".', is_scalar($langcode) ? $langcode : gettype($langcode)));
      }
    }
    $languages = array_values(array_unique($languages));
    $default = $raw['default_language'] ?? NULL;
    if (!is_string($default) || !in_array($default, $languages, TRUE)) {
      throw new \InvalidArgumentException('Seed "default_language" must be one of "languages".');
    }

    $wording = $raw['wording'] ?? 'report';
    if (!CitizenWordingResolver::isSupportedPreset($wording)) {
      throw new \InvalidArgumentException('Seed "wording" must be one of: ' . implode(', ', CitizenWordingResolver::PRESETS));
    }

    $data = [
      'slug' => $slug,
      'template' => $template,
      'seed_version' => $seedVersion,
      'default_language' => $default,
      'languages' => $languages,
      'wording' => $wording,
      'name' => self::plain($raw['name'] ?? NULL, 'name', 64),
      'claim' => isset($raw['claim']) ? self::localized($raw['claim'], 'claim', $languages, 255) : [],
      'map' => self::map($raw['map'] ?? NULL),
      'boundary' => self::boundary($raw['boundary'] ?? NULL),
      'theme' => self::theme($raw['theme'] ?? NULL),
      'operator' => isset($raw['operator']) ? self::operator($raw['operator']) : NULL,
      'start_page' => self::startPage($raw['start_page'] ?? NULL, $languages),
      'task' => isset($raw['task']) ? self::localized($raw['task'], 'task', $languages, 1000) : [],
    ];

    $data['statuses'] = self::statuses($raw['statuses'] ?? NULL, $languages);
    $data['categories'] = self::categories($raw['categories'] ?? NULL, $languages);
    $data['organisations'] = self::organisations($raw['organisations'] ?? []);
    $data['reports'] = self::reports(
      $raw['reports'] ?? NULL,
      $languages,
      array_column($data['statuses'], NULL, 'key'),
      array_column($data['categories'], NULL, 'key'),
      array_column($data['organisations'], NULL, 'key'),
      $baseDir,
      $requirePhotos,
    );

    return new self($data, $baseDir);
  }

  /**
   * Returns the raw document needed to rebuild this seed later.
   *
   * @return array{data: array, base_dir: string}
   *   The normalized data and the photo base directory.
   */
  public function toStorage(): array {
    return ['data' => $this->data, 'base_dir' => $this->baseDir];
  }

  /**
   * Rebuilds a seed from toStorage() output without requiring photo files.
   */
  public static function fromStorage(array $stored): self {
    $data = $stored['data'] ?? NULL;
    $baseDir = $stored['base_dir'] ?? '';
    if (!is_array($data) || !is_string($baseDir)) {
      throw new \InvalidArgumentException('Stored demo seed is malformed.');
    }
    return self::fromArray(self::denormalize($data), $baseDir, FALSE);
  }

  /**
   * Converts normalized data back into the authoring format.
   */
  private static function denormalize(array $data): array {
    $raw = [
      'version' => 1,
      'slug' => $data['slug'],
      'template' => $data['template'],
      'seed_version' => $data['seed_version'],
      'default_language' => $data['default_language'],
      'languages' => $data['languages'],
      'wording' => $data['wording'],
      'name' => $data['name'],
      'map' => $data['map'],
      'statuses' => $data['statuses'],
      'categories' => $data['categories'],
      'organisations' => $data['organisations'],
      'start_page' => $data['start_page'],
    ];
    foreach (['claim', 'task'] as $key) {
      if (!empty($data[$key])) {
        $raw[$key] = $data[$key];
      }
    }
    if ($data['boundary'] !== NULL) {
      $raw['boundary'] = $data['boundary'];
    }
    if ($data['theme'] !== NULL) {
      $raw['theme'] = $data['theme'];
    }
    if ($data['operator'] !== NULL) {
      $raw['operator'] = $data['operator'];
    }
    $raw['reports'] = array_map(static function (array $report): array {
      if (isset($report['photo'])) {
        $report['photo'] = ['file' => $report['photo']['file'], 'alt' => $report['photo']['alt']];
      }
      return $report;
    }, $data['reports']);
    return $raw;
  }

  /**
   * Validates a per-language text map that must cover every seed language.
   *
   * @return array<string, string>
   *   Texts keyed by langcode, in seed language order.
   */
  private static function localized(mixed $value, string $label, array $languages, int $maxLength, bool $plainText = TRUE): array {
    if (!is_array($value)) {
      throw new \InvalidArgumentException(sprintf('"%s" must map every seed language to a text.', $label));
    }
    $unknown = array_diff(array_keys($value), $languages);
    if ($unknown !== []) {
      throw new \InvalidArgumentException(sprintf('"%s" has texts for languages outside the seed: %s', $label, implode(', ', $unknown)));
    }
    $result = [];
    foreach ($languages as $langcode) {
      $text = $value[$langcode] ?? NULL;
      if (!is_string($text) || trim($text) === '') {
        throw new \InvalidArgumentException(sprintf('"%s" is missing the "%s" text.', $label, $langcode));
      }
      $text = trim($text);
      if (mb_strlen($text) > $maxLength) {
        throw new \InvalidArgumentException(sprintf('"%s" (%s) exceeds %d characters.', $label, $langcode, $maxLength));
      }
      if ($plainText && strip_tags($text) !== $text) {
        throw new \InvalidArgumentException(sprintf('"%s" (%s) must be plain text.', $label, $langcode));
      }
      $result[$langcode] = $text;
    }
    return $result;
  }

  /**
   * Validates a single plain text value.
   */
  private static function plain(mixed $value, string $label, int $maxLength): string {
    if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength || strip_tags($value) !== $value) {
      throw new \InvalidArgumentException(sprintf('"%s" must be plain text of at most %d characters.', $label, $maxLength));
    }
    return trim($value);
  }

  /**
   * Validates a latitude/longitude pair.
   *
   * @return array{lat: float, lng: float}
   *   The coordinate.
   */
  private static function coordinate(mixed $lat, mixed $lng, string $label): array {
    if (!is_numeric($lat) || !is_numeric($lng)
      || (float) $lat < -90 || (float) $lat > 90
      || (float) $lng < -180 || (float) $lng > 180) {
      throw new \InvalidArgumentException(sprintf('%s needs a valid lat/lng.', $label));
    }
    return ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7)];
  }

  /**
   * Validates the map block.
   */
  private static function map(mixed $map): array {
    if (!is_array($map) || array_diff(array_keys($map), ['lat', 'lng', 'zoom']) !== []) {
      throw new \InvalidArgumentException('"map" must contain lat, lng and optional zoom.');
    }
    $zoom = $map['zoom'] ?? 15;
    if (!is_int($zoom) || $zoom < 1 || $zoom > 20) {
      throw new \InvalidArgumentException('"map.zoom" must be an integer between 1 and 20.');
    }
    return self::coordinate($map['lat'] ?? NULL, $map['lng'] ?? NULL, '"map"') + ['zoom' => $zoom];
  }

  /**
   * Validates the optional boundary block.
   *
   * @return array|null
   *   Either ['radius_km' => float] or ['geometry' => GeoJSON], NULL for the
   *   default 1 km circle.
   */
  private static function boundary(mixed $boundary): ?array {
    if ($boundary === NULL) {
      return NULL;
    }
    if (is_array($boundary) && array_keys($boundary) === ['radius_km']
      && is_numeric($boundary['radius_km'])
      && (float) $boundary['radius_km'] >= 0.1 && (float) $boundary['radius_km'] <= 20) {
      return ['radius_km' => (float) $boundary['radius_km']];
    }
    if (is_array($boundary) && array_keys($boundary) === ['geometry']
      && is_array($boundary['geometry'])
      && in_array($boundary['geometry']['type'] ?? NULL, ['Polygon', 'MultiPolygon'], TRUE)
      && is_array($boundary['geometry']['coordinates'] ?? NULL)) {
      return ['geometry' => $boundary['geometry']];
    }
    throw new \InvalidArgumentException('"boundary" must be {radius_km: 0.1..20} or {geometry: <GeoJSON Polygon|MultiPolygon>}.');
  }

  /**
   * Validates the optional theme override.
   */
  private static function theme(mixed $theme): ?array {
    if ($theme === NULL) {
      return NULL;
    }
    if (!is_array($theme) || array_diff(array_keys($theme), ['primary', 'secondary', 'neutral']) !== []) {
      throw new \InvalidArgumentException('"theme" accepts primary, secondary and neutral only.');
    }
    foreach ($theme as $key => $color) {
      if (!is_string($color) || !preg_match('/^([a-z]{3,20}|#[0-9a-fA-F]{6})$/D', $color)) {
        throw new \InvalidArgumentException(sprintf('"theme.%s" must be a Tailwind color name or #RRGGBB.', $key));
      }
    }
    return $theme;
  }

  /**
   * Validates an address block.
   */
  private static function address(mixed $address, string $label, bool $withOrganization): array {
    $allowed = $withOrganization ? self::ADDRESS_KEYS : array_diff(self::ADDRESS_KEYS, ['organization']);
    if (!is_array($address) || array_diff(array_keys($address), $allowed) !== []) {
      throw new \InvalidArgumentException(sprintf('%s accepts only: %s', $label, implode(', ', $allowed)));
    }
    $result = [];
    foreach ($address as $key => $value) {
      if (!is_scalar($value) || trim((string) $value) === '' || mb_strlen((string) $value) > 255 || strip_tags((string) $value) !== (string) $value) {
        throw new \InvalidArgumentException(sprintf('%s.%s must be short plain text.', $label, $key));
      }
      $result[$key] = trim((string) $value);
    }
    if (isset($result['country_code']) && !preg_match('/^[A-Z]{2}$/D', $result['country_code'])) {
      throw new \InvalidArgumentException(sprintf('%s.country_code must be an ISO 3166-1 alpha-2 code.', $label));
    }
    return $result;
  }

  /**
   * Validates the operator block (map operator for the legal pages).
   */
  private static function operator(mixed $operator): array {
    if (!is_array($operator) || !isset($operator['email'], $operator['address'])
      || array_diff(array_keys($operator), ['email', 'address']) !== []) {
      throw new \InvalidArgumentException('"operator" needs email and address.');
    }
    if (!is_string($operator['email']) || filter_var($operator['email'], FILTER_VALIDATE_EMAIL) === FALSE) {
      throw new \InvalidArgumentException('"operator.email" must be an e-mail address.');
    }
    return [
      'email' => $operator['email'],
      'address' => self::address($operator['address'], '"operator.address"', TRUE),
    ];
  }

  /**
   * Validates the start page block.
   */
  private static function startPage(mixed $page, array $languages): array {
    if (!is_array($page) || array_diff(array_keys($page), ['title', 'body']) !== []) {
      throw new \InvalidArgumentException('"start_page" needs title and body.');
    }
    return [
      'title' => self::localized($page['title'] ?? NULL, 'start_page.title', $languages, 255),
      // Rendered with basic_html like the provisioned start page.
      'body' => self::localized($page['body'] ?? NULL, 'start_page.body', $languages, 4000, FALSE),
    ];
  }

  /**
   * Validates a list of keyed items and returns them in order.
   */
  private static function keyedList(mixed $items, string $label, int $min, int $max): array {
    if (!is_array($items) || !array_is_list($items) || count($items) < $min || count($items) > $max) {
      throw new \InvalidArgumentException(sprintf('"%s" must be a list of %d to %d items.', $label, $min, $max));
    }
    $seen = [];
    foreach ($items as $item) {
      $key = is_array($item) ? ($item['key'] ?? NULL) : NULL;
      if (!is_string($key) || !preg_match(self::KEY_PATTERN, $key) || isset($seen[$key])) {
        throw new \InvalidArgumentException(sprintf('Every "%s" item needs a unique key matching %s.', $label, self::KEY_PATTERN));
      }
      $seen[$key] = TRUE;
    }
    return $items;
  }

  /**
   * Validates status definitions.
   */
  private static function statuses(mixed $statuses, array $languages): array {
    $result = [];
    foreach (self::keyedList($statuses, 'statuses', 3, 12) as $status) {
      if (array_diff(array_keys($status), ['key', 'mapping', 'name', 'hex', 'icon']) !== []) {
        throw new \InvalidArgumentException(sprintf('Status "%s" has unknown keys.', $status['key']));
      }
      if (!in_array($status['mapping'] ?? NULL, ['initial', 'open', 'closed'], TRUE)) {
        throw new \InvalidArgumentException(sprintf('Status "%s" needs mapping initial, open or closed.', $status['key']));
      }
      $result[] = [
        'key' => $status['key'],
        'mapping' => $status['mapping'],
        'name' => self::localized($status['name'] ?? NULL, 'statuses.' . $status['key'] . '.name', $languages, 255),
        'hex' => self::hex($status['hex'] ?? NULL, 'statuses.' . $status['key']) ?? '#808080',
        'icon' => self::icon($status['icon'] ?? NULL, 'statuses.' . $status['key']) ?? 'i-lucide-circle',
      ];
    }
    $mappings = array_unique(array_column($result, 'mapping'));
    if (count($mappings) !== 3) {
      throw new \InvalidArgumentException('Statuses must cover the mappings initial, open and closed.');
    }
    return $result;
  }

  /**
   * Validates category definitions.
   */
  private static function categories(mixed $categories, array $languages): array {
    $result = [];
    foreach (self::keyedList($categories, 'categories', 1, 30) as $category) {
      if (array_diff(array_keys($category), ['key', 'name', 'hex', 'icon']) !== []) {
        throw new \InvalidArgumentException(sprintf('Category "%s" has unknown keys.', $category['key']));
      }
      $result[] = [
        'key' => $category['key'],
        'name' => self::localized($category['name'] ?? NULL, 'categories.' . $category['key'] . '.name', $languages, 255),
        'hex' => self::hex($category['hex'] ?? NULL, 'categories.' . $category['key']),
        'icon' => self::icon($category['icon'] ?? NULL, 'categories.' . $category['key']),
      ];
    }
    return $result;
  }

  /**
   * Validates organisation definitions (assignment targets).
   */
  private static function organisations(mixed $organisations): array {
    if ($organisations === []) {
      return [];
    }
    $result = [];
    foreach (self::keyedList($organisations, 'organisations', 1, 10) as $organisation) {
      if (array_diff(array_keys($organisation), ['key', 'name']) !== []) {
        throw new \InvalidArgumentException(sprintf('Organisation "%s" has unknown keys.', $organisation['key']));
      }
      $result[] = [
        'key' => $organisation['key'],
        'name' => self::plain($organisation['name'] ?? NULL, 'organisations.' . $organisation['key'] . '.name', 255),
      ];
    }
    return $result;
  }

  /**
   * Validates report definitions.
   */
  private static function reports(mixed $reports, array $languages, array $statuses, array $categories, array $organisations, string $baseDir, bool $requirePhotos): array {
    $result = [];
    foreach (self::keyedList($reports, 'reports', 1, 20) as $report) {
      $key = $report['key'];
      $label = 'reports.' . $key;
      $unknown = array_diff(array_keys($report), self::REPORT_KEYS);
      if ($unknown !== []) {
        throw new \InvalidArgumentException(sprintf('Report "%s" has unknown keys: %s', $key, implode(', ', $unknown)));
      }
      if (!isset($categories[$report['category'] ?? ''])) {
        throw new \InvalidArgumentException(sprintf('Report "%s" references an unknown category.', $key));
      }
      if (isset($report['organisation']) && !isset($organisations[$report['organisation']])) {
        throw new \InvalidArgumentException(sprintf('Report "%s" references an unknown organisation.', $key));
      }

      $history = $report['history'] ?? NULL;
      if (!is_array($history) || !array_is_list($history) || $history === [] || count($history) > 10) {
        throw new \InvalidArgumentException(sprintf('Report "%s" needs a history of 1 to 10 entries.', $key));
      }
      $entries = [];
      $previousDaysAgo = PHP_INT_MAX;
      foreach ($history as $index => $entry) {
        if (!is_array($entry) || array_diff(array_keys($entry), ['status', 'days_ago', 'note']) !== []) {
          throw new \InvalidArgumentException(sprintf('Report "%s" history entry %d has unknown keys.', $key, $index));
        }
        if (!isset($statuses[$entry['status'] ?? ''])) {
          throw new \InvalidArgumentException(sprintf('Report "%s" history entry %d references an unknown status.', $key, $index));
        }
        $daysAgo = $entry['days_ago'] ?? NULL;
        if (!is_int($daysAgo) || $daysAgo < 0 || $daysAgo > 365 || $daysAgo > $previousDaysAgo) {
          throw new \InvalidArgumentException(sprintf('Report "%s" history must be chronological with days_ago 0..365.', $key));
        }
        $previousDaysAgo = $daysAgo;
        $entries[] = [
          'status' => $entry['status'],
          'days_ago' => $daysAgo,
          'note' => self::localized($entry['note'] ?? NULL, $label . '.history.' . $index . '.note', $languages, 2000),
        ];
      }

      $normalized = [
        'key' => $key,
        'category' => $report['category'],
        'title' => self::localized($report['title'] ?? NULL, $label . '.title', $languages, 255),
        'description' => self::localized($report['description'] ?? NULL, $label . '.description', $languages, 2000),
        'history' => $entries,
      ] + self::coordinate($report['lat'] ?? NULL, $report['lng'] ?? NULL, sprintf('Report "%s"', $key));
      if (isset($report['address'])) {
        $normalized['address'] = self::address($report['address'], '"' . $label . '.address"', FALSE);
      }
      if (isset($report['organisation'])) {
        $normalized['organisation'] = $report['organisation'];
      }
      if (isset($report['photo'])) {
        $normalized['photo'] = self::photo($report['photo'], $label, $languages, $baseDir, $requirePhotos);
      }
      $result[] = $normalized;
    }
    return $result;
  }

  /**
   * Validates a report photo reference.
   */
  private static function photo(mixed $photo, string $label, array $languages, string $baseDir, bool $requireFile): array {
    if (!is_array($photo) || array_diff(array_keys($photo), ['file', 'alt']) !== []
      || !is_string($photo['file'] ?? NULL)) {
      throw new \InvalidArgumentException(sprintf('"%s.photo" needs file and alt.', $label));
    }
    $file = $photo['file'];
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,200}$/D', $file) || str_contains($file, '..')) {
      throw new \InvalidArgumentException(sprintf('"%s.photo.file" must be a plain path relative to the seed file.', $label));
    }
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png'], TRUE)) {
      throw new \InvalidArgumentException(sprintf('"%s.photo.file" must be a JPEG or PNG.', $label));
    }
    $path = rtrim($baseDir, '/') . '/' . $file;
    if ($requireFile) {
      $real = realpath($path);
      if ($real === FALSE || is_link($path) || !is_file($real)
        || !str_starts_with($real, rtrim((string) realpath($baseDir), '/') . '/')
        || (int) filesize($real) > self::MAX_PHOTO_BYTES
        || getimagesize($real) === FALSE) {
        throw new \InvalidArgumentException(sprintf('"%s.photo.file" is missing, too large, not an image or outside the seed directory.', $label));
      }
    }
    return [
      'file' => $file,
      'path' => $path,
      'alt' => self::localized($photo['alt'] ?? NULL, $label . '.photo.alt', $languages, 512),
    ];
  }

  /**
   * Validates an optional hex color.
   */
  private static function hex(mixed $value, string $label): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $value)) {
      throw new \InvalidArgumentException(sprintf('"%s.hex" must be #RRGGBB.', $label));
    }
    return $value;
  }

  /**
   * Validates an optional Lucide icon name.
   */
  private static function icon(mixed $value, string $label): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value) || !preg_match('/^i-lucide-[a-z0-9-]{1,64}$/D', $value)) {
      throw new \InvalidArgumentException(sprintf('"%s.icon" must be an i-lucide-* name.', $label));
    }
    return $value;
  }

}
