<?php

/**
 * @file
 * Post-update functions for markaspot_request_id.
 */

/**
 * Backfill jurisdiction_id=0 rows to the single root jurisdiction.
 *
 * Legacy migrations land all historical sequence rows with jurisdiction_id=0
 * (the single-tenant sentinel). After the tenant's root jur group is created
 * via jurisdiction/setup.sh, RequestIdGenerator queries by that root's GID and
 * finds nothing, so new requests restart at seq=1 instead of continuing from
 * the imported max. This backfill rebinds the orphan rows to the single root
 * so the counter continues smoothly.
 *
 * Safe when:
 * - Exactly one root jurisdiction exists: backfill runs.
 * - No root jurisdiction exists yet: noop, runs again on next drush updb.
 * - More than one root exists: noop with warning; operators must reassign
 *   manually because historical sequence cannot be split automatically.
 *
 * Idempotent: second run finds zero jid=0 rows and returns NULL.
 *
 * Concurrency: acquires the same per-jurisdiction lock
 * (markaspot_request_id:<gid>) that RequestIdGenerator uses, so a concurrent
 * request generation waits out this backfill instead of deadlocking on the
 * 116k-row UPDATE. Recommended to run in maintenance mode, but safe without.
 *
 * Transaction-wrapped: a mid-statement failure rolls back the entire
 * UPDATE, leaving the table in its pre-backfill state instead of a partial
 * rebind.
 */
function markaspot_request_id_post_update_backfill_jurisdiction_from_single_tenant(array &$sandbox): ?string {
  $database = \Drupal::database();

  $orphanCount = (int) $database->select('markaspot_request_id', 'r')
    ->condition('jurisdiction_id', 0)
    ->countQuery()
    ->execute()
    ->fetchField();

  if ($orphanCount === 0) {
    return NULL;
  }

  /** @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $resolver */
  $resolver = \Drupal::service('markaspot_group.hierarchy_resolver');
  $rootIds = $resolver->getAllRootJurisdictionIds();

  if (empty($rootIds)) {
    \Drupal::logger('markaspot_request_id')->notice(
      'Skipping jurisdiction backfill: no root jur group exists yet (@count orphan rows waiting). Will retry on next drush updb after jurisdiction/setup.sh.',
      ['@count' => $orphanCount]
    );
    return "Found $orphanCount orphan rows at jurisdiction_id=0 but no root jurisdiction yet. Waiting for jurisdiction setup.";
  }

  if (count($rootIds) > 1) {
    $rootList = implode(', ', $rootIds);
    \Drupal::logger('markaspot_request_id')->warning(
      'Skipping jurisdiction backfill: multiple root jur groups exist (@roots). @count orphan rows at jurisdiction_id=0 require manual reassignment because historical sequence cannot be split automatically between tenants.',
      ['@roots' => $rootList, '@count' => $orphanCount]
    );
    return "Found $orphanCount orphan rows but " . count($rootIds) . " root jurisdictions ($rootList). Manual reassignment required.";
  }

  $rootId = (int) $rootIds[array_key_first($rootIds)];
  $lockName = 'markaspot_request_id:' . $rootId;
  $lock = \Drupal::lock();

  // Wait up to ~60s for any in-flight sequence generation on this root to
  // release the lock. acquire() timeout = how long the lock itself is valid,
  // not how long we wait for it. We need a wait loop.
  $attempts = 0;
  while (!$lock->acquire($lockName, 60)) {
    $lock->wait($lockName, 5);
    if (++$attempts >= 12) {
      \Drupal::logger('markaspot_request_id')->error(
        'Could not acquire @lock after @attempts attempts; aborting backfill to avoid conflict with live request generation.',
        ['@lock' => $lockName, '@attempts' => $attempts]
      );
      return "Could not acquire lock $lockName; backfill skipped.";
    }
  }

  try {
    $transaction = $database->startTransaction();
    try {
      $updated = $database->update('markaspot_request_id')
        ->fields(['jurisdiction_id' => $rootId])
        ->condition('jurisdiction_id', 0)
        ->execute();
      // Explicit commit: unset drops the transaction object which triggers
      // commit on the underlying connection when no parent transaction is
      // active.
      unset($transaction);
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $e;
    }
  }
  finally {
    $lock->release($lockName);
  }

  \Drupal::logger('markaspot_request_id')->notice(
    'Backfilled @count markaspot_request_id rows from jurisdiction_id=0 to root jurisdiction @rid.',
    ['@count' => $updated, '@rid' => $rootId]
  );

  return "Backfilled $updated rows to jurisdiction_id=$rootId.";
}
