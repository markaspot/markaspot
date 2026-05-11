# Mark-a-Spot Geocoder

Server-side geocoding for Mark-a-Spot service requests. The node presave hook
automatically resolves coordinates to addresses and district fields. Importers
can explicitly call the service for address to coordinate lookup; address-only
nodes are not forward-geocoded automatically.

## Providers

- **Nominatim** (default): Free, OpenStreetMap-based, no API key required.
- **Mapbox**: Requires an access token from mapbox.com.

## Configuration

The recommended way to configure the geocoder is via environment variables.
Drupal configuration is supported as a fallback for single-instance setups
that do not have access to the runtime environment.

### ENV variables (recommended)

```
GEOCODER_PROVIDER=nominatim|mapbox
GEOCODER_API_KEY=your-mapbox-token
GEOCODER_LANGUAGE=de
```

When `GEOCODER_API_KEY` is set, the admin form disables its token field and
the form submit no longer writes the Mapbox token to Drupal configuration.
This keeps tokens out of `drush cex` exports and out of the configuration
storage that ships with the codebase.

### Drupal config (fallback, legacy)

Admin UI at `/admin/structure/markaspot/geocoder/settings`, or via drush:

```bash
drush config:set markaspot_geocoder.settings provider nominatim
drush config:set markaspot_geocoder.settings language de
drush config:set markaspot_geocoder.settings mapbox_token pk.eyJ...
```

The admin form uses a password input for the Mapbox token: the stored value
is never echoed back into the page, an empty submit preserves the existing
stored token, and only a non-empty submit replaces it. Future releases may
remove the config-stored token in favour of `GEOCODER_API_KEY` only.

### Fallback chain

1. ENV variable (e.g. `GEOCODER_PROVIDER`)
2. Drupal config (`markaspot_geocoder.settings`)
3. Default: `nominatim` provider, `de` language

An unknown `GEOCODER_PROVIDER` value falls back to Nominatim and logs a
warning to the `markaspot_geocoder` channel. Behaviour stays fail-open so a
typo in the environment never blocks report saves.

## Logging policy

This module is invoked on every service-request save and runs against
third-party providers. The logging policy is deliberately conservative:

- Provider exception messages are never surfaced verbatim. The Mapbox
  request URL carries the access token in its query string, and the
  underlying `willdurand/geocoder` exception builder embeds the URL into
  its message. Logs and re-thrown exceptions therefore carry only the
  exception class, file, and line.
- Mapbox error responses (HTTP 401, 403, 429, ...) are detected
  explicitly and re-thrown with a short, token-free summary so operators
  see token rotation and rate-limit issues instead of silent "no result".
- The "no address found" path rounds coordinates to three decimals,
  roughly 110 metres, before writing them to watchdog. Service-request
  coordinates are PII and are not persisted at full precision in logs.

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
address-only data, it must opt into `getCoordinatesFromAddress()`. The
service returns the provider's first coordinate candidate only; callers
must decide whether that is acceptable for their workflow and validate
boundaries before writing `field_geolocation`.
