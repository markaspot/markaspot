/**
 * @file
 * Nominatim widget functionality.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.geolocationNominatimWidgetMap = {};

  /**
   * Initialize the Nominatim widget.
   *
   * @param {object} mapSettings
   *   Map settings from Drupal.
   * @param {object} context
   *   DOM context.
   * @param {function} updateCallback
   *   Callback to update coordinates.
   */
  Drupal.geolocationNominatimWidget = function (mapSettings, context, updateCallback) {
    // Only init once.
    if ($('#' + mapSettings.id).hasClass('leaflet-container')) {
      return;
    }
    let geosearchMarker;
    let marker;

    // Initialize the map.
    Drupal.geolocationNominatimWidget.map = L.map(mapSettings.id, {
      dragging: mapSettings.dragging,
      zoomControl: mapSettings.zoomControl,
      tab: mapSettings.zoomControl
    }).setView([mapSettings.centerLat, mapSettings.centerLng], 14);

    const map = Drupal.geolocationNominatimWidget.map;

    // Add tile layer based on settings.
    if (mapSettings.mapboxStyle !== '') {
      L.maplibreGL({
        accessToken: mapSettings.mapboxToken,
        style: mapSettings.mapboxStyle
      }).addTo(map);
    }
    else {
      if (mapSettings.wmsLayer === '') {
        L.tileLayer(mapSettings.tileServerUrl).addTo(map);
      }
      else {
        L.tileLayer.wms(mapSettings.tileServerUrl, {layers: mapSettings.wmsLayer}).addTo(map);
      }
    }

    // Add fullscreen control if enabled.
    if (mapSettings.fullscreenControl) {
      L.control.fullscreen({
        position: 'bottomright',
        pseudoFullscreen: false // Set this to 'false' to use actual fullscreen mode
      }).addTo(map);
    }

    // Add attribution.
    map.attributionControl.addAttribution(mapSettings.customAttribution);

    // Setup locate control.
    const locateOptions = {
      position: 'bottomright'
    };
    const lc = L.control.locate(locateOptions).addTo(map);

    // Check for ongoing validation and autolocate settings combination.
    if (mapSettings.autoLocate && !$('.messages')[0]) {
      lc.start();
    }

    /**
     * Handle location found event.
     *
     * @param {object} e
     *   Event object.
     */
    function onLocationFound(e) {
      map.stopLocate();
      reverseGeocode(e.latlng);
    }

    map.on('locationfound', onLocationFound);

    // Define provider for GeoSearch.
    // https://nominatim.org/release-docs/latest/api/Search/#parameters
    const provider = new GeoSearch.OpenStreetMapProvider({
      params: {
        'access_token': mapSettings.mapboxToken,
        'accept-language': mapSettings.limitCountryCodes,
        'viewbox': mapSettings.limitViewbox,
        'limit': 15,
        'bounded': 1,
        'addressdetails': 1
      },
      searchUrl: mapSettings.serviceUrl + 'search'
    });

    // Add GeoSearch control.
    const search = GeoSearch.GeoSearchControl({
      style: 'bar',
      provider: provider,
      showMarker: true,
      showPopup: true,
      marker: {
        icon: new L.Icon.Default(),
        draggable: true
      },
      popupFormat: ({query, result}) => parseResult(result.raw.address, mapSettings),
      resultFormat: ({result}) => parseResult(result.raw.address, mapSettings),
      maxMarkers: 1,
      retainZoomLevel: false,
      animateZoom: true,
      autoClose: true,
      searchLabel: Drupal.t('Street name'),
      keepResult: true,
      updateMap: true
    });

    /**
     * Parse address result for display.
     *
     * @param {object} result
     *   The address result.
     * @param {object} mapSettings
     *   Map settings.
     *
     * @return {string}
     *   Formatted address string.
     */
    function parseResult(result, mapSettings) {
      if (!result) {
        return '';
      }

      const address = result;
      const placeholders = {
        road: address.road || '',
        house_number: address.house_number || '',
        postcode: address.postcode || '',
        city: address.city || '',
        suburb: address.suburb || '',
        hamlet: address.hamlet || '',
        town: address.town || '',
        village: address.village || '',
        municipality: address.municipality || '',
        county: address.county || ''
      };

      // Replace placeholders in the format.
      let formattedAddress = mapSettings.addressFormat;
      Object.keys(placeholders).forEach(key => {
        formattedAddress = formattedAddress.replace(
          new RegExp(`\\$\{address.${key}\}`, 'g'),
          placeholders[key]
        );
      });

      // Clean up the address.
      formattedAddress = formattedAddress.replace(/\s*,\s*/g, ', ').trim();
      formattedAddress = formattedAddress.replace(/\s{2,}/g, ' ');

      return formattedAddress;
    }

    // Initialize geocoder parameters.
    const geocodingQueryParams = {};
    if (mapSettings.limitCountryCodes !== '' || mapSettings.limitViewbox !== '') {
      geocodingQueryParams.key = mapSettings.LocationIQToken;
      geocodingQueryParams.countrycodes = mapSettings.limitCountryCodes;
      geocodingQueryParams.viewbox = mapSettings.limitViewbox;
      geocodingQueryParams.bounded = 1;
      geocodingQueryParams.limit = 100;
      geocodingQueryParams.city = mapSettings.city;
    }

    map.addControl(search);

    /**
     * Handle GeoSearch result.
     *
     * @param {object} result
     *   The search result.
     */
    const handleResult = result => {
      geosearchMarker = result.marker; // Save the GeoSearch marker.
      geosearchMarker.on('dragend', function (e) {
        const newPosition = e.target.getLatLng();
        reverseGeocode(newPosition, geosearchMarker);
      });

      const location = Drupal.geolocationNominatimParseReverseGeo(result.location.raw);
      updateCallback(geosearchMarker, map, location);

      $('.geolocation-widget-lng.for--geolocation-nominatim-map')
        .attr('value', result.location.x);
      $('.geolocation-widget-lat.for--geolocation-nominatim-map')
        .attr('value', result.location.y);
    };

    // Handle map click events.
    map.on('click', function (e) {
      search.clearResults();

      // Remove the GeoSearch marker if it exists.
      if (geosearchMarker) {
        map.removeLayer(geosearchMarker);
        geosearchMarker = null;
      }

      if (map._geocoderIsActive) {
        return;
      }

      // Only call reverseGeocode if a geosearch result is not being shown.
      if (!geosearchMarker) {
        reverseGeocode(e.latlng);
      }
    });

    map.on('geosearch/showlocation', handleResult);

    // Initialize map with default values.
    if (mapSettings.lat && mapSettings.lng) {
      // Map lat and lng are always set to user defined values or 0 initially.
      // If field values already set, use only those and set marker.
      const fieldValues = {
        lat: $('.geolocation-widget-lat.for--' + mapSettings.id, context).attr('value') || mapSettings.lat,
        lng: $('.geolocation-widget-lng.for--' + mapSettings.id, context).attr('value') || mapSettings.lng
      };

      // Validate coordinates before setting view.
      if (isValidCoordinate(fieldValues.lat) && isValidCoordinate(fieldValues.lng)) {
        map.setView([fieldValues.lat, fieldValues.lng], mapSettings.zoom);
        reverseGeocode(fieldValues);
      }
      else {
        // Fall back to default coordinates.
        map.setView([mapSettings.lat, mapSettings.lng], mapSettings.zoom);
      }
    }

    /**
     * Helper function to validate coordinates.
     *
     * @param {string} coord
     *   The coordinate to validate.
     *
     * @return {boolean}
     *   True if the coordinate is valid.
     */
    function isValidCoordinate(coord) {
      const num = parseFloat(coord);
      return !isNaN(num) && isFinite(num) && Math.abs(num) <= 90;
    }

    /**
     * Parse road information for display.
     *
     * @param {object} result
     *   The geocoding result.
     */
    function parseRoad(result) {
      let address = '';
      if (result.type === 'geosearch/showlocation') {
        address = result.location.raw.address;
      }
      else {
        address = result;
      }

      let $val = '';
      if (address.road) {
        switch (mapSettings.streetNumberFormat) {
          case '1':
            $val = (address.road || '') +
              ((typeof address.house_number !== 'undefined') ? ' ' + address.house_number : '') +
              ((typeof address.postcode !== 'undefined') ? ', ' + address.postcode : '') +
              ' ' + (address.city || '');
            break;

          case 0:
            $val = (address.house_number ? address.house_number + ' ' : '') + address.road;
            break;
        }
      }
      else {
        $val = '';
      }
      const $input = $('.leaflet-control-geosearch form input');
      $input.val($val);
    }

    /**
     * Set marker on the map.
     *
     * @param {object} result
     *   The geocoding result.
     * @param {object} latLng
     *   The latitude and longitude.
     */
    function setMarker(result, latLng) {
      if (typeof marker !== 'undefined') {
        map.removeLayer(marker);
      }

      if (geosearchMarker) {
        // If geosearchMarker exists, remove it from the map before creating a new one.
        map.removeLayer(geosearchMarker);
      }

      // Format and display the address.
      const addressValue = parseResult(result, mapSettings);
      const $input = $('.leaflet-control-geosearch form input');
      $input.val(addressValue);

      // Check if method is called with a pair of coordinates to prevent
      // marker jumping to nominatm reverse results lat/lon.
      latLng = latLng ? latLng : result.center;
      marker = L.marker(latLng, {
        draggable: true
      }).addTo(map);

      marker.on('dragend', function (e) {
        const newPosition = e.target.getLatLng();
        if (newPosition.lat !== latLng.lat || newPosition.lng !== latLng.lng) {
          reverseGeocode(newPosition, marker);
        }
        else {
          updateCallback(marker, map, result);
        }
      });

      updateCallback(marker, map, result);
    }

    /**
     * Perform reverse geocoding.
     *
     * @param {object} latlng
     *   The latitude and longitude to geocode.
     */
    function reverseGeocode(latlng) {
      const url = mapSettings.serviceUrl + 'reverse?' + 'lon=' + latlng.lng + '&lat=' + latlng.lat + '&format=json';
      fetch(url)
        .then(function (response) {
          return response.json();
        })
        .then(function (body) {
          const location = Drupal.geolocationNominatimParseReverseGeo(body);

          // Direct update of the address field based on the provided selector.
          if (location && location.road) {
            // Target the address-line1 field directly.
            const addressSelector = '.field--type-address.field--name-field-address .js-form-item-field-address-0-address-address-line1 input';
            const $addressField = $(addressSelector);

            if ($addressField.length) {
              // Include house number if available.
              let addressValue = location.road || '';
              if (location.house_number) {
                addressValue += ' ' + location.house_number;
              }

              $addressField.val(addressValue);

              // Also try to update postal code if available.
              if (location.postcode) {
                const $postalField = $('.field--type-address.field--name-field-address .js-form-item-field-address-0-address-postal-code input');
                if ($postalField.length) {
                  $postalField.val(location.postcode);
                }
              }

              // Also try to update city if available.
              const localityValue = location.city || location.town || location.village || location.hamlet || location.county || location.municipality;
              if (localityValue) {
                const $cityField = $('.field--type-address.field--name-field-address .js-form-item-field-address-0-address-locality input');
                if ($cityField.length) {
                  $cityField.val(localityValue);
                }
              }
            }
          }

          setMarker(location, latlng);
          updateCallback(marker, map, location);
        })
        .catch(function (error) {
          console.error('Geocoding error:', error);
        });

      // Update hidden lat/lng fields.
      $('.field--widget-geolocation-nominatim-widget .geolocation-hidden-lat')
        .attr('value', latlng.lat);
      $('.field--widget-geolocation-nominatim-widget .geolocation-hidden-lng')
        .attr('value', latlng.lng);

      // Also update the standard lat/lng fields.
      $('.geolocation-widget-lat').attr('value', latlng.lat);
      $('.geolocation-widget-lng').attr('value', latlng.lng);
    }
  };

  /**
   * Set address field based on geocoding result.
   *
   * @param {object} mapSettings
   *   Map settings.
   * @param {object} result
   *   Geocoding result.
   * @param {object} context
   *   DOM context.
   */
  Drupal.geolocationNominatimSetAddressField = function (mapSettings, result, context) {
    if (!('road' in result)) {
      return;
    }

    const address = result;
    const $form = $('.geolocation-widget-lat.for--' + mapSettings.id, context)
      .closest('form');
    const $address = $form.find('.field--type-address').first();

    if ($address.length) {
      // Bind to addressfields AJAX complete event.
      $.each(Drupal.ajax.instances, function (idx, instance) {
        try {
          if (
            instance !== null &&
            typeof instance === 'object' &&
            instance.hasOwnProperty('callback') &&
            Array.isArray(instance.callback) &&
            instance.callback.length >= 2 &&
            instance.callback[0] === 'Drupal\\address\\Element\\Address' &&
            instance.callback[1] === 'ajaxRefresh'
          ) {
            const originalSuccess = instance.options.success;
            instance.options.success = function (response, status, xmlhttprequest) {
              originalSuccess(response, status, xmlhttprequest);

              // Wait for the text input fields to be loaded via AJAX.
              waitForAddressFields(function () {
                const $addressNew = $form.find('.field--type-address').first();
                Drupal.geolocationNominatimSetAddressDetails($addressNew, address);
              });
            };
          }
        }
        catch (e) {
          console.error('Error checking AJAX instance:', e);
        }
      });

      const $country = $('select.country', $address);
      const currentCountry = $country.val();

      if (
        address.country_code &&
        currentCountry &&
        currentCountry.toLowerCase() !== address.country_code.toLowerCase()
      ) {
        $country
          .val(address.country_code.toUpperCase())
          .trigger('change');
      }
      else {
        // Wait for the text input fields to be loaded via AJAX.
        waitForAddressFields(function () {
          Drupal.geolocationNominatimSetAddressDetails($address, address);
        });
      }
    }

    /**
     * Wait for address fields to be available in the DOM.
     *
     * @param {function} callback
     *   The callback to execute when fields are available.
     */
    function waitForAddressFields(callback) {
      // Check if the text input fields are available in the DOM.
      const selectors = [
        'input.address-line1',
        'input[name*="address-line1"]',
        'input[data-drupal-selector*="address-line1"]'
      ];
      let found = false;

      for (const selector of selectors) {
        const $addressLine1 = $form.find(selector).first();
        if ($addressLine1.length) {
          found = true;
          // Text input fields are present, execute the callback.
          callback();
          break;
        }
      }

      if (!found) {
        // Text input fields not yet available, wait and check again.
        setTimeout(function () {
          waitForAddressFields(callback);
        }, 100); // Adjust the delay as needed.
      }
    }
  };

  /**
   * Parse reverse geocoding result.
   *
   * @param {object} geoData
   *   The geocoding response data.
   *
   * @return {object}
   *   Formatted address data.
   */
  Drupal.geolocationNominatimParseReverseGeo = function (geoData) {
    let address = {};
    if (!geoData) {
      return address;
    }

    if (geoData.address) {
      address = geoData.address;

      // Format place_name if road and house_number are available.
      if (address.road && address.house_number) {
        address.place_name = `${address.road} ${address.house_number}`;
      }
      else if (address.road) {
        address.place_name = address.road;
      }

      address.text = geoData.display_name || '';

      // Ensure required properties exist and normalize.
      address.country_code = address.country_code || '';
      address.postcode = address.postcode || '';
      address.road = address.road || '';
      address.house_number = address.house_number || '';

      // Set additional properties for locality to make sure we have something to use.
      if (!address.city && !address.town && !address.village && !address.hamlet) {
        if (address.suburb) {
          address.city = address.suburb;
        }
        else if (address.county) {
          address.city = address.county;
        }
        else if (address.state) {
          address.city = address.state;
        }
      }
    }
    return address;
  };

  /**
   * Set address details from geocoding data.
   *
   * @param {object} $address
   *   The address field container.
   * @param {object} details
   *   Address details from geocoding.
   */
  Drupal.geolocationNominatimSetAddressDetails = function ($address, details) {
    /**
     * Find a field by type.
     *
     * @param {string} type
     *   The field type to find.
     *
     * @return {object|null}
     *   jQuery object or null if not found.
     */
    const findField = (type) => {
      const selectors = [
        `input.${type}`,
        `input[name*="${type}"]`,
        `input[data-drupal-selector*="${type}"]`
      ];

      for (const selector of selectors) {
        const $field = $address.find(selector).first();
        if ($field.length) {
          return $field;
        }
      }
      return null;
    };

    // Postal code.
    if ('postcode' in details) {
      const $postalCode = findField('postal-code');
      if ($postalCode) {
        $postalCode.val(details.postcode);
      }
    }

    // Suburb/District.
    if ('suburb' in details) {
      const districtSelectors = [
        'select#edit-field-district',
        'select[name*="district"]',
        'select[data-drupal-selector*="district"]'
      ];

      for (const selector of districtSelectors) {
        const $district = $(selector);
        if ($district.length) {
          $district.find('option').each(function () {
            if ($(this).text() === details.suburb) {
              $(this).prop('selected', true);
            }
          });
          break;
        }
      }
    }

    // State/Region.
    if ('state' in details) {
      const $state = $address.find('select.administrative-area');

      if ($state.length) {
        $state.find('option').each(function () {
          if ($(this).text() === details.state) {
            $(this).prop('selected', true);
          }
        });
      }
    }

    // City/Locality.
    const localityValue =
      details.city ||
      details.town ||
      details.village ||
      details.hamlet ||
      details.county ||
      details.neighbourhood;

    if (localityValue) {
      const $locality = findField('locality');
      if ($locality) {
        $locality.val(localityValue);
      }
    }

    // Street address.
    const streetType =
      details.path ||
      details.road ||
      details.footway ||
      details.pedestrian ||
      details.path;

    if (streetType) {
      const $addressLine1 = findField('address-line1');
      if ($addressLine1) {
        let addressValue = streetType;

        // Add house number if available and not already in the street.
        if (details.house_number && addressValue.indexOf(details.house_number) === -1) {
          addressValue += ' ' + details.house_number;
        }

        $addressLine1.val(addressValue);
      }
    }

    // Building or additional address info.
    if (details.building) {
      const $addressLine2 = findField('address-line2');
      if ($addressLine2) {
        $addressLine2.val(details.building);
      }
    }

    // Direct update for specific selectors that might not be found by generic methods.
    const addressSelector = '.field--type-address.field--name-field-address .js-form-item-field-address-0-address-address-line1 input';
    const $directAddress = $(addressSelector);
    if ($directAddress.length && streetType) {
      let addressValue = streetType;
      if (details.house_number) {
        addressValue += ' ' + details.house_number;
      }
      $directAddress.val(addressValue);
    }
  };

  /**
   * Drupal behavior for geolocation nominatim widget.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches geolocation nominatim widget behavior.
   */
  Drupal.behaviors.geolocationNominatimWidget = {
    attach: function (context, settings) {
      if (settings.geolocationNominatim && settings.geolocationNominatim.widgetMaps) {
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
  };

})(jQuery, Drupal);