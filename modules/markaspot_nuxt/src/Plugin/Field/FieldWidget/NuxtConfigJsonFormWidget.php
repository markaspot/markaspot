<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Plugin\Field\FieldWidget;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\json_form_widget\FormBuilder;
use Drupal\json_form_widget\Plugin\Field\FieldWidget\JsonFormWidgetBase;
use Drupal\json_form_widget\ValueHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Constructs a NuxtConfigJsonFormWidget.
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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
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
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings, $builder, $value_handler);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('json_form.builder'),
      $container->get('json_form.value_handler'),
      $container->get('extension.list.module'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('markaspot_nuxt'),
    );
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
    // Remove JSON Schema meta properties that cause issues.
    $cleaned_schema = (object) [
      'type' => $schema->type ?? 'object',
      'properties' => $schema->properties ?? (object) [],
    ];

    // Preserve required field list if present.
    if (isset($schema->required)) {
      $cleaned_schema->required = $schema->required;
    }

    // Check pro setting and remove commercial features if disabled.
    // AI image analysis is provided by the OSS markaspot_vision module, so it
    // remains configurable outside the commercial pro layer.
    $config = $this->configFactory->get('markaspot_nuxt.settings');
    $isPro = $config->get('pro_enabled') ?? FALSE;

    if (!$isPro && isset($cleaned_schema->properties->features->properties)) {
      $proFeatures = ['dashboard', 'operationsDashboard', 'aiProcessing', 'piiRedaction', 'feedback', 'offline'];
      foreach ($proFeatures as $feature) {
        if (isset($cleaned_schema->properties->features->properties->$feature)) {
          unset($cleaned_schema->properties->features->properties->$feature);
        }
      }
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
   * Get the module path for markaspot_nuxt.
   *
   * @return string
   *   The absolute path to the markaspot_nuxt module.
   */
  protected function getModulePath(): string {
    return $this->moduleExtensionList->getPath('markaspot_nuxt');
  }

}
