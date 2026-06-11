# Mark-a-Spot Mail Inbound

Inbound email-to-report pipeline: citizens email a configured address (for
example `report@city.de`), cron fetches the mailbox via IMAP and each message
becomes an unpublished service request in the mapped jurisdiction.

## Pipeline

```
hook_cron -> MailboxFetcher (IMAP, webklex/php-imap)
          -> queue markaspot_mail_inbound (raw RFC822 + mailbox id)
          -> MailInboundQueueWorker
          -> MailParserService (raw -> InboundMessage DTO)
          -> InboundRequestCreator (dedupe, threading, checks, node)
```

Cron only fetches and enqueues. Parsing and node creation happen in the
queue worker, so a large mailbox cannot stall cron.

## Behavior

- **Dedupe:** a Message-ID that already exists in `field_email_message_id`
  is skipped (logged no-op).
- **Reply threading:** when In-Reply-To/References match an existing
  request, NO new request is created. The reply text is appended as an
  `internal_remark` paragraph (the same structure the dashboard uses) and
  `InboundReplyReceivedEvent` is dispatched. On tenants without the
  paragraph stack the event plus a watchdog notice is the fallback.
- **Moderation:** created nodes are always unpublished so emailed reports
  enter the normal review queue. The Open311-style initial status and
  status note paragraph are applied through `markaspot_open311.processor`.
- **No coordinates (v1):** an email carries no location, so
  `field_geolocation` is left empty (it is `required: false`); no `0,0`
  placeholder is set (that would drop a pin in the Atlantic). The report
  appears in the dashboard LIST with the email source icon for staff to
  geolocate manually. Deriving a jurisdiction centroid is a deferred
  enhancement.
- **Trusted ingestion (no entity validation):** like `markaspot_open311`'s
  `GeoreportProcessorService` (the platform's primary citizen-report
  channel), the creator builds properties and saves directly WITHOUT calling
  `$node->validate()`. Entity-level validation enforces form/UI constraints
  (a non-default location, a viewable jurisdiction reference) that do not
  apply to this trusted server-to-server channel and would reject every
  legitimate email report. The mailbox config is validated instead at
  config-save time (see Configuration), which is the correct layer.
- **Source tracking:** `field_source` is set to `email`. Allowed values:
  `web`, `app`, `email`, `phone`, `letter`, `api` (shared contract with the
  Nuxt frontend).
- **Auto-reply:** subscribe to `InboundRequestCreatedEvent` (also available
  to ECA) to acknowledge the citizen.

## Security

- Every header and body byte is attacker-controlled. Nothing is rendered as
  HTML; bodies are stored as `plain_text` after tag stripping.
- Attachment MIME types are verified by content sniffing (finfo), never by
  the declared Content-Type; count and size caps apply; stored file names
  are generated, the client file name is never used.
- Senders are checked against per-mailbox block/allow lists and the core
  flood service (default: 10 mails per hour per sender).
- A reply is only appended to an existing request when its From address
  matches the original reporter's `field_e_mail` (case-insensitive). A reply
  from any other sender creates a new, independent request instead of
  appending to (or leaking into) someone else's request.
- `field_email_message_id` ships with `field_permissions: custom`, so no
  role can read it via JSON:API unless explicitly granted (same gating
  pattern as `field_first_name`/`field_last_name`).
- Per fetch a configurable `imap.fetch_limit` (default 50) caps how many
  messages are pulled into memory; oversized raw messages (> 25 MB) are
  skipped before they reach the queue.

## IMAP passwords (do not commit them)

IMAP passwords are exportable configuration: anything entered in the admin
form is written to `config/sync`, lands in version control and is baked into
any container image built from that config. In production, leave the config
password empty and provide it out-of-band.

Order of precedence (highest first):

1. Per-mailbox environment variable, keyed by the uppercased mailbox id:
   ```
   MARKASPOT_MAIL_INBOUND_PASSWORD_CITY_MAIN=...
   ```
2. Generic environment variable for a single-mailbox setup:
   ```
   MARKASPOT_MAIL_INBOUND_PASSWORD=...
   ```
3. The classic settings.php config override:
   ```php
   $config['markaspot_mail_inbound.settings']['mailboxes'][0]['imap']['password'] = getenv('MAIL_INBOUND_PASSWORD');
   ```

The non-empty config value always wins over the env vars, so an accidental
committed password is not silently shadowed; remove it from config to let the
out-of-band value take effect. Project-level `config_ignore` for the mailbox
password is a separate, recommended ops step.

## Configuration

`/admin/config/markaspot/mail-inbound` (permission: `administer markaspot
mail inbound`). Mailboxes are edited as a validated YAML sequence; see the
config schema for the full structure. On save the form rejects a
`default_category_tid` that does not resolve to a `service_category` term and
a `jurisdiction_gid` that is not an existing `jur` group, so a typo cannot
silently make every incoming mail fail validation and get dropped.

## Uninstall

This module owns four shipped field configs (declared with
`dependencies.enforced.module`): the `field_source` and
`field_email_message_id` field storages and their `service_request`
instances. **Uninstalling the module removes `field_source` and
`field_email_message_id` together with all their stored data** (the source
channel of every report and every stored email Message-ID). Export anything
you need first.

## Raw message storage

Persistent raw-source retention was deferred and is intentionally not
implemented: raw emails carry PII (addresses, signatures, full headers) and
nothing in the pipeline stores them. Re-introducing an opt-in raw-retention
toggle is a possible future option.

## Local testing without IMAP

```
drush markaspot:mail-inbound:ingest message.eml --mailbox=city_main
drush markaspot:mail-inbound:fetch --mailbox=city_main
drush queue:run markaspot_mail_inbound
```
