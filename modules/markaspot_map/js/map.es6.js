/**
 * @file
 * Main Map-Application File with Leaflet Maps API.
 */

function invertColor(hex, bw) {
  if (hex.indexOf('#') === 0) {
    hex = hex.slice(1);
  }
  // convert 3-digit hex to 6-digits.
  if (hex.length === 3) {
    hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
  }
  if (hex.length !== 6) {
    throw new Error('Invalid HEX color.');
  }
  var r = parseInt(hex.slice(0, 2), 16),
      g = parseInt(hex.slice(2, 4), 16),
      b = parseInt(hex.slice(4, 6), 16);
  if (bw) {
    // http://stackoverflow.com/a/3943023/112731
    return (r * 0.299 + g * 0.587 + b * 0.114) > 186
        ? '#000000'
        : '#FFFFFF';
  }
  // invert color components
  r = (255 - r).toString(16);
  g = (255 - g).toString(16);
  b = (255 - b).toString(16);
  // pad each with zeros and return
  return "#" + padZero(r) + padZero(g) + padZero(b);
}

function padZero(str, len) {
  len = len || 2;
  var zeros = new Array(len).join('0');
  return (zeros + str).slice(-len);
}


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
        Drupal.Markaspot.maps[0] = L.map("map", {
          fullscreenControl: true,
          scrollWheelZoom: !L.Browser.mobile,
          minZoom: 12,
          maxZoom: 18,
          dragging: !L.Browser.mobile,
          zoom: masSettings.zoom_initial
        });
        // console.log(masSettings.zoom_initial,Drupal.Markaspot.maps[0].getZoom());
        // console.log(Drupal.Markaspot.maps[0].getCenter());
        $("#map").css(`background-color:${masSettings.map_background}`);
        let tileLayer;
        const map = Drupal.Markaspot.maps[0];

        if (masSettings.map_type === "0") {
          const gl = L.mapboxGL({
            accessToken: masSettings.mapbox_token,
            style: masSettings.mapbox_style
          }).addTo(map);
        }
        if (masSettings.map_type === "1") {
          if (masSettings.wms_service == '') {
            tileLayer = L.tileLayer(masSettings.osm_custom_tile_url, { edgeBufferTiles: 1 });
          } else {
            tileLayer = L.tileLayer.wms(masSettings.wms_service, { layers: masSettings.wms_layer, edgeBufferTiles: 1 });
          }
          map.addLayer(tileLayer);
        }
        map.attributionControl.addAttribution(
          masSettings.osm_custom_attribution
        );


        markerLayer = L.markerClusterGroup({
          maxClusterRadius: 20
        });
        map.addLayer(markerLayer);

        // Initial heat map layer for front page:

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
          const heatControls = L.easyButton({
            position: "bottomright",
            states: [
              {
                stateName: "add-heatControls",
                icon: "fa-thermometer-4",
                title: Drupal.t("Show Heatmap"),
                onClick(control) {
                  //Drupal.markaspot_map.showTimeController(map);
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
                  control.state("remove-timeControls");
                  console.log("clicked")
                }
              },
              {
                icon: "fa-clock-o active",
                stateName: "remove-timeControls",
                title: Drupal.t("Remove TimeControl Layer"),
                onClick(control) {
                  map.removeControl(timeControls);
                  map.removeLayer(geoJsonTimedLayer);
                  map.removeLayer(heatLayer);

                  control.state("add-timeControls");
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
          markerLayer.clearLayers(); // Load and showData on map
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
        });
      });
      $('.view-content').once('markaspot_map').each(function() {

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
    showHeatMap: function showHeatMap() {
      const map = Drupal.Markaspot.maps[0];
      //Drupal.markaspot_map.showTimeController(map);
      Drupal.markaspot_map.createGeoJsonTimedLayer(map);
      Drupal.Markaspot.heatMapLayer = Drupal.markaspot_map.createHeatMapLayer();
      Drupal.Markaspot.heatMapLayer.addTo(map);
    },
    hideHeatMap: function hideHeatMap(){
      const map = Drupal.Markaspot.maps[0];
      map.removeLayer(Drupal.Markaspot.heatMapLayer);
    },
    showTimeControl: function showTimeControl(){
      const map = Drupal.Markaspot.maps[0];
      const getInterval = function (request) {
        // earthquake data only has a time, so we'll use that as a "start"
        // and the "end" will be that + some value based on magnitude
        // 18000000 = 30 minutes, so a quake of magnitude 5 would show on the
        // map for 150 minutes or 2.5 hours
        return {
          start: request.properties.time,
          end: request.properties.updated,
        };
      };
      const data = Drupal.markaspot_map.createGeoJsonLayer(map);
      // console.log(data);
      const timeline = L.timeline(data, {
        getInterval: getInterval,
        pointToLayer: function (data, latlng) {
          return L.circleMarker(latlng, {
            radius: 10,
            color: "#000",
            fillColor: "#000",
          })
        },
      });
      // console.log(timeline)
      const timelineControl = L.timelineSliderControl({
        formatOutput: function (date) {
          const dateObj = new Date(date);
          const yyyy = dateObj.getFullYear();
          const mm = String(dateObj.getMonth() + 1).padStart(2,'0');
          const dd = String(dateObj.getDate()).padStart(2,'0');

          return `${dd}.${mm}.${yyyy}`
          // return new Date(date).toString();
        }
      });

      timelineControl.addTo(map);
      timelineControl.addTimelines(timeline);
      timeline.addTo(map);
    },
    hideTimeControl: function hideTimeControl(){
      console.log("hide");
      const map = Drupal.Markaspot.maps[0];
      map.removeControl(Drupal.markaspot_map.timeControl);
      map.removeLayer(Drupal.Markaspot.geoJsonTimedLayer);
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


    createGeoJson(data) {
      // Retrieve static Data.
      if (typeof data !== "undefined") {
        let feature;
        const features = [];
        if (data.length) {
          for (let i = 0; i < data.length; i++) {
            const time = Math.floor(new Date(data[i].requested_datetime).getTime())
            feature = {
              type: "Feature",
              properties: { time: time, updated: time + (86400000 *2) },
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
          metadata: {
            count: data.length
          },
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
      console.log(geoJson);
      map.fitBounds(L.geoJson(geoJson).getBounds());

      return geoJson;
    },

    createGeoJsonTimedLayer() {
      const geoJsonLayer = Drupal.markaspot_map.createGeoJsonLayer();
      // console.log(geoJsonLayer);
      return geoJsonLayer;
      // console.log(drupalSettings['mas']['timeline_period']);

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

    showData(dataset) {
      if (dataset.status === 404) {
        // bootbox.alert(Drupal.t('No reports found for this category/status'));.
        return false;
      }

      $.each(dataset, (serviceRequests, request) => {
        const categoryColor =
          request.extended_attributes.markaspot.category_hex;
        const awesomeIcon =
            request.extended_attributes.markaspot.category_icon;
        // const iconColor = element.iconColor ? element.iconColor : "#ffffff";
        const { nid } = request.extended_attributes.markaspot;
        const latlon = new L.LatLng(request.lat, request.long);

        // No markers on default position
        let masSettings =  drupalSettings.mas;
        const center = {};
        const pos = {};
        pos.long = Number((request.long).toFixed(3));
        pos.lat = Number((request.lat).toFixed(3));
        center.lat = parseFloat(masSettings.center_lat).toFixed(3);
        center.lng = parseFloat(masSettings.center_lng).toFixed(3);
        if (center.lat == pos.lat && center.lng == pos.long) {
          return;
        }

        // console.log(masSettings);
        let iconSettings = {
          mapIconUrl: masSettings.marker
        };

        // https://stackoverflow.com/questions/35969656/how-can-i-generate-the-opposite-color-according-to-current-color
        iconSettings.mapIconColor = invertColor(categoryColor, 1);
        iconSettings.mapIconFill = categoryColor;
        iconSettings.mapIconSymbol = awesomeIcon;
        let svgIcon = L.Util.template(iconSettings.mapIconUrl, iconSettings);
        // console.log(masSettings.iconAnchor);
        let icon = L.divIcon({
          html: svgIcon,
          iconAnchor: eval(masSettings.iconAnchor),
        });

        let marker = new L.Marker(latlon, {
          icon: icon,
          nid
        });
        marker.on("click", this.markerClickFn(marker, nid));
        markerLayer.addLayer(marker);

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
