/**
 * @file
 * Main Map-Application File with Leaflet Maps API.
 */
L.TimeDimension.Layer.MaS = L.TimeDimension.Layer.GeoJson.extend({
  _update: function _update() {
    if (!this._map) {
      return;
    }

    if (!this._loaded) {
      return;
    }

    var requestTime = this._timeDimension.getCurrentTime();

    var formattime = dateFormat(
      requestTime,
      Drupal.Markaspot.settings.timeline_date_format
    );

    var maxTime = this._timeDimension.getCurrentTime();

    var minTime = 0;

    if (this._duration) {
      var date = new Date(maxTime);
      L.TimeDimension.Util.subtractTimeDuration(date, this._duration, true);
      minTime = date.getTime();
    } // New coordinates:

    var layer = L.geoJson(null, this._baseLayer.options);

    var layers = this._baseLayer.getLayers();

    for (var i = 0, l = layers.length; i < l; i++) {
      var feature = this._getFeatureBetweenDates(
        layers[i].feature,
        minTime,
        maxTime
      );

      if (feature) {
        layer.addData(feature);

        if (this._addlastPoint && feature.geometry.type === "LineString") {
          if (feature.geometry.coordinates.length > 0) {
            var properties = feature.properties.properties;
            properties.last = true;
            layer.addData({
              type: "Feature",
              properties: properties,
              geometry: {
                type: "Point",
                coordinates:
                  feature.geometry.coordinates[
                  feature.geometry.coordinates.length - 1
                    ]
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
      var log = jQuery("ul.log_list");
      log.append(
        '<li><span class="time">'
          .concat(formattime, '</span> <span class="count">')
          .concat(requests, "</span>")
      );
      var height = log.get(0).scrollHeight;
      log.animate(
        {
          scrollTop: height
        },
        10
      );
      layer.addTo(this._map);
      this._currentLayer = layer;
    }
  }
});

(function($, Drupal, drupalSettings) {
  // 'use strict';.
  Drupal.Markaspot = {};
  Drupal.Markaspot.maps = [];
  var markerLayer;
  var scrolledMarker = [];
  var currentPath = drupalSettings.path.currentPath;
  currentPath = "/".concat(currentPath);
  var masSettings = drupalSettings.mas;

  Drupal.behaviors.markaspot_map = {
    attach: function attach(context, settings) {
      Drupal.Markaspot.settings = masSettings; // Make map stick to the page top or wherever, override via theme.

      var mapSelector = $("#map");
      if (typeof mapSelector === 'undefined'){
          return;
      }
      mapSelector.once("markaspot_map").each(function() {
        $(".log_header .left").text(Drupal.t("Date"));
        $(".log_header .right").text(Drupal.t("Requests"));
        Drupal.Markaspot.maps[0] = L.map("map", {
          scrollWheelZoom: !L.Browser.mobile,
          maxZoom: 18,
          dragging: !L.Browser.mobile,
          zoom: masSettings.zoom_initial
        }); // console.log(masSettings.zoom_initial,Drupal.Markaspot.maps[0].getZoom());
        // console.log(Drupal.Markaspot.maps[0].getCenter());

        $("#map").css("background-color:".concat(masSettings.map_background));
        var tileLayer;
        if (masSettings.osm_custom_tile_url !== "") {
          tileLayer = L.tileLayer(masSettings.osm_custom_tile_url);
        } else {
          tileLayer = L.tileLayer.wms(masSettings.wms_service, { layers: masSettings.wms_layer });
        }
        var map = Drupal.Markaspot.maps[0];
        map.attributionControl.addAttribution(
          masSettings.osm_custom_attribution
        );
        map.addLayer(tileLayer);
        markerLayer = L.markerClusterGroup({
          maxClusterRadius: 20
        });
        map.addLayer(markerLayer); // Initital heat map layer for front page:

        if (currentPath === "/node") {
          Drupal.markaspot_map.createHeatMapLayer(map); // heatMapLayer.addTo(map);
          Drupal.markaspot_map.setDefaults();
        }

        if (
          currentPath === masSettings.visualization_path ||
          currentPath === "/home"
        ) {
          // Drupal.markaspot_map.hideMarkers();
          var geoJsonTimedLayer = Drupal.markaspot_map.createGeoJsonTimedLayer(
            map
          );
          var heatStart = [[masSettings.center_lat, masSettings.center_lng, 1]];
          var heatLayer = new L.HeatLayer(heatStart).addTo(map);
          heatLayer.id = "heatTimedLayer"; // Show Markers additionally ob button click.

          var timeDimensionControl = Drupal.markaspot_map.showTimeController(
            map
          ); // Show Markers additionally ob button click.

          var heatControls = L.easyButton({
            position: "bottomright",
            states: [
              {
                stateName: "add-heatControls",
                icon: "fa-thermometer-4",
                title: Drupal.t("Show Heatmap"),
                onClick: function onClick(control) {
                  Drupal.markaspot_map.showTimeController(map);
                  Drupal.markaspot_map.createGeoJsonTimedLayer(map);
                  control.state("remove-heatControls");
                  control.heatMapLayer = Drupal.markaspot_map.createHeatMapLayer();
                  control.heatMapLayer.addTo(map);
                }
              },
              {
                stateName: "remove-heatControls",
                icon: "fa-thermometer-4 active",
                title: Drupal.t("Hide Heatmap"),
                onClick: function onClick(control) {
                  map.removeLayer(control.heatMapLayer);
                  control.state("add-heatControls");
                }
              }
            ]
          });
          heatControls.addTo(map);
          var timeControls = L.easyButton({
            position: "bottomright",
            states: [
              {
                stateName: "add-timeControls",
                icon: "fa-clock-o",
                title: Drupal.t("Show TimeControl Layer"),
                onClick: function onClick(control) {
                  $("div.log").show();
                  control.state("remove-timeControls");
                  map.addControl(timeDimensionControl);
                  heatLayer.addTo(map);
                  geoJsonTimedLayer.addTo(map);
                }
              },
              {
                icon: "fa-clock-o active",
                stateName: "remove-timeControls",
                title: Drupal.t("Remove TimeControl Layer"),
                onClick: function onClick(control) {
                  $("div.log").hide();
                  map.removeControl(timeDimensionControl);
                  map.removeLayer(geoJsonTimedLayer);
                  map.removeLayer(heatLayer);
                  control.state("add-timeControls");
                  $("ul.log_list").empty();
                }
              }
            ]
          });
          timeControls.addTo(map);
        } // Empty storedNids.

        localStorage.setItem("storedNids", JSON.stringify("")); // End once.
      }); // Get all nids to be called via ajax(Open311).
      // jQuery.Once is not working with ajax loaded views, which means
      // requests.json is loaded twice in logged in state. We check which nids
      // are shown already and we now store nids in localStorage.

      var storedNids = JSON.parse(localStorage.getItem("storedNids"));
      var nids = Drupal.markaspot_map.getNids(masSettings.nid_selector);

      if (!nids.length) {
        Drupal.markaspot_map.setDefaults(masSettings);
      }

      if (JSON.stringify(nids) !== JSON.stringify(storedNids)) {
        localStorage.setItem("storedNids", JSON.stringify(nids));
        if (typeof markerLayer !== "undefined"){
            markerLayer.clearLayers(); // Load and showData on map.
        
            Drupal.markaspot_map.load(function(data) {
              Drupal.markaspot_map.showData(data);
              markerLayer.eachLayer(function(layer) {
                // Define marker-properties for Scrolling.
                var nid = layer.options.nid;
                scrolledMarker[nid] = {
                  latlng: layer.getLatLng(),
                  nid: layer.options.nid,
                  color: layer.options.color
                };
              });
            }, nids
          );
        }
      } // Theme independent selector.

      var $serviceRequests = $(masSettings.nid_selector);
      $serviceRequests.hover(function() {
        var nid = this.dataset.historyNodeId;
        var $node = this;
        scrolledMarker.forEach(function(value){
          if (value['nid'] == nid) {
            $node.classList.toggle("focus");
            Drupal.markaspot_map.showCircle(scrolledMarker[nid]);
          }
        })
      }); // Loop through all current teasers.

      $serviceRequests.each(function() {
        new Waypoint({
          element: this,
          handler: function handler(direction) {
            var nid = this.element.dataset.historyNodeId;

            if (typeof scrolledMarker[nid] !== "undefined") {
              Drupal.markaspot_map.showCircle(scrolledMarker[nid]);
              this.element.classList.add("focus");
            } else {
              this.element.classList.add("no-location");
              Drupal.Markaspot.maps[0].setView([masSettings.center_lat, masSettings.center_lng],10);
              return;                
            }

            if (direction === "up") {
              this.element.classList.remove("focus");
            }
          },
          offset: "40%"
        });
      });
    }
  };
  Drupal.markaspot_map = {
    setDefaults: function setDefaults() {
      var defaultCenter = new L.LatLng(
        masSettings.center_lat,
        masSettings.center_lng
      );
      var map = Drupal.Markaspot.maps[0];
      if (typeof map !== "undefined") {
        map.setView(defaultCenter, masSettings.zoom_initial);
      }
    },
    // Showing a Circle Marker on hover and scroll over.
    showCircle: function showCircle(marker) {
      var markerId = marker.nid;
      if (typeof markerId === undefined || markerId === this.marker) {
          return;
      }
      this.marker = markerId;
      var map = Drupal.Markaspot.maps[0]; // Get zoomlevel to set circle radius.

      var currentZoom = map.getZoom();
      var mapDefaultZoom = masSettings.zoom_initial;

      if (typeof marker === "undefined") {
        return;
      }

      var color = marker.color;
      var circle = L.circle(marker.latlng, 3600 / mapDefaultZoom, {
        color: color,
        className: "auto_hide",
        weight: 1,
        fillColor: color,
        fillOpacity: 0.2
      }).addTo(map);

      map.flyTo(marker.latlng, mapDefaultZoom, {
        duration: 0.8
      }); // console.log(map.getZoom());
      map.invalidateSize();

      setTimeout(function() {
        $(".auto_hide").animate(
          {
            opacity: 0
          },
          500,
          function() {
            map.removeLayer(circle);
          }
        );
      }, 1000);
      return circle;
    },
    showTimeController: function showTimeController(map) {
      // Start of TimeDimension manual instantiation.
      map.timeDimension = new L.TimeDimension({
        period: drupalSettings.mas.timeline_period
      });
      L.Control.TimeDimensionCustom = L.Control.TimeDimension.extend({
        _getDisplayDateFormat: function _getDisplayDateFormat(date) {
          return this._dateUTC ? date.toISOString() : date.toLocaleString();
        },
        options: {
          position: "bottomright",
          minSpeed: 0.1,
          maxSpeed: 50,
          speedStep: 1,
          timeSteps: 1,
          displayDate: true,
          autoPlay: true,
          timeSlider: false,
          loopButton: true,
          playerOptions: {
            transitionTime: 125,
            loop: true
          }
        }
      });
      return new L.Control.TimeDimensionCustom();
    },
    trendMarker: function trendMarker(param) {
      markerLayer.clearLayers();
      var data = Drupal.markaspot_static_json.getData();
      var filterString = param.substr(1).replace("/", "-");
      var filteredData = data.filter(function(i) {
        return i.requested_datetime.indexOf(filterString) > -1;
      });
      var geojson = this.createGeoJson(filteredData);
      var heatPoints = Drupal.markaspot_map.transformGeoJson2heat(geojson, 4); // Define heatlayer

      var heatLayer = new L.HeatLayer(heatPoints, {});
      markerLayer.addLayer(heatLayer);
      this.showMarkers(); // heatLayer.remove();
    },
    createGeoJson: function createGeoJson(data) {
      // Retrieve static Data.
      if (typeof data !== "undefined") {
        var feature;
        var features = [];

        if (data.length) {
          for (var i = 0; i < data.length; i++) {
            feature = {
              type: "Feature",
              properties: {
                time: data[i].requested_datetime
              },
              geometry: {
                type: "Point",
                coordinates: [data[i].long, data[i].lat]
              }
            };
            features.push(feature);
          }
        }

        return {
          type: "FeatureCollection",
          features: features
        };
      }
    },
    retrieveStaticData: function retrieveStaticData() {
      return Drupal.markaspot_static_json.getData();
    },
    createGeoJsonLayer: function createGeoJsonLayer(map) {
      // Create a geojson feature from static json module.
      var data = this.retrieveStaticData();
      var geoJson = this.createGeoJson(data); // Set bounds from geojson.

      map.fitBounds(L.geoJson(geoJson).getBounds());
      var currentZoom = map.getZoom();

      if (typeof geoJson !== "undefined") {
        return L.geoJson(geoJson, {
          pointToLayer: function pointToLayer(feature, latlng) {
            var circle = L.circle(latlng, 3600 / currentZoom, {
              color: "#333",
              className: "auto_hide",
              weight: 1,
              fillColor: "#333",
              fillOpacity: 0.2
            }); // map.panTo(latlng);

            setTimeout(function() {
              $(".auto_hide").animate(
                {
                  opacity: 0
                },
                500,
                function() {
                  map.removeLayer(circle);
                }
              );
            }, 3000);
            return circle;
          }
        });
      }
    },
    createGeoJsonTimedLayer: function createGeoJsonTimedLayer(map) {
      var geoJsonLayer = Drupal.markaspot_map.createGeoJsonLayer(map); // console.log(drupalSettings['mas']['timeline_period']);.

      if (typeof geoJsonLayer !== "undefined") {
        return new L.TimeDimension.Layer.MaS(geoJsonLayer, {
          updateTimeDimension: true,
          duration: drupalSettings.mas.timeline_period
        });
      }
    },
    transformGeoJson2heat: function transformGeoJson2heat(geojson, intensity) {
      return geojson.features.map(function(feature) {
        return [
          feature.geometry.coordinates[1],
          feature.geometry.coordinates[0],
          intensity
        ];
      });
    },
    updateHeatMapLayer: function updateHeatMapLayer(heatPoint) {
      var heatLayer = {};
      var map = Drupal.Markaspot.maps[0];
      map.eachLayer(function(layer) {
        if (layer.id === "heatTimedLayer") {
          heatLayer = layer;
        }
      });
      heatLayer.addLatLng(heatPoint);
    },
    createHeatMapLayer: function createHeatMapLayer() {
      var data = this.retrieveStaticData();
      var geoJson = this.createGeoJson(data);
      var heatPoints = this.transformGeoJson2heat(geoJson, 4);
      return new L.HeatLayer(heatPoints, {
        // radius: 10,.
        blur: 25,
        maxZoom: 17 // maxOpacity: .4.
      });
    },
    hideMarkers: function hideMarkers() {
      Drupal.Markaspot.maps[0].closePopup();
      Drupal.Markaspot.maps[0].removeLayer(markerLayer);
    },
    showMarkers: function showMarkers() {
      Drupal.Markaspot.maps[0].addLayer(markerLayer);
    },
    markerClickFn: function markerClickFn(marker, nid) {
      return function markerClick() {
        var map = Drupal.Markaspot.maps[0];
        var currentZoom = map.getZoom();
        var fullscreen = map.isFullscreen();
        var target = $("article[data-history-node-id=".concat(nid, "]"));

        if (target.length && fullscreen === false && currentPath !== "/home") {
          map.setZoom(currentZoom + 2); // event.preventDefault();

          $("html, body")
            .stop()
            .animate(
              {
                scrollTop: target.offset().top - 250
              },
              1000
            );
        } else if (
          (target.length && fullscreen === true) ||
          currentPath === "/home"
        ) {
          var html = target.find("h2").html();
          marker.bindPopup(html);
        }
      };
    },
    getAwesomeColors: function getAwesomeColors() {
      return [
        {
          color: "red",
          hex: "#FF0000"
        },
        {
          color: "darkred",
          hex: "#8B0000"
        },
        {
          color: "orange",
          hex: "#FFA500",
          iconColor: "dark-red"
        },
        {
          color: "green",
          hex: "#008000"
        },
        {
          color: "darkgreen",
          hex: "#006400"
        },
        {
          color: "blue",
          hex: "#0000FF"
        },
        {
          color: "darkblue",
          hex: "#00008B"
        },
        {
          color: "purple",
          hex: "#A020F0"
        },
        {
          color: "darkpurple",
          hex: "#871F78"
        },
        {
          color: "cadetblue",
          hex: "#5F9EA0"
        },
        {
          color: "lightblue",
          hex: "#ADD8E6",
          iconColor: "#000000"
        },
        {
          color: "lightgray",
          hex: "#D3D3D3",
          iconColor: "#000000"
        },
        {
          color: "gray",
          hex: "#808080"
        },
        {
          color: "black",
          hex: "#000000"
        },
        {
          color: "beige",
          hex: "#F5F5DC",
          iconColor: "darkred"
        },
        {
          color: "white",
          hex: "#FFFFFF",
          iconColor: "#000000"
        }
      ];
    },
    showData: function showData(dataset) {
      var _this = this;

      if (dataset.status === 404) {
        // bootbox.alert(Drupal.t('No reports found for this category/status'));.
        return false;
      }

      var awesomeColors = this.getAwesomeColors();
      $.each(dataset, function(serviceRequests, request) {
        var categoryColor = request.extended_attributes.markaspot.category_hex;
        var colorswitch = categoryColor
          ? categoryColor.toUpperCase()
          : "#000000";
        $.each(awesomeColors, function(key, element) {
          if (colorswitch === element.hex) {
            var awesomeColor = element.color;
            var awesomeIcon =
              request.extended_attributes.markaspot.category_icon;
            var iconColor = element.iconColor ? element.iconColor : "#ffffff";
            var icon = L.AwesomeMarkers.icon({
              icon: awesomeIcon,
              prefix: "fa",
              markerColor: awesomeColor,
              iconColor: iconColor
            });
            var nid = request.extended_attributes.markaspot.nid;
            var markerColor = categoryColor;
            var latlon = new L.LatLng(request.lat, request.long);

            // No markers on default position
            masSettings =  drupalSettings.mas;
            const center = {};
            const pos = {};
            pos.long = Number((request.long).toFixed(3));
            pos.lat = Number((request.lat).toFixed(3));
            center.lat = parseFloat(masSettings.center_lat).toFixed(3);
            center.lng = parseFloat(masSettings.center_lng).toFixed(3);
            if (center.lat == pos.lat && center.lng == pos.long) {

              return;
            }

            var marker = new L.Marker(latlon, {
              icon: icon,
              nid: nid,
              color: markerColor,
              time: request.requested_datetime
            });
            marker.on("click", _this.markerClickFn(marker, nid));
            markerLayer.addLayer(marker);
          }
        });
      });
      var size = markerLayer.getLayers().length;

      if (size >= 1) {
        Drupal.Markaspot.maps[0].fitBounds(markerLayer.getBounds(), {
          padding: [50, 50]
        });
      } else {
        Drupal.markaspot_map.setDefaults();
      }

      return markerLayer;
    },

    /*
     * Parse data out of static or dynamic geojson
     */
    load: function load(getData, nids) {
      var url = drupalSettings.path.baseUrl;
      url = ""
        .concat(url, "georeport/v2/requests.json?extensions=true&nids=")
        .concat(nids);
      return $
        .getJSON(url)
        .done(function(data) {
          getData(data);
        })
        .fail(function(data) {
          getData(data);
        });
    },
    getNids: function getNids(selector) {
      var serviceRequests = $(selector);
      var nids = [];

      for (var i = 0, length = serviceRequests.length; i < length; i++) {
        // console.log(i);
        var element = serviceRequests[i]; // console.log(element);

        nids.push(element.getAttribute("data-history-node-id")); // console.log(nids);
      }

      return nids;
    }
  };
})(jQuery, Drupal, drupalSettings);
