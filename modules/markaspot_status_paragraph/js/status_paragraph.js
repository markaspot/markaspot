/**
 * @file
 * Provides status paragraph behaviors.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Disable edit button and dropdown for the first paragraph.
   */
  Drupal.behaviors.disableFirstParagraphActions = {
    attach: function (context, settings) {
      // Disable the edit button and dropdown for the first paragraph
      once('disable-first-paragraph', '#field-status-notes-values tbody', context).forEach(function (tbody) {
        const firstRow = tbody.querySelector('tr.draggable:first-child');

        if (firstRow) {
          // Use the stable 'name' attribute to select the edit button
          const editButton = firstRow.querySelector('input[name="field_status_notes_0_edit"]');
          if (editButton) {
            editButton.style.display = 'none';
          }

          // Selectors for dropdown remain the same (usually stable)
          const dropdownToggle = firstRow.querySelector('.paragraphs-dropdown .paragraphs-dropdown-toggle');
           if (dropdownToggle) {
             dropdownToggle.style.display = 'none';
           }
        }
      });
    }
  };

  /**
   * Add status colors to collapsed paragraph summaries in edit forms.
   * Uses colors from taxonomy terms via drupalSettings.
   */
  Drupal.behaviors.addStatusColorClasses = {
    attach: function (context, settings) {
      // Get status class map from drupalSettings (loaded from taxonomy terms)
      const statusClassMap = settings.markaspot_status_paragraph?.statusClassMap || {};

      // Find all collapsed paragraph summaries in status notes field
      once('add-status-colors', '.field--name-field-status-notes .paragraphs-description', context).forEach(function (description) {
        // Get the first text content which should be the status term
        const contentWrapper = description.querySelector('.paragraphs-content-wrapper');
        if (contentWrapper) {
          const firstText = contentWrapper.textContent.trim().split(',')[0].trim();

          // Check if it matches a known status
          if (statusClassMap[firstText]) {
            const statusData = statusClassMap[firstText];
            const statusClass = statusData.class;
            const statusColor = statusData.color;

            // Find the element containing the status text and apply styling
            const textNodes = contentWrapper.childNodes;
            for (let i = 0; i < textNodes.length; i++) {
              const node = textNodes[i];
              if (node.nodeType === Node.TEXT_NODE || node.nodeType === Node.ELEMENT_NODE) {
                const text = node.textContent || node.nodeValue || '';
                if (text.trim() === firstText) {
                  // Wrap in span with color class and inline style if it's a text node
                  if (node.nodeType === Node.TEXT_NODE) {
                    const span = document.createElement('span');
                    span.className = 'summary-content ' + statusClass;
                    span.textContent = text;
                    // Apply inline style with color from taxonomy
                    span.style.color = statusColor;
                    span.style.fontWeight = '700';
                    contentWrapper.replaceChild(span, node);
                  } else if (node.nodeType === Node.ELEMENT_NODE) {
                    node.classList.add(statusClass);
                    // Apply inline style with color from taxonomy
                    node.style.color = statusColor;
                    node.style.fontWeight = '700';
                  }
                  break;
                }
              }
            }
          }
        }
      });
    }
  };

}(Drupal, once));
