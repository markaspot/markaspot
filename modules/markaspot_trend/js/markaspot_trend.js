'use strict';

/**
 * @file
 */

var router = new VueRouter({
  routes: []
});

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.markaspot_trend = {
    attach: function attach(context, settings) {
      // $('.trend').html(JSON.stringify(trendData));
      var element = $('.block--markaspottrendfilter')[0];
      if (element) {
        var filterView = new Waypoint.Inview({
          element: element,
          entered: function entered(direction) {
            if (direction === 'up') {
              $('.trend_filter').removeClass('sticky').hide().fadeIn(800);
            }
          },
          exited: function exited(direction) {
            $('.trend_filter').addClass('sticky').hide().fadeIn(400);
          }
        });
      }
    }
  };

  Drupal.markaspot_trend = {
    getStats: function getStats(endpoint) {
      var baseUrl = drupalSettings.path.baseUrl;
      var url = baseUrl + 'georeport/stats/' + endpoint;
      var json = {};
      $.ajax({
        url: url,
        async: false
      }).done(function (data) {
        json = data;
      });
      return json;
    },
    getColor: function getColor() {
      return '#' + '0123456789abcdef'.split('').map(function (v, i, a) {
        return i > 5 ? null : a[Math.floor(Math.random() * 16)];
      }).join('');
    },
    getRequestsStats: function getRequestsStats(param) {
      var baseUrl = drupalSettings.path.baseUrl;
      param = param || '';
      var json = {};

      $.ajax({
        url: baseUrl + 'georeport/stats/requests' + param,
        async: false
      }).done(function (data) {
        json = data;
      });
      return json;
    },
    createData: function createData(param) {
      var requestDays = {};
      var data = this.getRequestsStats(param);
      var categories = [];
      Object.keys(data).forEach(function (key) {
        categories[data[key].category] = data[key].category;
      });

      Object.keys(categories).forEach(function (key) {
        var filtered = data.filter(function (item) {
          return item.category === key;
        });
        requestDays[key] = filtered.map(function (val) {
          return val.created;
        });
      });

      var request2Days = {};
      var timeData = {};

      var _loop = function _loop(category) {
        // Group requests per day:
        request2Days = requestDays[category].reduce(function (r, a) {
          r[a] = r[a] || [];
          r[a].push(a);
          return r;
        }, Object.create(null));

        var dataset = [];
        var days = [];
        Object.keys(request2Days).forEach(function (key) {
          days = { x: key, y: request2Days[key].length };
          dataset.push(days);
        });
        timeData[category] = dataset;
      };

      for (var category in requestDays) {
        _loop(category);
      }

      return timeData;
    }
  };
})(jQuery, Drupal, drupalSettings);