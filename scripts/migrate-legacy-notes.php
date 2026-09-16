<?php

/**
 * @file
 * Migrate field_notes to internal remarks, in explicit offline batches.
 *
 * Run: drush php:script scripts/migrate-legacy-notes.php -- --jurisdiction=123
 * Add --apply --write-freeze-confirmed only during an exclusive write freeze.
 * Options: --after=N --limit=100 --ids=1,2 --label="Imported legacy note..."
 * Default: read-only; no config, access grants or existing content is changed.
 * See scripts/migrate-legacy-notes.md before applying.
 */

use Drupal\markaspot_dashboard\Migration\LegacyNotesMigration;

if (PHP_SAPI !== 'cli') {
  throw new RuntimeException('CLI only.');
}

$options = [
  'jurisdiction' => NULL,
  'after' => '0',
  'limit' => '100',
  'ids' => '',
  'label' => 'Imported legacy note; author and original date unknown.',
];
$apply = FALSE;
$frozen = FALSE;
$seen = [];
foreach ($extra ?? [] as $argument) {
  if ($argument === '--apply') {
    $apply = TRUE;
    continue;
  }
  if ($argument === '--write-freeze-confirmed') {
    $frozen = TRUE;
    continue;
  }
  if (!preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $match)
    || !array_key_exists($match[1], $options) || isset($seen[$match[1]])) {
    throw new InvalidArgumentException('Unknown or duplicate argument.');
  }
  $seen[$match[1]] = TRUE;
  $options[$match[1]] = $match[2];
}
foreach (['jurisdiction', 'after', 'limit'] as $name) {
  if (!is_string($options[$name]) || !preg_match('/^\d{1,9}$/', $options[$name])) {
    throw new InvalidArgumentException('Expected an integer option: ' . $name);
  }
}
if ((int) $options['jurisdiction'] < 1 || (int) $options['limit'] < 1 || (int) $options['limit'] > 1000) {
  throw new InvalidArgumentException('Select a jurisdiction and limit between 1 and 1000.');
}
if ($options['ids'] !== '' && !preg_match('/^[1-9]\d{0,8}(,[1-9]\d{0,8})*$/', $options['ids'])) {
  throw new InvalidArgumentException('Expected comma-separated positive node IDs.');
}
if (trim($options['label']) === '' || mb_strlen($options['label']) > 500) {
  throw new InvalidArgumentException('Provide a clear legacy-import label of 1 to 500 characters.');
}
// Maintenance mode alone does not block privileged accounts, APIs or workers.
// The second flag confirms those writers were stopped separately.
if ($apply && (!$frozen || !\Drupal::state()->get('system.maintenance_mode', FALSE))) {
  throw new RuntimeException('Apply requires maintenance mode and --write-freeze-confirmed.');
}
$migration = new LegacyNotesMigration(\Drupal::getContainer(), \Drupal::database(), \Drupal::entityTypeManager());
$jurisdiction = (int) $options['jurisdiction'];
$migration->preflight($jurisdiction, $apply);
$ids = $options['ids'] === '' ? [] : array_map('intval', explode(',', $options['ids']));
$candidates = $migration->candidates($jurisdiction, (int) $options['after'], (int) $options['limit'], $ids);
$summary = [
  'mode' => $apply ? 'apply' : 'dry-run', 'jurisdiction' => $jurisdiction,
  'counts' => [], 'last_nid' => (int) $options['after'],
];
foreach (array_chunk($candidates, 100) as $batch) {
  if (!$apply) {
    $migration->preparePlanBatch($batch);
  }
  foreach ($batch as $nid) {
    try {
      $result = $migration->process($nid, $jurisdiction, $apply, $options['label']);
      $status = $result['status'];
      $summary['counts'][$status] = ($summary['counts'][$status] ?? 0) + 1;
      $summary['last_nid'] = $nid;
      echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    }
    catch (Throwable $error) {
      // Avoid SQL exceptions containing source text or other private data.
      echo json_encode(['nid' => $nid, 'status' => 'conflict', 'error_type' => get_class($error)], JSON_THROW_ON_ERROR) . PHP_EOL;
      echo json_encode($summary, JSON_THROW_ON_ERROR) . PHP_EOL;
      throw new RuntimeException('Migration stopped. Inspect this report privately before resuming.');
    }
  }
}
echo json_encode($summary, JSON_THROW_ON_ERROR) . PHP_EOL;
