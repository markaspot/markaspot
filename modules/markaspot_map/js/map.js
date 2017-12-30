/**
 * @file
 * Main Map-Application File with Leaflet Maps api.
 */

L.TimeDimension.Layer.MaS = L.TimeDimension.Layer.GeoJson.extend(
  {

    _update: function () {
      if (!this._map) {
        return;
      }
      if (!this._loaded) {
        return;
      }

      var request_time = this._timeDimension.getCurrentTime()
      var formattime = dateFormat(request_time, Drupal.Markaspot.settings.timeline_date_format);
      var maxTime = this._timeDimension.getCurrentTime(), minTime = 0;
      if (this._duration) {
        var date = new Date(maxTime);
        L.TimeDimension.Util.subtractTimeDuration(date, this._duration, true);
        minTime = date.getTime();
      }

      // New coordinates:
      var layer = L.geoJson(null, this._baseLayer.options);
      var layers = this._baseLayer.getLayers();
      for (var i = 0, l = layers.length; i < l; i++) {
        var feature = this._getFeatureBetweenDates(layers[i].feature, minTime, maxTime);
        if (feature) {
          layer.addData(feature);
          if (this._addlastPoint && feature.geometry.type == "LineString") {
            if (feature.geometry.coordinates.length > 0) {
              var properties = feature.properties;
              properties.last = true;
              layer.addData({
                type: 'Feature',
                properties: properties,
                geometry: {
                  type: 'Point',
                  coordinates: feature.geometry.coordinates[feature.geometry.coordinates.length - 1]
                }
              });
            }
          }
        }
      }

      if (this._currentLayer) {
        this._map.removeLayer(this._currentLayer);
      }
      if (layer.getLayers().length) {

        var requests = layer.getLayers().length;
        var log = jQuery('ul.log_list');
        log.append('<li><span class="time">' + formattime + '</span>' + '<span class="count">' + requests + '</span>');
        var height = log.get(0).scrollHeight;
        log.animate({
          scrollTop: height
        }, 10);
        layer.addTo(this._map);
        this._currentLayer = layer;
      }
    }

  }
);

