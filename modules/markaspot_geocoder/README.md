# Mark-a-Spot Geocoder

Server-side geocoding for Mark-a-Spot service requests. The node presave hook
automatically resolves coordinates to addresses and district fields. Importers
can explicitly call the service for address to coordinate lookup; address-only
nodes are not forward-geocoded automatically.

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

## Service API

```php
/** @var \Drupal\markaspot_geocoder\Service\MarkaspotGeocoderService $geocoder */
$geocoder = \Drupal::service('markaspot_geocoder.geocoder');

// Reverse geocoding: coordinates to address and district metadata.
$address = $geocoder->getAddressFromCoordinates(51.4324, 6.7652);

// Forward geocoding: address to coordinates, explicit caller opt-in.
$coordinates = $geocoder->getCoordinatesFromAddress('Friedrich-Ebert-Strasse 134, Duisburg', [
  'country' => 'DE',
]);
```

The presave hook remains reverse-only by design. If an importer receives
address-only data, it must opt into `getCoordinatesFromAddress()`. The service
returns the provider's first coordinate candidate only; callers must decide
whether that is acceptable for their workflow and validate boundaries before
writing `field_geolocation`.
