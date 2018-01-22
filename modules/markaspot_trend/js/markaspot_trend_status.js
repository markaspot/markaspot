/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  Vue.component('doughnut-status', {
    extends: VueChartJs.PolarArea,
    data() {

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
      '$route'(to, from) {
        this.getChartData();
      }
    },
    methods: {
      getChartData: function (param) {
        param = (param) ? param : this.$route.path;
        let baseUrl = settings.path.baseUrl;
        let url = baseUrl + 'georeport/stats/status' + param;

        let parent = this;
        axios.get(url, {}).then(function (response) {
          let stats = response.data;

          let chartData = {
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

      getLabels: function (stats) {

        let labels = [];
        Object.keys(stats).forEach(function (key) {
          labels.push(stats[key].status);
        });
        return labels;
      },

      getStats: function (stats) {

        let backgroundColor = [];
        let label = [];
        let data = [];
        let dataset = {};

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

  const vueStatus = new Vue({
    el: '.trend_status',
    router
  });
})(Drupal, drupalSettings);
