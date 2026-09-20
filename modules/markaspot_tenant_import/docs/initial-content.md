# Initial content and runtime configuration

Dedicated provisioning applies the reviewed configuration after an empty installation. The Hub acceptance workflow performs one configuration import followed by one synthetic demo import. Operational recovery uses the source workbook and its asset files.

`tenant.boilerplates` is an optional list of up to 100 templates. Each row contains a stable `key`, plain-text `title` and `text`, `type` (`status_notes` or `remarks`), and boolean `active`. Imported nodes belong to the jurisdiction and use `plain_text`. Ownership records and Group relationships must agree; missing owned nodes, foreign relationships, duplicates and omitted owned keys fail closed. Deactivate a retained template explicitly instead of omitting its key.

Template readers require staff capabilities and jurisdiction membership. Organisation restrictions apply unless the account administers that jurisdiction. Scoped managers can retain and reactivate inactive templates; the insertion endpoint and frontend picker use active templates only. Writes validate both original ownership and proposed organisation scope.

`tenant.features.unifiedReporting` contains `enabled` (boolean), `aiMode` (`disabled`, `opt_in`, `opt_out`) and `photoPolicy` (`optional`, `required`, `required_by_category`). These values pass through to the shared frontend runtime configuration.

`tenant.features.operationsDashboard` controls the Fachadmin's `access dashboard kpis` permission. Enabling it requires `markaspot_dashboard`; the saved permission is checked. Authentication responses expose the permission to the frontend. Initial optional template manager permissions are established once after configuration installation. A completed setup does not regrant later revocations.

Status colours are explicit `statuses[].hex` values. The workbook converter accepts six-digit hexadecimal values from the status sheet; a missing colour retains its previous default. Existing municipal labels require a source or confirmation and must not be inferred from a colour.

German field descriptions are shipped as Drupal language configuration overrides. The canonical English definitions remain available to other interface languages.
