#!/usr/bin/env drush
<?php

/**
 * @file
 * Seed facilities into the canonical field_facilities payload from a CSV.
 *
 * Generic tenant tooling: reads facility rows from an operator-supplied CSV
 * and writes them into field_facilities on the target jurisdiction group via
 * FacilityManager. Tenant-specific CSV fixtures live outside the profile.
 *
 * Usage:
 *   ddev drush php:script web/profiles/contrib/markaspot/scripts/seed-facilities-from-csv.php -- <jurisdiction> --csv=/absolute/path/to/facilities.csv [--replace] [--dry-run]
 *
 * CSV columns (required): id, label, lat, lng, address, active
 *
 * Modes:
 * - merge (default): upsert items by id into the existing payload.
 * - --replace: drop existing items and write the CSV content verbatim.
 *
 * Requires markaspot_facility to be enabled; aborts otherwise.
 */

use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_facility\Service\FacilityManager;

if (!function_exists('drush_print')) {

  /**
   * Drush compatibility shim: prints a line when drush_print is unavailable.
   */
  function drush_print($message = '') {
    echo $message . PHP_EOL;
  }

}

$args = $extra ?? [];
$jurisdiction_selector = NULL;
$csv_path = NULL;
$replace = FALSE;
$dry_run = FALSE;

foreach ($args as $arg) {
  if ($arg === '--replace') {
    $replace = TRUE;
    continue;
  }

  if ($arg === '--dry-run') {
    $dry_run = TRUE;
    continue;
  }

  if (str_starts_with($arg, '--csv=')) {
    $csv_path = substr($arg, strlen('--csv='));
    continue;
  }

  if (!str_starts_with($arg, '--') && $jurisdiction_selector === NULL) {
    $jurisdiction_selector = $arg;
  }
}

if ($jurisdiction_selector === NULL || $csv_path === NULL || trim($csv_path) === '') {
  drush_print('ERROR: Missing required arguments.');
  drush_print('Usage: drush php:script seed-facilities-from-csv.php -- <jurisdiction> --csv=/absolute/path/to/facilities.csv [--replace] [--dry-run]');
  return;
}

$seed_items = load_seed_items_from_csv($csv_path);
if ($seed_items === []) {
  drush_print('ERROR: No facility rows loaded from CSV: ' . $csv_path);
  return;
}

drush_print('=== Seed Facilities From CSV ===');
drush_print('Jurisdiction selector: ' . $jurisdiction_selector);
drush_print('Mode: ' . ($replace ? 'replace' : 'merge'));
drush_print('Dry run: ' . ($dry_run ? 'yes' : 'no'));
drush_print('Seed CSV: ' . $csv_path);
drush_print('');

$group = load_jurisdiction_group($jurisdiction_selector);
if (!$group) {
  drush_print('ERROR: Could not find a jurisdiction for selector "' . $jurisdiction_selector . '".');
  return;
}

$facility_manager = \Drupal::hasService('markaspot_facility.manager')
  ? \Drupal::service('markaspot_facility.manager')
  : NULL;

if (!$facility_manager instanceof FacilityManager || !$group->hasField('field_facilities')) {
  drush_print('ERROR: markaspot_facility is not installed. Enable it first:');
  drush_print('  ddev drush pm:install markaspot_facility -y');
  return;
}

$existing = $facility_manager->getDashboardSettings($group);
$existing_source = 'field_facilities';

if (($existing['items'] ?? []) === [] && $group->hasField('field_nuxt_config')) {
  $nuxt_config = load_nuxt_config($group);
  if ($nuxt_config === NULL) {
    drush_print('ERROR: field_nuxt_config contains invalid JSON. Aborting without changes.');
    return;
  }

  if (!empty($nuxt_config['facilities']) && is_array($nuxt_config['facilities'])) {
    $existing = $nuxt_config['facilities'];
    $existing_source = 'field_nuxt_config (legacy fallback, will migrate on save)';
  }
}

$existing_items = [];
foreach ($existing['items'] ?? [] as $item) {
  if (!empty($item['id'])) {
    $existing_items[$item['id']] = $item;
  }
}

$merged_items = $replace ? [] : $existing_items;
$created = [];
$updated = [];

foreach ($seed_items as $item) {
  $id = $item['id'];
  if (isset($merged_items[$id])) {
    $updated[] = $id;
  }
  else {
    $created[] = $id;
  }
  $merged_items[$id] = $item;
}

$payload = [
  'enabled' => TRUE,
  'mode' => 'facility',
  'hideMapPicker' => TRUE,
  'label' => [
    'singular' => 'Einrichtung',
    'plural' => 'Einrichtungen',
  ],
  'items' => array_values($merged_items),
];

try {
  $payload = $facility_manager->normalizeSubmittedSettings($payload);
}
catch (\InvalidArgumentException $e) {
  drush_print('ERROR: ' . $e->getMessage());
  return;
}

drush_print('Target jurisdiction: #' . $group->id() . ' ' . $group->label());
drush_print('Existing source: ' . $existing_source);
drush_print('Existing facilities: ' . count($existing_items));
drush_print('Created from seed: ' . count($created));
drush_print('Updated from seed: ' . count($updated));
drush_print('Final facilities count: ' . count($payload['items']));

if ($created !== []) {
  drush_print('Created IDs: ' . implode(', ', $created));
}
if ($updated !== []) {
  drush_print('Updated IDs: ' . implode(', ', $updated));
}

