# Mark-a-Spot CAP Export Module

This module provides CAP 1.2 (Common Alerting Protocol) export functionality for Mark-a-Spot service requests during emergency situations.

## Overview

The CAP module allows emergency citizen reports to be exported in CAP 1.2 XML format, which is the international standard for emergency alerting. This enables integration with emergency management systems, disaster response tools, and alert aggregation platforms.

## Features

- **CAP 1.2 Compliant**: Generates valid CAP 1.2 XML according to OASIS standard
- **Emergency Mode Gating**: CAP endpoints only available when emergency mode is active
- **Scoped Current Revision**: Emergency state uses the root jurisdiction, while report data keeps the requested child subtree and excludes reports created before the current activation
- **Standalone Controller**: No dependencies on markaspot_open311 request handling
- **REST API Endpoints**:
  - `/api/cap/v1/alerts` - List of all emergency reports
  - `/api/cap/v1/alerts/{id}` - Single emergency report
- **Restricted Metadata Mapping**:
  - system-generated `request_id` → `identifier`
  - staff-maintained category → `event` and neutral `headline`
  - creation time → `sent` (ISO 8601)
  - `field_hazard_level` (0-4) → `severity`
  - coarsened geolocation → `circle` (format: "lat,long 1")
  - no citizen title, body, reporter data, or street address is exported

## Installation

1. Enable the module:
   ```bash
   ddev drush en markaspot_cap -y
   ```

2. Clear cache:
   ```bash
   ddev drush cr
   ```

CAP feeds use a server-enforced `Restricted` dissemination scope and contain
only staff-maintained category metadata plus coarsened locations. They do not
export citizen-authored titles or report bodies. Grant the restricted `access
emergency CAP feed` permission only to trusted platform-wide emergency-response
integrations or platform staff. The
permission is intentionally global and does not infer tenant membership from
the requested jurisdiction. Do not grant it to tenant-scoped roles until a
root-membership or integration-scope access policy is added. Routes resolve the
requested jurisdiction, require its emergency mode to be active, and the
controller rechecks the same state snapshot before querying reports.

Citizen submissions are never alerts automatically. A staff member with the
generated field permission must explicitly enable **Publish as CAP alert** on a
service request. The feed also rechecks that its category remains in the active
root-jurisdiction catalog.

Public dissemination and any CAP message containing report text require a
separate, staff-curated and redacted publication workflow. This module does not
provide that workflow or a configuration switch to expose citizen free text.

Atom feed metadata advances monotonically when an approved report enters,
changes, leaves, or moves between scoped feeds, when a scoped category changes,
or when the root emergency profile changes. Empty feeds therefore still expose
a reliable `updated` value for downstream pollers. Per-root lock serialization
prevents concurrent entity hooks from moving that timestamp backwards.

The approval control currently lives on the Drupal service-request edit form.
The Nuxt dashboard does not yet expose `field_cap_publish`; treat a dashboard
approval control as a separate staff-workflow enhancement.

### Updating an existing installation

Run database updates after deploying this module version:

```bash
ddev drush updb -y
ddev drush cr
```

The install and update create `node.service_request.field_cap_publish`, keep
its default value disabled, and use custom Field Permissions. By default only
administrator roles can approve CAP publication. To delegate approval, add the
staff role ID to `approval_roles` in `markaspot_cap.settings`, then grant that
role exactly `view field_cap_publish` and `edit field_cap_publish`. No other
role may receive any `field_cap_publish` permission. A rollout smoke must prove
both cases: a new citizen report is absent by default, and the same report
appears only after configured staff approval while its emergency category
remains active.

When CAP is first enabled through `drush cim`, config entities are imported
after the module install hook. The module remains safely disabled until the
post-import readiness audit runs. Run `ddev drush cron` after `drush cim` (or
wait for the normal scheduler) to complete that audit.

The feed remains fail-closed until this update completes. If a compatible
same-name field already contains approved rows, the update stops and asks for
an explicit data migration instead of treating unknown values as alerts.

## Usage

### Activate Emergency Mode

CAP endpoints are only available when emergency mode is active:

```bash
ddev drush markaspot:emergency:activate --jurisdiction=amsterdam --mode-type=disaster
```

