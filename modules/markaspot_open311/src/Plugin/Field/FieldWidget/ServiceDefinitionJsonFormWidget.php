<?php

declare(strict_types=1);

namespace Drupal\markaspot_open311\Plugin\Field\FieldWidget;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\json_form_widget\FormBuilder;
use Drupal\json_form_widget\Plugin\Field\FieldWidget\JsonFormWidgetBase;
use Drupal\json_form_widget\ValueHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * JSON Form widget for Open311 Service Definition attributes.
 *
 * Provides a structured form interface for defining additional form fields
 * per service category, following the Open311 GeoReport v2 specification.
 */
#[FieldWidget(
  id: 'service_definition_json_form',
  label: new TranslatableMarkup('Service Definition JSON Form'),
  description: new TranslatableMarkup('A JSON form widget for editing Open311 service definition attributes using JSON Schema.'),
  field_types: ['text_long', 'string_long'],
)]
class ServiceDefinitionJsonFormWidget extends JsonFormWidgetBase {

  /**
   * The schema path relative to module.
   */
  protected const SCHEMA_PATH = 'schema/service_definition.schema.json';

  /**
   * The UI schema path relative to module.
   */
  protected const UI_SCHEMA_PATH = 'schema/service_definition.ui.json';

  /**
   * Constructs a ServiceDefinitionJsonFormWidget.
   *
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Third party settings.
   * @param \Drupal\json_form_widget\FormBuilder $builder
   *   The JSON form builder.
   * @param \Drupal\json_form_widget\ValueHandler $value_handler
   *   The JSON form value handler.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    FormBuilder $builder,
    ValueHandler $value_handler,
    protected readonly ModuleExtensionList $moduleExtensionList,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings, $builder, $value_handler);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('json_form.builder'),
      $container->get('json_form.value_handler'),
      $container->get('extension.list.module'),
      $container->get('logger.factory')->get('markaspot_open311'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Pre-populates from source language when editing a translation with an
   * empty service definition. The admin then only needs to translate the
   * description and values[].name strings.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // When translating: if this field is empty, copy from source language.
    $entity = $items->getEntity();
    if ($entity->isTranslatable()
      && !$entity->isDefaultTranslation()
      && (!isset($items[0]) || empty($items[0]->value))
    ) {
      $source_langcode = $entity->getUntranslated()->language()->getId();
      $source = $entity->getTranslation($source_langcode);
      $source_value = $source->get($items->getName())->value;
      if ($source_value) {
        $items->setValue(['value' => $source_value]);
      }
    }

    return parent::formElement($items, $delta, $element, $form, $form_state);
  }

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

    // Clean the schema to only include what json_form_widget expects.
    $cleaned_schema = (object) [
      'type' => $schema->type ?? 'object',
      'properties' => $schema->properties ?? (object) [],
    ];

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
      $this->logger->warning('UI Schema file not found: @path', ['@path' => $ui_schema_file]);
      return NULL;
    }

    $ui_schema_content = file_get_contents($ui_schema_file);
    if ($ui_schema_content === FALSE) {
      $this->logger->warning('Could not read UI schema file: @path', ['@path' => $ui_schema_file]);
      return NULL;
    }

    $ui_schema = json_decode($ui_schema_content);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger->warning('Invalid JSON in UI schema file: @error', ['@error' => json_last_error_msg()]);
      return NULL;
    }

    return $ui_schema;
  }

  /**
   * Get the module path for markaspot_open311.
   *
   * @return string
   *   The absolute path to the markaspot_open311 module.
   */
  protected function getModulePath(): string {
    return $this->moduleExtensionList->getPath('markaspot_open311');
  }

}
