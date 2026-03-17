# Mark-a-Spot Geocoder

Server-side reverse geocoding for Mark-a-Spot service requests. Automatically resolves coordinates to addresses on node presave.

## Providers

- **Nominatim** (default): Free, OpenStreetMap-based, no API key required.
- **Mapbox**: Requires an access token from mapbox.com.

## Configuration

### ENV variables (highest priority)

```
GEOCODER_PROVIDER=nominatim|mapbox
GEOCODER_API_KEY=your-mapbox-token
GEOCODER_LANGUAGE=de
```

### Drupal config

Admin UI at `/admin/structure/markaspot/geocoder/settings`, or via drush:

```bash
drush config:set markaspot_geocoder.settings provider nominatim
drush config:set markaspot_geocoder.settings language de
drush config:set markaspot_geocoder.settings mapbox_token pk.eyJ...
```

### Fallback chain

1. ENV variable (e.g. `GEOCODER_PROVIDER`)
2. Drupal config (`markaspot_geocoder.settings`)
3. Default: `nominatim` provider, `de` language
