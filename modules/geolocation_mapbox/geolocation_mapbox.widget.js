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
    let geosearchMarker; // store the GeoSearch marker


    Drupal.geolocationMapboxWidget.map = L.map(mapSettings.id, {
      dragging: mapSettings.dragging,
      zoomControl: mapSettings.zoomControl,
      tab: mapSettings.zoomControl
    });
    const map = Drupal.geolocationMapboxWidget.map;

    let tileLayer;
    if (mapSettings.mapboxStyle !== "") {
      tileLayer = L.mapboxGL({
        accessToken: mapSettings.mapboxToken,
        style: mapSettings.mapboxStyle
      }).addTo(map);
    } else {
      tileLayer = L.tileLayer.wms(mapSettings.tileServerUrl, { layers: mapSettings.wmsLayer }).addTo(map);
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

    function onLocationFound(e) {
      map.stopLocate();
      reverseGeocode(e.latlng);
    }

    // map.on('locationfound', onLocationFound);

    const provider = new GeoSearch.MapBoxProvider({
      params: {
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
      provider: provider,
      showMarker: true,
      showPopup: true,
      marker: {
        icon: new L.Icon.Default(),
        draggable: false
      },
      popupFormat: ({ query, result }) => result.label,
      resultFormat: ({ result }) => result.label,
      maxMarkers: 1,
      retainZoomLevel: false,
      animateZoom: true,
      autoClose: true,
      searchLabel: Drupal.t('Street name'),
      keepResult: true,
      updateMap: true
    });


    function parseResult(result) {
      if (result.raw.address) {
        const address = result.raw.address;
        const addressComponents = [
          address.road,
          address.house_number,
          address.postcode,
          address.city,
          address.suburb,
          address.hamlet,
          address.town,
          address.village,
          address.county
        ];

        const filteredComponents = addressComponents.filter(component => component);
        const addressString = filteredComponents.join(', ');
        return addressString.trim();
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
      geosearchMarker = result.marker; // save the GeoSearch marker
      geosearchMarker.on('dragend', function (e) {
        const newPosition = e.target.getLatLng();
        reverseGeocode(newPosition, geosearchMarker);
      });

      const location = Drupal.geolactionMapboxParseReverseGeo(result.location.raw);
      updateCallback(geosearchMarker, map, location);

      $('.geolocation-widget-lng.for--geolocation-mapbox-map')
        .attr('value', result.location.x);
      $('.geolocation-widget-lat.for--geolocation-mapbox-map')
        .attr('value', result.location.y);
    };





    map.on('click', function (e) {
      search.clearResults();

      // remove the GeoSearch marker if it exists
      if (geosearchMarker) {
        map.removeLayer(geosearchMarker);
        geosearchMarker = null;
      }

      if (map._geocoderIsActive) {
        return;
      }

      // Only call reverseGeocode if a geosearch result is not being shown
      if (!geosearchMarker) {
        reverseGeocode(e.latlng);
      }
    });

    map.on('geosearch/showlocation', handleResult);

    // Init default values.
    if (mapSettings.lat && mapSettings.lng) {
      // Map lat and lng are always set to user defined values or 0 initially.
      // If field values already set, use only those and set marker.
      const fieldValues = {
        lat: $('.geolocation-widget-lat.for--' + mapSettings.id, context).attr('value'),
        lng: $('.geolocation-widget-lng.for--' + mapSettings.id, context).attr('value')
      };

      map.setView([fieldValues.lat, fieldValues.lng], mapSettings.zoom);
      reverseGeocode(fieldValues);
    }



    function setMarker(result, latLng) {
      // parseRoad(result);
      if (typeof marker !== "undefined") {
        map.removeLayer(marker);
      }

      if (geosearchMarker) {
        // If geosearchMarker exists, remove it from the map before creating a new one
        map.removeLayer(geosearchMarker);
      }



      // Check if method is called with a pair of coordinates to prevent
      // marker jumping to nominatm reverse results lat/lon.
      latLng = latLng ? latLng : result.center;
      marker = L.marker(latLng, {
        draggable: true
      }).addTo(map);

      if (result.text) {
        marker.bindPopup(result.place_name).openPopup()
      }
      marker.on('dragend', function (e) {
        const newPosition = e.target.getLatLng();
        if (newPosition.lat !== latLng.lat || newPosition.lng !== latLng.lng) {
          reverseGeocode(newPosition, marker);
        } else {
          updateCallback(marker, map, result);
        }
      });

      updateCallback(marker, map, result);
    }
    
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
      const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${latlng.lng},${latlng.lat}.json?types=address&access_token=${mapSettings.mapboxToken}`;
      fetch(url)
        .then(response => response.json())
        .then(body => {
          const location = Drupal.geolactionMapboxParseReverseGeo(body.features[0]);
          setMarker(location, latlng);
          updateCallback(marker, map, location);
        });

      $('.field--widget-geolocation-mapbox-widget .geolocation-hidden-lat').attr('value', latlng.lat);
      $('.field--widget-geolocation-mapbox-widget .geolocation-hidden-lng').attr('value', latlng.lng);
    }
  };

  Drupal.geolocationMapboxSetAddressField = function (mapSettings, location, context) {
    if (!('place_name' in location)) {
      return;
    }

    const address = location;
    const $form = $('.geolocation-widget-lat.for--' + mapSettings.id, context).parents('form');
    const $address = $form.find('.field--type-address').first();

    if ($address.length) {
      // Bind to addressfields AJAX complete event.
      $.each(Drupal.ajax.instances, (idx, instance) => {
        // Todo: Simplify this check.
        if (
          instance !== null &&
          instance.hasOwnProperty('callback') &&
          instance.callback[0] == 'Drupal\\address\\Element\\Address' &&
          instance.callback[1] == 'ajaxRefresh'
        ) {
          const originalSuccess = instance.options.success;
          instance.options.success = (response, status, xmlhttprequest) => {
            originalSuccess(response, status, xmlhttprequest);

            // Wait for the text input fields to be loaded via AJAX.
            waitForAddressFields(() => {
              const $addressNew = $form.find('.field--type-address').first();
              Drupal.geolocationMapboxSetAddressDetails($addressNew, address);
            });
          };
        }
      });

      if (
        $('select.country', $address)
          .val()
          .toLowerCase() != address.country_code
      ) {
        $('select.country', $address)
          .val(address.country_code.toUpperCase())
          .trigger('change');
      } else {
        // Wait for the text input fields to be loaded via AJAX.
        waitForAddressFields(() => {
          Drupal.geolocationMapboxSetAddressDetails($address, address);
        });
      }
    }

    function waitForAddressFields(callback) {
      // Check if the text input fields are available in the DOM.
      const $addressLine1 = $form.find('input.address-line1').first();
      if ($addressLine1.length) {
        // Text input fields are present, execute the callback.
        callback();
      } else {
        // Text input fields not yet available, wait and check again.
        setTimeout(() => {
          waitForAddressFields(callback);
        }, 100); // Adjust the delay as needed.
      }
    }
  };

  Drupal.geolactionMaboxParseReverseGeo = (geoData) => {
    let address = {};
    if (geoData) {
      address = geoData.address;
      address.place_name = `${address.road} ${address.house_number}`;
      address.text = geoData.display_name;
    }
    return address;
  };

  Drupal.geolocationMapboxSetAddressDetails = ($address, details) => {
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
  };

  Drupal.geolactionMapboxParseReverseGeo = function (geoData) {
    let address = {};
    if (geoData.context) {
      address.housenumber = geoData.address;
      address.place_name = geoData.place_name;
      address.text = geoData.text;
      $.each(geoData.context, function (i, v) {
        if (v.id.indexOf('region') >= 0) {
          address.region = v.text;
        }
        if (v.id.indexOf('country') >= 0) {
          address.country = v.text;
          address.country_code = v.short_code;
        }
        if (v.id.indexOf('postcode') >= 0) {
          address.postcode = v.text;
        }
        if (v.id.indexOf('place') >= 0) {
          address.place = v.text;
        }
      });
    }
    return address;
  };

  Drupal.behaviors.geolocationMapboxWidget = {
    attach: function (context, settings) {
      if (settings.geolocationMapbox.widgetMaps) {
        $.each(settings.geolocationMapbox.widgetMaps, function (index, mapSettings) {
          Drupal.geolocationMapboxWidget(mapSettings, context, function (marker, map, result) {
            $('.geolocation-widget-lat.for--' + mapSettings.id, context).attr('value', marker.getLatLng().lat);
            $('.geolocation-widget-lng.for--' + mapSettings.id, context).attr('value', marker.getLatLng().lng);
            $('.geolocation-widget-zoom.for--' + mapSettings.id, context)
              .attr('value', map.getZoom());
            if (mapSettings.setAddressField) {
              Drupal.geolocationMapboxSetAddressField(mapSettings, result, context);
            }
          });
        });
      }
    }
  }

})(jQuery);
