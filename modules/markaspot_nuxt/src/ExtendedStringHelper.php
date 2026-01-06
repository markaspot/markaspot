<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt;

use Drupal\Component\Utility\Xss;
use Drupal\json_form_widget\StringHelper;

/**
 * Extended string helper with color format support.
 *
 * Extends json_form_widget's StringHelper to add support for JSON Schema
 * "format": "color" which renders a combined Tailwind preset dropdown
 * and HTML5 color picker for custom colors.
 */
class ExtendedStringHelper extends StringHelper {

  /**
   * Tailwind color palette (500 shades).
   */
  protected const TAILWIND_COLORS = [
    'slate' => '#64748b',
    'gray' => '#6b7280',
    'zinc' => '#71717a',
    'neutral' => '#737373',
    'stone' => '#78716c',
    'red' => '#ef4444',
    'orange' => '#f97316',
    'amber' => '#f59e0b',
    'yellow' => '#eab308',
    'lime' => '#84cc16',
    'green' => '#22c55e',
    'emerald' => '#10b981',
    'teal' => '#14b8a6',
    'cyan' => '#06b6d4',
    'sky' => '#0ea5e9',
    'blue' => '#3b82f6',
    'indigo' => '#6366f1',
    'violet' => '#8b5cf6',
    'purple' => '#a855f7',
    'fuchsia' => '#d946ef',
    'pink' => '#ec4899',
    'rose' => '#f43f5e',
  ];

  /**
   * Neutral colors only (subset for neutral field).
   */
  protected const NEUTRAL_COLORS = [
    'slate' => '#64748b',
    'gray' => '#6b7280',
    'zinc' => '#71717a',
    'neutral' => '#737373',
    'stone' => '#78716c',
  ];

  /**
   * {@inheritdoc}
   */
  public function getElementType($property): string {
    // Color format uses our custom container element.
    if (isset($property->format) && in_array($property->format, ['color', 'color-neutral'])) {
      return 'container';
    }

    return parent::getElementType($property);
  }

  /**
   * {@inheritdoc}
   */
  public function handleStringElement($definition, $data, $object_schema = FALSE): array {
    $property = $definition['schema'];

    // Check if this is a color format field.
    if (isset($property->format) && in_array($property->format, ['color', 'color-neutral'])) {
      $is_neutral = $property->format === 'color-neutral';
      return $this->buildColorElement($definition, $data, $object_schema, $is_neutral);
    }

    return parent::handleStringElement($definition, $data, $object_schema);
  }

  /**
   * Build the combined color selector element.
   *
   * @param array $definition
   *   The field definition.
   * @param mixed $data
   *   The current data value.
   * @param mixed $object_schema
   *   The object schema.
   * @param bool $is_neutral
   *   Whether this is a neutral-only color field.
   *
   * @return array
   *   The form element.
   */
  protected function buildColorElement(array $definition, $data, $object_schema, bool $is_neutral = FALSE): array {
    $property = $definition['schema'];
    $field_name = $definition['name'];
    $default_color = $is_neutral ? 'slate' : 'blue';
    $current_value = $data ?? $property->default ?? $default_color;

    // Use appropriate color palette.
    $color_palette = $is_neutral ? self::NEUTRAL_COLORS : self::TAILWIND_COLORS;

    // Determine if current value is a preset or custom hex.
    $is_preset = isset($color_palette[strtolower(trim($current_value))]);
    $preset_value = $is_preset ? strtolower($current_value) : '';
    $default_hex = $is_neutral ? '#64748b' : '#3b82f6';
    $custom_value = !$is_preset ? $this->normalizeHexColor($current_value) : $default_hex;

    // Build preset options.
    $preset_options = ['' => $this->t('- Custom color -')];
    foreach ($color_palette as $name => $hex) {
      $preset_options[$name] = ucfirst($name);
    }

    $element = [
      '#type' => 'container',
      '#attributes' => ['class' => ['color-picker-container']],
      '#tree' => TRUE,
    ];

    // Title and description from schema.
    if (!empty($property->title)) {
      $element['label'] = [
        '#type' => 'label',
        '#title' => $property->title,
      ];
    }

    if (!empty($property->description)) {
      $element['description'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['description']],
        '#markup' => '<small>' . Xss::filterAdmin((string) $property->description) . '</small>',
      ];
    }

    // Preset dropdown.
    $element['tailwind'] = [
      '#type' => 'select',
      '#title' => $this->t('Preset'),
      '#options' => $preset_options,
      '#default_value' => $preset_value,
      '#attributes' => [
        'class' => ['color-tailwind-select'],
        'data-color-type' => 'tailwind',
      ],
    ];

    // Custom color picker.
    $element['custom'] = [
      '#type' => 'color',
      '#title' => $this->t('Custom'),
      '#default_value' => $custom_value,
      '#attributes' => [
        'class' => ['color-custom-picker'],
        'data-color-type' => 'custom',
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $field_name . '[tailwind]"]' => ['value' => ''],
        ],
      ],
    ];

    // Hidden field to store the actual value.
    $element['value'] = [
      '#type' => 'hidden',
      '#default_value' => $current_value,
      '#attributes' => [
        'class' => ['color-final-value'],
        'data-field-name' => $field_name,
      ],
    ];

    // No PHP validation needed - JavaScript updates the hidden 'value' field
    // and json_form_widget reads from $formValues[$property]['value'].

    // Attach JavaScript for dynamic behavior.
    $element['#attached']['library'][] = 'markaspot_nuxt/color_picker';

    return $element;
  }

  /**
   * Check if a value is a Tailwind color name.
   */
  protected function isTailwindColor(string $value): bool {
    return isset(self::TAILWIND_COLORS[strtolower(trim($value))]);
  }

  /**
   * Normalize a hex color value.
   */
  protected function normalizeHexColor(string $value): string {
    $value = trim($value);

    // Already valid 6-digit hex.
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
      return strtolower($value);
    }

    // Short 3-digit hex.
    if (preg_match('/^#([0-9A-Fa-f])([0-9A-Fa-f])([0-9A-Fa-f])$/', $value, $m)) {
      return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    }

    // If it's a Tailwind name, return its hex.
    $lower = strtolower($value);
    if (isset(self::TAILWIND_COLORS[$lower])) {
      return self::TAILWIND_COLORS[$lower];
    }

    // Default blue.
    return '#3b82f6';
  }

}
