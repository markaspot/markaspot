<?php

/**
 * @file
 * Removes reporter and account PII from an imported production dump.
 *
 * Runs via `drush scr` in a local DDEV project after `ddev pull cloud`, before
 * the database is passed on to a shared server. Self-contained on purpose: it
 * does not require markaspot_archive, because enabling that module just to
 * scrub would also switch on its cron archiving (cron_enable: 1) and mutate the
 * dump on its own schedule.
 *
 * Usage:
 *   ddev drush scr web/profiles/contrib/markaspot/scripts/scrub-pii.php
 *
 * Modes:
 *   (default)  dry run, writes nothing
 *   --apply    performs the scrub
 *   --verify   transfer gate, exits 1 while anything personal remains
 *
 * Options:
 *   --keep-usernames        leave account names untouched
 *   --keep-admin            leave uid 1 completely untouched
 *   --keep-body             leave the report description untouched
 *   --accept-uncovered      acknowledge carriers this tool does not scrub
 *   --sweep-only            rewrite field tables directly, skip per-node saves
 *   --allow-unset-mail-mode proceed outside DDEV when no mail mode is declared
 */

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

const SCRUB_REVISION_LOG = 'Reporter contact data removed by dump scrub.';
const SCRUB_MAIL_SUFFIX = '@anonymized.off';
const SCRUB_PHONE_PLACEHOLDER = '+49-0123459995555';
const SCRUB_TOKEN_PATTERN = '^[0-9a-f]{10}$';
const SCRUB_BATCH = 100;
const SCRUB_SAFE_MAIL_MODES = ['mailpit', 'none', 'disabled', 'log', 'test'];

// Used when markaspot_archive.settings is absent, which is the normal case on
// sites that never enabled the module. body is included because reporters
// routinely put names and callback numbers into the description.
const SCRUB_DEFAULT_FIELDS = [
  'field_e_mail',
  'field_first_name',
  'field_last_name',
  'field_phone',
  'field_notification',
  'body',
];

// Tables emptied outright: they hold copies of the same personal data.
const SCRUB_PURGE_TABLES = [
  'watchdog',
  'sessions',
  'flood',
  'key_value_expire',
  'queue',
  'inbound_mail',
  'resubmission_reminder',
  'devel_mail_logger',
];

// Carriers this tool knowingly does not rewrite. Verify refuses to pass while
// any of them holds rows, unless the operator acknowledges them explicitly.
const SCRUB_UNCOVERED_TABLES = [
  'comment_field_data',
  'webform_submission',
  'paragraphs_item_field_data',
  'file_managed',
];

$args = $extra ?? [];
$apply = in_array('--apply', $args, TRUE);
$verify = in_array('--verify', $args, TRUE);
$keep_usernames = in_array('--keep-usernames', $args, TRUE);
$keep_admin = in_array('--keep-admin', $args, TRUE);
$keep_body = in_array('--keep-body', $args, TRUE);
$accept_uncovered = in_array('--accept-uncovered', $args, TRUE);
$sweep_only = in_array('--sweep-only', $args, TRUE);
$allow_unset_mail = in_array('--allow-unset-mail-mode', $args, TRUE);
$mode = $verify ? 'VERIFY' : ($apply ? 'APPLY' : 'DRY-RUN');

// A DDEV project is a local workstation copy by construction, which is where
// the pulled production dump is scrubbed. Anywhere else the site has to prove
// it cannot deliver mail before anything is rewritten.
$is_ddev = getenv('IS_DDEV_PROJECT') === 'true';
$mail_mode = (string) getenv('MARKASPOT_MAIL_MODE');

if ($is_ddev) {
  print "Local DDEV project detected; mail mode gate not applied.\n";
}
elseif ($mail_mode === '' && $allow_unset_mail) {
  print "MARKASPOT_MAIL_MODE is unset; continuing because --allow-unset-mail-mode was passed.\n";
}
elseif (!in_array($mail_mode, SCRUB_SAFE_MAIL_MODES, TRUE)) {
  throw new \RuntimeException(sprintf(
    'Refusing to scrub: MARKASPOT_MAIL_MODE is "%s". Expected one of: %s. ' .
    'Pass --allow-unset-mail-mode to proceed on a site that declares no mode.',
    $mail_mode === '' ? '(empty)' : $mail_mode,
    implode(', ', SCRUB_SAFE_MAIL_MODES)
  ));
}

