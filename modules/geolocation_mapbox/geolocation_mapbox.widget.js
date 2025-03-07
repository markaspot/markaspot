/**
 * @file
 * Mapbox widget functionality.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.geolocationMapboxWidgetMap = {};

  /**
   * Initialize the Mapbox widget.
   *
   * @param {object} mapSettings
   *   Map settings from Drupal.
   * @param {object} context
   *   DOM context.
   * @param {function} updateCallback
   *   Callback to update coordinates.
   */
  Drupal.geolocationMapboxWidget = function (mapSettings, context, updateCallback) {
    // Only init once.
    if ($('#' + mapSettings.id).hasClass('leaflet-container')) {
      return;
    }
    let geosearchMarker;
    let marker;

    // Initialize the map.
    Drupal.geolocationMapboxWidget.map = L.map(mapSettings.id, {
      fullscreenControl: mapSettings.fullscreenControl,
      dragging: mapSettings.dragging,
      zoomControl: mapSettings.zoomControl,
      tab: mapSettings.zoomControl
    });
    const map = Drupal.geolocationMapboxWidget.map;

    // Add tile layer.
    if (mapSettings.mapboxStyle !== '') {
      L.maplibreGL({
        accessToken: mapSettings.mapboxToken,
        style: mapSettings.mapboxStyle
      }).addTo(map);
    } 
    else {
      L.tileLayer.wms(mapSettings.tileServerUrl, {layers: mapSettings.wmsLayer}).addTo(map);
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

    // Handle location found event.
    function onLocationFound(e) {
      map.stopLocate();
      reverseGeocode(e.latlng);
    }

    map.on('locationfound', onLocationFound);

    // Configure GeoSearch provider.
    const provider = new GeoSearch.MapBoxProvider({
      params: {
        'access_token': mapSettings.mapboxToken,
        'country': mapSettings.limitCountryCodes,
        'language': mapSettings.limitCountryCodes,
        'bbox': mapSettings.limitViewbox,
        'bounded': 1,
        'limit': 5,
        'city': mapSettings.city
      }
    });

    // Add GeoSearch control.
    const search = GeoSearch.GeoSearchControl({
      style: 'bar',
      provider: provider,
      showMarker: true,
      showPopup: true,
      marker: {
        icon: new L.Icon.Default(),
        draggable: false
      },
      popupFormat: ({query, result}) => result.label,
      resultFormat: ({result}) => result.label,
      maxMarkers: 1,
      retainZoomLevel: false,
      animateZoom: true,
      autoClose: true,
      searchLabel: Drupal.t('Street name'),
      keepResult: true,
      updateMap: true
    });

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
            $val = (address.road || '') + ((typeof address.house_number !== 'undefined') ? ' ' + address.house_number : '') + ((typeof address.postcode !== 'undefined') ? ', ' + address.postcode : '') + ' ' + (address.city || '');
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
     * Perform reverse geocoding.
     *
     * @param {object} latlng
     *   The latitude and longitude to geocode.
     */
    function reverseGeocode(latlng) {
      // Build the URL with appropriate parameters
      let url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${latlng.lng},${latlng.lat}.json?types=address&access_token=${mapSettings.mapboxToken}`;
      
      // Add bbox parameter if available
      if (mapSettings.limitViewbox) {
        url += `&bbox=${mapSettings.limitViewbox}`;
      }
      
      // Add country limitation if available
      if (mapSettings.limitCountryCodes) {
        url += `&country=${mapSettings.limitCountryCodes}`;
      }
      
      fetch(url)
        .then(response => {
          if (!response.ok) {
            throw new Error(`Geocoding failed: ${response.status} ${response.statusText}`);
          }
          return response.json();
        })
        .then(body => {
          // Check if we have features.
          if (!body.features || body.features.length === 0) {
            return;
          }
          
          const location = Drupal.geolocationMapboxParseReverseGeo(body.features[0]);
          
          // Direct update of the address field.
          if (location && location.text) {
            // Target the address-line1 field directly.
            const addressSelector = '.field--type-address.field--name-field-address .js-form-item-field-address-0-address-address-line1 input';
            const $addressField = $(addressSelector);
            
            if ($addressField.length) {
              // Include house number if available.
              let addressValue = location.text || '';
              if (location.address || location.housenumber || location.house_number) {
                const houseNumber = location.address || location.housenumber || location.house_number;
                addressValue += ' ' + houseNumber;
              }
              
              $addressField.val(addressValue);
              
              // Update postal code if available.
              if (location.postcode) {
                const $postalField = $('.field--type-address.field--name-field-address .js-form-item-field-address-0-address-postal-code input');
                if ($postalField.length) {
                  $postalField.val(location.postcode);
                }
              }
              
              // Update city if available.
              if (location.place) {
                const $cityField = $('.field--type-address.field--name-field-address .js-form-item-field-address-0-address-locality input');
                if ($cityField.length) {
                  $cityField.val(location.place);
                }
              }
            }
          }
          
          setMarker(location, latlng);
          updateCallback(marker, map, location);
        })
        .catch(error => {
          console.error('Geocoding error:', error);
        });

      // Update hidden lat/lng fields.
      $('.field--widget-geolocation-mapbox-widget .geolocation-hidden-lat').attr('value', latlng.lat);
      $('.field--widget-geolocation-mapbox-widget .geolocation-hidden-lng').attr('value', latlng.lng);
      
      // Also update the standard lat/lng fields.
      $('.geolocation-widget-lat').attr('value', latlng.lat);
      $('.geolocation-widget-lng').attr('value', latlng.lng);
    }

    // Initialize geocoder.
    var geocodingQueryParams = {};
    if (mapSettings.limitCountryCodes !== '' || mapSettings.limitViewbox !== '') {
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

      const location = Drupal.geolocationMapboxParseReverseGeo(result.location.raw);
      
      // Update address field with selected location.
      if (location && mapSettings.setAddressField) {
        // Target the address-line1 field directly.
        const addressSelector = '.field--type-address.field--name-field-address .js-form-item-field-address-0-address-address-line1 input';
        const $addressField = $(addressSelector);
        
        if ($addressField.length) {
          // Include house number if available.
          let addressValue = location.text || '';
          if (location.address || location.housenumber || location.house_number) {
            const houseNumber = location.address || location.housenumber || location.house_number;
            addressValue += ' ' + houseNumber;
          }
          
          $addressField.val(addressValue);
          
          // Update postal code if available.
          if (location.postcode) {
            const $postalField = $('.field--type-address.field--name-field-address .js-form-item-field-address-0-address-postal-code input');
            if ($postalField.length) {
              $postalField.val(location.postcode);
            }
          }
          
          // Update city if available.
          if (location.place) {
            const $cityField = $('.field--type-address.field--name-field-address .js-form-item-field-address-0-address-locality input');
            if ($cityField.length) {
              $cityField.val(location.place);
            }
          }
        }
      }
      
      updateCallback(geosearchMarker, map, location);

      $('.geolocation-widget-lng.for--geolocation-mapbox-map')
        .attr('value', result.location.x);
      $('.geolocation-widget-lat.for--geolocation-mapbox-map')
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

      // Check if method is called with a pair of coordinates to prevent
      // marker jumping to nominatm reverse results lat/lon.
      latLng = latLng ? latLng : result.center;
      marker = L.marker(latLng, {
        draggable: true
      }).addTo(map);

      if (result.text) {
        marker.bindPopup(result.place_name).openPopup();
      }
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
     * @return {boolean}
     *   True if the coordinate is valid.
     */
    function isValidCoordinate(coord) {
      const num = parseFloat(coord);
      return !isNaN(num) && isFinite(num) && Math.abs(num) <= 90;
    }
  };

  /**
   * Set address field based on geocoding result.
   *
   * @param {object} mapSettings
   *   Map settings.
   * @param {object} location
   *   Location data.
   * @param {object} context
   *   DOM context.
   */
  Drupal.geolocationMapboxSetAddressField = function (mapSettings, location, context) {
    // Check if we have meaningful data to use.
    if (!location || (Object.keys(location).length === 0)) {
      return;
    }

    const address = location;
    const $form = $('.geolocation-widget-lat.for--' + mapSettings.id, context).closest('form');
    
    // Try multiple selectors to find the address field.
    const addressSelectors = [
      '.field--type-address',
      '.field--name-field-address',
      '.form-item-field-address',
      '[data-drupal-selector*="address"]',
      '.js-form-item-field-address-0-address'
    ];
    
    let $address = null;
    for (const selector of addressSelectors) {
      const $found = $form.find(selector).first();
      if ($found.length) {
        $address = $found;
        break;
      }
    }
    
    if (!$address || !$address.length) {
      return;
    }

    // Bind to addressfields AJAX complete event.
    $.each(Drupal.ajax.instances, (idx, instance) => {
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
          instance.options.success = (response, status, xmlhttprequest) => {
            originalSuccess(response, status, xmlhttprequest);

            // Wait for the text input fields to be loaded via AJAX.
            waitForAddressFields(() => {
              const $addressNew = $form.find('.field--type-address').first();
              Drupal.geolocationMapboxSetAddressDetails($addressNew, address);
            });
          };
        }
      }
      catch (e) {
        console.error('Error checking AJAX instance:', e);
      }
    });

    // Check if country needs to be changed.
    if (address.country_code) {
      const $country = $('select.country', $address);
      const currentCountry = $country.val();
      
      if (currentCountry && currentCountry.toLowerCase() !== address.country_code.toLowerCase()) {
        $country
          .val(address.country_code.toUpperCase())
          .trigger('change');
      }
      else {
        // Wait for the text input fields to be loaded via AJAX.
        waitForAddressFields(() => {
          Drupal.geolocationMapboxSetAddressDetails($address, address);
        });
      }
    }
    else {
      // No country code, just try to set address details directly.
      waitForAddressFields(() => {
        Drupal.geolocationMapboxSetAddressDetails($address, address);
      });
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
        setTimeout(() => {
          waitForAddressFields(callback);
        }, 100); // Adjust the delay as needed.
      }
    }
  };

  /**
   * Set address details from geocoding data.
   *
   * @param {object} $address
   *   The address field container.
   * @param {object} details
   *   Address details from geocoding.
   */
  Drupal.geolocationMapboxSetAddressDetails = ($address, details) => {
    /**
     * Find a field by type.
     *
     * @param {string} type
     *   The field type to find.
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
          $district.find('option').each(function() {
            if ($(this).text() === details.suburb) {
              $(this).prop('selected', true);
            }
          });
          break;
        }
      }
    }

    // State/Region.
    if ('region' in details || 'state' in details) {
      const state = details.state || details.region;
      const $state = $address.find('select.administrative-area');
      
      if ($state.length) {
        $state.find('option').each(function() {
          if ($(this).text() === state) {
            $(this).prop('selected', true);
          }
        });
      }
    }
    
    // City/Locality.
    if ('place' in details) {
      const $locality = findField('locality');
      if ($locality) {
        $locality.val(details.place);
      }
    }
    
    // Street address.
    let addressLine1 = '';
    
    if ('road' in details) {
      addressLine1 = details.road;
      if ('house_number' in details) {
        addressLine1 += ' ' + details.house_number;
      }
    }
    else if ('text' in details) {
      addressLine1 = details.text;
      if ('housenumber' in details) {
        addressLine1 += ' ' + details.housenumber;
      }
    }
    
    if (addressLine1) {
      const $addressLine1 = findField('address-line1');
      if ($addressLine1) {
        $addressLine1.val(addressLine1);
      }
    }
    
    // Building or additional address info.
    if ('building' in details) {
      const $addressLine2 = findField('address-line2');
      if ($addressLine2) {
        $addressLine2.val(details.building);
      }
    }
  };

  /**
   * Parse reverse geocoding result.
   *
   * @param {object} geoData
   *   The geocoding response data.
   * @return {object}
   *   Formatted address data.
   */
  Drupal.geolocationMapboxParseReverseGeo = function (geoData) {
    let address = {};
    // Return empty address if no data.
    if (!geoData) {
      return address;
    }
    
    // Extract address components from Mapbox response.
    address.place_name = geoData.place_name || '';
    address.text = geoData.text || '';
    
    // Try to extract house number from the place_name.
    // Typical format: "Am Rathaus 12, 47475 Kamp-Lintfort, Germany".
    if (address.place_name && address.text) {
      const match = address.place_name.match(new RegExp(address.text + ' (\\d+\\w*)'));
      if (match && match[1]) {
        address.house_number = match[1];
      }
    }
    
    // Parse address components from the context array if available.
    if (geoData.context && Array.isArray(geoData.context)) {
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
        // Add district/neighborhood data if available.
        if (v.id.indexOf('neighborhood') >= 0 || v.id.indexOf('district') >= 0) {
          address.suburb = v.text;
        }
      });
    }
    
    // Extract house number and street from address property if available.
    if (geoData.address) {
      address.house_number = geoData.address;
    }
    
    // Try to extract house number from place_name as a fallback.
    if (!address.house_number && address.place_name) {
      const numberMatch = address.place_name.match(/(\d+\w*)/);
      if (numberMatch && numberMatch[1]) {
        address.house_number = numberMatch[1];
      }
    }
    
    // If the main text looks like a street name, use it for address line 1.
    if (geoData.text && !address.road) {
      address.road = geoData.text;
    }
    
    return address;
  };

  /**
   * Drupal behavior for geolocation mapbox widget.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches geolocation mapbox widget behavior.
   */
  Drupal.behaviors.geolocationMapboxWidget = {
    attach: function (context, settings) {
      if (settings.geolocationMapbox && settings.geolocationMapbox.widgetMaps) {
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
  };

})(jQuery, Drupal);