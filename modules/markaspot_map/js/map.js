/**
 * DO NOT EDIT THIS FILE.
 * See the following change record for more information,
 * https://www.drupal.org/node/2815083
 * @preserve
 **/

function _typeof(obj) { "@babel/helpers - typeof"; if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

function invertColor(hex, bw) {
  if (hex.indexOf('#') === 0) {
    hex = hex.slice(1);
  }

  if (hex.length === 3) {
    hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
  }

  if (hex.length !== 6) {
    throw new Error('Invalid HEX color.');
  }

  let r = parseInt(hex.slice(0, 2), 16),
    g = parseInt(hex.slice(2, 4), 16),
    b = parseInt(hex.slice(4, 6), 16);

  if (bw) {
    return r * 0.299 + g * 0.587 + b * 0.114 > 186 ? '#000000' : '#FFFFFF';
  }

  r = (255 - r).toString(16);
  g = (255 - g).toString(16);
  b = (255 - b).toString(16);
  return `#${padZero(r)}${padZero(g)}${padZero(b)}`;
}

function padZero(str, len = 2) {
  let zeros = new Array(len).join('0');
  return (zeros + str).slice(-len);
}

(($, Drupal, drupalSettings, once) => {
  Drupal.Markaspot = {};
  Drupal.Markaspot.maps = [];
  let markerLayer;
  const scrolledMarker = [];
  let currentPath = drupalSettings.path.currentPath;
  currentPath = "".concat(currentPath);
  const masSettings = drupalSettings.mas;
  Drupal.behaviors.markaspot_map = {
    attach: function attach(context, settings) {
      Drupal.Markaspot.settings = masSettings;
      const mapSelector = $("#map");
      const center = [[masSettings.center_lat, masSettings.center_lng]];
      if (typeof mapSelector === 'undefined') {
        return;
      }

      const $serviceRequests = $(masSettings.nid_selector);
      /*
      $serviceRequests.hover(function () {
        const nid = this.dataset.historyNodeId;
        const $node = this;
        scrolledMarker.forEach(value => {
          if (value["nid"] == nid) {
            $node.classList.toggle("focus");
            Drupal.markaspot_map.showCircle(scrolledMarker[nid]);
          }
        });
      });
      */
      const elements = once("markaspot_map", mapSelector);
      elements.forEach(() => {
        Drupal.Markaspot.maps[0] = L.map("map", {
          fullscreenControl: true,
          scrollWheelZoom: !L.Browser.mobile,
          dragging: true,
          zoom: masSettings.zoom_initial
        });
        let tileLayer;
        const map = Drupal.Markaspot.maps[0];
        let gl;

        if (masSettings.map_type == "0" && masSettings.maplibre == "1") {
          gl = L.maplibreGL({
            accessToken: masSettings.mapbox_token,
            style: masSettings.mapbox_style,
            center
          }).addTo(map);
        }
        if (masSettings.map_type == "0" && masSettings.maplibre == "0") {
          gl = L.mapboxGL({
            accessToken: masSettings.mapbox_token,
            style: masSettings.mapbox_style,
            center
          }).addTo(map);
        }
        if (masSettings.map_type === "1") {
          if (masSettings.wms_service == '') {
            tileLayer = L.tileLayer(masSettings.osm_custom_tile_url, {
              edgeBufferTiles: 1
            });
          } else {
            tileLayer = L.tileLayer.wms(masSettings.wms_service, {
              layers: masSettings.wms_layer,
              edgeBufferTiles: 1
            });
          }

          map.addLayer(tileLayer);
        }

        map.attributionControl.addAttribution(masSettings.osm_custom_attribution);
        markerLayer = L.markerClusterGroup({
          maxClusterRadius: 20
        });
        map.addLayer(markerLayer);
        localStorage.setItem("storedNids", JSON.stringify(""));
      });
      const storedNids = JSON.parse(localStorage.getItem("storedNids"));
      const nids = Drupal.markaspot_map.getNids(masSettings.nid_selector);
      Drupal.markaspot_map.setDefaults();

      let markersOnMap = {};

      if (JSON.stringify(nids) !== JSON.stringify(storedNids)) {
        localStorage.setItem("storedNids", JSON.stringify(nids));

        if (typeof markerLayer !== "undefined") {
          markerLayer.clearLayers();
          Drupal.markaspot_map.load(data => {
            Drupal.markaspot_map.showData(data);
            markerLayer.eachLayer(layer => {
              const nid = layer.options.nid;
              scrolledMarker[nid] = {
                latlng: layer.getLatLng(),
                nid: layer.options.nid,
                color: layer.options.color,
                marker: layer
              };
              markersOnMap[nid] = true;
            });
          }, nids);
        }
      }

      const viewHeader = document.getElementsByClassName('view-header')[0]
      if(viewHeader) {
        new Waypoint({
          element: document.getElementsByClassName('view-header')[0],
          handler: function(direction) {
            // When the element is at the top and we are scrolling up, we reset the map to its initial view.
            if (direction === 'up') {
              Drupal.markaspot_map.setDefaults(masSettings);
            }
          },
          offset: '0'
        });
      }
      elements.forEach(() => {
        // Loop through all current teasers.
        $serviceRequests.each(function () {
          // Re
          const element = this;
          const nid = this.dataset.historyNodeId;

          // Handler when the element comes into view just beneath the map
          function handleEnter(direction) {
            // If marker doesn't exist, fallback to default view and return
            if (typeof scrolledMarker[nid] === "undefined") {
              this.element.classList.add("no-location");
              Drupal.Markaspot.maps[0].setView([masSettings.center_lat, masSettings.center_lng],10);
              return;
            }

            // Marker exists, add focus class and show the marker
            this.element.classList.add("focus");
            Drupal.markaspot_map.showCircle(scrolledMarker[nid]);
          }

          // Handler when the element goes out of view behind the map
          function handleExit(direction) {
            this.element.classList.remove("focus");
          }

          new Waypoint({
            element: element,
            handler: handleEnter,
            offset: '50%'
          });

          new Waypoint({
            element: element,
            handler: handleExit,
            offset: function() {
              return -element.clientHeight;
            }
          });

        });
      });
    }
  };
  Drupal.markaspot_map = {
    setDefaults: function setDefaults() {
      const defaultCenter = new L.LatLng(masSettings.center_lat, masSettings.center_lng);
      const defaultZoom = masSettings.zoom_initial;
      let map = Drupal.Markaspot.maps[0];

      if (typeof map !== "undefined" && defaultCenter && defaultZoom) {
        map.setView(defaultCenter, defaultZoom-3);
      } else {
        console.error("Unable to set defaults. Map center or zoom is not defined.");
      }
    },
    showHeatMap: function showHeatMap() {
      const map = Drupal.Markaspot.maps[0];
      Drupal.markaspot_map.createGeoJsonTimedLayer(map);
      Drupal.Markaspot.heatMapLayer = Drupal.markaspot_map.createHeatMapLayer();
      Drupal.Markaspot.heatMapLayer.addTo(map);
    },
    hideHeatMap: function hideHeatMap() {
      const map = Drupal.Markaspot.maps[0];
      map.removeLayer(Drupal.Markaspot.heatMapLayer);
    },
    showTimeControl: function showTimeControl() {
      const map = Drupal.Markaspot.maps[0];

      const getInterval = function getInterval(request) {
        return {
          start: request.properties.time,
          end: request.properties.updated
        };
      };

      const data = Drupal.markaspot_map.createGeoJsonLayer(map);
      const timeline = L.timeline(data, {
        getInterval,
        pointToLayer: function pointToLayer(data, latlng) {
          return L.circleMarker(latlng, {
            radius: 10,
            color: "#000",
            fillColor: "#000"
          });
        }
      });
      Drupal.markaspot_map.timelineControl = L.timelineSliderControl({
        formatOutput: function formatOutput(date) {
          const dateObj = new Date(date);
          const yyyy = dateObj.getFullYear();
          const mm = String(dateObj.getMonth() + 1).padStart(2, '0');
          const dd = String(dateObj.getDate()).padStart(2, '0');
          return "".concat(dd, ".").concat(mm, ".").concat(yyyy);
        }
      });
      const timelineControl = Drupal.markaspot_map.timelineControl;
      timelineControl.addTo(map);
      timelineControl.addTimelines(timeline);
      timeline.addTo(map);
    },
    hideTimeControl: function hideTimeControl() {
      const map = Drupal.Markaspot.maps[0];
      map.removeControl(Drupal.markaspot_map.timelineControl);
    },
    showCircle: function showCircle(marker) {
      const markerId = marker.nid;

      if (_typeof(markerId) === undefined || markerId === this.marker) {
        return;
      }

      this.marker = markerId;
      const map = Drupal.Markaspot.maps[0];
      const mapDefaultZoom = masSettings.zoom_initial;

      if (typeof marker === "undefined") {
        return;
      }

      const color = marker.color;
      const circle = L.circle(marker.latlng, 100, {
        color,
        className: "auto_hide",
        weight: 1,
        fillColor: color,
        fillOpacity: 0.2
      }).addTo(map);
      // Check the state of the fullscreen mode

      const target = $(`article[data-history-node-id = ${marker.nid}]`);
      if (target.length) {
        const html = target.find("h2").html();
        if (!marker._map) {
          circle.addTo(Drupal.Markaspot.maps[0]); // Add the marker to the map
        }
        circle.bindPopup(html); // bind the popup with content
        circle.openPopup();
      }

      map.flyTo(marker.latlng, 16, {
        duration: 0.5
      });
      map.invalidateSize();
      setTimeout(() => {
        $(".auto_hide").animate({
          opacity: 0
        }, 500, () => {
          map.removeLayer(circle);
        });
      }, 1000);
      return circle;
    },
    createGeoJson: function createGeoJson(data) {
      if (typeof data !== "undefined") {
        let feature;
        const features = [];

        if (data.length) {
          for (let i = 0; i < data.length; i++) {
            const time = Math.floor(new Date(data[i].requested_datetime).getTime());
            feature = {
              type: "Feature",
              properties: {
                time,
                updated: time + 86400000 * 2
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
          metadata: {
            count: data.length
          },
          features
        };
      }
    },
    retrieveStaticData: function retrieveStaticData() {
      return Drupal.markaspot_static_json.getData();
    },
    createGeoJsonLayer: function createGeoJsonLayer(map) {
      const data = this.retrieveStaticData();
      const geoJson = this.createGeoJson(data);
      return geoJson;
    },
    createGeoJsonTimedLayer: function createGeoJsonTimedLayer() {
      const geoJsonLayer = Drupal.markaspot_map.createGeoJsonLayer();
      return geoJsonLayer;
    },
    transformGeoJson2heat: function transformGeoJson2heat(geojson, intensity) {
      return geojson.features.map(({geometry}) => [geometry.coordinates[1], geometry.coordinates[0], intensity]);
    },
    updateHeatMapLayer: function updateHeatMapLayer(heatPoint) {
      let heatLayer = {};
      const map = Drupal.Markaspot.maps[0];
      map.eachLayer(layer => {
        if (layer.id === "heatTimedLayer") {
          heatLayer = layer;
        }
      });
      heatLayer.addLatLng(heatPoint);
    },
    createHeatMapLayer: function createHeatMapLayer() {
      const data = this.retrieveStaticData();
      const geoJson = this.createGeoJson(data);
      const heatPoints = this.transformGeoJson2heat(geoJson, 4);
      return new L.HeatLayer(heatPoints, {
        blur: 25,
        maxZoom: 17
      });
    },
    hideMarkers: function hideMarkers() {
      Drupal.Markaspot.maps[0].closePopup();
      Drupal.Markaspot.maps[0].removeLayer(markerLayer);
    },
    showMarkers: function showMarkers() {
      Drupal.Markaspot.maps[0].addLayer(markerLayer);
    },
    markerClickFn: (marker, nid) => () => {
      let map = Drupal.Markaspot.maps[0];
      let target = document.querySelector(`article[data-history-node-id="${nid}"]`);
      let html;
      if (target) {
        html = target;
        if (map.isFullscreen()) {
          marker.bindPopup(html, { autoClose: true, closeOnClick: true });
          return
        } else {
          if (drupalSettings.path.currentPath === "requests" || drupalSettings.path.isFront === true) {
            // List view and front view (no fullscreen)
            html = target.querySelector("h2").innerHTML; // Get the HTML content of the <h2> element
            marker.bindPopup(html);
            marker.addEventListener("click", () => {
              marker.openPopup();

              document.addEventListener('click', function(event) {
                if (event.target.closest('.leaflet-popup-content a')) {
                  event.preventDefault();
                  window.scrollTo({
                    top: Math.max(target.offsetTop - 150, 0),
                    behavior: 'smooth'
                  });

                }
              });
            });
          }
        }
        // marker.bindPopup(html); // bind the popup with content
      }


    },
    showData: function showData(dataset) {
      const _this = this;

      if (dataset.status === 404) {
        return false;
      }

      $.each(dataset, (serviceRequests, request) => {
        const categoryColor = request.extended_attributes.markaspot.category_hex;
        const awesomeIcon = request.extended_attributes.markaspot.category_icon;
        const nid = request.extended_attributes.markaspot.nid;
        const latlon = new L.LatLng(request.lat, request.long);
        const masSettings = drupalSettings.mas;
        const center = {};
        const pos = {};
        pos.long = Number(request.long.toFixed(3));
        pos.lat = Number(request.lat.toFixed(3));
        center.lat = parseFloat(masSettings.center_lat).toFixed(3);
        center.lng = parseFloat(masSettings.center_lng).toFixed(3);

        if (center.lat == pos.lat && center.lng == pos.long) {
          return;
        }

        const iconSettings = {
          mapIconUrl: masSettings.marker
        };
        iconSettings.mapIconColor = invertColor(categoryColor, 1);
        iconSettings.mapIconFill = categoryColor;
        iconSettings.mapIconSymbol = awesomeIcon;
        const svgIcon = L.Util.template(iconSettings.mapIconUrl, iconSettings);
        const icon = L.divIcon({
          html: svgIcon,
          iconAnchor: eval(masSettings.iconAnchor)
        });
        const marker = new L.Marker(latlon, {
          icon,
          nid
        });
        marker.on("click", _this.markerClickFn(marker, nid));
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
    load: function load(getData, nids) {
      let url = drupalSettings.path.baseUrl;
      url = "".concat(url, "georeport/v2/requests.json?extensions=true&nids=").concat(nids);
      return $.getJSON(url).done(data => {
        getData(data);
      }).fail(data => {
        getData(data);
      });
    },
    getNids: function getNids(selector) {
      const serviceRequests = $(selector);
      const nids = [];

      for (let i = 0, length = serviceRequests.length; i < length; i++) {
        const element = serviceRequests[i];
        nids.push(element.getAttribute("data-history-node-id"));
      }

      return nids;
    }
  };
})(jQuery, Drupal, drupalSettings, once);