$etm = \Drupal::entityTypeManager();
$database = \Drupal::database();
$node_storage = $etm->getStorage('node');

$configured = (array) \Drupal::config('markaspot_archive.settings')->get('anonymize_fields');
$fields = scrub_normalize_fields($configured) ?: SCRUB_DEFAULT_FIELDS;

// A fixed list silently misses sites that renamed their contact fields, so
// every email and telephone field on the bundle is folded in by type.
$discovered = array_values(array_diff(scrub_discover_contact_fields(), $fields));
$fields = array_merge($fields, $discovered);

// The configured list predates this tool and never covers the description, so
// body is added regardless of where the rest of the list came from.
if (!$keep_body && !in_array('body', $fields, TRUE)) {
  $fields[] = 'body';
}
elseif ($keep_body) {
  $fields = array_values(array_diff($fields, ['body']));
}

printf("=== PII scrub [%s] ===\n", $mode);
printf("Reporter fields: %s\n", implode(', ', $fields));
if ($discovered !== []) {
  printf("Additionally discovered by field type: %s\n", implode(', ', $discovered));
}

// Fail closed rather than reporting success over a field nothing can rewrite.
$unsupported = scrub_unsupported_fields($fields);
if ($unsupported !== []) {
  throw new \RuntimeException(sprintf(
    'No safe replacement strategy for: %s. These fields hold values that ' .
    'would survive the scrub, so the run is aborted instead of reporting success.',
    implode(', ', $unsupported)
  ));
}

// --- Verify -----------------------------------------------------------------
// Gate for the transfer: exits non-zero while any recognisable original value
// survives, in current values as well as in revision history.
if ($verify) {
  $residual = 0;

  foreach ($fields as $field_name) {
    foreach (scrub_field_tables($field_name) as $table) {
      $count = scrub_count_unscrubbed($table, $field_name);
      if ($count === NULL) {
        printf("FAIL %s: cannot be verified for %s\n", $table, $field_name);
        $residual++;
        continue;
      }
      if ($count > 0) {
        $residual += $count;
        printf("FAIL %s: %d rows still hold original values\n", $table, $count);
      }
      else {
        printf("ok   %s\n", $table);
      }
    }
  }

  $accounts = scrub_count_unscrubbed_accounts($keep_admin);
  if ($accounts > 0) {
    $residual += $accounts;
    printf("FAIL users_field_data: %d accounts still hold a real mail address\n", $accounts);
  }
  else {
    print "ok   users_field_data\n";
  }

  // Every table the apply path empties is checked, so the two cannot drift.
  foreach (SCRUB_PURGE_TABLES as $table) {
    $rows = scrub_table_rows($table);
    if ($rows === NULL) {
      continue;
    }
    if ($rows > 0) {
      $residual += $rows;
      printf("FAIL %s: %d rows remain\n", $table, $rows);
    }
    else {
      printf("ok   %s\n", $table);
    }
  }

  $uncovered = [];
  foreach (SCRUB_UNCOVERED_TABLES as $table) {
    $rows = scrub_table_rows($table);
    if ($rows !== NULL && $rows > 0) {
      $uncovered[] = sprintf('%s (%d rows)', $table, $rows);
    }
  }
  if ($uncovered !== []) {
    printf(
      "\n%s carriers this tool does not scrub: %s\n",
      $accept_uncovered ? 'ACKNOWLEDGED' : 'UNCOVERED',
      implode(', ', $uncovered)
    );
    if (!$accept_uncovered) {
      print "Review them and re-run with --accept-uncovered to take responsibility.\n";
      $residual++;
    }
  }

  if ($residual > 0) {
    printf("\nVERIFY FAILED. Do not transfer this database.\n");
    exit(1);
  }
  print "\nVERIFY PASSED for the carriers this tool covers.\n";
  // No exit(0) here: exit() aborts drush's command lifecycle, which logs
  // "terminated abnormally" and yields a non-zero process exit even on
  // success, breaking automated gates. A plain return ends `drush scr`
  // cleanly with exit code 0. The exit(1) above must stay: a hard non-zero
  // is exactly what the failure path is for.
  return;
}

