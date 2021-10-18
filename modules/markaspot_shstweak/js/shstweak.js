/**
 * @file
 * Defines the behavior of the Markaspot Tweak for Simple hierarchical select
 *   module.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * behavior for attaching taxonomy term description to SHS widget
   *
   * @type {{attach: Drupal.shstweak.attach}}
   */
  Drupal.behaviors.shstweak = {
    attach: function (context) {
      $(document).on('change', '.shs-description-enabled', function () {
        getDescription($(this));
      });
    },
  };

  /**
   * get the description for chosen taxonomy term and attach it to html placeholder
   * @param element
   */
  function getDescription(element) {
    if (Number.isInteger(parseInt(element.val()))) {
      $.getJSON('/markaspotshstweak/' + element.val() + '/' + element.data('shs-last-child'), function (data) {
        element.parent().siblings('.taxonomy-description').html(data.data);
      }).fail(function (jqXHR) {
        setDescription(element, '');
      })
    }
    else {
      setDescription(element, '');
    }
  }

  function setDescription(element, description) {
    element.parent().siblings('.taxonomy-description').html(description);
  }

}(jQuery, Drupal, drupalSettings));
