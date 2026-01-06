/**
 * @file
 * JavaScript for the color picker widget.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Tailwind color hex values for preview.
   */
  const TAILWIND_COLORS = {
    slate: '#64748b',
    gray: '#6b7280',
    zinc: '#71717a',
    neutral: '#737373',
    stone: '#78716c',
    red: '#ef4444',
    orange: '#f97316',
    amber: '#f59e0b',
    yellow: '#eab308',
    lime: '#84cc16',
    green: '#22c55e',
    emerald: '#10b981',
    teal: '#14b8a6',
    cyan: '#06b6d4',
    sky: '#0ea5e9',
    blue: '#3b82f6',
    indigo: '#6366f1',
    violet: '#8b5cf6',
    purple: '#a855f7',
    fuchsia: '#d946ef',
    pink: '#ec4899',
    rose: '#f43f5e'
  };

  /**
   * Update the hidden value field with the final color value.
   */
  function updateHiddenValue(container, tailwindSelect, customPicker) {
    const hiddenField = container.querySelector('.color-final-value');
    if (!hiddenField) {
      return;
    }
    // Use Tailwind name if selected, otherwise use custom hex.
    const finalValue = tailwindSelect.value ? tailwindSelect.value : customPicker.value;
    hiddenField.value = finalValue;
  }

  /**
   * Initialize color picker containers.
   */
  Drupal.behaviors.markaspotColorPicker = {
    attach: function (context) {
      once('color-picker', '.color-picker-container', context).forEach(function (container) {
        const tailwindSelect = container.querySelector('.color-tailwind-select');
        const customPicker = container.querySelector('.color-custom-picker');

        if (!tailwindSelect || !customPicker) {
          return;
        }

        // Sync color picker with Tailwind selection and update hidden field.
        tailwindSelect.addEventListener('change', function () {
          const value = this.value;
          if (value && TAILWIND_COLORS[value]) {
            // Update custom picker to show the Tailwind color.
            customPicker.value = TAILWIND_COLORS[value];
          }
          updateHiddenValue(container, tailwindSelect, customPicker);
        });

        // Update hidden field when custom picker changes.
        customPicker.addEventListener('change', function () {
          updateHiddenValue(container, tailwindSelect, customPicker);
        });
        customPicker.addEventListener('input', function () {
          updateHiddenValue(container, tailwindSelect, customPicker);
        });

        // Initialize: if Tailwind is selected, show its color in picker.
        if (tailwindSelect.value && TAILWIND_COLORS[tailwindSelect.value]) {
          customPicker.value = TAILWIND_COLORS[tailwindSelect.value];
        }

        // Initialize hidden field value.
        updateHiddenValue(container, tailwindSelect, customPicker);
      });
    }
  };

})(Drupal, once);
