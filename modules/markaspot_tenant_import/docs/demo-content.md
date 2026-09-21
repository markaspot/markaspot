# Explicit synthetic reporting fixtures

`mas:tenant:demo-content` creates bounded synthetic examples in an already
configured root jurisdiction. It is separate from configuration import and
never creates users, roles, schema or SaaS tenants. A preview is the default.

```bash
drush mas:tenant:demo-content /local/demo.json \
  --assets-dir=/local/assets \
  --expected-site-uuid=INDEPENDENTLY-CHECKED-SITE-UUID \
  --jurisdiction-id=1 --confirm-test-data --format=json
```

Append `--apply` to create the examples. The command requires
`MARKASPOT_DEPLOY_CONTEXT=nonproduction` and `MARKASPOT_MAIL_MODE=mailpit` in its
process environment. Current deployment Compose files may not forward the
context variable: a verified test operator must supply it explicitly for this
invocation. Do not change production runtime configuration to satisfy this gate.

The fixture has `version: 1`, `synthetic: true`, a stable `fixture_id`, and
`requests` containing 1 to 20 objects. Each request needs:

- A unique stable `key`, a `title`, exact scoped category and status labels.
- `fields`: supported scalar/address/geolocation field values. Formatted text
  uses plain strings and is stored as plain text. Reporter email must end in
  `@example.invalid`; names are Demo, Test or Synthetic; telephone, when the
  optional field exists, is `+49 000 000000`. Citizen notification is false.
- `status_history`: explicit entries with `status`, plain `note`, and an
  `author_email` resolving to an existing active jurisdiction member. The last
  history status must match the current status.

Optional entries are `organisations` (exact labels), `assignee_email`,
`internal_status` (exact jurisdiction-scoped term label), `internal_remarks`
(each with plain `text` and `author_email`), and `files`. No numeric IDs from a
previous installation are accepted for these references.

A file entry supplies `field`, a local `basename`, and its exact `sha256`.
Request images use modern `field_request_media` and require plain `alt` text.
Supported attachment fields are `field_attachment`,
`field_service_provider_files`, and `field_sp_attachment`. Optional plain
`description` is supported. PNG/JPEG photos and bounded TXT/PDF/PNG/JPEG
attachments are supported where the current field extension settings allow
that type. For example, `field_sp_attachment` ordinarily requires PDF rather
than TXT. File basenames must be unique within a request. Assets must already
exist locally, be at most 5 MiB, and be explicitly synthetic; no download occurs.
The configured public/private file scheme is preserved.

Scalar support covers body, address, geolocation, synthetic contact,
notification, internal notes, object ID, priority, approval, source, request
attributes, feedback, provider feedback and hazard level. Presence and allowed
values come from the installed schema. District/sublocality/team references
are not implemented by this initial fixture contract. Legacy request images,
legacy internal-status strings and GDPR fields are explicitly not seeded.

The JSON result inventories every active node field and related
paragraph/media/file fields. It distinguishes supplied, optional unset,
computed/read-only, system defaults, retired and unsupported fields. Missing
requested fields and normal entity validation failures stop the operation.
A mismatching map boundary must be fixed in configuration, never bypassed.
Status history uses the canonical Open311 helper; internal remarks and file/media
references use entity APIs. Normal hooks may still run and are not mocked in the
actual command. Seeding is not evidence that ordinary UI/API writes work.

Ownership state binds site UUID, jurisdiction UUID and fixture input hash.
Created nodes have deterministic UUIDs. Repeating the unchanged fixture only
verifies stored entity/file fingerprints and returns `unchanged`. Existing
unowned, edited or removed content is not adopted or overwritten. Changing the
fixture identifier creates a different set deliberately; do not use that as a
way to hide a failed run.

An interrupted operation retains a `started` state requiring explicit review.
A database transaction protects entity writes, but files and hook effects are
not universally transactional. There is no automatic cleanup or retry. The
zero-node reset command intentionally refuses a stack after demo content has
been added. Archive/removal of an owned demo fixture is a separate operation.
