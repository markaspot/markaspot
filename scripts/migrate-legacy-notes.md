# Legacy notes migration

This explicit Drush tool appends one `internal_remark` paragraph per report with
nonempty `field_notes`. It never splits free text into guessed historical events.
It retains the source field byte-for-byte, all earlier revisions and existing
remarks. It does not hide fields or change any configuration or permissions.

## Read-only planning

```sh
drush php:script web/profiles/contrib/markaspot/scripts/migrate-legacy-notes.php -- --jurisdiction=123 --limit=100
```

Use an actual jurisdiction ID. There is no default tenant or all-tenants option.
Output contains IDs, text formats, outcomes and a cursor, never note contents.
Repeat with `--after=<last_nid>` to inspect the next page. Maximum batch: 1000.
Use `--ids=123,456` for a pilot. An empty page means the selected range is complete.

Preflight rejects incompatible field schemas, source permission models it cannot
compare and roles allowed to read remarks but not the legacy notes. Existing
dashboard access guards must be enabled. Custom access hooks still require an
operator review for each tenant. No role grants are added to make migration pass.
Translated reports, non-default pending revisions, unsupported formats and
changed/deleted migration targets stop the run for individual review.

## Apply in an exclusive maintenance window

1. Back up the database and files and verify the backup.
2. Complete the dry run, review conflicts and freeze **all writers**: application
   ingress for staff/API clients, queues, cron, integrations and other CLI jobs.
   Maintenance mode alone does not stop privileged or API writes. Let in-flight
   requests finish. Keep the freeze in place for the entire migration and checks.
3. Enable Drupal maintenance mode and explicitly acknowledge the external freeze:

```sh
drush php:script web/profiles/contrib/markaspot/scripts/migrate-legacy-notes.php -- --jurisdiction=123 --ids=123,456 --apply --write-freeze-confirmed --label="Imported legacy note; author and original date unknown."
```

4. Verify the pilot and repeat it. The second run must say `already_migrated`.
   Check source checksums, earlier revisions, existing remarks, publication,
   assignments, node grants, mailbox/queue counts and reader/nonreader accounts.
5. Apply bounded pages and keep the JSON results. A failed node rolls back its
   transaction; earlier successful nodes remain committed. Resume at the last
   successful cursor or rerun from zero. A changed source or changed/deleted target
   is a conflict, never silently overwritten or duplicated.
6. Verify the whole selected scope from cursor zero, then release the write freeze
   and restore the previous maintenance setting. Only after complete acceptance
   hide the legacy input through the tenant's form display configuration. Keep
   source storage and historical access available. That UI change is separate.

The optional label is plain text. Use an explicit import label in the tenant's
language. The paragraph date is the migration time, written into its text too;
author remains unset. The migration revision has UID 0 and an explicit log, not
the original report author. The report's `changed` timestamp is preserved.
`plain_text`, empty and NULL formats are copied literally. `basic_html` is
converted using Drupal's safe HTML-to-text helper, including link footnotes.
Original markup remains available in the retained source and earlier revisions.
Other formats require review rather than guessing their semantics.

Imported paragraphs carry the `markaspot_legacy_notes.exclude_from_ai` behavior
marker. AI form assist excludes them before reading text or selecting its ten
most recent remarks, preserving the old field's exclusion from AI input. Deploy
the accompanying `AttributeFillingService` change before applying the migration.
Do not strip this marker when moving or exporting migrated paragraphs.

## Storage and recovery contract

The CLI creates isolated node and paragraph storage instances, never registered
as runtime handlers. Core SQL entity storage allocates IDs/revisions and writes field
tables. Normal entity/field callbacks, ECA, mails, webhooks, access recalculation
and business normalization do not run. Paragraph parent metadata is supplied
explicitly. Existing paragraph revisions are not resaved. Transactions cover the
new paragraph, node revision and database `key_value` marker in collection
`markaspot_legacy_notes_v1`. Node UUID and source/target hashes protect reruns.
Caches are invalidated after commit. This intentionally narrow offline path must
be kernel-tested when upgrading Drupal or Paragraphs; never reuse it for edits.

On failure, keep the freeze, investigate the reported ID privately and either
resume after resolving the conflict or restore the verified pre-migration backup.
There is no destructive rollback or source-delete switch. Do not manually remove
the marker or target: that breaks the idempotency evidence. A backup restore also
restores the markers, references and revisions as one consistent state.
