/**
 * @file
 */

(function ($) {
  Drupal.geolocationMapboxWidgetMap = {};

  Drupal.geolocationMapboxWidget = function (mapSettings, context, updateCallback) {
    // Only init once.
    if ($('#' + mapSettings.id).hasClass('leaflet-container')) {
      return;
    }
    // Init map.

    Drupal.geolocationMapboxWidgetMap.map = L.map(mapSettings.id, {
      fullscreenControl: mapSettings.fullscreenControl,
      dragging: !L.Browser.mobile,
      zoomControl: !L.Browser.mobile
    }).setView([
        mapSettings.centerLat,
        mapSettings.centerLng
      ], 18);

    const map =  Drupal.geolocationMapboxWidgetMap.map;
    if (mapSettings.mapboxStyle !== "") {
      const tileLayer = L.mapboxGL({
        accessToken: mapSettings.mapboxToken,
        style: mapSettings.mapboxStyle
      }).addTo(map);
    } else {
      const tileLayer = L.tileLayer.wms(mapSettings.tileServerUrl, { layers: mapSettings.wmsLayer }).addTo(map);
    }
    map.attributionControl.addAttribution(
      mapSettings.customAttribution
    );

    const locateOptions = {
      position: 'bottomright'
    };
    const lc = L.control.locate(locateOptions).addTo(map);
    // Check for ongoing validation and autolocate settings combination.
    if (mapSettings.autoLocate && !$('.messages')[0]) {
      lc.start();
    }
    $('#locateMe').click(function () {
      lc.start();
    });
    // Define provider.
    const provider = new GeoSearch.MapBoxProvider({
        params:{
        'access_token': mapSettings.mapboxToken,
        'country': mapSettings.limitCountryCodes,
        'language': mapSettings.limitCountryCodes,
        'bbox': [mapSettings.limitViewbox],
        'bounded': 1,
        'limit': 5,
        'city': mapSettings.city
        }
    });

    // Add Control.
    const search = GeoSearch.GeoSearchControl({
      style: 'bar',
      provider: provider, // required
      /* position: 'topright', */
      showMarker: true, // optional: true|false  - default true
      showPopup: false, // optional: true|false  - default false
      marker: {
        // optional: L.Marker    - default L.Icon.Default
        icon: new L.Icon.Default(),
        draggable: false,
      },
      popupFormat: ({ query, result }) => result.label, // optional: function    - default returns result label,
      resultFormat: ({ result }) => result.label, // optional: function    - default returns result label
      maxMarkers: 1, // optional: number      - default 1
      retainZoomLevel: false, // optional: true|false  - default false
      animateZoom: false, // optional: true|false  - default true
      autoClose: true, // optional: true|false  - default false
      searchLabel: Drupal.t('Street name'), // optional: string      - default 'Enter address'
      keepResult: true, // optional: true|false  - default false
      updateMap: true, // optional: true|false  - default true
    });
    console.log(search)
    map.addControl(search);

    const handleResult = result => {
      const location = Drupal.geolactionMapboxparseReverseGeo(result.location.raw);
      updateCallback(marker, map, location);

      $('.geolocation-widget-lng.for--geolocation-mapbox-map')
        .attr('value', result.location.x);
      $('.geolocation-widget-lat.for--geolocation-mapbox-map')
        .attr('value', result.location.y);
    };

    map.on('geosearch/showlocation', handleResult);

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
      //setMarker(result, initLatLng);
      map.setView([fieldValues.lat, fieldValues.lng], mapSettings.zoom);
    }

    function setMarker(result, latLng) {

      if (typeof marker !== 'undefined') {
        // map.removeLayer(marker);
      }
      // Check if method is called with a pair of coordinates to prevent
      // marker jumping to nominatim reverse results lat/lon.
      latLng = latLng ? latLng : result.center;
      marker = L.marker(latLng, {
        draggable: true
      }).addTo(map).openPopup();
      // map.setView(latLng);
      marker.on('dragend', function (e) {
        updateCallback(marker, map, result);
        reverseGeocode(e.target._latlng, marker);
      });
      updateCallback(marker, map, result);
    }

    map.on('click', function (e) {
      search.clearResults();
      reverseGeocode(e.latlng);
    });


    function reverseGeocode(latlng) {
      const url = "https://api.mapbox.com/geocoding/v5/mapbox.places/" + latlng.lng + "," + latlng.lat + ".json?types=address&access_token=" + mapSettings.mapboxToken;
      fetch(url).then(function (response) {
        return response.json();
      })
      .then(function (body) {
        const location = Drupal.geolactionMapboxparseReverseGeo(body.features[0]);
        updateCallback(marker, map, location);
        setMarker(body, latlng)
      });

      $('.field--widget-geolocation-mapbox-widget .geolocation-hidden-lat')
        .attr('value', latlng.lat);
      $('.field--widget-geolocation-mapbox-widget .geolocation-hidden-lng')
        .attr('value', latlng.lng);
    }
  };

  Drupal.geolocationMapboxSetAddressField = function (mapSettings, location, context) {
    if (!('place_name' in location)) {
      return;
    }
    const address = location;
    const $form = $('.geolocation-widget-lat.for--' + mapSettings.id, context)
      .parents('form');
    const $address = $form.find('.field--type-address').first();

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
            Drupal.geolocationMapboxSetAddressDetails($addressNew, address);
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
        Drupal.geolocationMapboxSetAddressDetails($address, address);
      }
    }
  },

    Drupal.geolocationMapboxSetAddressDetails = function ($address, details) {
      if ('postcode' in details) {
        $('input.postal-code', $address).val(details.postcode);
      }

      if ('suburb' in details) {
        $('select#edit-field-district option').each(function () {
          if ($(this).text() == details.suburb) {
            $(this).attr('selected', 'selected');
          }
        });
      }

      if ('state' in details) {
        $('select.administrative-area option').each(function () {
          if ($(this).text() == details.state) {
            $(this).attr('selected', 'selected');
          }
        });
      }
      if ('place' in details) {
        $('input.locality', $address).val(details.place);
      }
      if ('text' in details || 'building' in details) {
        if (typeof details.housenumber !== 'undefined') {
          $('input.address-line1', $address).val(details.text + " " + details.housenumber );
        } else {
          $('input.address-line1', $address).val(details.text);
        }
        $('input.address-line2', $address).val(details.building);
      }
      if ('house_number' in details) {
        $('input.address-line1', $address)
          .val($('input.address-line1', $address)
            .val() + ' ' + details.house_number);
      }
    },
    Drupal.geolactionMapboxparseReverseGeo = function (geoData) {
      let address = {};
      if(geoData.context){
        address.housenumber = geoData.address
        address.place_name = geoData.place_name;
        address.text = geoData.text
        $.each(geoData.context, function (i, v) {
          if(v.id.indexOf('region') >= 0) {
            address.region = v.text;
          }
          if(v.id.indexOf('country') >= 0) {
            address.country = v.text;
            address.country_code = v.short_code
          }
          if(v.id.indexOf('postcode') >= 0) {
            address.postcode = v.text;
          }
          if(v.id.indexOf('place') >= 0) {
            address.place = v.text;
          }
        });
      }
      return address;
    },
    Drupal.behaviors.geolocationMapboxWidget = {
      attach: function (context, settings) {
        if (settings.geolocationMapbox.widgetMaps) {
          $.each(settings.geolocationMapbox.widgetMaps, function (index, mapSettings) {
            Drupal.geolocationMapboxWidget(mapSettings, context, function (marker, map, location) {
              $('.geolocation-widget-lat.for--' + mapSettings.id, context).attr('value', marker.getLatLng().lat);
              $('.geolocation-widget-lng.for--' + mapSettings.id, context).attr('value', marker.getLatLng().lng);
              $('.geolocation-widget-zoom.for--' + mapSettings.id, context)
                .attr('value', map.getZoom());
              if (mapSettings.setAddressField) {
                Drupal.geolocationMapboxSetAddressField(mapSettings, location, context);
              }
            });
          });
        }
      }
    }
})(jQuery);
