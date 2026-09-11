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
the JSON slug differs. It does not rename the jurisdiction or change its slug.

```sh
drush mas:tenant:import /path/tenant-config.json --jurisdiction=1
drush mas:tenant:import /path/tenant-config.json --jurisdiction=1 --apply
drush mas:tenant:import /path/tenant-config.json --jurisdiction=1 --apply --skip=users
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
reports `member of N other jurisdictions` when this option is used.
In form-only tenants, `org_member` alone is not sufficient for report visibility:
`org-moderator` or `contractor` is additionally required. The plan points this
out but the importer does not grant either role.

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
- Filled tenant values update only platform name, jurisdiction email, address,
  and no other jurisdiction fields. The v1 free-text address is interpreted as a
  German address: `street, five-digit postcode locality`; the tenant label is
  used as the address organisation. Other address shapes retain the complete
  text in address line 1 with country DE.
- Branding, maps, languages, contact details, domains, logos, feature flags and
  `field_nuxt_config` are outside this import. Use the cloud jurisdiction setup
  workflow for those values.

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
Strings are capped at 255 characters, emails at 254 and codes at 32; installed
field storage can impose a stricter code limit, which is checked before writes.

The importer orchestrates entities and the transaction. `TenantConfigValidator`
owns document validation; `TenantImportFieldMapper` owns field representations.
Organisation category sets are planned once using IDs or new-term placeholders
and resolved after category creation. Apply acquires its lock before preparing
the entity context, so each application performs that preparation only once.
