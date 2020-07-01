(function ($, Drupal, drupalSettings) {
  Drupal.markaspot_static_json = Drupal.markaspot_static_json || {}
  Drupal.behaviors.markaspot_static_json = {
    attach: function (context, settings) {
    }
  }
  Drupal.markaspot_static_json.getData = function () {
    var baseUrl = drupalSettings.path.baseUrl
    // const masSettings = Drupal.settings.mas;
    var json = []
    var bounds = {
      lat: drupalSettings.mas.center_lat,
      long: drupalSettings.mas.center_lng
    }

    json.push(bounds)

    $.ajax({
      url: baseUrl + 'sites/default/files/requests.json',
      async: false
    })
      .done(function (data) {
        json = data
      })
    return json
  }
})(jQuery, Drupal, drupalSettings)
