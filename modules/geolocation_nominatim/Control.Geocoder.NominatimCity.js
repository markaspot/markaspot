(function e(t, n, r) {
    function s(o, u) {
      if (!n[o]) {
        if (!t[o]) {
          var a = typeof require == "function" && require;
          if (!u && a)
            return a(o, !0);
          if (i)
            return i(o, !0);
          var f = new Error("Cannot find module '" + o + "'");
          throw f.code = "MODULE_NOT_FOUND",
            f
        }
        var l = n[o] = {
          exports: {}
        };
        t[o][0].call(l.exports, function(e) {
          var n = t[o][1][e];
          return s(n ? n : e)
        }, l, l.exports, e, t, n, r)
      }
      return n[o].exports
    }
    var i = typeof require == "function" && require;
    for (var o = 0; o < r.length; o++)
      s(r[o]);
    return s
  }
)({
  1: [function(require, module, exports) {
    (function(global) {
        var L = (typeof window !== "undefined" ? window['L'] : typeof global !== "undefined" ? global['L'] : null)
          , Util = require('../util');
        module.exports = {
          class: L.Class.extend({
            options: {
              serviceUrl: 'https://nominatim.openstreetmap.org/',
              geocodingQueryParams: {},
              reverseQueryParams: {},
              htmlTemplate: function(r) {
                var a = r.address,
                  parts = [];
                if (a.road || a.building) {
                  parts.push('{building} {road} {house_number}');
                }

                if (a.city || a.town || a.village || a.hamlet) {
                  parts.push('<span class="' + (parts.length > 0 ? 'leaflet-control-geocoder-address-detail' : '') +
                    '">{postcode} {city} {town} {village} {hamlet}</span>');
                }

                if (a.state || a.country) {
                  parts.push('<span class="' + (parts.length > 0 ? 'leaflet-control-geocoder-address-context' : '') +
                    '">{state} {country}</span>');
                }

                return Util.template(parts.join('<br/>'), a, true);
              }
            },

            initialize: function(options) {
              L.Util.setOptions(this, options);
            },

            geocode: function(query, cb, context) {
              const locationIQsuffix = (this.options.key !== false) ? '.php' : false;
              Util.jsonp(this.options.serviceUrl + 'search' + locationIQsuffix, L.extend({
                  key: this.options.key,
                  q: query,
                  limit: 5,
                  format: 'json',
                  addressdetails: 1
                }, this.options.geocodingQueryParams),
                function(data) {
                  var results = [];
                  for (var i = data.length - 1; i >= 0; i--) {
                    var bbox = data[i].boundingbox;
                    for (var j = 0; j < 4; j++) bbox[j] = parseFloat(bbox[j]);
                    results[i] = {
                      icon: data[i].icon,
                      name: data[i].display_name,
                      html: this.options.htmlTemplate ?
                        this.options.htmlTemplate(data[i])
                        : undefined,
                      bbox: L.latLngBounds([bbox[0], bbox[2]], [bbox[1], bbox[3]]),
                      center: L.latLng(data[i].lat, data[i].lon),
                      properties: data[i]
                    };
                  }
                  cb.call(context, results);
                }, this, 'json_callback');
            },

            reverse: function(location, scale, cb, context) {
              const locationIQsuffix = (this.options.key !== false) ? '.php' : false;
              Util.jsonp(this.options.serviceUrl + 'reverse' + locationIQsuffix, L.extend({
                lat: location.lat,
                lon: location.lng,
                zoom: Math.round(Math.log(scale / 256) / Math.log(2)),
                addressdetails: 1,
                format: 'json'
              }, this.options.reverseQueryParams), function(data) {
                var result = [],
                  loc;

                if (data && data.lat && data.lon) {
                  loc = L.latLng(data.lat, data.lon);
                  result.push({
                    name: data.display_name,
                    html: this.options.htmlTemplate ?
                      this.options.htmlTemplate(data)
                      : undefined,
                    center: loc,
                    bounds: L.latLngBounds(loc, loc),
                    properties: data
                  });
                }

                cb.call(context, result);
              }, this, 'json_callback');
            }
          }),

          factory: function(options) {
            return new L.Control.Geocoder.NominatimCity(options);
          }
        };

      }
    ).call(this, typeof global !== "undefined" ? global : typeof self !== "undefined" ? self : typeof window !== "undefined" ? window : {})
  }
    , {
      "../util": 3
    }],
  2: [function(require, module, exports) {
    (function(global) {
        var L = (typeof window !== "undefined" ? window['L'] : typeof global !== "undefined" ? global['L'] : null)
          , NominatimCity = require('./geocoders/nominatim');
        // var L = {Control : {require('leaflet-control-geocoder');

        module.exports = NominatimCity["class"];

        L.Util.extend(L.Control.Geocoder, {
          NominatimCity: module.exports,
          nominatim: NominatimCity.factory
        });

      }
    ).call(this, typeof global !== "undefined" ? global : typeof self !== "undefined" ? self : typeof window !== "undefined" ? window : {})
  }
    , {
      "./geocoders/nominatim": 1
    }],
  3: [function(require, module, exports) {
    (function(global) {
        var L = (typeof window !== "undefined" ? window['L'] : typeof global !== "undefined" ? global['L'] : null)
          , lastCallbackId = 0
          , htmlEscape = (function() {
            // Adapted from handlebars.js
            // https://github.com/wycats/handlebars.js/
            var badChars = /[&<>"'`]/g;
            var possible = /[&<>"'`]/;
            var escape = {
              '&': '&amp;',
              '<': '&lt;',
              '>': '&gt;',
              '"': '&quot;',
              '\'': '&#x27;',
              '`': '&#x60;'
            };

            function escapeChar(chr) {
              return escape[chr];
            }

            return function(string) {
              if (string == null) {
                return '';
              } else if (!string) {
                return string + '';
              }

              // Force a string conversion as this will be done by the append regardless and
              // the regex test will do this transparently behind the scenes, causing issues if
              // an object's to string has escaped characters in it.
              string = '' + string;

              if (!possible.test(string)) {
                return string;
              }
              return string.replace(badChars, escapeChar);
            }
              ;
          }
        )();

        module.exports = {
          jsonp: function(url, params, callback, context, jsonpParam) {
            var callbackId = '_l_geocoder_' + (lastCallbackId++);
            params[jsonpParam || 'callback'] = callbackId;
            window[callbackId] = L.Util.bind(callback, context);
            var script = document.createElement('script');
            script.type = 'text/javascript';
            script.src = url + L.Util.getParamString(params);
            script.id = callbackId;
            document.getElementsByTagName('head')[0].appendChild(script);
          },

          getJSON: function(url, params, callback) {
            var xmlHttp = new XMLHttpRequest();
            xmlHttp.onreadystatechange = function() {
              if (xmlHttp.readyState !== 4) {
                return;
              }
              if (xmlHttp.status !== 200 && xmlHttp.status !== 304) {
                callback('');
                return;
              }
              callback(JSON.parse(xmlHttp.response));
            }
            ;
            xmlHttp.open('GET', url + L.Util.getParamString(params), true);
            xmlHttp.setRequestHeader('Accept', 'application/json');
            xmlHttp.send(null);
          },

          template: function(str, data) {
            return str.replace(/\{ *([\w_]+) *\}/g, function(str, key) {
              var value = data[key];
              if (value === undefined) {
                value = '';
              } else if (typeof value === 'function') {
                value = value(data);
              }
              return htmlEscape(value);
            });
          },

          htmlEscape: htmlEscape
        };

      }
    ).call(this, typeof global !== "undefined" ? global : typeof self !== "undefined" ? self : typeof window !== "undefined" ? window : {})
  }
    , {}]
}, {}, [2]);



