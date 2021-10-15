(function ($, Drupal, drupalSettings) {

  Drupal.behaviors.markaspot_boilerplate = {
    attach: function (context, settings) {
      $('select[name*=boilerplate]').change(function () {
        const url = '/markaspot_boilerplate/load/' + this.value
        const $textarea = $(this).closest('.fieldset-wrapper').find('textarea')
        $.getJSON(url, function (data) {
          CKEDITOR.instances[$textarea.attr('id')].setData(data)
        })
      })
    }
  }

}(jQuery, Drupal, drupalSettings))