// --- Service requests -------------------------------------------------------
$nids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'service_request')
  ->sort('nid')
  ->execute();
$nids = array_values(array_map('intval', $nids));

$touched = 0;
$clean = 0;

// Saving every node individually keeps the revision history readable, but on a
// large tenant it means tens of thousands of entity saves, each writing cache
// entries inside its own transaction. On a busy database those cache writes
// collide (deadlock 1213, "record has changed" 1020) and a failed rollback then
// aborts the whole run. --sweep-only skips the per-node path entirely and lets
// the storage sweep below rewrite the same values directly in the field and
// revision tables, which is what a shared test copy actually needs.
$node_chunks = $sweep_only ? [] : array_chunk($nids, SCRUB_BATCH);
if ($sweep_only) {
  print "Sweep-only mode: skipping per-node saves, rewriting storage directly.\n";
}

foreach ($node_chunks as $chunk) {
  foreach ($node_storage->loadMultiple($chunk) as $node) {
    if (!$node instanceof NodeInterface) {
      continue;
    }
    if (!scrub_entity_has_values($node, $fields)) {
      $clean++;
      continue;
    }
    $touched++;
    if (!$apply) {
      continue;
    }

    scrub_with_retriable_conflict(function () use ($database, $node, $fields): void {
      $transaction = $database->startTransaction();
      try {
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage(SCRUB_REVISION_LOG);
        scrub_anonymize_entity($node, $fields);
        $node->save();
        unset($transaction);
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    });
  }
  $node_storage->resetCache($chunk);
  gc_collect_cycles();
}

if ($sweep_only) {
  printf("Service requests: %d total, per-node pass skipped\n", count($nids));
}
else {
  printf(
    "Service requests: %d total, %d with reporter data in the current revision%s, %d already clean\n",
    count($nids),
    $touched,
    $apply ? ' anonymized' : ' would be anonymized',
    $clean
  );
}

// --- Storage sweep ----------------------------------------------------------
// Rewrites every remaining value directly in the field tables. This is what
// catches history: a node whose current revision is already empty is skipped
// above, but its older revisions can still hold the original contact data.
foreach ($fields as $field_name) {
  foreach (scrub_field_tables($field_name) as $table) {
    $count = scrub_count_unscrubbed($table, $field_name);
    if ($count === NULL || $count === 0) {
      continue;
    }
    if ($apply) {
      scrub_sweep_table($table, $field_name);
    }
    printf(
      "  Sweep %s: %d rows%s\n",
      $table,
      $count,
      $apply ? ' rewritten' : ' would be rewritten'
    );
  }
}

// --- User accounts ----------------------------------------------------------
// The Bonn finding was an open user collection, so accounts are scrubbed too.
$user_storage = $etm->getStorage('user');
$query = $user_storage->getQuery()->accessCheck(FALSE)->sort('uid');
if ($keep_admin) {
  $query->condition('uid', 1, '>');
}
else {
  $query->condition('uid', 0, '>');
}
$uids = array_values(array_map('intval', $query->execute()));
$user_fields = ['field_internal_phone', 'field_phone', 'field_mobile'];
$users_done = 0;

foreach (array_chunk($uids, SCRUB_BATCH) as $chunk) {
  foreach ($user_storage->loadMultiple($chunk) as $account) {
    if (!$account instanceof UserInterface) {
      continue;
    }
    $users_done++;
    if (!$apply) {
      continue;
    }
    $is_admin = (int) $account->id() === 1;
    $token = scrub_token();
    $account->setEmail($token . SCRUB_MAIL_SUFFIX);
    $account->set('init', $token . SCRUB_MAIL_SUFFIX);
    // Uid 1 is the operator account, not a citizen; its name stays readable so
    // the scrubbed dump is still usable to log in and work with.
    if (!$keep_usernames && !$is_admin) {
      $account->setUsername('user_' . $account->id() . '_' . $token);
    }
    foreach ($user_fields as $field_name) {
      if ($account->hasField($field_name) && !$account->get($field_name)->isEmpty()) {
        $account->set($field_name, NULL);
      }
    }
    $account->setPassword(bin2hex(random_bytes(16)));
    scrub_with_retriable_conflict(function () use ($account): void {
      $account->save();
    });
  }
  $user_storage->resetCache($chunk);
}

printf(
  "User accounts: %d%s (%s, passwords randomized)\n",
  $users_done,
  $apply ? ' anonymized' : ' would be anonymized',
  $keep_admin ? 'uid 1 untouched' : 'uid 1 included, its name kept'
);

// --- Copies of the same data ------------------------------------------------
foreach (SCRUB_PURGE_TABLES as $table) {
  $rows = scrub_table_rows($table);
  if ($rows === NULL) {
    continue;
  }
  if ($apply) {
    $database->truncate($table)->execute();
  }
  printf("Table %s: %d rows%s\n", $table, $rows, $apply ? ' truncated' : ' would be truncated');
}

// The search index keeps its own copy of body, title and field_e_mail, which no
// cache rebuild removes.
if (\Drupal::moduleHandler()->moduleExists('search_api')) {
  foreach ($etm->getStorage('search_api_index')->loadMultiple() as $index) {
    if ($apply) {
      $index->clear();
    }
    printf("Search index %s%s\n", $index->id(), $apply ? ' cleared' : ' would be cleared');
  }
}

if ($apply) {
  drupal_flush_all_caches();
  print "Caches rebuilt.\n";
  print "Set an admin password before use: drush upwd admin <new-password>\n";
  print "Then run --verify before passing this database on.\n";
}
else {
  print "\nNothing was written. Re-run with --apply to perform the scrub.\n";
}

/**
 * Runs an operation, retrying briefly on transient storage-engine conflicts.
 *
 * The scrub rewrites tens of thousands of revisions while the tenant keeps
 * serving requests, so its writes collide with concurrent cache and cron
 * writes. MariaDB reports those collisions in more than one shape: 1213
 * deadlocks (SQLSTATE 40001) and 1020 "record has changed since last read"
 * on the Aria cache tables. Both end in "try restarting transaction", which
 * is the marker used here, because a retriable conflict must not kill a run
 * that takes hours. Anything else, or a conflict surviving every attempt,
 * is rethrown.
 */
function scrub_with_retriable_conflict(callable $operation, int $attempts = 5): void {
  for ($try = 1; TRUE; $try++) {
    try {
      $operation();
      return;
    }
    catch (\Throwable $e) {
      $message = $e->getMessage();
      $retriable = str_contains($message, 'try restarting transaction')
        || str_contains($message, '40001')
        || str_contains($message, 'Deadlock');
      if (!$retriable || $try >= $attempts) {
        throw $e;
      }
      usleep(250000 * $try);
    }
  }
}

/**
 * Normalizes a configured field list that may be keyed or plain.
 *
 * @param array<mixed, mixed> $fields
 *   Raw configuration value.
 *
 * @return string[]
 *   Field machine names.
 */
function scrub_normalize_fields(array $fields): array {
  $names = [];
  foreach ($fields as $key => $value) {
    $name = is_numeric($key) ? $value : $key;
    if (is_string($name) && $name !== '') {
      $names[$name] = $name;
    }
  }
  return array_values($names);
}

/**
 * Finds email and telephone fields on service_request by type.
 *
 * @return string[]
 *   Field machine names.
 */
function scrub_discover_contact_fields(): array {
  $definitions = \Drupal::service('entity_field.manager')
    ->getFieldDefinitions('node', 'service_request');
  $found = [];
  foreach ($definitions as $field_name => $definition) {
    if (in_array($definition->getType(), ['email', 'telephone'], TRUE)) {
      $found[] = $field_name;
    }
  }
  return $found;
}

/**
 * Lists fields that hold values but have no safe replacement strategy.
 *
 * @param string[] $fields
 *   Field machine names.
 *
 * @return string[]
 *   Field names that cannot be scrubbed.
 */
function scrub_unsupported_fields(array $fields): array {
  $definitions = \Drupal::service('entity_field.manager')
    ->getFieldDefinitions('node', 'service_request');
  $unsupported = [];
  foreach ($fields as $field_name) {
    $definition = $definitions[$field_name] ?? NULL;
    if ($definition === NULL) {
      continue;
    }
    $type = $definition->getType();
    if (scrub_value_for_type($type) === NULL && !in_array($type, ['boolean'], TRUE)) {
      $unsupported[] = sprintf('%s (%s)', $field_name, $type);
    }
  }
  return $unsupported;
}

/**
 * Builds a short opaque replacement token.
 */
function scrub_token(): string {
  return bin2hex(random_bytes(5));
}

/**
 * Returns the replacement value for a field type, or NULL when unsupported.
 *
 * @param string $type
 *   Field type machine name.
 *
 * @return int|string|null
 *   Replacement value.
 */
function scrub_value_for_type(string $type): int|string|null {
  return match ($type) {
    'email' => scrub_token() . SCRUB_MAIL_SUFFIX,
    'telephone' => SCRUB_PHONE_PLACEHOLDER,
    'boolean' => 0,
    'string', 'string_long', 'text', 'text_long', 'text_with_summary' => scrub_token(),
    default => NULL,
  };
}

/**
 * Returns the row count of a table, or NULL when it does not exist.
 *
 * @param string $table
 *   Table name.
 *
 * @return int|null
 *   Row count.
 */
function scrub_table_rows(string $table): ?int {
  $database = \Drupal::database();
  if (!$database->schema()->tableExists($table)) {
    return NULL;
  }
  return (int) $database->select($table)->countQuery()->execute()->fetchField();
}

/**
 * Checks whether any listed field still holds a value in any translation.
 *
 * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
 *   Entity to inspect.
 * @param string[] $fields
 *   Field machine names.
 *
 * @return bool
 *   TRUE when at least one field has a non-empty value.
 */
function scrub_entity_has_values(FieldableEntityInterface $entity, array $fields): bool {
  foreach ($fields as $field_name) {
    if (!$entity->hasField($field_name)) {
      continue;
    }
    foreach ($entity->getTranslationLanguages() as $language) {
      if (!$entity->getTranslation($language->getId())->get($field_name)->isEmpty()) {
        return TRUE;
      }
    }
  }
  return FALSE;
}

/**
 * Replaces field values on every translation of the default revision.
 *
 * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
 *   Entity to anonymize; not saved here.
 * @param string[] $fields
 *   Field machine names.
 */
function scrub_anonymize_entity(FieldableEntityInterface $entity, array $fields): void {
  foreach ($fields as $field_name) {
    if (!$entity->hasField($field_name)) {
      continue;
    }
    $type = $entity->getFieldDefinition($field_name)->getType();
    $value = scrub_value_for_type($type);
    if ($value === NULL) {
      continue;
    }

    foreach ($entity->getTranslationLanguages() as $language) {
      $items = $entity->getTranslation($language->getId())->get($field_name);
      if ($items->isEmpty()) {
        continue;
      }
      foreach ($items as $item) {
        $item->set('value', $value);
        if ($type === 'text_with_summary') {
          $item->set('summary', $value);
        }
      }
    }
  }
}

/**
 * Returns the existing data and revision tables backing a field.
 *
 * @param string $field_name
 *   Field machine name.
 *
 * @return string[]
 *   Table names, empty when the field has no dedicated storage.
 */
function scrub_field_tables(string $field_name): array {
  $mapping = \Drupal::entityTypeManager()->getStorage('node')->getTableMapping();
  $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node');
  $definition = $definitions[$field_name] ?? NULL;
  if ($definition === NULL || !$mapping->requiresDedicatedTableStorage($definition)) {
    return [];
  }

  $schema = \Drupal::database()->schema();
  $tables = [];
  foreach ([
    $mapping->getDedicatedDataTableName($definition),
    $mapping->getDedicatedRevisionTableName($definition),
  ] as $table) {
    if ($schema->tableExists($table)) {
      $tables[] = $table;
    }
  }
  return $tables;
}

/**
 * Applies the "value does not look scrubbed" condition to a query.
 *
 * @param \Drupal\Core\Database\Query\ConditionInterface $query
 *   Select or update query.
 * @param string $column
 *   Value column name.
 * @param string $type
 *   Field type machine name.
 *
 * @return bool
 *   TRUE when the type carries a detectable placeholder.
 */
function scrub_apply_unscrubbed_condition($query, string $column, string $type): bool {
  $database = \Drupal::database();
  switch ($type) {
    case 'email':
      $query->condition($column, '%' . $database->escapeLike(SCRUB_MAIL_SUFFIX), 'NOT LIKE');
      return TRUE;

    case 'telephone':
      $query->condition($column, SCRUB_PHONE_PLACEHOLDER, '<>');
      return TRUE;

    case 'string':
    case 'string_long':
    case 'text':
    case 'text_long':
    case 'text_with_summary':
      $query->condition($column, SCRUB_TOKEN_PATTERN, 'NOT REGEXP');
      return TRUE;

    default:
      // Booleans and similar have no value that proves a scrub happened.
      return FALSE;
  }
}

/**
 * Counts rows whose value does not look like a scrub placeholder.
 *
 * @param string $table
 *   Field data or revision table.
 * @param string $field_name
 *   Field machine name.
 *
 * @return int|null
 *   Row count, or NULL when the type carries no detectable placeholder.
 */
function scrub_count_unscrubbed(string $table, string $field_name): ?int {
  $mapping = \Drupal::entityTypeManager()->getStorage('node')->getTableMapping();
  $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node');

  $column = $mapping->getColumnNames($field_name)['value'] ?? NULL;
  $definition = $definitions[$field_name] ?? NULL;
  if ($column === NULL || $definition === NULL) {
    return NULL;
  }
  if (scrub_value_for_type($definition->getType()) === NULL) {
    return NULL;
  }

  $query = \Drupal::database()->select($table, 't');
  $query->isNotNull("t.$column");
  $query->condition("t.$column", '', '<>');
  if (!scrub_apply_unscrubbed_condition($query, "t.$column", $definition->getType())) {
    // Type is writable but not provable, e.g. boolean. Nothing to report.
    return 0;
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Rewrites every remaining value of a field directly in one of its tables.
 *
 * @param string $table
 *   Field data or revision table.
 * @param string $field_name
 *   Field machine name.
 */
function scrub_sweep_table(string $table, string $field_name): void {
  $database = \Drupal::database();
  $mapping = \Drupal::entityTypeManager()->getStorage('node')->getTableMapping();
  $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node');

  $columns = $mapping->getColumnNames($field_name);
  $column = $columns['value'] ?? NULL;
  $definition = $definitions[$field_name] ?? NULL;
  if ($column === NULL || $definition === NULL) {
    return;
  }
  $type = $definition->getType();
  $value = scrub_value_for_type($type);
  if ($value === NULL) {
    return;
  }

  $update = $database->update($table);
  $fields_to_set = [$column => $value];
  if (isset($columns['summary'])) {
    $fields_to_set[$columns['summary']] = $value;
  }
  $update->fields($fields_to_set);
  $update->isNotNull($column);
  $update->condition($column, '', '<>');
  if (!scrub_apply_unscrubbed_condition($update, $column, $type)) {
    return;
  }
  scrub_with_retriable_conflict(function () use ($update): void {
    $update->execute();
  });
}

/**
 * Counts accounts whose mail address is not a scrub placeholder.
 *
 * @param bool $keep_admin
 *   Whether uid 1 is excluded from the scrub.
 *
 * @return int
 *   Account count.
 */
function scrub_count_unscrubbed_accounts(bool $keep_admin): int {
  $database = \Drupal::database();
  $query = $database->select('users_field_data', 'u')
    ->condition('u.uid', $keep_admin ? 1 : 0, '>')
    ->condition('u.mail', '%' . $database->escapeLike(SCRUB_MAIL_SUFFIX), 'NOT LIKE');
  return (int) $query->countQuery()->execute()->fetchField();
}
