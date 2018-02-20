/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  const vueFilter = new Vue({
    el: '.trend_filter',
    router,
    delimiters: ['${', '}'],
    watch: {
      selectedYear: function (newYear) {
        const path = '/' + this.selectedYear;
        this.$router.push({path: '/' + newYear});
        this.filterYear = newYear;
        this.selectedMonth = '';
        Drupal.markaspot_map.trendMarker(path);

      },
      selectedMonth: function (newMonth) {
        const path = '/' + this.selectedYear + '/' + newMonth;
        this.$router.push({path: path});
        Drupal.markaspot_map.trendMarker(path);
      }
    },
    methods: {
      createMonths: function () {
        return ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];
      },
      createYears: function (startYear) {
        const currentYear = new Date().getFullYear();
        const years = [];
        startYear = startYear || 2015;

        while (startYear <= currentYear) {
          years.push(startYear++);
        }

        return years;

      }
    },
    data() {
      return {
        filterYear: '',
        selectedYear: '',
        selectedMonth: '',
        years: this.createYears(),
        months: this.createMonths()
      };
    }
  });

})(Drupal, drupalSettings);

