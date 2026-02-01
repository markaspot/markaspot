# Mark-a-Spot Open311

This module implements the GeoReport v2 (Open311) API for Drupal, enabling service request management through a standardized REST interface.

## Overview

The module provides REST resources that expose Drupal nodes as GeoReport v2 service requests and taxonomy terms as services.

- **Format Support**: JSON (`.json`) and XML (`.xml`) via URL suffix
- **Specification**: [GeoReport v2](http://wiki.open311.org/GeoReport_v2/)

## API Endpoints

### GET /georeport/v2/requests.json

Retrieve service requests with optional filtering.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `limit` | int | Maximum results to return |
| `offset` | int | Pagination offset |
| `status` | string | Filter by status (comma-separated) |
| `service_code` | string | Filter by category code |
| `start_date` | string | Filter by date range start |
| `end_date` | string | Filter by date range end |
| `bbox` | string | Bounding box filter: `minLng,minLat,maxLng,maxLat` |
| `q` | string | Text search in title/body/address |
| `sort` | string | Sort field with optional direction prefix (see below) |
| `extensions` | bool | Enable Mark-a-Spot extensions (includes `meta.total`) |
| `group_filter` | bool | Filter by user's organisation membership (see below) |

### GET /georeport/v2/requests/{id}.json

Retrieve a single service request by ID.

### POST /georeport/v2/requests.json

Create a new service request.

### GET /georeport/v2/services.json

List available service categories.

## Configuration

Configure at: `/admin/config/services/markaspot-open311`

### Status Configuration

Map taxonomy terms to Open311 status values (open/closed).

### Field Access

Configure which fields are exposed via API for different access levels:
- **Public**: Unauthenticated requests
- **User**: Authenticated users
- **Manager**: Users with manager permissions

### Group Integration

When the Group module is installed, enable organisation-based filtering:

- **Enable Group Filtering**: Allow `group_filter` parameter
- **Group Type**: Specify which group type to filter by (default: `organisation`)

## Group-Based Filtering

Filter service requests by user's group membership. Requires:

1. [Group module](https://www.drupal.org/project/group) installed
2. Group type configured (e.g., `organisation`)
3. Service requests assigned to groups via `group_node:service_request` plugin
4. Users as members of organisation groups

### Usage

```
GET /georeport/v2/requests.json?group_filter=1
```

**Behavior:**

| User Type | Result |
|-----------|--------|
| Anonymous | Parameter ignored, returns public requests |
| Authenticated (no groups) | Returns empty result |
| Authenticated (member of orgs) | Returns requests in user's organisations |
| Admin / bypass node access | Returns all requests in user's organisations |

### Permission Model

Group module controls view/edit permissions:

| Group Role | View | Edit |
|------------|------|------|
| Member | All in group | All in group |
| Outsider | All in group | Own only |
| Anonymous | Published only | None |

Configure group permissions at: `/admin/group/types/manage/[type]/permissions`

## Sorting

> **Note:** The Open311 GeoReport v2 standard does not define a sort parameter.
> This is a Mark-a-Spot extension for enhanced usability.

The `sort` parameter supports JSON:API style sorting with a `-` prefix for descending order.

### Supported Sort Fields

| Parameter Value | Entity Field | Description |
|-----------------|--------------|-------------|
| `created` | `created` | Creation date (ascending) |
| `-created` | `created` | Creation date (descending, default) |
| `updated` | `changed` | Last modified date (ascending) |
| `-updated` | `changed` | Last modified date (descending) |
| `status` | `field_status` | Status taxonomy term (ascending) |
| `-status` | `field_status` | Status taxonomy term (descending) |
| `service_code` | `field_category` | Category taxonomy term (ascending) |
| `-service_code` | `field_category` | Category taxonomy term (descending) |
| `nid` | `nid` | Numeric node ID (ascending) |
| `-nid` | `nid` | Numeric node ID (descending) |
| `request_id` | `request_id` | Request ID string (ascending) |
| `-request_id` | `request_id` | Request ID string (descending) |

### nid vs request_id

Use `nid` for proper numeric sorting of request IDs:

- `request_id` is a string (e.g., "47-2026") and sorts alphabetically: 1, 10, 11, 2, 3...
- `nid` is a numeric integer and sorts correctly: 1, 2, 3, 10, 11...

**Recommendation:** Use `sort=-nid` or `sort=nid` when sorting by ID.

### Examples

```
GET /georeport/v2/requests.json?sort=-created     # Newest first (default)
GET /georeport/v2/requests.json?sort=created      # Oldest first
GET /georeport/v2/requests.json?sort=-updated     # Recently updated first
GET /georeport/v2/requests.json?sort=-nid         # Highest ID first (numeric)
GET /georeport/v2/requests.json?sort=nid          # Lowest ID first (numeric)
```

### Backward Compatibility (Deprecated)

The legacy `sort=DESC` and `sort=ASC` values are still supported for backward
compatibility and default to sorting by `created` date. New implementations
should use the JSON:API style format above.

```
GET /georeport/v2/requests.json?sort=DESC  # Deprecated, same as sort=-created
GET /georeport/v2/requests.json?sort=ASC   # Deprecated, same as sort=created
```

## Mark-a-Spot Extensions

Enable with `extensions=true` to get:

```json
{
  "requests": [...],
  "meta": {
    "total": 1234,
    "limit": 20,
    "offset": 0
  }
}
```

## Dependencies

- Drupal Core REST
- Mark-a-Spot Core

### Optional

- Group module (for organisation filtering)
- Group Node module (gnode)

## Development

### Service

The main processing service is `markaspot_open311.processor`:

```php
$processor = \Drupal::service('markaspot_open311.processor');
$query = $processor->createNodeQuery($parameters, $user);
```

### Hooks

- `hook_markaspot_open311_request_alter(&$request, $node)` - Modify API response
- `hook_markaspot_open311_create_alter(&$values, $request_data)` - Modify node creation

## Changelog

See CHANGELOG.md for version history.
