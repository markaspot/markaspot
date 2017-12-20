Geolocation Nominatim Geocoder Wiget
====================================

Provides a [Open Street Map - Nominatim](http://wiki.openstreetmap.org/wiki/Nominatim) geocoder with a
[Leaflet](https://drupal.org/project/leaflet) map widget for [Geolocation](https://drupal.org/project/geolocation) fields.

Installation
------------

1. Download and install [geolocation](https://drupal.org/project/geolocation) and [leaflet](https://drupal.org/project/leaflet) modules.

2. Download https://github.com/perliedman/leaflet-control-geocoder/releases/tag/v1.5.1 and extract the download to `/libraries/leaflet-control-geocoder/`

3. Enable Geolocation Nominatim

Usage
-----

Set any geolocation field to use the Geolocation Nominatim widget. In the widget settings, set up the default map view
(lat/lon and zoom) for the widget.

The field widget is a map with a search bar. Use the search bar to search for a location. You can also click on the map
set the location, or drag the marker around.

Experimental features
---------------------

There's an experimental feature to populate an [Address](https://drupal.org/project/address) field with the result of
the geocode search (and also the results of a reverse geocode search when clicking on the map). The feature can be
enabled in the widget settings. It likely doesn't work for all scenarios and countries. It won't work with multiple or
multi-value address fields or other widgets than the default address widget.
