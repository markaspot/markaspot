# Template demo seeds

One YAML file per template demo workspace. A seed creates a permanent,
multilingual `jur` workspace with curated reports that show one complete use
case: a report on the map, a question back to the reporter, work in progress
and a resolved case. `demo-school-routes.yml` is the reference example.

```bash
drush mas:seed-demo-workspace path/to/demo-school-routes.yml          # create or update
drush mas:seed-demo-workspace path/to/demo-school-routes.yml --reset  # remove visitor content first
drush mas:reset-demo-workspaces [--slug=demo-school-routes]          # reset allowlisted demos
```

## Who answers in a demo

Only the issue reporter demo is run by a city administration. Every other
template is a tool for citizens, initiatives and associations that work
without one: they read the reports, ask back, solve what they can and hand
the rest over. The administration benefits but makes no service promise.
Write notes, start pages and tasks from the point of view of the group that
runs the map. A city appears only as the recipient of what is handed over,
never as the party that has to answer. CivicSpot workspaces have no
organisations feature, so CivicSpot demos leave `organisations` empty.

Seeding alone does not publish a demo. The slug must also be listed in
`markaspot_fastmap.settings:demo_workspaces`; only then does the settings API
deliver the translations and the task, and only then does the reset touch it:

```bash
drush cset markaspot_fastmap.settings demo_workspaces '["demo-school-routes"]' --input-format=yaml -y
```

## What a seed run does

- Creates or updates the group with slug `slug`. A workspace with that slug
  but without a `demo` block in `field_nuxt_config` is refused, so a seed can
  never take over a customer workspace. Slugs must start with `demo-`.
- The workspace is permanent (no `field_expiry_date`), tier `heart`,
  visibility `public`. Its `field_nuxt_config` is rebuilt from the standard
  workspace configuration on every run, so dashboard changes are undone.
- Installs missing languages, creates the statuses, categories, the promoted
  start page and the reports with all translations.
- Reports are language neutral (`und`) like every report. Their texts in the
  default language are stored on the node; all languages go into the demo
  overlay (see below).
- Running the same seed twice changes nothing visible. Reports keep their
  node and their request ID.

## Settings

`markaspot_fastmap.settings`:

| Key | Default | Meaning |
|---|---|---|
| `demo_workspaces` | `[]` | Allowlisted demo slugs. Delivery of the `demo` key and the reset both need the slug here AND the demo block on the group. |
| `demo_seed_dir` | `''` | Directory with `<slug>.yml` files for the reset. Empty: the reset re-applies the copy of the last seed run stored in State. |
| `demo_reset_interval` | `0` | Seconds between cron resets. `0` turns the cron reset off. |

## Contract with the frontend

`/api/mark-a-spot-settings?jurisdiction=<id>` returns a top-level `demo` key
only for workspaces that carry the demo block AND are allowlisted in
`demo_workspaces` (the response depends on that config, so changing the
allowlist invalidates it):

```json
{
  "template": "safe-routes",
  "seedVersion": 1,
  "translations": {
    "12-2026": {
      "es": { "title": "…", "description": "…", "statusNotes": ["…", "…"] }
    }
  },
  "task": { "en": "…", "es": "…" },
  "showcase_request_id": "1-2026"
}
```

- `showcase_request_id` is the service_request_id of the first report in the
  seed, read from the live node on every seed run; `null` until a seed run
  has stored it. Solution pages link their dashboard slides to it. Request IDs
  count per workspace, and a reset keeps the seeded nodes, so the value stays
  the same until the workspace is created anew.

- The `translations` key is the Open311 `service_request_id`, which is the
  node's `request_id` field (`<seq>-<year>` by default, optionally with the
  jurisdiction's `field_request_id_prefix`, e.g. `BONN-7-2026`).
- `title` is the display title. The node title itself is system owned
  (`#<request_id> <category>`) and never carries the seed title.
- `statusNotes` is index-aligned with the report's `status_notes` as Open311
  returns them (oldest first).
- Every language of the seed is present, including the default language.
- `task` is also appended to the start page body as a bold paragraph.

## Demo staff login

