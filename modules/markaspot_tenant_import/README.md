# Tenant configuration import

## Aktivierung

Dieses optionale Modul ist im Profil standardmäßig deaktiviert. Der Betreiber
aktiviert es vor dem Onboarding ausdrücklich:

```sh
drush en markaspot_tenant_import -y
drush cr
```

Nach dem Onboarding kann es mit `drush pm:uninstall markaspot_tenant_import -y`
wieder deaktiviert werden. Die importierten Inhalte bleiben dabei erhalten.

## Usage

`mas:tenant:import` imports version 1 JSON into an existing jurisdiction. The
explicit group ID selects the target; the plan displays its label and warns when
the JSON slug differs only when `--allow-slug-mismatch` is present. By default,
a mismatch with the target's `field_slug` is rejected. The command never
renames the jurisdiction or changes its slug.

```sh
drush mas:tenant:import /path/tenant-config.json --jurisdiction=1
drush mas:tenant:import /path/tenant-config.json --jurisdiction=1 --apply
drush mas:tenant:import /path/tenant-config.json --jurisdiction=1 --apply --skip=users
drush mas:tenant:import /path/tenant-config.json --jurisdiction=1 --apply --allow-slug-mismatch
```

Without `--apply`, the command validates the file and prints a plan without
saving entities. `--skip` accepts comma-separated `organisations`, `categories`,
`statuses`, and `users`. The complete JSON is still validated. References to
skipped organisations must resolve to existing organisations. Skipped
organisation fields and memberships are left unchanged.

The importer uses category codes, organisation codes, status names, and user
email addresses as case-insensitive identity keys. Memberships and roles are
only added, never removed. Existing passwords are preserved; new accounts get
random passwords that are never printed. The existing Group membership hooks
synchronize the Drupal `tenant_admin` role and derived organisation memberships.
Only new accounts use the email as their login name and start active. Existing
login names, email spelling, passwords and account status are preserved. Blocked
accounts are reported as `blocked, membership skipped` and are not modified.
Existing UID 1, administrators and accounts belonging to jurisdictions outside
the target root are rejected by default. `--allow-cross-tenant-users` explicitly
overrides this guard only; it never renames or unblocks an account. The plan
reports `member of N other jurisdictions` when this option is used. Accounts
with unexpected Drupal roles, `field_all_groups_member`, or the `administer
nodes` permission are privileged too. Privileged and cross-root accounts never
receive profile changes. Other existing accounts only receive first or last
names when the corresponding stored field is empty.
In form-only tenants, `org_member` alone is not sufficient for report visibility:
`org-moderator` or `contractor` is additionally required. The plan points this
out but the importer does not grant either role.

`org_moderator` is an explicit organisation-scoped alternative: it requires one
`organisation_code`, grants the Drupal `contractor` role, a `jur-org_member`
membership and a plain membership in exactly that organisation. The installed
`org-contractor` insider role supplies report access through that membership.
It does not grant global moderation, editorial or tenant-administration rights.
Preflight rejects administrative contractor roles, permissions marked
`restrict access`, and permissions beginning with `administer `. The two shipped
scoped capabilities `add dashboard status notes` and
`use service request management form` are explicit exceptions only when their
definitions identify `markaspot_dashboard` and `markaspot_ui` respectively as the
provider. Missing or conflicting definitions are rejected. Permission
definitions cover new modules and custom restricted permissions without an
evolving denylist. Permissions that an operator revoked are never restored.
The contractor access model prevents access to other organisations' reports,
reporter contact data, internal remarks and responsibility reassignment.

