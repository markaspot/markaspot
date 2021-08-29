'use strict';

/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  Vue.component('doughnut-categories', {
    extends: VueChartJs.PolarArea,
    data: function data() {
      return {
        chartData: this.getChartData(),
        options: {
          layout: {
            padding: {
                left: 50,
                right: 50,
                top: 50,
                bottom: 50
            }
          },
          cutoutPercentage: 100,
          legend: {
            display: false
          }
        }
      };
    },

    watch: {
      '$route': function $route(to, from) {
        console.log(this.$route.path);
        this.getChartData();
      }
    },
    methods: {
      getChartData: function getChartData(param) {
        param = param ? param : this.$route.path;
        var baseUrl = settings.path.baseUrl;
        var url = baseUrl + 'georeport/stats/categories' + param;

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
          localStorage.setItem('categoryStats', JSON.stringify(chartData));

          parent.renderChart(chartData, parent.options);
        }).catch(function (error) {
          console.log(error);
        });
        return this.chartData;
      },

      getLabels: function getLabels(stats) {

        var labels = [];
        Object.keys(stats).forEach(function (key) {
          labels.push(stats[key].category);
        });
        return labels;
      },

      getStats: function getStats(stats) {

        var backgroundColor = [];
        var data = [];
        var dataset = {};

        Object.keys(stats).forEach(function (key) {
          backgroundColor.push(stats[key].color);
          data.push(stats[key].count);
        }.bind(this));

        dataset.backgroundColor = backgroundColor;
        dataset.data = data;
        return dataset;
      }
    }

  });

  var vueCat = new Vue({
    el: '.trend_categories',
    router: router
  });
})(Drupal, drupalSettings);
