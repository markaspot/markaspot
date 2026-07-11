# Mark-a-Spot Emergency Module

Admin-controlled, jurisdiction-scoped system mode switching for emergencies
and maintenance. The module is part of the open-source Mark-a-Spot backend and
has no FastMap, billing, or subscription-tier coupling.

## Admin UI
- Settings form: `/admin/config/markaspot/emergency`
  - Select and load a root jurisdiction before changing runtime state
  - Runtime status is read-only. Explicit action buttons activate, switch, or deactivate a profile
  - Switch active profiles among `disaster`, `crisis`, and `maintenance` without losing the original restore snapshot
  - Emergency: unpublish regular categories; publish emergency presets
  - Maintenance: optionally unpublish non-selected categories; keep a selected list published; optional banner + redirect flags for frontend
  - Auto-deactivation window (hours)
  - Restore queue: shows how many categories will be restored when deactivating

Operational policy is stored per root jurisdiction in State, including banner,
redirect exceptions, Lite flags, maintenance selection, and auto-deactivation.
Deployable Config provides defaults only. A change for one root therefore
cannot alter another active incident. The restricted global `administer
emergency mode` permission is intended for platform operators; do not grant it
to delegated tenant roles without adding a root-membership access policy.

Banner titles, messages, and maintenance text are stored per administrative
interface language. Load the settings form in each required language and save
that language's wording. Status responses resolve the current request language,
then a legacy language-neutral value, then the site's default language. Existing
single-string State remains available as a language-neutral fallback.

## API
- GET `/api/emergency-mode/status?jurisdiction_id={id-or-slug}` (public)
  - Returns:
    - `contract_version`, numeric root `jurisdiction_id`, and monotonic `revision`
    - `emergency_mode` boolean, `status`, `mode_type`, `lite_ui`
    - `available_categories`: published categories for the current mode
      (`id`, `uuid`, `service_code`, `name`, `label`, display metadata,
      `lite_compatible`)
    - `details` (for advanced/admin clients): auto-deactivation, network detection
      - Includes `maintenance.force_redirect` and `maintenance.banner_text`
      - Includes `restore_queue_count` for admins
  - Note: Activation/deactivation is performed via the Drupal admin UI. No admin mutation routes are exposed by default to minimize attack surface. If needed later, admin-only routes can be added.

`network_detection` is retained as legacy configuration metadata only. Version
2 does not redirect from browser connection heuristics. Operators choose Lite
explicitly through `lite_ui` and `force_redirect`; this avoids unreliable
client-side network guesses changing incident behavior.

## Drush
- `markaspot:emergency:status --jurisdiction=amsterdam`: Show scoped status
- `markaspot:emergency:activate --jurisdiction=amsterdam --mode-type=disaster`: Activate
- `markaspot:emergency:deactivate --jurisdiction=amsterdam`: Deactivate and restore the exact pre-activation category set

Legacy names (`emergency:status`, `emergency:activate`, `emergency:deactivate`, `emer:*`) remain available as aliases.

## Frontend Integration (Optional)
- Middleware can read `/api/emergency-mode/status` to redirect or show a banner.
- `details.maintenance` fields support a non-destructive maintenance mode UX.

## Category model

`field_emergency_modes` is a multi-value list on `service_category`. It is
authoritative when installed and supports `disaster`, `crisis`, and
`maintenance`. The update hook migrates the legacy
`field_emergency_category=true` flag to `disaster` plus `crisis`. The legacy
boolean is read only on installations where the new field is not installed.
The existing service-category edit form exposes the new mode selector at
runtime and hides the legacy boolean without replacing the shared form-display
configuration.

Mode changes are protected by a global transition lock, a per-root lock, and a database transaction. State
stores the exact set of category TIDs that was published before activation, so
deactivation restores that exact set rather than guessing from flags. For
translated categories it also snapshots every language's publication flag,
switches all translations together, and restores the original per-language
state on deactivation.
Maintenance keep-lists are stored per root jurisdiction and cannot select terms
from another tenant.

Every non-operator JSON:API service-request create is scoped server-side. While
an incident is active it may use only a currently published category from its
root. Outside an incident, existing authenticated management workflows retain
their prior draft-category semantics. During an active incident the normal
full frontend keeps its established backend boundary assignment. Whenever a
client submits a jurisdiction, its root must match the category root. Automatic
boundary-based child assignment is restricted to that already verified root
tree.

Lite submissions additionally carry the expected active status, root, and
revision headers plus the verified leaf-jurisdiction relationship. Normal
offline replays carry the exact expected off status, root, and revision. If a
non-operator client removes those headers, Drupal creates its own status and
revision snapshot during request preparation. Removing headers therefore never
removes the transition pin. Only users with the restricted `administer
emergency mode` permission may bypass an unmarked import.

The public JSON:API account does not need general permission to view
jurisdiction groups. During an armed Lite request, a validation-only selection
handler admits exactly the numeric group ID already resolved by the request
guard. The relationship stays on the entity throughout normal validation and
all node-presave hooks, so child boundaries, consent, AI policy, and other
tenant rules still see the exact leaf. Listings and autocomplete continue to
use the configured core selection handler unchanged.

The final generic entity-presave hook acquires the prepared root lock after all
node-specific presave work. It reads State, category publication, category
jurisdictions, and the submitted jurisdiction hierarchy with current SQL row
locks, then releases the root only after the surrounding entity transaction
commits or rolls back. This covers the actual node write and nested insert-hook
saves without holding the root during request parsing or geocoding. Stable
headerless off-mode management creates retain their prior draft-category
semantics. Explicit client pins and every active snapshot require a currently
published category.

Offline attachments are uploaded before the node because JSON:API relationships
need media UUIDs. The queue checks fresh status again after the final upload and
the node write still carries the atomic pin. A transition inside the remaining
upload-to-node interval can reject the node after a media entity already exists.
Closing that storage boundary requires a server-owned upload session with claim
and TTL cleanup, or an atomic multipart submission endpoint.