Every seeded demo workspace has one staff account that all visitors share.
The solution pages link dashboard features into the demo with one click,
without a code or a mail.

The seeder creates the account and restores it on every run and reset:

| Property | Value |
|---|---|
| Name | `demo-<slug>`, e.g. `demo-demo-school-routes` |
| Mail | `demo+<slug>@civicspot.invalid` (never deliverable) |
| UUID | derived like every seeded entity (kind `user`, key `staff`); this is how code recognises the account |
| Password | random on every run, never stored or shown |
| Global roles | none besides `authenticated` |
| Memberships | only the demo workspace, roles `jur-member` + `jur-moderator`, as the team dialog gives an invited moderator |

If the moderator role is missing or not an individual role, the seed logs an
error and skips the account; the endpoint then answers 404.

The role set is deliberately minimal (`DemoStaffAccount::GLOBAL_ROLES` and
`MEMBERSHIP_ROLES`, pinned by a kernel test). Without the global `moderator`
role the account can open and update reports of its workspace and toggle
their publication, but Drupal denies editing `field_status`,
`field_status_notes` and `field_assignee` and hides reporter fields. What
that means for dashboard deep links (from the code, measured on dev
permissions):

| Link | Result |
|---|---|
| `/<slug>/dashboard` | renders |
| `/<slug>/dashboard/requests` | list renders; reporter columns stay empty |
| `/<slug>/dashboard/requests/<id>/edit` | renders with edit controls (the UI treats `jur-moderator` as editor); saving a status change or public answer fails with 403 from JSON:API; assignment is hidden (no `assign service requests`) |
| `/<slug>/dashboard/metrics` | redirected to `/<slug>/dashboard` with an info toast: the page needs `access dashboard kpis`, which only `administrator` and `tenant_admin` hold |

The first seeded report is published as `demo.showcase_request_id` (see
above).

### Endpoint

```
POST /api/fastmap/demo-session
Content-Type: application/json
X-CSRF-Token: <token from /session/token>

{"workspace": "demo-school-routes"}
```

| Status | When |
|---|---|
| 200 `{"ok": true, "dashboard": "/<slug>/dashboard"}` | logged in as the demo account; also when the session already is that account (no new login) |
| 400 | body is not `{"workspace": "<string>"}` |
| 404 | slug not in `demo_workspaces`, no demo block, or no active staff account with membership; all cases look the same |
| 409 | the visitor is logged in as any other account; a real session is never replaced |
| 415 | Content-Type is not `application/json` (or `application/vnd.api+json`) |
| 429 | more than 20 new demo sessions per client IP and hour (flood event `markaspot_fastmap.demo_session`, `Retry-After` header) |

Cross-site requests: Drupal checks the CSRF header token only for
authenticated sessions. Anonymous visitors have no persistent session, so
`/session/token` hands them a new token on every call that cannot be
validated. For them the JSON content type is the guard: a cross-site form
cannot send it, and a cross-site fetch with it needs a CORS preflight. The
flood limit counts per `getClientIp()`, which relies on the reverse proxy
settings passing the visitor IP through.

### Guard rails

- The session lives at most two hours. `mas:reset-demo-workspaces` (and the
  cron reset) also deletes all sessions of the account, so visitors start
  over together with the content.
- A demo session must be started by the endpoint. Any other login of the
  account (password, OTP, one-time link, user switching, session handoff) is
  ended in `hook_user_login()`, and a request subscriber logs out sessions
  without the endpoint marker or older than two hours.
- Visitors cannot edit or delete the account (entity access), and code that
  saves users cannot change its name, mail, password, status or roles unless
  a user administrator acts.
- The account cannot become a member of any other group.
- Mail to `@civicspot.invalid` is dropped in `hook_mail_alter()`, so the
  account never receives assignment, status, OTP or password mails.
- `/api/auth/status` reports `user.is_demo_account: true` for the account, so
  the frontend can show a demo banner and hide account settings.

## Recognising seeded content

Seeded entities have deterministic UUIDs derived from the site UUID, the slug,
the entity kind and the seed key. Nothing is written into visible fields. The
reset deletes every node in the workspace whose UUID the seed does not own,
together with its status history, media and unused files, and removes
categories and statuses the seed does not define.

