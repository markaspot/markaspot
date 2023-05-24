(function ($, Drupal, drupalSettings) {

  Drupal.behaviors.markaspot_boilerplate = {
    attach: function (context, settings) {
      const boilerplateElements = once('markaspot_boilerplate', context.querySelectorAll('select[name*=boilerplate]'));
      boilerplateElements.forEach(el => {
        el.addEventListener('change', function() {
          const url = '/markaspot_boilerplate/load/' + this.value;
          let $textarea = $(this).closest('.paragraphs-subform').find('textarea');
          console.log(Drupal.CKEditor5Instances);
          if ($textarea.length > 0) {
            let instanceId = $textarea.data('ckeditor5-id');  // the id you are looking for
            if (Drupal.CKEditor5Instances.has(instanceId)) {
              const editor = Drupal.CKEditor5Instances.get(instanceId);
              // Use editor instance here...
            } else {
              console.log('CKEditor instance not found for', instanceId);
            }
            //const editor = Drupal.CKEditor5Instances.get($textarea.data('ckeditor5-id'));
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
