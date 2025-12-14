<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\json_form_widget\Plugin\Field\FieldWidget\JsonFormWidgetBase;

/**
 * JSON Form widget for Mark-a-Spot Nuxt configuration.
 *
 * This widget provides a structured form interface for editing JSON
 * configuration stored in text fields. It uses JSON Schema and UI Schema
 * to generate appropriate form elements for the Nuxt frontend configuration.
 */
#[FieldWidget(
  id: 'nuxt_config_json_form',
  label: new TranslatableMarkup('Nuxt Config JSON Form'),
  description: new TranslatableMarkup('A JSON form widget for editing Mark-a-Spot Nuxt configuration using JSON Schema.'),
  field_types: ['text_long', 'string_long'],
)]
class NuxtConfigJsonFormWidget extends JsonFormWidgetBase {

  /**
   * The module handler service.
   *
   * @var string
   */
  protected const SCHEMA_PATH = 'schema/nuxt_config.schema.json';

  /**
   * The UI schema path relative to module.
   *
   * @var string
   */
  protected const UI_SCHEMA_PATH = 'schema/nuxt_config.ui.json';

  /**
   * {@inheritdoc}
   */
  protected function resolveSchema(FormStateInterface $form_state): object {
    $module_path = $this->getModulePath();
    $schema_file = $module_path . '/' . self::SCHEMA_PATH;

    if (!file_exists($schema_file)) {
      \Drupal::logger('markaspot_nuxt')->error('Schema file not found: @path', ['@path' => $schema_file]);
      return (object) ['properties' => (object) [], 'type' => 'object'];
    }

    $schema_content = file_get_contents($schema_file);
    if ($schema_content === FALSE) {
      \Drupal::logger('markaspot_nuxt')->error('Could not read schema file: @path', ['@path' => $schema_file]);
      return (object) ['properties' => (object) [], 'type' => 'object'];
    }

    $schema = json_decode($schema_content);
    if (json_last_error() !== JSON_ERROR_NONE) {
      \Drupal::logger('markaspot_nuxt')->error('Invalid JSON in schema file: @error', ['@error' => json_last_error_msg()]);
      return (object) ['properties' => (object) [], 'type' => 'object'];
    }

    // Clean the schema to only include what json_form_widget expects.
    // Remove JSON Schema meta properties that cause issues.
    $cleaned_schema = (object) [
      'type' => $schema->type ?? 'object',
      'properties' => $schema->properties ?? (object) [],
    ];

    // Preserve required field list if present.
    if (isset($schema->required)) {
      $cleaned_schema->required = $schema->required;
    }

    return $cleaned_schema;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveUiSchema(FormStateInterface $form_state): ?object {
    $module_path = $this->getModulePath();
    $ui_schema_file = $module_path . '/' . self::UI_SCHEMA_PATH;

    if (!file_exists($ui_schema_file)) {
      \Drupal::logger('markaspot_nuxt')->warning('UI Schema file not found: @path', ['@path' => $ui_schema_file]);
      return NULL;
    }

    $ui_schema_content = file_get_contents($ui_schema_file);
    if ($ui_schema_content === FALSE) {
      \Drupal::logger('markaspot_nuxt')->warning('Could not read UI schema file: @path', ['@path' => $ui_schema_file]);
      return NULL;
    }

    $ui_schema = json_decode($ui_schema_content);
    if (json_last_error() !== JSON_ERROR_NONE) {
      \Drupal::logger('markaspot_nuxt')->warning('Invalid JSON in UI schema file: @error', ['@error' => json_last_error_msg()]);
      return NULL;
    }

    return $ui_schema;
  }

  /**
   * Get the module path for markaspot_nuxt.
   *
   * @return string
   *   The absolute path to the markaspot_nuxt module.
   */
  protected function getModulePath(): string {
    /** @var \Drupal\Core\Extension\ModuleExtensionList $extension_list */
    $extension_list = \Drupal::service('extension.list.module');
    return $extension_list->getPath('markaspot_nuxt');
  }

}
