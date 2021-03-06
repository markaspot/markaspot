'use strict';

/**
 * @file
 */

/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  Vue.component('count-requests', {
    template: '<span>{{ count }}</span>',
    data: function data() {
      return {
        count: this.getData()
      };
    },

    watch: {
      '$route': function $route(to, from) {
        this.getData();
      }
    },
    methods: {
      getData: function getData(param) {
        param = param ? param : this.$route.path;
        var baseUrl = settings.path.baseUrl;
        var url = baseUrl + 'georeport/stats/categories' + param;

        var parent = this;
        axios.get(url, {}).then(function (response) {
          var stats = response.data;
          parent.count = parent.getStats(stats);
        }).catch(function (error) {
          // console.log(error);
        });
        return this.data;
      },
      getStats: function getStats(stats) {
        var data = 0;
        Object.keys(stats).forEach(function (key) {
          data = data + parseInt(stats[key].count);
        }.bind(this));
        return data;
      }
    }

  });

  var vueCount = new Vue({
    el: '.trend_count',
    router: router
  });
})(Drupal, drupalSettings);