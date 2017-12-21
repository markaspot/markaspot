(function ($, Drupal, drupalSettings) {
    Drupal.markaspot_static_json =  Drupal.markaspot_static_json || {};
    Drupal.behaviors.markaspot_static_json = {
        attach: function(context, settings) {
        }
    };
    Drupal.markaspot_static_json.getData = function (){
      var baseUrl = drupalSettings.path.baseUrl;
      $.ajax({
            url: baseUrl + "sites/default/files/requests.json",
            async: false
        })
            .done(function( data ) {
                geoJson = data;
            });
        return geoJson;
    }
})(jQuery, Drupal, drupalSettings);
