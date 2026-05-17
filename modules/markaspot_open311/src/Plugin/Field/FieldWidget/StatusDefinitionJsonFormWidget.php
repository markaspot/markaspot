<?php

declare(strict_types=1);

namespace Drupal\markaspot_open311\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * JSON Form widget for internal status transition attributes.
 */
#[FieldWidget(
  id: 'status_definition_json_form',
  label: new TranslatableMarkup('Status Definition JSON Form'),
  description: new TranslatableMarkup('A JSON form widget for editing status transition attributes using JSON Schema.'),
  field_types: ['text_long', 'string_long'],
)]
class StatusDefinitionJsonFormWidget extends ServiceDefinitionJsonFormWidget {

  /**
   * The schema path relative to module.
   */
  protected const SCHEMA_PATH = 'schema/status_definition.schema.json';

  /**
   * Status definitions intentionally reuse the service definition UI schema.
   */
  protected const UI_SCHEMA_PATH = 'schema/service_definition.ui.json';

  /**
   * {@inheritdoc}
   */
  protected function resolveSchema(FormStateInterface $form_state): object {
    $module_path = $this->getModulePath();
    $schema_file = $module_path . '/' . self::SCHEMA_PATH;

    if (!file_exists($schema_file)) {
      $this->logger->error('Schema file not found: @path', ['@path' => $schema_file]);
      return (object) ['properties' => (object) [], 'type' => 'object'];
    }

    $schema_content = file_get_contents($schema_file);
    if ($schema_content === FALSE) {
      $this->logger->error('Could not read schema file: @path', ['@path' => $schema_file]);
      return (object) ['properties' => (object) [], 'type' => 'object'];
    }

    $schema = json_decode($schema_content);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger->error('Invalid JSON in schema file: @error', ['@error' => json_last_error_msg()]);
      return (object) ['properties' => (object) [], 'type' => 'object'];
    }

    $cleaned_schema = (object) [
      'type' => $schema->type ?? 'object',
      'properties' => $schema->properties ?? (object) [],
    ];

    if (isset($schema->required)) {
      $cleaned_schema->required = $schema->required;
    }

    return $cleaned_schema;
  }

}
