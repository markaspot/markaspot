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
