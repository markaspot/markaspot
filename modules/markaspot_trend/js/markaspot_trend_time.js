/**
 * @file
 */



Vue.component('line-chart', {
  extends: VueChartJs.Line,
  data() {
    return {
      chartData: this.getChartData(),
      options: {
        maintainAspectRatio: false,
        height: '300',

        legend: {
          display: true,
          position: 'top',
          labels: {
            boxWidth: 20
          }
        },
        scales: {
          xAxes: [
            {
              type: 'time'
            }
          ],
          yAxes: [
            {
              type: 'linear',
              position: 'right',
              ticks: {
                min: 0,
                stepSize: 10
              }
            }
          ]
        }
      }
    };
  },
  watch: {
    '$route'(to, from) {
      this.$data._chart.destroy();
      this.getChartData();
    }
  },
  mounted() {
    this.getChartData();
  },
  methods: {
    getChartData: function (param) {
      param = (param) ? param : this.$route.path;
      const timeData = Drupal.markaspot_trend.createData(param);

      const dataset = [];

      // get category names and color to iterate.
      const categoryStats = Drupal.markaspot_trend.getStats('categories');

      Object.keys(categoryStats).forEach(function (key) {
        const data = {
          label: categoryStats[key].category,
          backgroundColor: categoryStats[key].color + '90',
          data: timeData[categoryStats[key].category]
        };

        dataset.push(data);
      });

      let chartData = {
        datasets: dataset
      };

      if (this.options) {
        this.renderChart(chartData, this.options);
      }

    }
  }

});


const vueaaTime = new Vue({
  el: '.trend_time',
  router
});


