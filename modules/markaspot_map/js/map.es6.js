/**
 * @file
 * Main Map-Application File with Leaflet Maps api.
 */


L.TimeDimension.Layer.MaS = L.TimeDimension.Layer.GeoJson.extend({

  _update() {
    if (!this._map) {
      return;
    }
    if (!this._loaded) {
      return;
    }

    const request_time = this._timeDimension.getCurrentTime();
    const formattime = dateFormat(request_time, Drupal.Markaspot.settings.timeline_date_format);
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
      const feature = this._getFeatureBetweenDates(layers[i].feature, minTime, maxTime);
      if (feature) {
        layer.addData(feature);
        if (this._addlastPoint && feature.geometry.type === 'LineString') {
          if (feature.geometry.coordinates.length > 0) {
            const properties = feature.properties;
            properties.last = true;
            layer.addData({
              type: 'Feature',
              properties,
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
      const requests = layer.getLayers().length;
      const log = jQuery('ul.log_list');
      log.append(`<li><span class="time">${formattime}</span>` + `<span class="count">${requests}</span>`);
      const height = log.get(0).scrollHeight;
      log.animate({
        scrollTop: height
      }, 10);
      layer.addTo(this._map);
      this._currentLayer = layer;
    }
  }

});

(function ($, Drupal, drupalSettings) {
  // 'use strict';.
  Drupal.Markaspot = {};
  Drupal.Markaspot.maps = [];
  let markerLayer;
  const scrolledMarker = [];

  Drupal.behaviors.markaspot_map = {

    attach(context, settings) {
      const masSettings = settings.mas;
      Drupal.Markaspot.settings = masSettings;
      // Make map stick to the page top or wherever, override via theme.
      const mapSelector = $('#map');

      mapSelector.once('markaspot_map').each(() => {
        $('.log_header .left').text(Drupal.t('Date'));
        $('.log_header .right').text(Drupal.t('Requests'));
        Drupal.Markaspot.maps[0] = L.map('map', {
          fullscreenControl: true,
          scrollWheelZoom: false,
          maxZoom: 18,
          setZoom: 14
        });

        $('#map').css(`background-color:${masSettings.map_background}`);
        const tileLayer = L.tileLayer(masSettings.osm_custom_tile_url);

        const map = Drupal.Markaspot.maps[0];
        map.attributionControl.addAttribution(masSettings.osm_custom_attribution);

        map.addLayer(tileLayer);

        markerLayer = L.markerClusterGroup({
          maxClusterRadius: 20
        });

        map.addLayer(markerLayer);

        // Initital heat map layer for front page:
        let currentPath = drupalSettings.path.currentPath;

        if (currentPath === 'node') {
          Drupal.markaspot_map.createHeatMapLayer(map);
          // heatMapLayer.addTo(map);
          Drupal.markaspot_map.setDefaults(masSettings);
        }
        currentPath = `/${currentPath}`;
        if (currentPath === masSettings.visualization_path || currentPath === '/home') {
          // Drupal.markaspot_map.hideMarkers();
          // Show Markers additionally ob button click.

          const categoryMarker = L.easyButton({
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

          const geoJsonTimedLayer = Drupal.markaspot_map.createGeoJsonTimedLayer(map);
          const heatStart = [
            [masSettings.center_lat, masSettings.center_lng, 1]
          ];
          const heatLayer = new L.heatLayer(heatStart).addTo(map);
          heatLayer.id = 'heatTimedLayer';
          // Show Markers additionally ob button click.
          const timeDimensionControl = Drupal.markaspot_map.showTimeController(map);
          // Show Markers additionally ob button click.
          const heatControls = L.easyButton({
            position: 'bottomright',
            states: [
              {
                stateName: 'add-heatControls',
                icon: 'fa-thermometer-4',
                title: Drupal.t('Show Heatmap'),
                onClick(control) {
                  Drupal.markaspot_map.showTimeController(map);
                  Drupal.markaspot_map.createGeoJsonTimedLayer(map);
                  control.state('remove-heatControls');
                  control.heatMapLayer = Drupal.markaspot_map.createHeatMapLayer(map);
                  control.heatMapLayer.addTo(map);
                }
              }, {
                stateName: 'remove-heatControls',
                icon: 'fa-thermometer-4 active',
                title: Drupal.t('Hide Heatmap'),
                onClick(control) {
                  map.removeLayer(control.heatMapLayer);
                  control.state('add-heatControls');
                }
              }
            ]
          });

          heatControls.addTo(map);

          const timeControls = L.easyButton({
            position: 'bottomright',
            states: [
              {
                stateName: 'add-timeControls',
                icon: 'fa-clock-o',
                title: Drupal.t('Show TimeControl Layer'),
                onClick(control) {
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
                onClick(control) {
                  $('div.log').hide();
                  map.removeControl(timeDimensionControl);
                  map.removeLayer(geoJsonTimedLayer);
                  map.removeLayer(heatLayer);

                  control.state('add-timeControls');
                  $('ul.log_list').empty();
                }
              }
            ]
          });
          timeControls.addTo(map);
        }
        // Empty storedNids.
        localStorage.setItem('storedNids', JSON.stringify(''));
        // End once.
      });

      // Get all nids to be called via ajax(Open311).
      // jQuery.Once is not working with ajax loaded views, which means requests.json is loaded
      // twice in logged in state.
      // We check which nids are shown already and we now store nids in localStorage.
      const storedNids = JSON.parse(localStorage.getItem('storedNids'));
      const nids = Drupal.markaspot_map.getNids(masSettings.nid_selector);

      if (!nids.length) {
        Drupal.markaspot_map.setDefaults(masSettings);
      }
      if (JSON.stringify(nids) !== JSON.stringify(storedNids)) {

        localStorage.setItem('storedNids', JSON.stringify(nids));

        markerLayer.clearLayers();
        // Load and showData on map.
        Drupal.markaspot_map.load((data) => {
          Drupal.markaspot_map.showData(data);
          markerLayer.eachLayer((layer) => {
            // Define marker-properties for Scrolling.
            const nid = layer.options.title;
            scrolledMarker[nid] = {
              latlng: layer.getLatLng(),
              title: layer.options.title,
              color: layer.options.color
            };
          });
        }, nids);
      }
      // Theme independent selector.
      const serviceRequests = $(masSettings.nid_selector);

      for (let i = 0, length = serviceRequests.length; i < length; i++) {
        // Event of hovering.
        $(serviceRequests[i]).hover(function () {
          const nid = this.getAttribute('data-history-node-id');
          Drupal.markaspot_map.showCircle(scrolledMarker[nid]);
        });

        new Waypoint({
          element: serviceRequests[i],
          handler() {
            const nid = this.element.getAttribute('data-history-node-id');

            const previousWp = this.previous();
            const nextWp = this.next();
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
        });
      }
    }
  };

  Drupal.markaspot_map = {

    settings(drupalSettings) {
      return drupalSettings;
    },
    setDefaults(masSettings) {
      const defaultCenter = new L.latLng(masSettings.center_lat, masSettings.center_lng);
      Drupal.Markaspot.maps[0].setView(defaultCenter, masSettings.zoom_initial);
    },
    // Showing a Circle Marker on hover and scroll over.
    showCircle(marker) {
      const map = Drupal.Markaspot.maps[0];
      // Get zoomlevel to set circle radius.
      const currentZoom = map.getZoom();
      if (typeof marker !== 'undefined') {
        const color = marker.color;
        const circle = L.circle(marker.latlng, 2600 / currentZoom, {
          color,
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
        setTimeout(() => {
          // marker.setIcon(icon);
          map.removeLayer(circle);
        }, 1300);
      }
    },

    showTimeController(map) {
      // Start of TimeDimension manual instantiation.
      const timeDimension = new L.TimeDimension({
        period: drupalSettings.mas.timeline_period
      });

      // Helper to share the timeDimension object between all layers.
      map.timeDimension = timeDimension;

      L.Control.TimeDimensionCustom = L.Control.TimeDimension.extend({
        _getDisplayDateFormat(date) {
          return this._dateUTC ? date.toISOString() : date.toLocaleString();
        },
        options: {
          position: 'bottomright',
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
      const filter_string = param.substr(1).replace('/', '-');
      const filteredData = data.filter(function(i) {
        return i.requested_datetime.indexOf(filter_string) > -1;
      });
      const geojson = this.createGeoJson(filteredData);
      const heatPoints = Drupal.markaspot_map.transformGeoJson2heat(geojson, 4);
      // Define heatlayer
      const heatLayer = new L.heatLayer(heatPoints, {  });
      markerLayer.addLayer(heatLayer);
      this.showMarkers();
      // heatLayer.remove();

    },
    createGeoJson(data) {
      // Retrieve static Data.
      if (typeof data !== 'undefined') {

        let feature;
        let features = [];
        if (data.length) {
          for (let i = 0; i < data.length; i++) {
            feature = {
              type: 'Feature',
              properties: {time: data[i].requested_datetime},
              geometry: {
                type: 'Point',
                coordinates: [data[i].long, data[i].lat]
              }
            };
            features.push(feature);
          }
        }

        return {
          type: 'FeatureCollection',
          features
        };
      }
    },

    retrieveStaticData(){
      return Drupal.markaspot_static_json.getData();
    },
    createGeoJsonLayer(map) {
      // Create a geojson feature from static json module.
      const data = this.retrieveStaticData();
      const geoJson = this.createGeoJson(data);
      // Set bounds from geojson.
      map.fitBounds(L.geoJson(geoJson).getBounds());
      const currentZoom = map.getZoom();

      if (typeof geoJson !== 'undefined') {
        return L.geoJson(geoJson, {
          pointToLayer(feature, latlng) {
            const circle = L.circle(latlng, 3600 / currentZoom, {
              color: '#333',
              className: 'auto_hide',
              weight: 1,
              fillColor: '#333',
              fillOpacity: 0.2
            });
            // map.panTo(latlng);
            setTimeout(() => {
              $('.auto_hide').animate({opacity: 0}, 500, () => {map.removeLayer(circle);});
            }, 3000);
            return circle;
          }
        });
      }
    },

    createGeoJsonTimedLayer(map) {
      const geoJsonLayer = Drupal.markaspot_map.createGeoJsonLayer(map);
      // console.log(drupalSettings['mas']['timeline_period']);.
      if (typeof geoJsonLayer !== 'undefined') {
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
      map.eachLayer((layer) => {
        if (layer.id === 'heatTimedLayer') {
          heatLayer = layer;
        }
      });

      heatLayer.addLatLng(heatPoint);
    },

    createHeatMapLayer() {
      const data = this.retrieveStaticData();
      const geoJson = this.createGeoJson(data);
      const heatPoints = this.transformGeoJson2heat(geoJson, 4);
      return new L.heatLayer(heatPoints, {
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
      return function () {
        const map = Drupal.Markaspot.maps[0];
        const currentZoom = map.getZoom();
        const fullscreen = map.isFullscreen();
        const target = $(`article[data-history-node-id=${nid}]`);
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
          const html = target.text();
          marker.bindPopup(html);
        }
      };
    },
    getAwesomeColors() {
      return [
        {
          color: 'red', hex: '#FF0000'
        }, {
          color: 'darkred', hex: '#8B0000'
        }, {
          color: 'orange', hex: '#FFA500', iconColor: 'dark-red'
        }, {
          color: 'green', hex: '#008000'
        }, {
          color: 'darkgreen', hex: '#006400'
        }, {
          color: 'blue', hex: '#0000FF'
        }, {
          color: 'darkblue', hex: '#00008B'
        }, {
          color: 'purple', hex: '#A020F0'
        }, {
          color: 'darkpurple', hex: '#871F78'
        }, {
          color: 'cadetblue', hex: '#5F9EA0'
        }, {
          color: 'lightblue', hex: '#ADD8E6', iconColor: '#000000'
        }, {
          color: 'lightgray', hex: '#D3D3D3', iconColor: '#000000'
        }, {
          color: 'gray', hex: '#808080'
        }, {
          color: 'black', hex: '#000000'
        }, {
          color: 'beige', hex: '#F5F5DC', iconColor: 'darkred'
        }, {
          color: 'white', hex: '#FFFFFF', iconColor: '#000000'
        }
      ];
    },

    showData(dataset) {
      if (dataset.status === 404) {
        // bootbox.alert(Drupal.t('No reports found for this category/status'));.
        return false;
      }

      const awesomeColors = this.getAwesomeColors();

      $.each(dataset, (service_requests, request) => {
        const categoryColor = request.extended_attributes.markaspot.category_hex;
        const colorswitch = categoryColor ? categoryColor.toUpperCase() : '#000000';

        const icon = {};
        $.each(awesomeColors, (key, element) => {
          if (colorswitch === element.hex) {
            let awesomeColor = element.color;
            let awesomeIcon = request.extended_attributes.markaspot.category_icon;
            let iconColor = element.iconColor ? element.iconColor : '#ffffff';

            let icon = L.AwesomeMarkers.icon({
              icon: awesomeIcon,
              prefix: 'fa',
              markerColor: awesomeColor,
              iconColor: iconColor
            });


            const nid = request.extended_attributes.markaspot.nid;
            let markerColor = categoryColor;
            const latlon = new L.LatLng(request.lat, request.long);
            const marker = new L.Marker(latlon, {
              icon,
              title: nid,
              color: markerColor,
              time: request.requested_datetime
            });
            marker.on('click', this.markerClickFn(marker, nid));
            markerLayer.addLayer(marker);

          }
        });
      });
      const size = markerLayer.getLayers().length;

      if (size >= 1) {
        // console.log(markerLayer.getBounds());
        Drupal.Markaspot.maps[0].fitBounds(markerLayer.getBounds(), {
          padding: [
            -150,
            -150
          ]
        });
      } else {
        Drupal.Markaspot.maps[0].setView(markerLayer);
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
        .done((data) => {
          getData(data);
        })
        .fail((data) => {
          getData(data);
        });
    },

    getNids(selector) {
      const serviceRequests = $(selector);
      const nids = [];
      for (let i = 0, length = serviceRequests.length; i < length; i++) {
        // console.log(i);
        const element = serviceRequests[i];
        // console.log(element);
        nids.push(element.getAttribute('data-history-node-id'));
        // console.log(nids);
      }
      return nids;
    }
  };
}(jQuery, Drupal, drupalSettings));
