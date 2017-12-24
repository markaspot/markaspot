(function ($, Drupal, drupalSettings) {
  Drupal.service_request =  Drupal.service_request || {};
  Drupal.behaviors.service_request = {

    updateStatus: function($paragraphForm) {
      var value =$('input[name=field_status]:checked', 'form').val();
      $paragraphForm.val(value);
    },

    attach: function(context, settings) {

      var $paragraphForm = $('div[id^=edit-field-status-notes-]' + ' .field--name-field-status-term select').last();
      var $nodeForm = $('.node-service-request-edit-form input');

      $nodeForm.on('change', function() {
        Drupal.behaviors.service_request.updateStatus($paragraphForm);
      });

      $paragraphForm.on('change', function(){
        var value = $paragraphForm.val();
        $('.node-service-request-edit-form input[name=field_status][value="' + value + '"]').prop('checked', true);
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
