/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  Vue.component('doughnut-categories', {
    extends: VueChartJs.PolarArea,
    data() {
      return {
        chartData: this.getChartData(),
        options: {
          cutoutPercentage: 100,
          legend: {
            display: false
          }
        }
      };
    },
    watch: {
      '$route'(to, from) {
        console.log(this.$route.path);
        this.getChartData();
      }
    },
    methods: {
      getChartData: function (param) {
        param = (param) ? param : this.$route.path;
        let baseUrl = settings.path.baseUrl;
        let url = baseUrl + 'georeport/stats/categories' + param;

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
          localStorage.setItem('categoryStats', JSON.stringify(chartData));

          parent.renderChart(chartData, parent.options);

        }).catch(function (error) {
          console.log(error);
        });
        return this.chartData;

      },

      getLabels: function (stats) {

        let labels = [];
        Object.keys(stats).forEach(function (key) {
          labels.push(stats[key].category);
        });
        return labels;
      },

      getStats: function (stats) {

        let backgroundColor = [];
        let data = [];
        let dataset = {};

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

  const vueCat = new Vue({
    el: '.trend_categories',
    router
  });


})(Drupal, drupalSettings);





