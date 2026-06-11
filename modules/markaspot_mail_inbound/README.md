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
- **Promotion field mapping:**
  - **Description (`body`) — precedence AI > original:** when the AI
    suggestion service stored a `suggested_description` on the staged
    mail (a concise, neutral problem description WITHOUT salutation,
    sign-off, names or other PII), it becomes the public report body.
    When no AI description is available (AI disabled, suggestion skipped
    or failed), the description falls back to the original citizen mail
    text: the subject plus the first segment of the staged conversation
    log, before any appended entries. In both cases the subject is
    prepended via `buildDescription()`.
  - **Internal remark — ALWAYS written:** regardless of which description
    path was taken, the FULL mail body log (original message plus any
    staged conversation entries) is preserved as ONE `internal_remark`
    paragraph on the node (label: "E-Mail-Konversation aus dem
    Posteingang (vor Übernahme)") through the same guarded mechanism
    post-promotion replies use. This ensures staff can always read the
    citizen's exact wording even when the public description is an AI
    paraphrase.
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

## Return channel & triage API (Phase 2, markaspot-ui#482)

- **Jurisdiction-scoped access:** `InboundMailAccessControlHandler` requires
  the `triage inbound mail` permission AND membership in the mail's
  jurisdiction (via markaspot_group's scope validator) for view/update/delete.
  Global admins (uid 1 / `administrator`) bypass; mail without a jurisdiction
  is permission-only. The admin list and the promote/discard forms enforce
  the same handler.
- **Dashboard REST API** (custom controller, deliberately NOT JSON:API; the
  JSON:API resource core would derive for `inbound_mail` is disabled by a
  shipped `jsonapi_extras.jsonapi_resource_config` (config/install, update
  11903 for existing installs), so this API is the entity's only HTTP
  surface; cookie auth + CSRF header on mutations):
  - `GET /api/inbound-mail?state=&page=&limit=` — scoped, paginated list
    with a `fidelity` block (imap_configured, outbound_available,
    private_fs, ai_available).
  - `GET /api/inbound-mail/{id}` — full detail incl. attachment metadata.
  - `GET /api/inbound-mail/{id}/attachment/{fid}` — streams a staged file
    (no direct URLs; private staging stays private).
  - `POST /api/inbound-mail/{id}/promote` `{"category_tid":N}` — 409 when
    not staged, 422 for an unknown or foreign-jurisdiction category, 503
    without the Open311 processor. All jurisdiction-scoped categories are
    accepted, including those without a field_service_code (codeless).
  - `POST /api/inbound-mail/{id}/discard` — 409 when not staged.
  - `POST /api/inbound-mail/{id}/reply` `{"body":"..."}` — staged mails
    only; `{"sent":false,"recorded":true}` when outbound is unavailable
    (record-only degradation).
- **Outbound replies** (`InboundMailReplyService`, keys `triage_reply` /
  `auto_reply_missing_location`): From/Reply-To = the jurisdiction's
  `field_jurisdiction_e_mail` (site mail fallback), own Message-ID +
  In-Reply-To/References from the thread chain, outbound ids recorded so a
  citizen reply to our reply threads back. Recipient is ONLY the verified
  sender; replies are recorded on the mail's conversation log. With
  markaspot_mail enabled the tagged `TriageReplyBuilder` renders the
  jurisdiction-branded chrome.
- **Status notifications** to email-origin citizens are threaded into the
  original conversation by `hook_mail_alter()` (`OutboundMailThreader`) —
  only when the outgoing mail resolves to an email-origin node AND goes to
  the verified reporter.
- **Auto-reply on missing location:** after an ungeolocated promotion the
  citizen is asked for the location (request id included), gated by the
  `auto_reply_missing_location` setting (default on).
- **Loop protection:** outbound replies carry RFC 3834 `Auto-Submitted`
  (`auto-replied` for the auto-reply, `auto-generated` for staff replies)
  plus `X-Auto-Response-Suppress: All` (and `Precedence: bulk` on the
  auto-reply only), so vacation responders and ticket systems do not answer
  our mail; the inbound `SpamHeuristicFilter` honors the same headers.
- **Known limitation — synchronous on-insert confirmation mails are not
  threaded:** the promoted node's `nid` and the mail's `promoted` state are
  recorded on the `inbound_mail` only AFTER `$node->save()` completes. A
  confirmation mail that fires synchronously DURING that save — e.g. an ECA
  model on `content_entity:insert` sending a "your report has been received"
  mail (the distribution ships such a model, `process_ugsohtl`, disabled by
  default; client tenants commonly enable equivalents) — therefore cannot be
  matched by `OutboundMailThreader` and goes out WITHOUT threading headers.
  Consequence: a citizen reply to THAT first mail carries only an unknown
  Message-ID and is staged as a NEW inbound mail instead of threading onto
  the conversation; triage staff see it as a fresh item. All later mails
  (status notifications on update, triage replies, the auto-reply) are
  threaded normally. No mail is suppressed.

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

## AI category suggestions

When markaspot_ai is installed (and optionally markaspot_vision for image
attachments), each staged mail can be automatically assigned a suggested
category before a triage operator opens it.

### Gate order

A miss at any gate leaves suggestion_status = skipped, not failed. "failed"
is only set when the AI service was reachable but the response was invalid.

1. Module setting `ai_suggestions_enabled` (default true)
2. Tenant feature flag `features.aiAnalysis` via FeatureFlagChecker
3. Token budget via TokenTrackingService (when wired)
4. Service availability: vision (attachment mails) or AI client (text mails)

When attachments are present and the vision service is wired, the vision path
(ImageProcessingService::processImages) is used. Otherwise the text path
makes one chat call via AiClientService.

### Suggest-first, auto-promote separate

The suggestion is stored on the mail entity; the triage inbox shows the
suggested category so the operator can confirm or override. Auto-promotion is
a separate, intentionally conservative gate:

- `auto_promote_enabled`: default **off**. Must be explicitly enabled per
  install after the tenant-level verification described below.
- `auto_promote_confidence_threshold`: a value from 0 to 1 (default 0.85).
  Only text suggestions with confidence >= threshold are considered.
- The suggested category must be jurisdiction-scoped (isPromotable). Both
  coded and codeless categories satisfy this gate.
- `is_report` returned by the AI must be true; mails the model classifies as
  non-reports (spam, inquiries, auto-replies) are stored with
  confidence = null to suppress auto-promote even if the threshold would
  otherwise be met.
- Vision suggestions (no numeric confidence) are never auto-promoted.

### Before enabling auto_promote on any tenant

The PromotableCategoryRepository runs its entity queries with
`accessCheck(TRUE)`. In cron context Drupal runs as the anonymous user, which
means the query inherits the anonymous access policy for taxonomy terms. Before
enabling auto-promote, verify that anonymous users can read service_category
terms in the target jurisdiction, or the promoted category list will be empty
and every auto-promote attempt will be skipped with a log notice (safe, but
silently ineffective). This is Drupal finding 9 from the review battery; it is
not fixed in the module because the access policy is install-specific.

### GDPR note

When `features.aiAnalysis` is enabled for a jurisdiction, the subject, body
and any image attachments of every inbound mail are transmitted to the
configured AI provider (set via markaspot_ai settings). Mail content is never
logged by this module, but it leaves the Drupal host. Tenants must disclose
this in their privacy policy and ensure the configured provider meets their
data-processing requirements.

## Local testing without IMAP

```
drush markaspot:mail-inbound:ingest message.eml --mailbox=city_main
drush markaspot:mail-inbound:fetch --mailbox=city_main
drush queue:run markaspot_mail_inbound
```