if ($dry_run) {
  drush_print('');
  drush_print('Dry run payload:');
  drush_print(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  return;
}

$facility_manager->saveDashboardSettings($group, $payload);

drush_print('');
drush_print('Saved facilities into field_facilities successfully.');
if ($existing_source !== 'field_facilities') {
  drush_print('Legacy facilities were migrated from field_nuxt_config into the canonical facility field.');
  if (strip_legacy_facilities_key($group)) {
    drush_print('Legacy facilities key removed from field_nuxt_config. Canonical field is now the single source of truth.');
  }
}

drush_print('Facility payload is live. Open the tenant URL with ?report=true&type=classic[&facility=<id>] to verify.');

/**
 * Loads a jurisdiction group by numeric ID, slug, or exact label.
 */
function load_jurisdiction_group(string $selector): ?GroupInterface {
  $storage = \Drupal::entityTypeManager()->getStorage('group');

  if (ctype_digit($selector)) {
    $group = $storage->load((int) $selector);
    return $group instanceof GroupInterface && $group->bundle() === 'jur'
      ? $group
      : NULL;
  }

  $query = \Drupal::entityQuery('group')
    ->accessCheck(FALSE)
    ->condition('type', 'jur');

  $candidate_ids = [];
  $group_definition = \Drupal::service('entity_field.manager')->getFieldDefinitions('group', 'jur');
  if (isset($group_definition['field_slug'])) {
    $candidate_ids = $query
      ->condition('field_slug', $selector)
      ->execute();
  }

  if ($candidate_ids === []) {
    $candidate_ids = \Drupal::entityQuery('group')
      ->accessCheck(FALSE)
      ->condition('type', 'jur')
      ->condition('label', $selector)
      ->execute();
  }

  if ($candidate_ids === []) {
    $all_ids = \Drupal::entityQuery('group')
      ->accessCheck(FALSE)
      ->condition('type', 'jur')
      ->execute();
    foreach ($storage->loadMultiple($all_ids) as $group) {
      if (strtolower($group->label()) === strtolower($selector)) {
        return $group;
      }
    }
    return NULL;
  }

  $group = $storage->load((int) reset($candidate_ids));
  return $group instanceof GroupInterface ? $group : NULL;
}

/**
 * Loads and decodes field_nuxt_config.
 */
function load_nuxt_config(GroupInterface $group): ?array {
  if (!$group->hasField('field_nuxt_config')) {
    return [];
  }

  $raw_nuxt_config = (string) $group->get('field_nuxt_config')->value;
  if (trim($raw_nuxt_config) === '') {
    return [];
  }

  $nuxt_config = json_decode($raw_nuxt_config, TRUE);
  return is_array($nuxt_config) ? $nuxt_config : NULL;
}

/**
 * Strips the legacy facilities key from field_nuxt_config.
 *
 * Called after a successful migration from field_nuxt_config into the
 * canonical field_facilities storage, so we do not leave a stale payload
 * behind that could diverge from the canonical source.
 */
function strip_legacy_facilities_key(GroupInterface $group): bool {
  if (!$group->hasField('field_nuxt_config')) {
    return FALSE;
  }

  $nuxt_config = load_nuxt_config($group);
  if (!is_array($nuxt_config) || !array_key_exists('facilities', $nuxt_config)) {
    return FALSE;
  }

  unset($nuxt_config['facilities']);
  $group->set('field_nuxt_config', json_encode(
    $nuxt_config,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
  ));
  $group->save();

  return TRUE;
}

/**
 * Loads facility seed rows from a CSV fixture.
 *
 * Required columns: id, label, lat, lng, address, active.
 */
function load_seed_items_from_csv(string $csv_path): array {
  if (!is_file($csv_path) || !is_readable($csv_path)) {
    drush_print('ERROR: CSV fixture is missing or unreadable: ' . $csv_path);
    return [];
  }

  $handle = fopen($csv_path, 'r');
  if ($handle === FALSE) {
    drush_print('ERROR: Could not open CSV fixture: ' . $csv_path);
    return [];
  }

  $header = fgetcsv($handle);
  if (!is_array($header)) {
    fclose($handle);
    drush_print('ERROR: CSV fixture does not contain a valid header row.');
    return [];
  }

  $header = array_map(static fn($value) => trim((string) $value), $header);
  $required_columns = ['id', 'label', 'lat', 'lng', 'address', 'active'];
  foreach ($required_columns as $column) {
    if (!in_array($column, $header, TRUE)) {
      fclose($handle);
      drush_print('ERROR: CSV fixture is missing required column "' . $column . '".');
      return [];
    }
  }

  $rows = [];
  $line_number = 1;
  while (($data = fgetcsv($handle)) !== FALSE) {
    $line_number++;

    if ($data === [NULL] || $data === FALSE) {
      continue;
    }

    $row = array_combine($header, array_pad($data, count($header), NULL));
    if (!is_array($row)) {
      fclose($handle);
      drush_print('ERROR: Could not parse CSV row at line ' . $line_number . '.');
      return [];
    }

    $id = trim((string) ($row['id'] ?? ''));
    if ($id === '') {
      continue;
    }

    $rows[] = [
      'id' => $id,
      'label' => trim((string) ($row['label'] ?? '')),
      'lat' => (float) ($row['lat'] ?? 0),
      'lng' => (float) ($row['lng'] ?? 0),
      'address' => trim((string) ($row['address'] ?? '')),
      'active' => parse_csv_bool($row['active'] ?? '1'),
    ];
  }

  fclose($handle);
  return $rows;
}

/**
 * Parses a CSV boolean value.
 */
function parse_csv_bool(mixed $value): bool {
  $normalized = strtolower(trim((string) $value));
  return !in_array($normalized, ['', '0', 'false', 'no', 'off'], TRUE);
}
