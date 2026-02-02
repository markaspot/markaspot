/**
 * @file
 * Icon picker using local iconify_field API.
 *
 * Provides a searchable icon picker modal that loads icons from
 * the local Drupal API (no external CDN calls - GDPR compliant).
 *
 * Format stored: i-{collection}-{name}, e.g. i-lucide-tree-pine
 */
(function ($, Drupal) {
  'use strict';

  // Default collections to show (can be configured)
  const COLLECTIONS = ['lucide', 'heroicons'];

  // Cache for loaded icons
  const iconCache = {};

  Drupal.behaviors.fa_icon_class_iconpicker = {
    attach: function (context, settings) {
      once('iconpicker', '.icon-widget', context).forEach(function(element) {
        if (element.parentNode.classList.contains('icon-picker-wrapper')) {
          return;
        }

        // Create wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'icon-picker-wrapper';
        wrapper.style.cssText = 'display: flex; gap: 8px; align-items: center; flex-wrap: wrap;';

        // Create icon preview (will be filled by server-rendered SVG)
        const preview = document.createElement('span');
        preview.className = 'icon-preview';
        preview.style.cssText = 'font-size: 24px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;';

        // Create picker button
        const pickerBtn = document.createElement('button');
        pickerBtn.type = 'button';
        pickerBtn.className = 'button button--small';
        pickerBtn.textContent = 'Choose Icon';
        pickerBtn.style.cssText = 'cursor: pointer;';

        // Create help text
        const help = document.createElement('small');
        help.style.cssText = 'display: block; width: 100%; color: #666; margin-top: 4px;';
        help.textContent = 'Click "Choose Icon" to browse available icons';

        // Insert elements
        element.parentNode.insertBefore(wrapper, element);
        wrapper.appendChild(element);
        wrapper.appendChild(preview);
        wrapper.appendChild(pickerBtn);
        wrapper.appendChild(help);

        // Update preview from current value
        function updatePreview() {
          const value = element.value.trim();
          if (value) {
            // Convert to iconify format for API lookup
            let iconName = value;
            if (value.startsWith('i-')) {
              const parts = value.replace('i-', '').split('-');
              const collection = parts[0];
              const name = parts.slice(1).join('-');
              iconName = collection + ':' + name;
            } else if (value.startsWith('fa-')) {
              iconName = 'lucide:' + value.replace('fa-', '').replace('-o', '');
            }

            // Fetch rendered icon from server
            fetch('/api/iconify_field/render/' + encodeURIComponent(iconName))
              .then(r => r.ok ? r.text() : '')
              .then(svg => {
                if (svg) {
                  preview.innerHTML = svg;
                } else {
                  preview.textContent = '?';
                }
              })
              .catch(() => {
                preview.textContent = '?';
              });
          } else {
            preview.textContent = '';
          }
        }

        element.addEventListener('input', updatePreview);
        element.addEventListener('change', updatePreview);
        updatePreview();

        // Picker button click handler
        pickerBtn.addEventListener('click', function(e) {
          e.preventDefault();
          openPickerModal(element, updatePreview);
        });
      });
    }
  };

  /**
   * Detect if dark mode is active
   */
  function isDarkMode() {
    // Check Gin theme dark mode
    if (document.documentElement.classList.contains('gin--dark-mode')) return true;
    // Check Claro dark mode
    if (document.body.classList.contains('claro-dark')) return true;
    // Check generic dark mode class
    if (document.body.classList.contains('dark-mode')) return true;
    // Check CSS custom property
    const bg = getComputedStyle(document.body).backgroundColor;
    if (bg) {
      const rgb = bg.match(/\d+/g);
      if (rgb && rgb.length >= 3) {
        const brightness = (parseInt(rgb[0]) + parseInt(rgb[1]) + parseInt(rgb[2])) / 3;
        return brightness < 128;
      }
    }
    return false;
  }

  /**
   * Open the icon picker modal
   */
  function openPickerModal(inputElement, onUpdate) {
    const dark = isDarkMode();

    // Theme colors
    const colors = dark ? {
      bg: '#1e1e1e',
      bgSecondary: '#2d2d2d',
      bgHover: '#3d3d3d',
      border: '#444',
      text: '#e0e0e0',
      textMuted: '#999',
      inputBg: '#2d2d2d',
      inputBorder: '#555'
    } : {
      bg: '#ffffff',
      bgSecondary: '#fafafa',
      bgHover: '#e8f0fe',
      border: '#ddd',
      text: '#333',
      textMuted: '#666',
      inputBg: '#fff',
      inputBorder: '#ccc'
    };

    // Create modal overlay
    const overlay = document.createElement('div');
    overlay.className = 'icon-picker-overlay';
    overlay.style.cssText = 'position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center;';
    overlay.setAttribute('role', 'presentation');

    // Create modal with ARIA attributes for accessibility
    const modal = document.createElement('div');
    modal.className = 'icon-picker-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', 'Icon picker');
    modal.style.cssText = `background: ${colors.bg}; color: ${colors.text}; border-radius: 8px; width: 90%; max-width: 800px; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 4px 20px rgba(0,0,0,0.4);`;

    // Header
    const header = document.createElement('div');
    header.style.cssText = `padding: 16px; border-bottom: 1px solid ${colors.border}; display: flex; gap: 12px; align-items: center;`;

    // Collection selector
    const collectionSelect = document.createElement('select');
    collectionSelect.setAttribute('aria-label', 'Icon collection');
    collectionSelect.style.cssText = `padding: 8px; border: 1px solid ${colors.inputBorder}; border-radius: 4px; background: ${colors.inputBg}; color: ${colors.text};`;
    COLLECTIONS.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c;
      opt.textContent = c.charAt(0).toUpperCase() + c.slice(1);
      collectionSelect.appendChild(opt);
    });

    // Search input
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Search icons...';
    searchInput.setAttribute('aria-label', 'Search icons');
    searchInput.style.cssText = `flex: 1; padding: 8px; border: 1px solid ${colors.inputBorder}; border-radius: 4px; background: ${colors.inputBg}; color: ${colors.text};`;

    // Close button
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.textContent = '✕';
    closeBtn.setAttribute('aria-label', 'Close icon picker');
    closeBtn.style.cssText = `padding: 8px 12px; border: none; background: none; cursor: pointer; font-size: 18px; color: ${colors.text};`;

    header.appendChild(collectionSelect);
    header.appendChild(searchInput);
    header.appendChild(closeBtn);

    // Icon grid container
    const gridContainer = document.createElement('div');
    gridContainer.style.cssText = 'flex: 1; overflow-y: auto; padding: 16px;';

    const grid = document.createElement('div');
    grid.className = 'icon-picker-grid';
    grid.style.cssText = 'display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 8px;';
    gridContainer.appendChild(grid);

    // Loading indicator
    const loading = document.createElement('div');
    loading.textContent = 'Loading icons...';
    loading.style.cssText = `text-align: center; padding: 40px; color: ${colors.textMuted};`;
    grid.appendChild(loading);

    modal.appendChild(header);
    modal.appendChild(gridContainer);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Load icons for selected collection
    function loadIcons(collection) {
      grid.innerHTML = `<div style="text-align: center; padding: 40px; color: ${colors.textMuted};">Loading icons...</div>`;

      if (iconCache[collection]) {
        renderIcons(iconCache[collection], '');
        return;
      }

      fetch('/api/iconify_field/icons/' + collection)
        .then(r => r.json())
        .then(data => {
          if (data.icons) {
            iconCache[collection] = data.icons;
            renderIcons(data.icons, '');
          } else {
            grid.innerHTML = `<div style="text-align: center; padding: 40px; color: #e55;">Failed to load icons</div>`;
          }
        })
        .catch(err => {
          grid.innerHTML = `<div style="text-align: center; padding: 40px; color: #e55;">Error loading icons</div>`;
        });
    }

    // Render icons to grid
    function renderIcons(icons, filter) {
      grid.innerHTML = '';
      const lowerFilter = filter.toLowerCase();
      const filtered = icons.filter(icon => {
        const name = icon.split(':')[1] || icon;
        return name.toLowerCase().includes(lowerFilter);
      });

      if (filtered.length === 0) {
        grid.innerHTML = `<div style="text-align: center; padding: 40px; color: ${colors.textMuted};">No icons found</div>`;
        return;
      }

      // Limit to first 200 for performance
      filtered.slice(0, 200).forEach(icon => {
        const iconDisplayName = icon.split(':')[1] || icon;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'icon-picker-item';
        btn.setAttribute('aria-label', 'Select ' + iconDisplayName + ' icon');
        btn.style.cssText = `display: flex; flex-direction: column; align-items: center; padding: 12px 8px; border: 1px solid ${colors.border}; border-radius: 4px; background: ${colors.bgSecondary}; cursor: pointer; transition: all 0.15s; color: ${colors.text};`;
        btn.dataset.icon = icon;

        // Icon display
        const iconSpan = document.createElement('span');
        iconSpan.style.cssText = 'width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px;';
        iconSpan.innerHTML = `<svg width="24" height="24" viewBox="0 0 24 24"><rect width="24" height="24" fill="${colors.border}" rx="2"/></svg>`;

        // Load actual icon
        fetch('/api/iconify_field/render/' + encodeURIComponent(icon))
          .then(r => r.ok ? r.text() : '')
          .then(svg => {
            if (svg) {
              iconSpan.innerHTML = svg;
            }
          });

        // Icon name
        const name = document.createElement('small');
        name.style.cssText = `font-size: 10px; color: ${colors.textMuted}; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%;`;
        name.textContent = iconDisplayName;

        btn.appendChild(iconSpan);
        btn.appendChild(name);

        btn.addEventListener('mouseenter', () => btn.style.background = colors.bgHover);
        btn.addEventListener('mouseleave', () => btn.style.background = colors.bgSecondary);

        btn.addEventListener('click', () => {
          // Convert to i-{collection}-{name} format
          const [coll, iconName] = icon.split(':');
          const value = 'i-' + coll + '-' + iconName;
          inputElement.value = value;
          inputElement.dispatchEvent(new Event('input', { bubbles: true }));
          inputElement.dispatchEvent(new Event('change', { bubbles: true }));
          onUpdate();
          closeModal();
        });

        grid.appendChild(btn);
      });

      if (filtered.length > 200) {
        const more = document.createElement('div');
        more.style.cssText = `grid-column: 1 / -1; text-align: center; padding: 12px; color: ${colors.textMuted}; font-size: 12px;`;
        more.textContent = `Showing 200 of ${filtered.length} icons. Use search to narrow results.`;
        grid.appendChild(more);
      }
    }

    // Event handlers
    collectionSelect.addEventListener('change', () => {
      searchInput.value = '';
      loadIcons(collectionSelect.value);
    });

    let searchTimeout;
    searchInput.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        const collection = collectionSelect.value;
        if (iconCache[collection]) {
          renderIcons(iconCache[collection], searchInput.value);
        }
      }, 200);
    });

    function closeModal() {
      document.body.removeChild(overlay);
    }

    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal();
    });

    // Keyboard support: Escape to close
    function handleKeyDown(e) {
      if (e.key === 'Escape') {
        closeModal();
      }
    }
    document.addEventListener('keydown', handleKeyDown);

    // Clean up keyboard listener when modal closes
    const originalCloseModal = closeModal;
    closeModal = function() {
      document.removeEventListener('keydown', handleKeyDown);
      originalCloseModal();
    };

    // Load initial collection
    loadIcons(COLLECTIONS[0]);

    // Focus search
    setTimeout(() => searchInput.focus(), 100);
  }

})(jQuery, Drupal);
