'use strict';

/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  Vue.component('doughnut-status', {
    extends: VueChartJs.PolarArea,
    data: function data() {

      return {
        chartData: this.getChartData(),
        options: {
          cutoutPercentage: 60,
          legend: {
            display: false
          }
        }
      };
    },

    watch: {
      '$route': function $route(to, from) {
        this.getChartData();
      }
    },
    methods: {
      getChartData: function getChartData(param) {
        param = param ? param : this.$route.path;
        var baseUrl = settings.path.baseUrl;
        var url = baseUrl + 'georeport/stats/status' + param;

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
          // console.log(error);
        });
        return this.chartData;
      },

      getLabels: function getLabels(stats) {

        var labels = [];
        Object.keys(stats).forEach(function (key) {
          labels.push(stats[key].status);
        });
        return labels;
      },

      getStats: function getStats(stats) {

        var backgroundColor = [];
        var label = [];
        var data = [];
        var dataset = {};

        Object.keys(stats).forEach(function (key) {
          backgroundColor.push(stats[key].color);
          label.push("label");
          data.push(stats[key].count);
        }.bind(this));

        dataset.backgroundColor = backgroundColor;
        dataset.data = data;
        return dataset;
      }
    }

  });

  var vueStatus = new Vue({
    el: '.trend_status',
    router: router
  });
})(Drupal, drupalSettings);
