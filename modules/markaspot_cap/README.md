# Mark-a-Spot CAP Export Module

This module provides CAP 1.2 (Common Alerting Protocol) export functionality for Mark-a-Spot service requests during emergency situations.

## Overview

The CAP module allows emergency citizen reports to be exported in CAP 1.2 XML format, which is the international standard for emergency alerting. This enables integration with emergency management systems, disaster response tools, and alert aggregation platforms.

## Features

- **CAP 1.2 Compliant**: Generates valid CAP 1.2 XML according to OASIS standard
- **Emergency Mode Gating**: CAP endpoints only available when emergency mode is active
- **Standalone Controller**: No dependencies on markaspot_open311 request handling
- **REST API Endpoints**:
  - `/api/cap/v1/alerts` - List of all emergency reports
  - `/api/cap/v1/alerts/{id}` - Single emergency report
- **Field Mapping**:
  - `service_request_id` → `identifier`
  - `service_name` → `event`
  - `requested_datetime` → `sent` (ISO 8601)
  - `priority` (0-4) → `severity` (Extreme, Severe, Moderate, Minor, Unknown)
  - `lat/long` → `circle` (format: "lat,long 0")
  - `description` → `description`
  - `address` → `areaDesc`

## Installation

1. Enable the module:
   ```bash
   ddev drush en markaspot_cap -y
   ```

2. Clear cache:
   ```bash
   ddev drush cr
   ```

**Note**: No permissions configuration needed. The CAP endpoints use `_access: 'TRUE'` and are gated by the Emergency Mode Subscriber.

## Usage

### Activate Emergency Mode

CAP endpoints are only available when emergency mode is active:

```bash
ddev drush config:set markaspot_emergency.settings emergency_mode.status active -y
```

### Access CAP Feeds

Once emergency mode is active, access the CAP feeds:

- **All Reports**: `https://your-site.com/api/cap/v1/alerts`
- **Single Report**: `https://your-site.com/api/cap/v1/alerts/{request-id}`

If emergency mode is not active, endpoints return `503 Service Unavailable`.

## Example CAP Output

```xml
<?xml version="1.0" encoding="UTF-8"?>
<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
  <identifier>1-2025</identifier>
  <sender>noreply@example.com</sender>
  <sent>2025-01-14T12:00:00Z</sent>
  <status>Actual</status>
  <msgType>Alert</msgType>
  <scope>Public</scope>
  <info>
    <category>Other</category>
    <event>Blocked Roads</event>
    <urgency>Expected</urgency>
    <severity>Severe</severity>
    <certainty>Observed</certainty>
    <effective>2025-01-14T12:00:00Z</effective>
    <headline>Road blocked by fallen tree</headline>
    <description>Large tree has fallen...</description>
    <senderName>John Doe</senderName>
    <language>en</language>
    <area>
      <areaDesc>123 Main St, Munich, 80331</areaDesc>
      <circle>48.1351,11.5820 0</circle>
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

4. **CapFormatSubscriber** (`src/EventSubscriber/CapFormatSubscriber.php`)
   - Kernel event subscriber (priority 99)
   - Detects CAP API paths
   - Enforces emergency mode requirement (503 if not active)

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
ddev drush config:set markaspot_emergency.settings emergency_mode.status active -y

# Test index endpoint
curl -I https://dev.ddev.site/api/cap/v1/alerts

# Test single alert endpoint
curl https://dev.ddev.site/api/cap/v1/alerts/{request-id}

# Deactivate emergency mode (should return 503)
ddev drush config:set markaspot_emergency.settings emergency_mode.status off -y
curl -I https://dev.ddev.site/api/cap/v1/alerts
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