(function ($, Drupal, drupalSettings, Date) {

  // 'use strict';.
  Drupal.Markaspot = {};
  Drupal.Markaspot.maps = [];
  var markerLayer;
  var scrolledMarker = [];

  Drupal.behaviors.markaspot_map = {

    attach: function (context, settings) {



      var map = {};
      var masSettings = settings.mas;
      Drupal.Markaspot.settings = masSettings;
      // Make map stick to the page top or wherever, override via theme.
      var mapSelector = $('#map');
      var sticky;

      mapSelector.once('markaspot_map').each(function () {
        $('.log_header .left').text(Drupal.t("Date"));
        $('.log_header .right').text(Drupal.t("Requests"));
        Drupal.Markaspot.maps[0] = L.map('map', {
          fullscreenControl: true,
          scrollWheelZoom: false,
          maxZoom: 18,
          setZoom: 14,
        });

        $('#map').css('background-color:' + masSettings.map_background);
        var tileLayer = L.tileLayer(masSettings.osm_custom_tile_url);

        var map = Drupal.Markaspot.maps[0];
        map.attributionControl.addAttribution(masSettings.osm_custom_attribution);

        map.addLayer(tileLayer);

        markerLayer = L.markerClusterGroup({
          maxClusterRadius: 20
        });

        map.addLayer(markerLayer);

        // Initital heat map layer for front page:
        var currentPath = drupalSettings.path.currentPath;

        if (currentPath === 'node') {
          var heatMapLayer = Drupal.markaspot_map.createHeatMapLayer(map);
          // heatMapLayer.addTo(map);
          Drupal.markaspot_map.setDefaults(masSettings);
        }

        // Drupal.markaspot_map.hideMarkers();
        // Show Markers additionally ob button click.
        var categoryMarker = L.easyButton({
          position: 'topleft',
          states: [
            {
              icon: 'fa-map-marker active',
              stateName: 'remove-markers',
              title: Drupal.t('Hide Markers'),
              onClick: function (control) {
                Drupal.markaspot_map.hideMarkers();
                control.state('add-markers');
              },
            }, {
              stateName: 'add-markers',
              icon: 'fa-map-marker',
              title: Drupal.t('Show Markers'),
              onClick: function (control) {
                Drupal.markaspot_map.showMarkers();
                control.state('remove-markers');
              }
            }
          ]
        });
        categoryMarker.addTo(map);

        var geoJsonTimedLayer = Drupal.markaspot_map.createGeoJsonTimedLayer(map);
        var heatStart = [
          [masSettings.center_lat, masSettings.center_lng, 1]
        ];
        var heatLayer = new L.heatLayer(heatStart).addTo(map);
        heatLayer.id = "heatTimedLayer";
        // Show Markers additionally ob button click.
        var timeDimensionControl = Drupal.markaspot_map.showTimeController(map);
        // Show Markers additionally ob button click.
        var heatControls = L.easyButton({
          position: 'bottomright',
          states: [
            {
              stateName: 'add-heatControls',
              icon: 'fa-thermometer-4',
              title: Drupal.t('Show Heatmap'),
              onClick: function (control) {
                var timeDimensionControl = Drupal.markaspot_map.showTimeController(map);
                var geoJsonTimedLayer = Drupal.markaspot_map.createGeoJsonTimedLayer(map);
                control.state('remove-heatControls');
                control.heatMapLayer = Drupal.markaspot_map.createHeatMapLayer(map);
                control.heatMapLayer.addTo(map);

              }
            }, {
              stateName: 'remove-heatControls',
              icon: 'fa-thermometer-4 active',
              title: Drupal.t('Hide Heatmap'),
              onClick: function (control) {
                map.removeLayer(control.heatMapLayer);
                control.state('add-heatControls');
              }
            }
          ]
        });
        heatControls.addTo(map);

        var timeControls = L.easyButton({
          position: 'bottomright',
          states: [
            {
              stateName: 'add-timeControls',
              icon: 'fa-clock-o',
              title: Drupal.t('Show TimeControl Layer'),
              onClick: function (control) {

                $('div.log').show();

                control.state('remove-timeControls');

                map.addControl(timeDimensionControl);
                heatLayer.addTo(map);
                geoJsonTimedLayer.addTo(map);
              }
            }, {
              icon: 'fa-clock-o active',
              stateName: 'remove-timeControls',
              title: Drupal.t('Remove TimeControl Layer'),
              onClick: function (control) {
                $('div.log').hide();
                map.removeControl(timeDimensionControl);
                map.removeLayer(geoJsonTimedLayer);
                map.removeLayer(heatLayer);

                control.state('add-timeControls');
                $('ul.log_list').empty();
              },
            }
          ]
        });
        timeControls.addTo(map);

        // Empty storedNids.
        localStorage.setItem("storedNids", JSON.stringify(''));
        // End once.
      });

      // Get all nids to be called via ajax(Open311).
      // jQuery.Once is not working with ajax loaded views, which means requests.json is loaded
      // twice in logged in state.
      // We check which nids are shown already and we now store nids in localStorage.
      var storedNids = JSON.parse(localStorage.getItem("storedNids"));
      var nids = Drupal.markaspot_map.getNids(masSettings.nid_selector);

      if (!nids.length) {
        Drupal.markaspot_map.setDefaults(masSettings);
      }

      if (nids.length != storedNids.length) {

        localStorage.setItem("storedNids", JSON.stringify(nids));

        markerLayer.clearLayers();
        // Load and showData on map.
        Drupal.markaspot_map.load(function (data) {
          Drupal.markaspot_map.showData(data);
          markerLayer.eachLayer(function (layer) {
            // Define marker-properties for Scrolling.
            var nid = layer.options.title;
            scrolledMarker[nid] = {
              latlng: layer.getLatLng(),
              title: layer.options.title,
              color: layer.options.color
            };
          });
        }, nids);
      }
      // Theme independent selector.
      var serviceRequests = $(masSettings.nid_selector);

      for (var i = 0, length = serviceRequests.length; i < length; i++) {

        // Event of hovering.
        $(serviceRequests[i]).hover(function () {
          var nid = this.getAttribute('data-history-node-id');
          Drupal.markaspot_map.showCircle(scrolledMarker[nid]);
        });

        new Waypoint({
          element: serviceRequests[i],
          handler: function (direction) {

            var nid = this.element.getAttribute('data-history-node-id');

            var previousWp = this.previous();
            var nextWp = this.next();
            if (previousWp) {
              $(previousWp.element).removeClass('focus');
            }
            if (nextWp) {
              $(nextWp.element).removeClass('focus');
            }
            $(this.element).addClass('focus');

            if (scrolledMarker.hasOwnProperty(nid)) {
              Drupal.markaspot_map.showCircle(scrolledMarker[nid]);
            }
          },
          offset: '40%'
        })
      }
    }

  };

  Drupal.markaspot_map = {

    settings: function (drupalSettings) {
      return drupalSettings;
    },
    setDefaults: function (masSettings) {
      var defaultCenter = new L.latLng(masSettings.center_lat, masSettings.center_lng);
      Drupal.Markaspot.maps[0].setView(defaultCenter, masSettings.zoom_initial);
    },
    // Showing a Circle Marker on hover and scroll over.
    showCircle: function (marker) {
      var map = Drupal.Markaspot.maps[0];
      // Get zoomlevel to set circle radius.
      var currentZoom = map.getZoom();
      if (typeof marker !== 'undefined') {
        var color = marker.color;
        var circle = L.circle(marker.latlng, 2600 / currentZoom, {
          color: color,
          weight: 1,
          fillColor: color,
          fillOpacity: 0.3,
          opacity: 0.7
        }).addTo(map);

        map.panTo(marker.latlng, {
          animate: true,
          duration: 0.5
        });

        // map.setView(marker.latlng, 15);.
        setTimeout(function () {
          // marker.setIcon(icon);
          map.removeLayer(circle);
        }, 1300);
      }
    },

    showTimeController: function (map) {
      // Start of TimeDimension manual instantiation.
      var timeDimension = new L.TimeDimension({
        period: drupalSettings['mas']['timeline_period']
      });

      // Helper to share the timeDimension object between all layers.
      map.timeDimension = timeDimension;

      L.Control.TimeDimensionCustom = L.Control.TimeDimension.extend({
        _getDisplayDateFormat: function (date) {
          return this._dateUTC ? date.toISOString() : date.toLocaleString();
        },

        options:{
          position: 'bottomright',
          autoPlay: true,
          timeSlider: false,
          loopButton: true,
          playerOptions: {
            transitionTime: 125,
            loop: true,
          }
        }
      });
      return new L.Control.TimeDimensionCustom();

    },

    createGeoJson: function () {

      // Retrieve static Data.
      var data = Drupal.markaspot_static_json.getData();

      var feature, features;
      features = [];
      for (var i = 0; i < data.length; i++) {
        feature = {
          type: "Feature",
          properties: {time: data[i]["requested_datetime"]},
          geometry: {
            type: "Point",
            coordinates: [data[i]['long'], data[i]['lat']]
          }
        };
        features.push(feature);
      }
      return {
        type: "FeatureCollection",
        features: features
      };
    },

    createGeoJsonLayer: function (map) {

      // Create a geojson feature from static json module.
      var geoJson = Drupal.markaspot_map.createGeoJson();
      // Set bounds from geojson.
      map.fitBounds(L.geoJson(geoJson).getBounds());
      var currentZoom = map.getZoom();

      if (typeof geoJson !== 'undefined') {
        return L.geoJson(geoJson, {
          pointToLayer: function (feature, latlng) {
            var circle = L.circle(latlng, 3600 / currentZoom, {
              color: '#333',
              className: "auto_hide",
              weight: 1,
              fillColor: '#333',
              fillOpacity: 0.2
            });
            // map.panTo(latlng);
            setTimeout(function () {
              $(".auto_hide").animate({opacity: 0}, 500, function () {
                // Animation complete.
                map.removeLayer(circle);
              });
            }, 3000);
            return circle;
          }
        });
      }
    },

    createGeoJsonTimedLayer: function (map) {

      geoJsonLayer = (typeof geoJsonLayer !== 'undefined') ? geoJsonLayer : Drupal.markaspot_map.createGeoJsonLayer(map);
      // console.log(drupalSettings['mas']['timeline_period']);.
      if (typeof geoJsonLayer !== 'undefined') {
        return new L.TimeDimension.Layer.MaS(geoJsonLayer, {
          updateTimeDimension: true,
          duration: drupalSettings['mas']['timeline_period']
        });
      }
    },

    transformGeoJson2heat: function (geojson, intensity) {
      return geojson.features.map(function (feature) {
        return [
          feature.geometry.coordinates[1],
          feature.geometry.coordinates[0],
          intensity
        ];
      });
    },

    updateHeatMapLayer: function (heatPoint) {
      var heatLayer = {};
      var map = Drupal.Markaspot.maps[0];
      map.eachLayer(function (layer) {
        if (layer.id == "heatTimedLayer") {
          heatLayer = layer;
        }
      });

      heatLayer.addLatLng(heatPoint)
    },

    createHeatMapLayer: function () {

      var geoJson = Drupal.markaspot_map.createGeoJson();
      var heatPoints = Drupal.markaspot_map.transformGeoJson2heat(geoJson, 4);
      return new L.heatLayer(heatPoints, {
        // radius: 10,.
        blur: 25,
        maxZoom: 17,
        // maxOpacity: .4.
      });

    },

    /*
     * Hide Layers
     */
    hideMarkers: function () {
      Drupal.Markaspot.maps[0].closePopup();
      Drupal.Markaspot.maps[0].removeLayer(markerLayer);
    },

    showMarkers: function () {
      Drupal.Markaspot.maps[0].addLayer(markerLayer);
    },
    /*
     * Actions on Marker Click and Hover
     */
    markerClickFn: function (marker, nid) {

      return function () {
        var map = Drupal.Markaspot.maps[0];
        var currentZoom = map.getZoom();
        var fullscreen = map.isFullscreen();
        var target = $('article[data-history-node-id=' + nid + ']');
        // Var target = document.querySelector('data-history-node-id') = nid;
        // var anchor = $(this).attr('data-attr-scroll');
        if (target.length && fullscreen === false) {
          map.setZoom(currentZoom + 2);
          event.preventDefault();
          $('html, body').stop().animate({
            scrollTop: target.offset().top - 200
          }, 1000);
        }
else if (target.length && fullscreen === true) {
          html = target.text();
          marker.bindPopup(html);
        }
      };
    },
    getAwesomeColors: function () {
      awesomeColors = [
        {
          "color": "red", "hex": "#FF0000"
        }, {
          "color": "darkred", "hex": "#8B0000"
        }, {
          "color": "orange", "hex": "#FFA500", "iconColor": "dark-red"
        }, {
          "color": "green", "hex": "#008000"
        }, {
          "color": "darkgreen", "hex": "#006400"
        }, {
          "color": "blue", "hex": "#0000FF"
        }, {
          "color": "darkblue", "hex": "#00008B"
        }, {
          "color": "purple", "hex": "#A020F0"
        }, {
          "color": "darkpurple", "hex": "#871F78"
        }, {
          "color": "cadetblue", "hex": "#5F9EA0"
        }, {
          "color": "lightblue", "hex": "#ADD8E6", "iconColor": "#000000"
        }, {
          "color": "lightgray", "hex": "#D3D3D3", "iconColor": "#000000"
        }, {
          "color": "gray", "hex": "#808080"
        }, {
          "color": "black", "hex": "#000000"
        }, {
          "color": "beige", "hex": "#F5F5DC", "iconColor": "darkred"
        }, {
          "color": "white", "hex": "#FFFFFF", "iconColor": "#000000"
        }
      ];

      return awesomeColors;
    },
    /*
     * Show Data out of filtered dataset
     */
    showData: function (dataset) {
      if (dataset.status == 404) {
        // bootbox.alert(Drupal.t('No reports found for this category/status'));.
        return false;

      }
      var statusColors = [];

      var awesomeColors = Drupal.markaspot_map.getAwesomeColors();

      $.each(dataset, function (service_requests, request) {

        var categoryColor = request.extended_attributes.markaspot.category_hex;
        colorswitch = categoryColor ? categoryColor.toUpperCase() : '#000000';

        $.each(awesomeColors, function (key, element) {

          if (colorswitch == element.hex) {
            var awesomeColor = element.color;
            var awesomeIcon = request.extended_attributes.markaspot.category_icon;
            var iconColor = element.iconColor ? element.iconColor : "#ffffff";

            icon = L.AwesomeMarkers.icon({
              icon: awesomeIcon,
              prefix: 'fa',
              markerColor: awesomeColor,
              iconColor: iconColor
            });
          }
        });

        var statusColor = request.extended_attributes.markaspot.status_hex;
        var categoryColor = request.extended_attributes.markaspot.category_hex;

        var nid = request.extended_attributes.markaspot.nid;
        var markerColor = statusColor ? statusColor : categoryColor;
        var latlon = new L.LatLng(request.lat, request.long);
        var marker = new L.Marker(latlon, {
          icon: icon,
          title: nid,
          color: markerColor,
          time: request.requested_datetime
        });
        marker.on('click', Drupal.markaspot_map.markerClickFn(marker, nid));

        markerLayer.addLayer(marker);
      });
      var size = markerLayer.getLayers().length;

      if (size >= 1) {
        // console.log(markerLayer.getBounds());
        Drupal.Markaspot.maps[0].fitBounds(markerLayer.getBounds(), {
          padding: [
            -150,
            -150
          ]
        });
      }
      return markerLayer;

    },

    /*
     * Parse data out of static or dynamic geojson
     */
    load: function (getData, nids) {
      var url = drupalSettings.path.baseUrl;
      url = url + 'georeport/v2/requests.json?extensions=true&nids=' + nids;
      return $.getJSON(url)
        .done(function (data) {
          getData(data);
        })
        .fail(function (data) {
          getData(data);
        });
    },

    getNids: function (selector) {
      var serviceRequests = $(selector);
      var nids = [];
      for (var i = 0, length = serviceRequests.length; i < length; i++) {
        // console.log(i);
        var element = serviceRequests[i];
        // console.log(element);
        nids.push(element.getAttribute('data-history-node-id'));
        // console.log(nids);
      }
      return nids;
    }
  }

})(jQuery, Drupal, drupalSettings, Date);
