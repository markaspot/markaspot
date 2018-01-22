/**
 * @file
 */



let router = new VueRouter({
  routes: []
});


(function ($,Drupal, drupalSettings) {

  Drupal.behaviors.markaspot_trend = {

    attach: function (context, settings) {
      // $('.trend').html(JSON.stringify(trendData));
      if ($('.block--markaspottrendfilter')[0]){
        const filterView = new Waypoint.Inview({
          element: $('.block--markaspottrendfilter')[0],
          entered: function (direction) {
            if (direction === 'up') {
              $('.trend_filter').removeClass('sticky').hide().fadeIn(800);
            }
          },
          exited: function (direction) {
            $('.trend_filter').addClass('sticky').hide().fadeIn(400);
          }
        });
      }
    }
  };


  Drupal.markaspot_trend = {

    getStats: function (endpoint) {
      var baseUrl = drupalSettings.path.baseUrl;
      var url = baseUrl + "georeport/stats/" + endpoint;

      $.ajax({
        url: url,
        async: false
      })
        .done(function (data) {
          json = data;
        });
      return json;
    },

    getColor: function(){
      return '#'+'0123456789abcdef'.split('').map(function(v,i,a){
        return i>5 ? null : a[Math.floor(Math.random()*16)] }).join('');
    },

    getRequestsStats: function (param) {
      const baseUrl = drupalSettings.path.baseUrl;
      param = (param) ?  param : '';
      $.ajax({
        url: baseUrl + "georeport/stats/requests" + param,
        async: false
      })
        .done(function (data) {
          json = data;
        });
      return json;
    },

    createData: function (param) {
      const requestDays = {};
      const data = this.getRequestsStats(param);
      const categories = [];
      Object.keys(data).forEach(function (key) {
        categories[data[key].category] = data[key].category;
      });

      Object.keys(categories).forEach(function (key) {
        const filtered = data.filter(function (item) {
          return item.category === key;
        });
        requestDays[key] = filtered.map(function (val) {
          return val.created;
        });
      });

      var request2Days = {};
      var timeData = {};

      for (var category in requestDays) {

        // Group requests per day:
        request2Days = requestDays[category].reduce(function (r, a) {
            r[a] = r[a] || [];
            r[a].push(a);
        return r;
        }, Object.create(null));


        var dataset = [];
        var days = [];
        
        Object.keys(request2Days).forEach(function (key) {
          days = {x: key, y: request2Days[key].length };
          dataset.push(days)

        });
        timeData[category] = dataset;
        // console.log(dataset);

      }

      return timeData;

    }

  };

})(jQuery, Drupal, drupalSettings);
