/**
 * @file
 */


const router = new VueRouter({
  routes: [],
});


(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.markaspot_trend = {

    attach(context, settings) {
      // $('.trend').html(JSON.stringify(trendData));
      const element = $('.block--markaspottrendfilter')[0];
      if (element) {
        const filterView = new Waypoint.Inview({
          element: element,
          entered(direction) {
            if (direction === 'up') {
              $('.trend_filter').removeClass('sticky').hide().fadeIn(800);
            }
          },
          exited(direction) {
            $('.trend_filter').addClass('sticky').hide().fadeIn(400);
          }
        });
      }
    }
  };


  Drupal.markaspot_trend = {

    getStats(endpoint) {
      const baseUrl = drupalSettings.path.baseUrl;
      const url = `${baseUrl}georeport/stats/${endpoint}`;
      let json = {};
      $.ajax({
        url,
        async: false
      })
        .done((data) => {
          json = data;
        });
      return json;
    },

    getColor() {
      return `#${'0123456789abcdef'.split('').map((v, i, a) => (i > 5 ? null : a[Math.floor(Math.random() * 16)])).join('')}`;
    },

    getRequestsStats(param) {
      const baseUrl = drupalSettings.path.baseUrl;
      param = (param) || '';
      let json = {};

      $.ajax({
        url: `${baseUrl}georeport/stats/requests${param}`,
        async: false
      })
        .done((data) => {
          json = data;
        });
      return json;
    },

    createData(param) {
      const requestDays = {};
      const data = this.getRequestsStats(param);
      const categories = [];
      Object.keys(data).forEach((key) => {
        categories[data[key].category] = data[key].category;
      });

      Object.keys(categories).forEach((key) => {
        const filtered = data.filter(item => item.category === key);
        requestDays[key] = filtered.map(val => val.created);
      });

      let request2Days = {};
      const timeData = {};

      for (const category in requestDays) {
        // Group requests per day:
        request2Days = requestDays[category].reduce((r, a) => {
          r[a] = r[a] || [];
          r[a].push(a);
          return r;
        }, Object.create(null));

        const dataset = [];
        let days = [];
        Object.keys(request2Days).forEach((key) => {
          days = {x: key, y: request2Days[key].length};
          dataset.push(days);
        });
        timeData[category] = dataset;
      }

      return timeData;
    },

  };
}(jQuery, Drupal, drupalSettings));