Existing `org_moderator` accounts must have no other Drupal roles beyond
`authenticated` and `contractor`, no elevated group roles, and no memberships
outside the declared jurisdiction and organisation. UID 1 and all-groups
accounts are also rejected. This guard cannot be overridden with
`--allow-cross-tenant-users`. The importer neither removes existing authority
nor silently migrates another import role into organisation moderation. V1
rejects existing memberships without the `contractor` role rather than silently
converting an `org_member` account. An unprivileged account without memberships
can be assigned for the first time. V1
accepts one organisation per account and rejects duplicate email rows.
`tenant_admin` remains the jurisdiction-wide role for municipal administration.
For dedicated installations, the installer provisions the technical UID 1
administrator separately; the source `users` list contains municipal accounts.
The generic import's existing `--allow-cross-tenant-users` semantics for other
source roles remain unchanged, including privileged-account reuse. The strict
`org_moderator` guard always rejects UID 1 regardless of that option. Optional
top-level `provisioning` metadata is ignored by this module; only the dedicated
installer consumes it.

`--send-mails` requires `--apply`. It sends password-reset emails only for newly
created accounts after the transaction commits. Mail delivery failures return
exit code 1 but do not roll back the committed entities. Repeating the import
does not resend mail to existing accounts; use the normal password-reset flow
to retry delivery. Without the option, no password-reset emails are sent.

Entity writes use a transaction. Application errors are collected per row and
roll back the complete import. Validation failures occur before entity writes.
Concurrent applications of this command are serialized with a Drupal lock.
Other entity writers are not covered by that lock. Third-party hook side effects
outside the database cannot be rolled back.

## Data-model mappings

- Categories and statuses are owned through term `field_jurisdiction` and
  selected by the jurisdiction's `field_service_categories` and
  `field_service_statuses`. Category `field_category_gid` references the
  responsible organisation. Category attributes become an Open311
  `{"attributes": [...]}` definition with default variable, order, and values.
- `kind: initial` maps to `field_open311_mapping: initial`; other statuses use
  `open311`. Citizen notification maps to `status_open` or `status_closed` when
  enabled and to an empty key when disabled. Existing status definitions are
  preserved because v1 does not supply status attributes; new definitions use
  `{"attributes": []}`.
- An omitted existing initial status causes a validation error. Include it in
  the JSON with its intended kind to leave exactly one initial status in the
  resulting root jurisdiction. Unmentioned ordinary statuses are preserved.
- An inactive category is not created. Existing inactive categories are removed
  from the target selection, retained as entities, and removed from imported
  organisations' category lists. Removing the last selected category is rejected
  because an empty selection means inheritance of all root categories.
  On first import, adding terms changes an empty inherited selection into an
  explicit list. This freezes the selected set; later root terms are not
  automatically included. An already matching inherited selection stays empty.
- The profile normalizes organisation ownership to root jurisdictions. Imports
  through a child jurisdiction therefore reject organisation and taxonomy
  sections and organisation-linked users, preventing implicit shared changes or
  root membership grants. Use the root GID for those imports. Child-specific
  tenant fields and non-organisation users can be imported with the shared
  sections skipped.
- Organisation `type` has no field in this model and is reported as unmapped.
  First and last names are mapped only if `field_first_name` and `field_last_name`
  exist; otherwise the plan says `names not mapped`, without printing names.
- Filled tenant values update platform name, jurisdiction email and address.
  Supported runtime values also merge into `field_nuxt_config` as described below. The v1 free-text address is interpreted as a
  German address: `street, five-digit postcode locality`; the tenant label is
  used as the address organisation. Other address shapes retain the complete
  text in address line 1 with country DE.
- Branding colours, font families, map coordinates, languages and supported
  feature flags are included. Contact metadata, domains, SMTP and legal URLs are
  informational and produce unapplied warnings. PNG logos are handled by the
  dedicated bootstrap command with an explicit assets directory.

No cache rebuild runs inside the command. After first deploying the new service,
perform the normal Drupal cache rebuild for command discovery. The output also
suggests `drush cr` if newly created terms are not immediately visible.

## Supplied example

`tests/fixtures/tenant-config.example.json` is an unchanged copy of the current
specification, including category `3`. The Kernel test imports it directly. A
separate negative test deliberately removes that parent to test validation.