### Access CAP Feeds

Once emergency mode is active, authenticated users with the restricted CAP
permission can access the feeds:

- **All Reports**: `https://your-site.com/api/cap/v1/alerts?jurisdiction_id=amsterdam`
- **Single Report**: `https://your-site.com/api/cap/v1/alerts/{request-id}?jurisdiction_id=amsterdam`

If emergency mode is not active for the resolved root jurisdiction, endpoints
return `403 Forbidden`.

## Example CAP Output

```xml
<?xml version="1.0" encoding="UTF-8"?>
<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
  <identifier>1-2025</identifier>
  <sender>noreply@example.com</sender>
  <sent>2025-01-14T12:00:00Z</sent>
  <status>Actual</status>
  <msgType>Alert</msgType>
  <scope>Restricted</scope>
  <restriction>Operational emergency responders only</restriction>
  <info>
    <category>Other</category>
    <event>Blocked Roads</event>
    <urgency>Expected</urgency>
    <severity>Severe</severity>
    <certainty>Observed</certainty>
    <effective>2025-01-14T12:00:00Z</effective>
    <headline>Blocked Roads report</headline>
    <senderName>City Emergency Services</senderName>
    <language>en</language>
    <area>
      <areaDesc>City Emergency Services</areaDesc>
      <circle>48.14,11.58 1</circle>
    </area>
  </info>
</alert>
```

## Technical Details

### Components

1. **CapAlertController** (`src/Controller/CapAlertController.php`)
   - Standalone controller for CAP endpoints
   - Handles query building and filtering
   - Returns CAP XML responses with proper Content-Type

2. **CapEncoder** (`src/Encoder/CapEncoder.php`)
   - Serializer encoder for CAP XML format
   - Implements CAP 1.2 namespace and structure
   - Supports single alerts and Atom feeds

3. **CapProcessorService** (`src/Service/CapProcessorService.php`)
   - Converts Drupal service request nodes to CAP format
   - Handles field mapping and data transformation

4. **EmergencyActiveAccessCheck** (`src/Access/EmergencyActiveAccessCheck.php`)
   - Resolves numeric IDs and slugs through the shared jurisdiction hierarchy
   - Gates each route on the selected root jurisdiction state
   - Varies route access by jurisdiction query and root-specific cache tag

### Dependencies

- `markaspot_emergency` - Emergency mode management
- No dependency on `markaspot_open311` request handling

## Configuration

Routes are defined in `markaspot_cap.routing.yml`:

- `markaspot_cap.alerts.index` - `/api/cap/v1/alerts`
- `markaspot_cap.alerts.show` - `/api/cap/v1/alerts/{id}`

No REST resource configuration needed.

## Development

### Testing

```bash
# Activate emergency mode
ddev drush markaspot:emergency:activate --jurisdiction=amsterdam

# Test index endpoint with an authenticated account that has
# access emergency CAP feed
curl -I 'https://dev.ddev.site/api/cap/v1/alerts?jurisdiction_id=amsterdam'

# Test single alert endpoint
curl 'https://dev.ddev.site/api/cap/v1/alerts/{request-id}?jurisdiction_id=amsterdam'

# Deactivate emergency mode (a permitted account receives 403)
ddev drush markaspot:emergency:deactivate --jurisdiction=amsterdam
curl -I 'https://dev.ddev.site/api/cap/v1/alerts?jurisdiction_id=amsterdam'
```

### Debugging

```bash
# Check if routes are registered
ddev drush ev "print_r(array_keys(\Drupal::service('router.route_provider')->getRoutesByNames(['markaspot_cap.alerts.index', 'markaspot_cap.alerts.show'])));"

# Check if services are available
ddev drush ev "print_r(\Drupal::service('markaspot_cap.processor'));"
ddev drush ev "print_r(\Drupal::service('markaspot_cap.serializer.encoder.cap'));"
```

## Standards Compliance

This module implements CAP 1.2 according to:
- OASIS Common Alerting Protocol Version 1.2
- Namespace: `urn:oasis:names:tc:emergency:cap:1.2`

## License

GPL-2.0-or-later

## Author

Holger Kreis for Civic Patches GmbH
