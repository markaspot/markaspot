/**
 * @file
 */

(function ($) {
  Drupal.geolocationNominatimWidget = function (mapSettings, context, updateCallback) {
    // Only init once.
    if ($('#' + mapSettings.id).hasClass('leaflet-container')) {
      return;
    }
    // Init map.
    var map = L.map(mapSettings.id, {
      fullscreenControl: true,
    })
      .setView([
        mapSettings.centerLat,
        mapSettings.centerLng
      ], 18);
    L.tileLayer(mapSettings.tileServerUrl, {
      attribution: '&copy; <a href="http://osm.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    var locateOptions = {
      position: 'bottomright'
    };
    var lc = L.control.locate(locateOptions).addTo(map);

    // Check for ongoing validation and autolocate settings combination.
    if (mapSettings.autoLocate && !$('.messages')[0]) {
      lc.start();
    }

    function onLocationFound(e) {
      map.stopLocate();
      reverseGeocode(e.latlng);
    }


    $('#locateMe').click(function(){
      lc.start();
    });


    map.on('locationfound', onLocationFound);

    // Init geocoder.
    var geocodingQueryParams = {};
    if (mapSettings.limitCountryCodes != '' || mapSettings.limitViewbox != '') {
      geocodingQueryParams = {
        'countrycodes': mapSettings.limitCountryCodes,
        'viewbox': mapSettings.limitViewbox,
        'bounded': 1,
        'limit': 5,
        'city': mapSettings.city
      };
    }

    var geocoderNominatim = new L.Control.Geocoder.NominatimCity({
      // Todo: Make this an optional setting.
      serviceUrl: mapSettings.serviceUrl,
      geocodingQueryParams: geocodingQueryParams,
      reverseQueryParams: {
        extratags: 1,
        namedetails: 0,
        addressdetails: 1
      }
    });
    var geocoder = L.Control.geocoder({
      defaultMarkGeocode: false,
      collapsed: false,
      geocoder: geocoderNominatim,
      placeholder: Drupal.t('Search'),
      errorMessage: Drupal.t('Nothing found.')
    });

    var marker;

    // Init default values.
    if (mapSettings.lat && mapSettings.lng) {
      var result = {
        center: [mapSettings.lat, mapSettings.lng],
        name: mapSettings.label
      };

      // Map lat and lng are always set to user defined values or 0 initially.
      // If field values already set, use only those and set marker.
      var fieldValues = {
        lat: $('.geolocation-widget-lat.for--' + mapSettings.id, context).attr('value'),
        lng: $('.geolocation-widget-lng.for--' + mapSettings.id, context).attr('value')
      };
      var initLatLng = new L.latLng(fieldValues.lat, fieldValues.lng);
      reverseGeocode(initLatLng);

      map.setView([fieldValues.lat, fieldValues.lng], mapSettings.zoom);
    }

    function setMarker(result, latLng) {
      if (marker) {
        map.removeLayer(marker);
      }

      // Check if method is called with a pair of coordinates to prevent
      // marker jumping to nominatm reverse results lat/lon.
      latLng = latLng ? latLng : result.center;

      marker = L.marker(latLng, {
        draggable: true
      }).bindPopup(result.html || result.name).addTo(map).openPopup();
      // map.setView(latLng).setZoom(zoom);
      marker.on('dragend', function (e) {
        updateCallback(marker, map, result);
        reverseGeocode(e.target._latlng, marker);
      });
      updateCallback(marker, map, result);
    }

    // Variable to disable click events on the map while the geocoder is active.
    map._geocoderIsActive = false;
    geocoder.on('markgeocode', function (result) {
      this._map.fitBounds(result.geocode.bbox);
      setMarker(result.geocode, result.geocode.center);
      // Set a delay to re-enable click events on the map.
      window.setTimeout(function () {
        map._geocoderIsActive = false
      }, 500);
      marker = L.marker(latLng, {
        draggable: true
      }).bindPopup(result.html || result.name).addTo(map).openPopup();
    });
    geocoder.on('startgeocode', function () {
      map._geocoderIsActive = true;
    });
    map.on('click', function (e) {
      if (map._geocoderIsActive) {
        return;
      }
      reverseGeocode(e.latlng);
    });
    function reverseGeocode(latlng) {
      geocoder.options.geocoder.reverse(latlng, map.options.crs.scale(map.getZoom()), function (results) {
        // Todo: Check if found result is close enough?
        if (results[0]) {
          setMarker(results[0], latlng);

          $('.field--widget-geolocation-nominatim-widget .geolocation-hidden-lat')
            .attr('value', latlng.lat);
          $('.field--widget-geolocation-nominatim-widget .geolocation-hidden-lng')
            .attr('value', latlng.lng);

        }
      });
    }

    geocoder.addTo(map);
  };

  Drupal.geolocationNominatimSetAddressField = function (mapSettings, result, context) {
    if (!('properties' in result && 'address' in result.properties)) {
      return;
    }
    var address = result.properties.address;
    var $form = $('.geolocation-widget-lat.for--' + mapSettings.id, context)
      .parents('form');
    var $address = $form.find('.field--type-address').first();

    // Take care if address field widget is not included in form due to field permissions or theme customization.
    if ($address.length) {
      // Bind to addressfields AJAX complete event.
      $.each(Drupal.ajax.instances, function (idx, instance) {

        // Todo: Simplyfy this check.
        if (instance !== null && instance.hasOwnProperty('callback')
          && instance.callback[0] == 'Drupal\\address\\Element\\Address'
          && instance.callback[1] == 'ajaxRefresh') {
          var originalSuccess = instance.options.success;
          instance.options.success = function (response, status, xmlhttprequest) {
            originalSuccess(response, status, xmlhttprequest);
            var $addressNew = $form.find('.field--type-address').first();
            Drupal.geolocationNominatimSetAddressDetails($addressNew, address);
          }
        }
      });

      if ($('select.country', $address)
          .val()
          .toLowerCase() != address.country_code) {
        $('select.country', $address)
          .val(address.country_code.toUpperCase())
          .trigger('change');
      }
      else {
        Drupal.geolocationNominatimSetAddressDetails($address, address);
      }
    }
  },

    Drupal.geolocationNominatimSetAddressDetails = function ($address, details) {
      if ('postcode' in details) {
        $('input.postal-code', $address).val(details.postcode);
      }

      if ('state' in details) {
        $('select.administrative-area option').each(function () {
          if ($(this).text() == details.state) {
            $(this).attr('selected', 'selected');
          }
        });
      }
      if ('city' in details || 'town' in details || 'village' in details || 'hamlet' in details || 'county' in details || 'neighbourhood' in details) {
        var localityType = details.city || details.town || details.village || details.hamlet || details.county || details.neighbourhood;
        $('input.locality', $address).val(localityType);
      }
      if ('road' in details || 'building' in details || 'footway' in details || 'pedestrian' in details || 'path' in details) {
        var streetType = details.path || details.road || details.footway || details.pedestrian || details.path;
        $('input.address-line1', $address).val(streetType);
        $('input.address-line2', $address).val(details.building);
      }
      if ('house_number' in details) {
        $('input.address-line1', $address)
          .val($('input.address-line1', $address)
            .val() + ' ' + details.house_number);
      }
    },

    Drupal.behaviors.geolocationNominatimWidget = {
      attach: function (context, settings) {
        if (settings.geolocationNominatim.widgetMaps) {
          $.each(settings.geolocationNominatim.widgetMaps, function (index, mapSettings) {
            Drupal.geolocationNominatimWidget(mapSettings, context, function (marker, map, result) {
              $('.geolocation-widget-lat.for--' + mapSettings.id, context).attr('value', marker.getLatLng().lat);
              $('.geolocation-widget-lng.for--' + mapSettings.id, context).attr('value', marker.getLatLng().lng);
              $('.geolocation-widget-zoom.for--' + mapSettings.id, context)
                .attr('value', map.getZoom());
              if (mapSettings.setAddressField) {
                Drupal.geolocationNominatimSetAddressField(mapSettings, result, context);
              }
            });
          });
        }
      }
    }
})(jQuery);
