/**
 * @file
 * Javascript for FontAwesome Icon class.
 */

/**
 * Provides the iconpicker widget.
 */
(function ($) {

  'use strict';

  Drupal.behaviors.fa_icon_class_colorpicker = {
    attach: function () {
      $('.icon-widget').iconpicker({placement: 'topRightCorner'});
    }
  };
})(jQuery);
