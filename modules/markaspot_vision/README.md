# Imported image screening

Open311 `media_url` imports and promoted email attachments create unpublished
`request_image` media and enqueue one `markaspot_media_intake` job per image.
The POST keeps its existing response and does no synchronous blur or AI call.

The worker reads the stored source, calls
`ImageProcessingService::blurSensitiveAreas()` with the normal `BlurPolicy`,
and persists the result through `ImageProcessingService::persistBlurResult()`.
The vision controller uses the same persistence path, including ownership
checks, optional original retention, overwrite and derivative invalidation. A
successful blur step writes `field_ai_metadata`, the marker the strict
publication gate already uses. Only published reports release eligible media
with no privacy hold. Explicit GeoReport media publication decisions remain
authoritative.

## Worker operation

Run the following command **every minute** from the tenant's existing scheduler
or supervisor. Use a scheduler lock (for example `flock`) to prevent
overlapping queue runners. This is required deployment wiring: the cloud
image's 15-minute cron loop alone is insufficient. The worker is also
registered for Drupal cron as a fallback.

```sh
drush queue:run markaspot_media_intake --time-limit=50 --lease-time=900
```

Each image gets at most five privacy attempts. Backoff is 60, 120, 240 and 480
seconds. The queue uses `DelayedRequeueException`; the durable next-attempt
timestamp also prevents immediate manual runs from bypassing backoff. Failed
images remain hidden, with a privacy-issues message visible to staff.
`markaspot:health` reports exhausted attempts and work waiting more than five
minutes, including a stopped worker. No historical images are backfilled
automatically.

The durable checkpoint collection is `markaspot_vision.media_intake`, keyed by
media ID. It records attempts and privacy/analysis state, never image bytes or
provider errors. Failed queue insertion is recoverable on the next cron run.
The output fingerprint is stored before overwrite so a retry after a stopped
process recognises the transformed file and finishes invalidating old image
variants before publication. The controller and worker share a per-image lock,
so concurrent requests cannot blur the same import twice. Completed jobs do
not blur or charge again. Replacing a media source invalidates its checkpoint
and schedules a new job. Publication verifies the current source fingerprint;
a mismatch is held
and reported by the health check. After fixing a terminal failure, staff may
use the existing vision analysis flow or make an explicit editorial publication
decision.

## Optional analysis

`markaspot_vision.settings.imported_image_analysis` accepts `off`,
`without_description` (default, also for existing installs missing this key),
or `always`. The Vision settings form exposes this choice. No update hook
writes config; config/sync tenants may export the key when choosing a
non-default policy.

Analysis requires the media's trusted tenant scope, its effective `aiAnalysis`
feature, and available token and SaaS analysis budgets. The worker reserves one
SaaS analysis slot before the external call and checkpoints the attempt. An
interrupted or failed optional analysis has an explicit metadata state and
health warning. It is not retried or charged twice; successful blur still
allows publication. AI output is stored as staff suggestions in
`field_ai_metadata.intake_suggestions`; citizen description, title and category
are never overwritten. Privacy findings still hold the media for staff review.

With `without_description`, imports containing citizen text follow the blur
policy but receive no AI check for residual privacy issues. Choose `always` to
request that additional check, subject to the tenant feature and budgets.
When blur is neither configured nor required, the fallback is recorded as
`processed: false` and `privacy_screened: false`. As for citizen uploads,
strict mode then requires successful AI analysis; legacy mode permits
publication without it. Enabling required blur invalidates older checkpoints
that were not processed under that requirement, before publication or AI use.

Orphaned intake entries do not fail health checks and can be queued again when
associated. An explicit editorial publication decision resolves intake work.
Report unpublishing records a separate temporary hold, so historical media can
return on republishing while privacy holds remain effective. Pruning and
indexing the intake key/value collection remain follow-up work.
