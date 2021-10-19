/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  Vue.component('doughnut-organisations', {
    extends: VueChartJs.Doughnut,
    data() {

      return {
        chartData: this.getChartData(),
        options: {
          cutoutPercentage: 60,
          layout: {
            padding: {
                left: 50,
                right: 50,
                top: 50,
                bottom: 50
            }
          },
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
        // console.log(this.$route.path);
        this.getChartData();

      }
    },
    methods: {
      getChartData: function (param) {
        param = (param) ? param : this.$route.path;
        let baseUrl = settings.path.baseUrl;
        let url = baseUrl + 'georeport/stats/organisations' + param;

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
          console.log(error);
        });
        return this.chartData;

      },

      getLabels: function (stats) {

        let labels = [];
        Object.keys(stats).forEach(function (key) {
          labels.push(stats[key].organisation);
        });
        return labels;
      },

      getStats: function (stats) {

        let backgroundColor = [];
        let data = [];
        let dataset = {};

        Object.keys(stats).forEach(function (key) {
          backgroundColor.push(this.getColor());
          data.push(stats[key].count);
        }.bind(this));

        dataset.backgroundColor = backgroundColor;
        dataset.data = data;
        return dataset;
      },
      getColor: function () {
        return '#' + '0123456789abcdef'.split('').map(function (v, i, a) {
          return i > 5 ? null : a[Math.floor(Math.random() * 16)];
        }).join('');
      }
    }

  });

  const vueOrga = new Vue({
    el: '.trend_organisations',
    router
  });

})(Drupal, drupalSettings);







