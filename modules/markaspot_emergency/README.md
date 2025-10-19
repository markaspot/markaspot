# Mark-a-Spot Emergency Module

Admin-controlled system mode switching for emergencies and maintenance.

## Admin UI
- Settings form: `/admin/config/markaspot/emergency`
  - Set `Status` (off/standby/active) and `Mode Type` (disaster/crisis/maintenance)
  - Emergency: unpublish regular categories; publish emergency presets
  - Maintenance: optionally unpublish non-selected categories; keep a selected list published; optional banner + redirect flags for frontend
  - Auto-deactivation window (hours)
  - Restore queue: shows how many categories will be restored when deactivating

## API
- GET `/api/system/status` (public)
  - Returns:
    - `emergency_mode` boolean, `status`, `mode_type`, `lite_ui`
    - `available_categories`: published categories for the current mode
    - `details` (for advanced/admin clients): auto-deactivation, network detection
      - Includes `maintenance.force_redirect` and `maintenance.banner_text`
      - Includes `restore_queue_count` for admins
  - Note: Activation/deactivation is performed via the Drupal admin UI. No admin mutation routes are exposed by default to minimize attack surface. If needed later, admin-only routes can be added.

## Drush
- `emergency:status` — Show current emergency mode status
- `emergency:activate --mode-type=disaster` — Activate emergency mode
- `emergency:deactivate --restore-categories=1` — Deactivate and restore categories

## Frontend Integration (Optional)
- Middleware can read `/api/system/status` to redirect or show a banner.
- `details.maintenance` fields support a non-destructive maintenance mode UX.
