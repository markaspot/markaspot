/**
 * @file
 * Loads the ESM-only MapLibre runtime before the Leaflet adapter and widget.
 */

import * as maplibregl from '/libraries/maplibre-gl/dist/maplibre-gl.mjs';

globalThis.maplibregl = maplibregl;

await import('/libraries/maplibre--maplibre-gl-leaflet/dist/leaflet-maplibre-gl.js');
await import('./geolocation_nominatim.widget.js');
