(function ($, Drupal, drupalSettings) {

  Drupal.behaviors.markaspot_boilerplate = {
    attach: function (context, settings) {
      $('select[name*=boilerplate]').change(function () {
        const url = '/markaspot_boilerplate/load/' + this.value
        let $textarea = $(this).closest('.paragraphs-subform').find('textarea')
        $textarea = $textarea.length > 0 ? $textarea : $(this).closest('#edit-group-service-provider').find('textarea')
        $.getJSON(url, function (data) {
          CKEDITOR.instances[$textarea.attr('id')].setData(data)
        })
      })
    }
  }

}(jQuery, Drupal, drupalSettings))


