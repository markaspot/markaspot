/**
 * @file
 */

/**
 * @file
 * doughnut component.
 */

(function (Drupal, settings) {

  Vue.component('count-requests', {
    template: '<span>{{ count }}</span>',
    data() {
      return {
        count: this.getData(),
      };
    },
    watch: {
      '$route'(to, from){
        this.getData();
      },
    },
    methods: {
      getData: function (param) {
        param = (param) ? param : this.$route.path;
        let baseUrl = settings.path.baseUrl;
        let url = baseUrl + 'georeport/stats/categories' + param;

        let parent = this;
        axios.get(url, {}).then(function (response) {
          let stats = response.data;
          parent.count = parent.getStats(stats);
        }).catch(function (error) {
          // console.log(error);
        });
        return this.data;

      },
      getStats: function (stats) {
        let data = 0;
        Object.keys(stats).forEach(function (key) {
          data = data + parseInt(stats[key].count);
        }.bind(this));
        return data;
      }
    }

  });

  const vueCount = new Vue({
    el: '.trend_count',
    router,
    data: {
      filterYear: this.filterYear
    }
  });


})(Drupal, drupalSettings);





