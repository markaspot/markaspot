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
  "task": { "en": "…", "es": "…" }
}
```

- The `translations` key is the Open311 `service_request_id`, which is the
  node's `request_id` field (`<seq>-<year>` by default, optionally with the
  jurisdiction's `field_request_id_prefix`, e.g. `BONN-7-2026`).
- `title` is the display title. The node title itself is system owned
  (`#<request_id> <category>`) and never carries the seed title.
- `statusNotes` is index-aligned with the report's `status_notes` as Open311
  returns them (oldest first).
- Every language of the seed is present, including the default language.
- `task` is also appended to the start page body as a bold paragraph.

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
        note: { en: …, … }
```

Writing guidelines: keep reporter fields empty (the seed has no fields for
them), use fictional places and people, use the workspace wording, and do not
promise functions the product does not have.