## Format

All texts are plain text unless noted. Every localized text must exist in
every language listed in `languages`; a missing translation stops the seed.

```yaml
version: 1                      # required, always 1
slug: demo-school-routes        # demo-[a-z0-9-]{1,25}
template: safe-routes           # template slug, also selects the theme colors
seed_version: 1                 # bump when the content changes
default_language: en            # one of languages
languages: [en, es, fr, de]
wording: report                 # report | suggestion | entry | contribution
name: 'Lindenhof School Routes' # workspace name, max 64 characters
claim: { en: …, es: …, fr: …, de: … }        # optional header claim
map: { lat: 50.83, lng: 6.90, zoom: 16 }
boundary: { radius_km: 0.8 }    # or { geometry: <GeoJSON Polygon|MultiPolygon> }
theme: { primary: amber }       # optional override of the template colors
operator:                       # optional, map operator for the legal pages
  email: support@civicpatches.de
  address: { organization: …, address_line1: …, postal_code: …, locality: …, country_code: DE }
statuses:                       # 3 to 12, must cover initial, open and closed
  - key: received
    mapping: initial            # initial | open | closed
    hex: '#D97706'              # optional
    icon: i-lucide-circle-plus  # optional
    name: { en: …, es: …, fr: …, de: … }
categories:                     # 1 to 30
  - key: crossing
    icon: i-lucide-traffic-cone # optional, guessed from the name otherwise
    hex: '#E53E3E'              # optional
    name: { en: …, es: …, fr: …, de: … }
organisations:                  # optional assignment targets (dashboard only)
  - key: traffic
    name: 'Traffic Department'
start_page:
  title: { en: …, … }
  body: { en: '<p>…</p>', … }   # HTML, basic_html
task: { en: …, … }              # optional mini task, max 1000 characters
reports:                        # 1 to 20, 6 to 8 recommended
  - key: dangerous-crossing
    category: crossing
    organisation: traffic       # optional
    lat: 50.8301
    lng: 6.9052
    time: '07:52'               # optional submission time, HH:MM (see Times)
    address:                    # optional; without it the geocoder fills the address
      { address_line1: …, postal_code: …, locality: …, country_code: DE }
    photo:                      # optional JPEG/PNG, path relative to the YAML file
      file: images/crossing.jpg
      alt: { en: …, … }
    title: { en: …, … }
    description: { en: …, … }
    history:                    # chronological, the last entry is the current status
      - status: received
        days_ago: 21
        note: { en: …, … }
      - status: question
        days_ago: 19
        time: '20:40'           # optional, HH:MM (see Times)
        note: { en: …, … }
```

### Times

Each history entry lands on the day `days_ago` before the seed run, at a time
of day in the site time zone (`system.date:timezone.default`). The first
entry is the submission, so its timestamp is also the report's creation date.

- `time: 'HH:MM'` on a history entry sets that entry's time. Quote it.
- `time` on a report sets the submission time; it is the same as `time` on
  the first history entry, so set only one of the two.
- Without a time, the entry gets one between 07:00 and 18:30, derived from
  the report key and the entry position. It is the same on every run, so a
  reset does not shuffle the history. A later entry on the same day comes at
  least 15 minutes after the one before it.
- An explicit time on the same day as the entry before it must be later.
- Nothing is dated in the future: an entry of today (`days_ago: 0`) with a
  time that has not come yet is placed at the time of the run.

Give times when they tell the story, for example reports on the way to school
in the morning and answers of a volunteer initiative in the evening.

Writing guidelines: keep reporter fields empty (the seed has no fields for
them), use fictional places and people, use the workspace wording, and do not
promise functions the product does not have.

## Photos

`images/school-routes-*.jpg` are AI-generated with OpenAI image generation via
Codex on 2026-10-18 for Civic Patches. They show fictional scenes: no
recognisable faces, no readable number plates, metadata stripped, 1280 px
JPEG. Photos for new seeds follow the same rules. Alt texts describe the
scene, not the category of the report.