The v1 attribute whitelist is `code`, `datatype`, `description`, `required`,
`variable`, `order`, `values`, and `media_type`. Unknown keys are discarded, not
stored as unchecked JSON. Values retain only their `key` and `name` properties.
On existing categories, imported attributes are merged by code. Existing
attribute keys such as `media_group` and existing attributes omitted from the
JSON are preserved. A non-empty stored definition is never replaced by an empty
one. Unsupported tenant properties are listed as skipped notice rows.
Strings are capped at 255 characters, emails at 254 and codes at 32; installed
field storage can impose a stricter code limit, which is checked before writes.

The importer orchestrates entities and the transaction. `TenantConfigValidator`
owns document validation; `TenantImportFieldMapper` owns field representations.
Organisation category sets are planned once using IDs or new-term placeholders
and resolved after category creation. Apply acquires its lock before preparing
the entity context, so each application performs that preparation only once.

## Dedicated installation bootstrap

`mas:tenant:bootstrap` complements the existing import command. It creates the
first permanent root jurisdiction on an installed dedicated Drupal stack. It is
not SaaS signup and never enables FastMap, billing, expiry or demo reports.

```sh
drush mas:tenant:bootstrap /input/tenant-config.json --assets-dir=/input/assets --format=json
drush mas:tenant:bootstrap /input/tenant-config.json --assets-dir=/input/assets --format=json --apply
```

The default is a read-only preview. JSON output is a one-row list containing
`jurisdiction_id` (null before creation), `action`, `applied`, `warnings` and
`rows`. Fresh-root preview checks input and prerequisites; entity-dependent
import checks execute inside the apply transaction. A failed import rolls back
the root, taxonomy, users, memberships and ownership marker. No mail is sent.

Prerequisites: explicit `markaspot_operating_mode=self_hosted`, FastMap absent,
profile fields installed, requested Drupal languages installed, and the GeoReport
key already linked to the active `api_user` account with no extra privileged
roles. The key must come from the secret store and is never displayed. Fresh
bootstrap rejects all existing groups. Subsequent runs accept only the sole
root whose UUID and slug match the transactional ownership marker. Existing
jurisdictions are never adopted. Application administrators come from the
existing `users` input; a document without users creates no tenant admin.

Runtime input supported by both bootstrap and subsequent imports:

- `primary_color`, `secondary_color`: six-digit hex colours; blank preserves.
- `font_family`: letters, numbers, spaces, commas and hyphens. Applies the family
  stack to headings and body; does not install licensed font files.
- `map_center`: `[longitude, latitude]`, and `map_zoom`: integer 1..22. Both are
  mandatory for bootstrap. Address geocoding is deliberately not implicit.
- `languages`: supported UI locale codes, first entry is default; lists replace.
- `features`: boolean `aiAnalysis`, `aiProcessing`, `operationsDashboard`,
  `statistics`, `photoReporting`, `classicReporting`, `dashboard`, `feedback`.
  Platform flags such as `passwordless` are configured by stack orchestration.
- Existing platform name, email, address, client name and short name.

`logo_file` is applied by bootstrap only. It requires `--assets-dir`, realpath
containment, and a valid PNG no larger than 500 KiB or 4096 pixels per side.
Content-addressed files make repeat imports idempotent. Failed writes remove only
newly created logo bytes; existing logo files are preserved. SVG and font upload
are not supported by this command. The single supplied logo is used for both
light and dark themes. A separately configured dark logo is preserved on repeat
imports; a dark logo that follows the previous light logo follows its replacement.

Unspecified runtime keys are preserved. Informational workbook values, including
legal/privacy URLs, font descriptions, SMTP data and map addresses, are reported
as unapplied warnings. Legal content still needs to be supplied separately.
`publicReports=true` warns that the existing access policy remains in force;
`false` is rejected by bootstrap because no runtime visibility switch implements
that policy. Existing imports accept it as metadata with an explicit unapplied
warning. Bootstrap also rejects child jurisdictions: this command manages exactly
one municipality on a dedicated stack.
No input is silently described as providing access-control guarantees.
