/**
 * @file
 * Loads the ESM-only MapLibre runtime before the Leaflet adapter and widget.
 */

import * as maplibregl from '/libraries/maplibre-gl/dist/maplibre-gl.mjs';

globalThis.maplibregl = maplibregl;

await import('/libraries/maplibre--maplibre-gl-leaflet/dist/leaflet-maplibre-gl.js');
await import('./geolocation_mapbox.widget.js');

// Module scripts run after Drupal's initial behavior attachment. Initialize the
// newly registered widget behavior once for the current document; later AJAX
// attachments continue to use Drupal's normal behavior lifecycle.
globalThis.Drupal?.behaviors?.geolocationMapboxWidget?.attach(
  document,
  globalThis.drupalSettings,
);
