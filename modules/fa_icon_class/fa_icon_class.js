/**
 * @file
 * Javascript for FontAwesome Icon class.
 */
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.fa_icon_class_iconpicker = {
    attach: function (context, settings) {
      // Initialize IconPicker once
      if (context === document) {
        IconPicker.Init({
          jsonUrl: '/libraries/furcan--iconpicker/dist/iconpicker-1.5.0.json',
        });
      }

      once('iconpicker', '.icon-widget', context).forEach(function(element) {
        // Make sure input has an ID
        if (!element.id) {
          element.id = 'icon-input-' + Math.random().toString(36).substr(2, 9);
        }

        // Create a button next to the input
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button--small button--primary';
        button.id = 'btn-' + element.id;
        button.innerHTML = '<span class="button__icon"><i class="fas fa-icons"></i></span> <span class="button__text">Select Icon</span>';

        // Set the data attributes that IconPicker needs
        button.setAttribute('data-iconpicker-input', '#' + element.id);

        // Wrap input and button in a container
        const wrapper = document.createElement('div');
        wrapper.className = 'icon-picker-wrapper';
        element.parentNode.insertBefore(wrapper, element);
        wrapper.appendChild(element);
        wrapper.appendChild(button);

        // Immediately trigger Run on the button
        IconPicker.Run('#' + button.id);

        // Add click handler for subsequent clicks
        button.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          const existingPicker = document.querySelector('.ip-container');
          if (existingPicker) {
            existingPicker.remove();
          }
          IconPicker.Run('#' + button.id);
        });
      });
    }
  };
})(jQuery, Drupal);
