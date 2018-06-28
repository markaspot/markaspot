<?php

/**
 * @file
 * Lists available colors and color schemes for the Bartik theme.
 */

$info = array(
  // Available colors and color labels used in theme.
  'fields' => array(
    'top' => t('Off-Canvas Navigation / Buttons background top'),
    'bottom' => t('Off-Canvas Navigation / Buttons background bottom'),
    'bg' => t('Main background'),
    'sidebar' => t('Sidebar background'),
    'sidebarborders' => t('Sidebar borders'),
    'footer' => t('Footer background'),
    'titleslogan' => t('Title and slogan'),
    'text' => t('Text color'),
    'link' => t('Link color'),
    'base' => t('Base color') // https://www.drupal.org/node/1236098#comment-5020582
  ),
  // Pre-defined color schemes.
  'schemes' => array(
    'default' => array(
      'title' => t('Blue Lagoon (default)'),
      'colors' => array(
        'top' => '#055a8e',
        'bottom' => '#1d84c3',
        'bg' => '#ffffff',
        'sidebar' => '#eeeeee',
        'sidebarborders' => '#ffffff',
        'footer' => '#292929',
        'titleslogan' => '#fffeff',
        'text' => '#3b3b3b',
        'link' => '#0071B3',
        'base' => '#ffffff'
      ),
    ),
    'firehouse' => array(
      'title' => t('Firehouse'),
      'colors' => array(
        'top' => '#cd2d2d',
        'bottom' => '#d64e4e',
        'bg' => '#ffffff',
        'sidebar' => '#f1f4f0',
        'sidebarborders' => '#ededed',
        'footer' => '#fffdf7',
        'titleslogan' => '#fffeff',
        'text' => '#888888',
        'link' => '#d6121f',
        'base' => '#ffffff'
      ),
    ),
    'ice' => array(
      'title' => t('Ice'),
      'colors' => array(
        'top' => '#d0d0d0',
        'bottom' => '#c2c4c5',
        'bg' => '#ffffff',
        'sidebar' => '#ffffff',
        'sidebarborders' => '#cccccc',
        'footer' => '#ffffff',
        'titleslogan' => '#000000',
        'text' => '#4a4a4a',
        'link' => '#019dbf',
        'base' => '#ffffff'
      ),
    ),
    'plum' => array(
      'title' => t('Plum'),
      'colors' => array(
        'top' => '#4c1c58',
        'bottom' => '#593662',
        'bg' => '#fffdf7',
        'sidebar' => '#edede7',
        'sidebarborders' => '#e7e7e7',
        'footer' => '#fffdf7',
        'titleslogan' => '#ffffff',
        'text' => '#301313',
        'link' => '#9d408d',
        'base' => '#ffffff'

      ),
    ),
    'slate' => array(
      'title' => t('Slate'),
      'colors' => array(
        'top' => '#4a4a4a',
        'bottom' => '#4e4e4e',
        'bg' => '#ffffff',
        'sidebar' => '#ffffff',
        'sidebarborders' => '#d0d0d0',
        'footer' => '#ffffff',
        'titleslogan' => '#ffffff',
        'text' => '#3b3b3b',
        'link' => '#0073b6',
        'base' => '#ffffff'
      ),
    ),
  ),

  // CSS files (excluding @import) to rewrite with new color scheme.
  'css' => array(
    'assets/css/color/colors.css',
  ),

  // Files to copy.
  'copy' => array(
    'logo.svg',
  ),

  // Gradient definitions.
  'gradients' => array(
    array(
      // (x, y, width, height).
      'dimension' => array(0, 0, 0, 0),
      // Direction of gradient ('vertical' or 'horizontal').
      'direction' => 'vertical',
      // Keys of colors to use for the gradient.
      'colors' => array('top', 'bottom'),
    ),
  ),

  // Preview files.
  'preview_library' => 'masradix/color.preview',
  'preview_html' => 'color/preview.html',

  // Attachments.
  '#attached' => [
    'drupalSettings' => [
      'color' => [
        // Put the logo path into JavaScript for the live preview.
        'logo' => theme_get_setting('logo.url', 'masradix'),
      ],
    ],
  ],
);
