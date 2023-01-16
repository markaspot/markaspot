/**
 * @file
 */

(function ($) {
  Drupal.geolocationNominatimWidgetMap = {};

  Drupal.geolocationNominatimWidget = function (mapSettings, context, updateCallback) {
    // Only init once.
    if ($('#' + mapSettings.id).hasClass('leaflet-container')) {
      return;
    }
    // Init map.
    Drupal.geolocationNominatimWidget.map = L.map(mapSettings.id, {
      fullscreenControl: mapSettings.fullscreenControl,
      dragging: !L.Browser.mobile,
      zoomControl: !L.Browser.mobile
    })
      .setView([
        mapSettings.centerLat,
        mapSettings.centerLng
      ], 18);
    const map = Drupal.geolocationNominatimWidget.map;
    let tileLayer;
    if (mapSettings.wmsLayer === "") {
      tileLayer = L.tileLayer(mapSettings.tileServerUrl);
    } else {
      tileLayer = L.tileLayer.wms(mapSettings.tileServerUrl, { layers: mapSettings.wmsLayer });
    }
    map.attributionControl.addAttribution(
      mapSettings.customAttribution
    );

    map.addLayer(tileLayer);

    const locateOptions = {
      position: 'bottomright'
    };

    const lc = L.control.locate(locateOptions).addTo(map);

    // Check for ongoing validation and autolocate settings combination.
    if (mapSettings.autoLocate && !$('.messages')[0]) {
      lc.start();
    }

    function onLocationFound(e) {
      map.stopLocate();
      reverseGeocode(e.latlng);
    }

    map.on('locationfound', onLocationFound);

    // Define provider.
    // https://nominatim.org/release-docs/latest/api/Search/#parameters
    const provider = new GeoSearch.OpenStreetMapProvider({
      params:{
        'access_token': mapSettings.mapboxToken,
        'country': mapSettings.limitCountryCodes,
        'accept-language': mapSettings.limitCountryCodes,
        'viewbox': mapSettings.limitViewbox,
        'limit': 15,
        'bounded': 1,
        'addressdetails': 1
      },
      searchUrl: mapSettings.serviceUrl + 'search/',
    });

    // Add Control.
    const search = GeoSearch.GeoSearchControl({
      style: 'bar',
      provider: provider, // required
      /* position: 'topright', */
      showMarker: true, // optional: true|false  - default true
      showPopup: true, // optional: true|false  - default false
      marker: {
        // optional: L.Marker    - default L.Icon.Default
        icon: new L.Icon.Default(),
        draggable: false,
      },
      popupFormat: ({ query, result }) => parseResult(result), // optional: function    - default returns result label,
      resultFormat: ({ result }) => parseResult(result), // optional: function    - default returns result label
      maxMarkers: 1, // optional: number      - default 1
      retainZoomLevel: true, // optional: true|false  - default false
      animateZoom: true, // optional: true|false  - default true
      autoClose: false, // optional: true|false  - default false
      searchLabel: Drupal.t('Street name'), // optional: string      - default 'Enter address'
      keepResult: true, // optional: true|false  - default false
      updateMap: true, // optional: true|false  - default true
    });

    function parseResult(result) {
      if (result.raw.address) {
        const address = result.raw.address
        return address.house_number ? `${address.road} ${address.house_number}, ${address.postcode} ${address.city} ${address.suburb}` : `${address.road}, ${address.postcode} ${address.city} ${address.suburb}`;
      }
    }
    // Init geocoder.
    var geocodingQueryParams = {};
    if (mapSettings.limitCountryCodes != '' || mapSettings.limitViewbox != '') {
      geocodingQueryParams = {
        'key': mapSettings.LocationIQToken,
        'countrycodes': mapSettings.limitCountryCodes,
        'viewbox': mapSettings.limitViewbox,
        'bounded': 1,
        'limit': 100,
        'city': mapSettings.city
      };
    }
    map.addControl(search);

    const handleResult = result => {
      const location = Drupal.geolactionNominatimParseReverseGeo(result.location.raw);
      updateCallback(marker, map, location);
      map.removeLayer(marker);
      parseRoad(result);

      $('.geolocation-widget-lng.for--geolocation-nominatim-map')
        .attr('value', result.location.x);
      $('.geolocation-widget-lat.for--geolocation-nominatim-map')
        .attr('value', result.location.y);
    };
    map.on('geosearch/showlocation', handleResult);

    // Init default values.
    if (mapSettings.lat && mapSettings.lng) {
      // Map lat and lng are always set to user defined values or 0 initially.
      // If field values already set, use only those and set marker.
      const fieldValues = {
        lat: $('.geolocation-widget-lat.for--' + mapSettings.id, context).attr('value'),
        lng: $('.geolocation-widget-lng.for--' + mapSettings.id, context).attr('value')
      };

      map.setView([fieldValues.lat, fieldValues.lng], 18);
      reverseGeocode(fieldValues);
    }



    function setMarker(result, latLng) {
      parseRoad(result);
      if (typeof marker !== "undefined") {
        map.removeLayer(marker);
      }
      // Check if method is called with a pair of coordinates to prevent
      // marker jumping to nominatm reverse results lat/lon.
      latLng = latLng ? latLng : result.center;
      marker = L.marker(latLng, {
        draggable: true
      }).addTo(map);

      marker.on('dragend', function (e) {
        updateCallback(marker, map, result);
        reverseGeocode(e.target._latlng, marker);
      });
      updateCallback(marker, map, result);
    }

    // Variable to disable click events on the map while the geocoder is active.

    map.on('click', function (e) {
      search.clearResults();
      if (map._geocoderIsActive) {
        return;
      }
      reverseGeocode(e.latlng);
    });

    function parseRoad(result) {
      let address = '';
      if (result.type == "geosearch/showlocation") {
        address = result.location.raw.address;
      } else {
        address = result;
      }
      let $val = '';
      if (address.road) {
        switch(mapSettings.streetNumberFormat) {
          case "1":
            $val = (address.road ||  '') + ((typeof address.house_number !== "undefined") ? ' ' + address.house_number : '') + ((typeof address.postcode !== "undefined") ? ', ' + address.postcode : '')  + ' ' + (address.city || '');
            break;
          case 0:
            $val = (address.house_number ? address.house_number + ' ' : '') + address.road;
            break;
        }
      } else {
        $val = '';
      }
      const $input = $('.leaflet-control-geosearch form input');
      $input.val($val);
    }

    function reverseGeocode(latlng) {
      const url = mapSettings.serviceUrl + 'reverse/?' + 'lon=' + latlng.lng + "&lat=" + latlng.lat + "&format=json";
      fetch(url).then(function (response) {
        return response.json();
      })
        .then(function (body) {
          const location = Drupal.geolactionNominatimParseReverseGeo(body);
          setMarker(location, latlng);
          updateCallback(marker, map, location);
        });

      $('.field--widget-geolocation-nominatim-widget .geolocation-hidden-lat')
        .attr('value', latlng.lat);
      $('.field--widget-geolocation-nominatim-widget .geolocation-hidden-lng')
        .attr('value', latlng.lng);
    }



    // geocoder.addTo(map);
  };

  Drupal.geolocationNominatimSetAddressField = function (mapSettings, result, context) {
    if (!('road' in result)) {
      return;
    }
    var address = result;
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
    Drupal.geolactionNominatimParseReverseGeo = function (geoData) {
      let address = {};
      if(geoData){
        address = geoData.address
        address.place_name = address.road + " " + address.house_number;
        address.text = geoData.display_name;
      }
      return address;
    },

    Drupal.geolocationNominatimSetAddressDetails = function ($address, details) {
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
