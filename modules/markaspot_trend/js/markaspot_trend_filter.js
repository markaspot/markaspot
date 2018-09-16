'use strict';

/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  var vueFilter = new Vue({
    el: '.trend_filter',
    router: router,
    delimiters: ['${', '}'],
    watch: {
      selectedYear: function selectedYear(newYear) {
        var path = '/' + this.selectedYear;
        this.$router.push({ path: '/' + newYear });
        this.filterYear = newYear;
        this.selectedMonth = '';
        Drupal.markaspot_map.trendMarker(path);
      },
      selectedMonth: function selectedMonth(newMonth) {
        var path = '/' + this.selectedYear + '/' + newMonth;
        this.$router.push({ path: path });
        Drupal.markaspot_map.trendMarker(path);
      }
    },
    methods: {
      createMonths: function createMonths() {
        return ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];
      },
      createYears: function createYears(startYear) {
        var currentYear = new Date().getFullYear();
        var years = [];
        startYear = startYear || currentYear -1;

        while (startYear <= currentYear) {
          years.push(startYear++);
        }

        return years;
      }
    },
    data: function data() {
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