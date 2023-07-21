(function ($, Drupal, drupalSettings) {

  Drupal.behaviors.markaspot_boilerplate = {
    attach: function (context, settings) {
      const boilerplateElements = once('markaspot_boilerplate', context.querySelectorAll('select[name*=boilerplate]'));
      boilerplateElements.forEach(el => {
        el.addEventListener('change', function() {
          const url = '/markaspot_boilerplate/load/' + this.value;
          let $textarea = $(this).closest('.paragraphs-subform').find('textarea');
          if ($textarea.length > 0) {
            let instanceId = String($textarea.data('ckeditor5-id'));
            let editor;
            if (Drupal.CKEditor5Instances.has(instanceId)) {
              editor = Drupal.CKEditor5Instances.get(instanceId);
            } else {
              console.log('CKEditor instance not found for', instanceId);
            }
            if (editor) {
              $.getJSON(url, function (data) {
                editor.setData(data);
              });
            } else {
              console.log('CKEditor instance not found for', $textarea.attr('id'));
            }
          } else {
            console.log('Textarea not found');
          }
        });
      });
    }
  }

}(jQuery, Drupal, drupalSettings));
