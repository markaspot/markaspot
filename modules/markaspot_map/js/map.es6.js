/**
 * @file
 * Main Map-Application File with Leaflet Maps API.
 */
L.TimeDimension.Layer.MaS = L.TimeDimension.Layer.GeoJson.extend({
  _update() {
    if (!this._map) {
      return;
    }
    if (!this._loaded) {
      return;
    }
    const requestTime = this._timeDimension.getCurrentTime();
    const formattime = dateFormat(
      requestTime,
      Drupal.Markaspot.settings.timeline_date_format
    );
    const maxTime = this._timeDimension.getCurrentTime();
    let minTime = 0;
    if (this._duration) {
      const date = new Date(maxTime);
      L.TimeDimension.Util.subtractTimeDuration(date, this._duration, true);
      minTime = date.getTime();
    }

    // New coordinates:
    const layer = L.geoJson(null, this._baseLayer.options);
    const layers = this._baseLayer.getLayers();
    for (let i = 0, l = layers.length; i < l; i++) {
      const feature = this._getFeatureBetweenDates(
        layers[i].feature,
        minTime,
        maxTime
      );
      if (feature) {
        layer.addData(feature);
        if (this._addlastPoint && feature.geometry.type === "LineString") {
          if (feature.geometry.coordinates.length > 0) {
            const { properties } = feature.properties;
            properties.last = true;
            layer.addData({
              type: "Feature",
              properties,
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
      const requests = layer.getLayers().length;
      const log = jQuery("ul.log_list");
      log.append(
        `<li><span class="time">${formattime}</span> <span class="count">${requests}</span>`
      );
      const height = log.get(0).scrollHeight;
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
  let markerLayer;
  const scrolledMarker = [];
  let { currentPath } = drupalSettings.path;
  currentPath = `/${currentPath}`;
  const masSettings = drupalSettings.mas;

  Drupal.behaviors.markaspot_map = {
    attach(context, settings) {
      Drupal.Markaspot.settings = masSettings;
      // Make map stick to the page top or wherever, override via theme.
      const mapSelector = $("#map");
      if (typeof mapSelector === 'undefined'){
        return;
      }
      mapSelector.once("markaspot_map").each(() => {
        $(".log_header .left").text(Drupal.t("Date"));
        $(".log_header .right").text(Drupal.t("Requests"));
        Drupal.Markaspot.maps[0] = L.map("map", {
          fullscreenControl: true,
          scrollWheelZoom: !L.Browser.mobile,
          maxZoom: 18,
          dragging: !L.Browser.mobile,
          zoom: masSettings.zoom_initial
        });
        // console.log(masSettings.zoom_initial,Drupal.Markaspot.maps[0].getZoom());
        // console.log(Drupal.Markaspot.maps[0].getCenter());
        $("#map").css(`background-color:${masSettings.map_background}`);
        let tileLayer;
        if (masSettings.osm_custom_tile_url !== "") {
          tileLayer = L.tileLayer(masSettings.osm_custom_tile_url);
        } else {
          tileLayer = L.tileLayer.wms(masSettings.wms_service, { layers: masSettings.wms_layer });
        }
        const map = Drupal.Markaspot.maps[0];
        map.attributionControl.addAttribution(
          masSettings.osm_custom_attribution
        );
        map.addLayer(tileLayer);

        markerLayer = L.markerClusterGroup({
          maxClusterRadius: 20
        });
        map.addLayer(markerLayer);

        // Initital heat map layer for front page:

        if (currentPath === "/node") {
          Drupal.markaspot_map.createHeatMapLayer(map);
          // heatMapLayer.addTo(map);
          Drupal.markaspot_map.setDefaults();
        }

        if (
          currentPath === masSettings.visualization_path ||
          currentPath === "/home"
        ) {
          // Drupal.markaspot_map.hideMarkers();

          const geoJsonTimedLayer = Drupal.markaspot_map.createGeoJsonTimedLayer(
            map
          );
          const heatStart = [
            [masSettings.center_lat, masSettings.center_lng, 1]
          ];
          const heatLayer = new L.HeatLayer(heatStart).addTo(map);
          heatLayer.id = "heatTimedLayer";
          // Show Markers additionally ob button click.
          const timeDimensionControl = Drupal.markaspot_map.showTimeController(
            map
          );
          // Show Markers additionally ob button click.
          const heatControls = L.easyButton({
            position: "bottomright",
            states: [
              {
                stateName: "add-heatControls",
                icon: "fa-thermometer-4",
                title: Drupal.t("Show Heatmap"),
                onClick(control) {
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
                onClick(control) {
                  map.removeLayer(control.heatMapLayer);
                  control.state("add-heatControls");
                }
              }
            ]
          });

          heatControls.addTo(map);

          const timeControls = L.easyButton({
            position: "bottomright",
            states: [
              {
                stateName: "add-timeControls",
                icon: "fa-clock-o",
                title: Drupal.t("Show TimeControl Layer"),
                onClick(control) {
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
                onClick(control) {
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
        }
        // Empty storedNids.
        localStorage.setItem("storedNids", JSON.stringify(""));
        // End once.
      });

      // Get all nids to be called via ajax(Open311).
      // jQuery.Once is not working with ajax loaded views, which means
      // requests.json is loaded twice in logged in state. We check which nids
      // are shown already and we now store nids in localStorage.
      const storedNids = JSON.parse(localStorage.getItem("storedNids"));
      const nids = Drupal.markaspot_map.getNids(masSettings.nid_selector);

      if (!nids.length) {
        Drupal.markaspot_map.setDefaults(masSettings);
      }
      if (JSON.stringify(nids) !== JSON.stringify(storedNids)) {
        localStorage.setItem("storedNids", JSON.stringify(nids));
        if (typeof markerLayer !== "undefined"){
          markerLayer.clearLayers(); // Load and showData on map.
                // Load and showData on map.
            Drupal.markaspot_map.load(data => {
              Drupal.markaspot_map.showData(data);
              markerLayer.eachLayer(layer => {
                // Define marker-properties for Scrolling.
                const { nid } = layer.options;
                scrolledMarker[nid] = {
                  latlng: layer.getLatLng(),
                  nid: layer.options.nid,
                  color: layer.options.color
                };
              });
            }, nids);
        }
      }
      // Theme independent selector.
      const $serviceRequests = $(masSettings.nid_selector);
      $serviceRequests.hover(function() {
        const nid = this.dataset.historyNodeId;
        const $node = this;
        scrolledMarker.forEach(function(value){
          if (value["nid"] == nid) {
            $node.classList.toggle("focus");
            Drupal.markaspot_map.showCircle(scrolledMarker[nid]);
          }
        })
      });
      // Loop through all current teasers.

      $serviceRequests.each(function() {
        new Waypoint({
          element: this,
          handler(direction) {
            const nid = this.element.dataset.historyNodeId;
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
    setDefaults() {
      const defaultCenter = new L.LatLng(
        masSettings.center_lat,
        masSettings.center_lng
      );
      const map = Drupal.Markaspot.maps[0];
      if (typeof map !== "undefined") {
        map.setView(defaultCenter, masSettings.zoom_initial);
      }
    },
    // Showing a Circle Marker on hover and scroll over.
    showCircle(marker) {
      const markerId = marker.nid;

      if (typeof markerId === undefined || markerId === this.marker) {
        return;
      }
      this.marker = markerId;

      const map = Drupal.Markaspot.maps[0];
      // Get zoomlevel to set circle radius.
      const currentZoom = map.getZoom();
      const mapDefaultZoom = masSettings.zoom_initial;
      if (typeof marker === "undefined") {
        return;
      }
      const { color } = marker;
      const circle = L.circle(marker.latlng, 3600 / mapDefaultZoom, {
        color,
        className: "auto_hide",
        weight: 1,
        fillColor: color,
        fillOpacity: 0.2
      }).addTo(map);
      map.flyTo(marker.latlng, mapDefaultZoom, { duration: 0.8 });
      map.invalidateSize();
      // console.log(map.getZoom());
      setTimeout(() => {
        $(".auto_hide").animate({ opacity: 0 }, 500, () => {
          map.removeLayer(circle);
        });
      }, 1000);
      return circle;
    },

    showTimeController(map) {
      // Start of TimeDimension manual instantiation.
      map.timeDimension = new L.TimeDimension({
        period: drupalSettings.mas.timeline_period
      });
      L.Control.TimeDimensionCustom = L.Control.TimeDimension.extend({
        _getDisplayDateFormat(date) {
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

    trendMarker(param) {
      markerLayer.clearLayers();
      const data = Drupal.markaspot_static_json.getData();
      const filterString = param.substr(1).replace("/", "-");
      const filteredData = data.filter(
        i => i.requested_datetime.indexOf(filterString) > -1
      );
      const geojson = this.createGeoJson(filteredData);
      const heatPoints = Drupal.markaspot_map.transformGeoJson2heat(geojson, 4);
      // Define heatlayer
      const heatLayer = new L.HeatLayer(heatPoints, {});
      markerLayer.addLayer(heatLayer);
      this.showMarkers();
      // heatLayer.remove();
    },
    createGeoJson(data) {
      // Retrieve static Data.
      if (typeof data !== "undefined") {
        let feature;
        const features = [];
        if (data.length) {
          for (let i = 0; i < data.length; i++) {
            feature = {
              type: "Feature",
              properties: { time: data[i].requested_datetime },
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
          features
        };
      }
    },

    retrieveStaticData() {
      return Drupal.markaspot_static_json.getData();
    },
    createGeoJsonLayer(map) {
      // Create a geojson feature from static json module.
      const data = this.retrieveStaticData();
      const geoJson = this.createGeoJson(data);
      // Set bounds from geojson.
      map.fitBounds(L.geoJson(geoJson).getBounds());
      const currentZoom = map.getZoom();

      if (typeof geoJson !== "undefined") {
        return L.geoJson(geoJson, {
          pointToLayer(feature, latlng) {
            const circle = L.circle(latlng, 3600 / currentZoom, {
              color: "#333",
              className: "auto_hide",
              weight: 1,
              fillColor: "#333",
              fillOpacity: 0.2
            });
            // map.panTo(latlng);
            setTimeout(() => {
              $(".auto_hide").animate({ opacity: 0 }, 500, () => {
                map.removeLayer(circle);
              });
            }, 3000);
            return circle;
          }
        });
      }
    },

    createGeoJsonTimedLayer(map) {
      const geoJsonLayer = Drupal.markaspot_map.createGeoJsonLayer(map);
      // console.log(drupalSettings['mas']['timeline_period']);.
      if (typeof geoJsonLayer !== "undefined") {
        return new L.TimeDimension.Layer.MaS(geoJsonLayer, {
          updateTimeDimension: true,
          duration: drupalSettings.mas.timeline_period
        });
      }
    },

    transformGeoJson2heat(geojson, intensity) {
      return geojson.features.map(feature => [
        feature.geometry.coordinates[1],
        feature.geometry.coordinates[0],
        intensity
      ]);
    },

    updateHeatMapLayer(heatPoint) {
      let heatLayer = {};
      const map = Drupal.Markaspot.maps[0];
      map.eachLayer(layer => {
        if (layer.id === "heatTimedLayer") {
          heatLayer = layer;
        }
      });

      heatLayer.addLatLng(heatPoint);
    },

    createHeatMapLayer() {
      const data = this.retrieveStaticData();
      const geoJson = this.createGeoJson(data);
      const heatPoints = this.transformGeoJson2heat(geoJson, 4);
      return new L.HeatLayer(heatPoints, {
        // radius: 10,.
        blur: 25,
        maxZoom: 17
        // maxOpacity: .4.
      });
    },

    hideMarkers() {
      Drupal.Markaspot.maps[0].closePopup();
      Drupal.Markaspot.maps[0].removeLayer(markerLayer);
    },

    showMarkers() {
      Drupal.Markaspot.maps[0].addLayer(markerLayer);
    },

    markerClickFn(marker, nid) {
      return function markerClick() {
        const map = Drupal.Markaspot.maps[0];
        const currentZoom = map.getZoom();
        const fullscreen = map.isFullscreen();
        const target = $(`article[data-history-node-id=${nid}]`);
        if (target.length && fullscreen === false && currentPath !== "/home") {
          map.setZoom(currentZoom + 2);
          // event.preventDefault();
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
          const html = target.find("h2").html();
          marker.bindPopup(html);
        }
      };
    },
    getAwesomeColors() {
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

    showData(dataset) {
      if (dataset.status === 404) {
        // bootbox.alert(Drupal.t('No reports found for this category/status'));.
        return false;
      }

      const awesomeColors = this.getAwesomeColors();

      $.each(dataset, (serviceRequests, request) => {
        const categoryColor =
          request.extended_attributes.markaspot.category_hex;
        const colorswitch = categoryColor
          ? categoryColor.toUpperCase()
          : "#000000";

        $.each(awesomeColors, (key, element) => {
          if (colorswitch === element.hex) {
            const awesomeColor = element.color;
            const awesomeIcon =
              request.extended_attributes.markaspot.category_icon;
            const iconColor = element.iconColor ? element.iconColor : "#ffffff";

            const icon = L.AwesomeMarkers.icon({
              icon: awesomeIcon,
              prefix: "fa",
              markerColor: awesomeColor,
              iconColor
            });

            const { nid } = request.extended_attributes.markaspot;
            const markerColor = categoryColor;
            const latlon = new L.LatLng(request.lat, request.long);

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

            const marker = new L.Marker(latlon, {
              icon,
              nid,
              color: markerColor,
              time: request.requested_datetime
            });
            marker.on("click", this.markerClickFn(marker, nid));
            markerLayer.addLayer(marker);
          }
        });
      });
      const size = markerLayer.getLayers().length;

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
    load(getData, nids) {
      let url = drupalSettings.path.baseUrl;
      url = `${url}georeport/v2/requests.json?extensions=true&nids=${nids}`;
      return $.getJSON(url)
        .done(data => {
          getData(data);
        })
        .fail(data => {
          getData(data);
        });
    },

    getNids(selector) {
      const serviceRequests = $(selector);
      const nids = [];
      for (let i = 0, { length } = serviceRequests; i < length; i++) {
        // console.log(i);
        const element = serviceRequests[i];
        // console.log(element);
        nids.push(element.getAttribute("data-history-node-id"));
        // console.log(nids);
      }
      return nids;
    }
  };
})(jQuery, Drupal, drupalSettings);
