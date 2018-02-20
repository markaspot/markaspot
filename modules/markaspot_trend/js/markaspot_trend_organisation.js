'use strict';

/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  Vue.component('doughnut-organisations', {
    extends: VueChartJs.Doughnut,
    data: function data() {

      return {
        chartData: this.getChartData(),
        options: {
          cutoutPercentage: 60,
          legend: {
            labels: {
              boxWidth: 20
            }
          }
        }
      };
    },

    watch: {
      '$route': function $route(to, from) {
        // console.log(this.$route.path);
        this.getChartData();
      }
    },
    methods: {
      getChartData: function getChartData(param) {
        param = param ? param : this.$route.path;
        var baseUrl = settings.path.baseUrl;
        var url = baseUrl + 'georeport/stats/organisations' + param;

        var parent = this;
        axios.get(url, {}).then(function (response) {
          var stats = response.data;

          var chartData = {
            datasets: [parent.getStats(stats)],
            labels: parent.getLabels(stats)
          };
          if (parent.$data._chart) {
            parent.$data._chart.destroy();
          }
          parent.renderChart(chartData, parent.options);
        }).catch(function (error) {
          console.log(error);
        });
        return this.chartData;
      },

      getLabels: function getLabels(stats) {

        var labels = [];
        Object.keys(stats).forEach(function (key) {
          labels.push(stats[key].organisation);
        });
        return labels;
      },

      getStats: function getStats(stats) {

        var backgroundColor = [];
        var data = [];
        var dataset = {};

        Object.keys(stats).forEach(function (key) {
          backgroundColor.push(this.getColor());
          data.push(stats[key].count);
        }.bind(this));

        dataset.backgroundColor = backgroundColor;
        dataset.data = data;
        return dataset;
      },
      getColor: function getColor() {
        return '#' + '0123456789abcdef'.split('').map(function (v, i, a) {
          return i > 5 ? null : a[Math.floor(Math.random() * 16)];
        }).join('');
      }
    }

  });

  var vueOrga = new Vue({
    el: '.trend_organisations',
    router: router
  });
})(Drupal, drupalSettings);