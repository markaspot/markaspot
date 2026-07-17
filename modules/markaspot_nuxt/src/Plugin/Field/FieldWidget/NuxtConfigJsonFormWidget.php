<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Plugin\Field\FieldWidget;

use Drupal\Core\Config\ConfigFactoryInterface;
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
   * Form-data marker for a rendered dynamic-property editor.
   */
  private const ADDITIONAL_PROPERTIES_KEY = '__markaspot_additional_properties';

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
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state): void {
    $original_value = $items->getValue()[0]['value'] ?? NULL;
    $existing = [];
    if (is_string($original_value) && $original_value !== '') {
      $decoded = json_decode($original_value, TRUE);
      if (is_array($decoded)) {
        $existing = $decoded;
      }
    }

    $this->currentEntity = $items->getEntity();

    // Recover parents for inline entity forms, as done by the base widget.
    if ($this->currentEntity) {
      $storage_key = 'json_schema_widget_parents_' . $this->getPluginId();
      $storage = $form_state->get($storage_key) ?? [];
      if (isset($storage[$this->currentEntity->uuid()])) {
        $this->fieldParents = $storage[$this->currentEntity->uuid()];
      }
    }

    $schema = $this->resolveSchema($form_state);
    $this->builder->setSchema($schema);

    $field_name = $this->fieldDefinition->getName();
    $path = $this->fieldParents;
    $path[] = $field_name;

    $values = $form_state->getValue($path);
    if ($values === NULL) {
      $values = $form_state->getValue($field_name);
    }

    if (!isset($values[0]['value']) || !is_array($values[0]['value'])) {
      return;
    }

    $form_data = $this->collectSchemaFormData($values[0]['value'], $schema);
    $data = self::mergePreservingUnknownKeys($existing, $form_data, $schema);

    $items->setValue([['value' => json_encode($data)]]);
  }

  /**
   * Merges submitted schema values into existing configuration.
   *
   * Existing keys not described by the schema are preserved recursively.
   * Submitted schema keys are authoritative, including empty values that
   * remove known non-boolean keys. A submitted FALSE is persisted only when
   * the key already existed; otherwise it remains absent. This means the UI
   * cannot distinguish and persist an explicit FALSE for a previously absent
   * key, which avoids materializing every unchecked boolean option.
   *
   * @param array $existing
   *   Existing decoded configuration.
   * @param array $form_data
   *   Flattened values submitted by the schema form.
   * @param object $schema
   *   JSON schema describing the submitted values.
   *
   * @return array
   *   The merged configuration.
   */
  public static function mergePreservingUnknownKeys(array $existing, array $form_data, object $schema): array {
    $merged = $existing;
    $properties = (array) ($schema->properties ?? new \stdClass());

    foreach ($properties as $property => $property_schema) {
      if (!array_key_exists($property, $form_data)) {
        continue;
      }

      $value = $form_data[$property];
      $type = $property_schema->type ?? NULL;

      if ($type === 'object' && is_array($value)) {
        $existing_value = isset($existing[$property]) && is_array($existing[$property])
          ? $existing[$property]
          : [];
        $object_value = self::mergePreservingUnknownKeys($existing_value, $value, $property_schema);

        if ($object_value === []) {
          // An empty submitted object means "nothing set here": preserve a
          // compact scalar form (e.g. formFirst: false) instead of dropping
          // it, and remove the key only when it never existed as an object.
          if (!array_key_exists($property, $existing) || is_array($existing[$property])) {
            unset($merged[$property]);
          }
        }
        else {
          $merged[$property] = $object_value;
        }
        continue;
      }

      if ($type === 'boolean') {
        // ExtendedValueHandler yields booleans for rendered checkboxes; any
        // non-scalar submission is an artifact of unsupported rendering and
        // must never overwrite existing data.
        if (!is_scalar($value)) {
          continue;
        }
        $submitted = (bool) $value;
        if (array_key_exists($property, $existing)) {
          $merged[$property] = $submitted;
        }
        elseif ($submitted !== (bool) ($property_schema->default ?? FALSE)) {
          // Only a value differing from the rendered schema default proves
          // user intent; untouched defaults must not materialize.
          $merged[$property] = $submitted;
        }
        continue;
      }

      if ($value === NULL || $value === '' || $value === FALSE) {
        unset($merged[$property]);
      }
      elseif (array_key_exists($property, $existing)) {
        $merged[$property] = $value;
      }
      elseif (!(is_array($value) && $value === [])
        && !(isset($property_schema->default) && $value == $property_schema->default)
        && !(($property_schema->type ?? NULL) === 'integer' && !isset($property_schema->default) && (int) $value === 0)) {
        // For keys the tenant never stored, persist only values differing
        // from the rendered schema default: everything else is form noise
        // and would materialize defaults into the stored configuration.
        $merged[$property] = $value;
      }
    }

    if (array_key_exists(self::ADDITIONAL_PROPERTIES_KEY, $form_data)
      && isset($schema->additionalProperties)
      && is_object($schema->additionalProperties)) {
      // The dynamic-property editor (ExtendedObjectHelper) lists every
      // existing entry, so a rendered and submitted editor is authoritative
      // for all non-static keys of this level.
      $known = array_fill_keys(array_keys($properties), TRUE);
      foreach (array_keys(array_diff_key($existing, $known)) as $dynamic_key) {
        unset($merged[$dynamic_key]);
      }
      $submitted = $form_data[self::ADDITIONAL_PROPERTIES_KEY];
      foreach (is_array($submitted) ? $submitted : [] as $key => $entry_data) {
        $existing_entry = isset($existing[$key]) && is_array($existing[$key])
          ? $existing[$key]
          : [];
        $entry = self::mergePreservingUnknownKeys(
          $existing_entry,
          is_array($entry_data) ? $entry_data : [],
          $schema->additionalProperties,
        );
        if ($entry !== []) {
          $merged[$key] = $entry;
        }
      }
    }

    return $merged;
  }

  /**
   * Collects submitted values while retaining empty and boolean values.
   *
   * @param array $raw_values
   *   Raw values for one schema object.
   * @param object $schema
   *   Schema for the object.
   *
   * @return array
   *   Flattened submitted values keyed by schema property.
   */
  private function collectSchemaFormData(array $raw_values, object $schema): array {
    $data = [];
    $properties = (array) ($schema->properties ?? new \stdClass());

    foreach ($properties as $property => $property_schema) {
      if (!array_key_exists($property, $raw_values)) {
        continue;
      }

      if (($property_schema->type ?? NULL) === 'object') {
        $object_values = $raw_values[$property][$property] ?? [];
        $data[$property] = is_array($object_values)
          ? $this->collectSchemaFormData($object_values, $property_schema)
          : [];
        continue;
      }

      $data[$property] = $this->valueHandler->flattenValues($raw_values, $property, $property_schema);
    }

    // Dynamic key-value entries rendered by ExtendedObjectHelper. The marker
    // is only set when the editor was actually part of the submitted form,
    // which makes it authoritative during the merge; unrendered dynamic data
    // (e.g. i18n.overrides, whose additionalProperties has no properties)
    // never gets a marker and is preserved from the stored value.
    if (isset($schema->additionalProperties)
      && is_object($schema->additionalProperties)
      && !empty((array) ($schema->additionalProperties->properties ?? new \stdClass()))
      && isset($raw_values['__additional']['items'])
      && is_array($raw_values['__additional']['items'])) {
      $entries = [];
      foreach ($raw_values['__additional']['items'] as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        $key = trim((string) ($entry['_key'] ?? ''));
        if ($key === '') {
          continue;
        }
        $entries[$key] = $this->collectSchemaFormData($entry, $schema->additionalProperties);
      }
      $data[self::ADDITIONAL_PROPERTIES_KEY] = $entries;
    }

    return $data;
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

    self::flattenOneOfNodes($cleaned_schema);

    return $cleaned_schema;
  }

  /**
   * Replaces oneOf-only nodes with their object branch, recursively.
   *
   * Json_form_widget knows no oneOf: a property without a top-level type
   * falls back to the string path, which puts stored objects into a
   * textfield #value and crashes Twig's Attribute rendering. Polymorphic
   * properties (formFirst, offline, pwaInstallPrompt: boolean|object) are
   * therefore pinned to their object branch; boolean data is expanded to
   * {enabled: bool} by ExtendedFieldTypeRouter::normalizeObjectData().
   *
   * @param object $schema
   *   A schema node whose properties are flattened in place.
   */
  public static function flattenOneOfNodes(object $schema): void {
    if (!isset($schema->properties) || !is_object($schema->properties)) {
      return;
    }
    foreach ($schema->properties as $property) {
      if (!is_object($property)) {
        continue;
      }
      if (!isset($property->type) && isset($property->oneOf) && is_array($property->oneOf)) {
        $branch = NULL;
        foreach ($property->oneOf as $candidate) {
          if (is_object($candidate) && ($candidate->type ?? NULL) === 'object') {
            $branch = $candidate;
            break;
          }
        }
        $branch ??= is_object($property->oneOf[0] ?? NULL) ? $property->oneOf[0] : NULL;
        if ($branch !== NULL) {
          foreach (get_object_vars($branch) as $key => $value) {
            $property->{$key} = $value;
          }
        }
        unset($property->oneOf);
      }
      self::flattenOneOfNodes($property);
      if (isset($property->additionalProperties) && is_object($property->additionalProperties)) {
        self::flattenOneOfNodes($property->additionalProperties);
      }
    }
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
