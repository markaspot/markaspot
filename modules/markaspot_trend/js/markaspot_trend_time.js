'use strict';

/**
 * @file
 */

Vue.component('line-chart', {
  extends: VueChartJs.Line,
  data: function data() {
    return {
      chartData: this.getChartData(),
      options: {
        maintainAspectRatio: false,
        height: '300',
        legend: {
          display: false
        },
        scales: {
          xAxes: [{
            type: 'time'
          }],
          yAxes: [{
            type: 'linear',
            position: 'right',
            ticks: {
              min: 0,
              stepSize: 10
            }
          }]
        }
      }
    };
  },

  watch: {
    '$route': function $route(to, from) {
      this.$data._chart.destroy();
      this.getChartData();
    }
  },
  mounted: function mounted() {
    this.getChartData();
  },

  methods: {
    getChartData: function getChartData(param) {
      param = param ? param : this.$route.path;
      var timeData = Drupal.markaspot_trend.createData(param);

      var dataset = [];

      // get category names and color to iterate.
      var categoryStats = Drupal.markaspot_trend.getStats('categories');

      Object.keys(categoryStats).forEach(function (key) {
        var data = {
          label: categoryStats[key].category,
          backgroundColor: categoryStats[key].color + '90',
          data: timeData[categoryStats[key].category]
        };

        dataset.push(data);
      });

      var chartData = {
        datasets: dataset
      };

      if (this.options) {
        this.renderChart(chartData, this.options);
      }
    }
  }

});

var vueaaTime = new Vue({
  el: '.trend_time',
  router: router
});
