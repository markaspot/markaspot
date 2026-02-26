<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\json_form_widget\ArrayHelper;
use Drupal\json_form_widget\ObjectHelper;

/**
 * Extended object helper with additionalProperties support.
 *
 * Renders dynamic key-value property editors for JSON Schema objects
 * that define additionalProperties as an object schema. Uses the same
 * AJAX add/remove pattern as ArrayHelper.
 */
class ExtendedObjectHelper extends ObjectHelper {

  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * FormState storage key for additional properties tracking.
   */
  const AP_STATE = 'json_form_additional_props';
  const AP_COUNT = 'count';
  const AP_ADD = 'add';

  /**
   * {@inheritdoc}
   *
   * Fixes upstream PHP warning when schema->properties is undefined, and
   * adds additionalProperties rendering.
   */
  protected function generateProperties(array $definition, ?object $data, FormStateInterface $form_state, array $context): \Generator {
    if (!isset($definition['schema']->properties)) {
      return;
    }
    yield from parent::generateProperties($definition, $data, $form_state, $context);
  }

  /**
   * {@inheritdoc}
   */
  protected function generateObjectElement(array $definition, ?object $data, FormStateInterface $form_state, array $context): \Generator {
    // Yield the parent's details wrapper and known properties.
    yield from parent::generateObjectElement($definition, $data, $form_state, $context);

    $schema = $definition['schema'];
    if (!isset($schema->additionalProperties) || !is_object($schema->additionalProperties)) {
      return;
    }

    // Only render if the additionalProperties schema defines properties.
    $additional_schema = $schema->additionalProperties;
    $ap_properties = (array) ($additional_schema->properties ?? []);
    if (empty($ap_properties)) {
      return;
    }

    // Collect existing data entries that aren't known static properties.
    $known_properties = array_keys((array) ($schema->properties ?? new \stdClass()));
    $additional_data = [];
    if ($data) {
      foreach ($data as $key => $value) {
        if (!in_array($key, $known_properties, TRUE)) {
          $additional_data[$key] = $value;
        }
      }
    }

    // Extract propertyNames pattern from parent schema for the key field hint.
    $property_pattern = $schema->propertyNames->pattern ?? NULL;

    yield '__additional' => $this->buildAdditionalPropertiesContainer(
      $additional_schema,
      $additional_data,
      $form_state,
      $context,
      $property_pattern,
    );
  }

  /**
   * Build the AJAX-enabled container for additional properties.
   *
   * @param object $additional_schema
   *   The additionalProperties schema definition.
   * @param array $additional_data
   *   Existing dynamic property data as key => value pairs.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $context
   *   Context hierarchy from parent object.
   * @param string|null $property_pattern
   *   Optional regex pattern for valid property names.
   *
   * @return array
   *   Render array for the additional properties container.
   */
  protected function buildAdditionalPropertiesContainer(
    object $additional_schema,
    array $additional_data,
    FormStateInterface $form_state,
    array $context,
    ?string $property_pattern,
  ): array {
    $ap_context = array_merge($context, ['__additional']);
    $context_name = ArrayHelper::buildContextName($ap_context);

    // Convert associative data to indexed entries for rendering.
    $data_entries = [];
    foreach ($additional_data as $key => $value) {
      $data_entries[] = ['_key' => $key, '_value' => $value];
    }

    $item_count = $this->getAPItemCount($context_name, count($data_entries), $form_state);
    $add_property = self::buildAPStateProperty(self::AP_ADD, $context_name);
    $is_adding = $form_state->get($add_property) ?? FALSE;

    $wrapper_id = $context_name . '-fieldset-wrapper';

    $element = [
      '#type' => 'fieldset',
      '#title' => $this->t('Dynamic Properties'),
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    // Build item entries.
    $items = [];
    for ($i = 0; $i < $item_count; $i++) {
      $entry = $data_entries[$i] ?? ['_key' => '', '_value' => NULL];
      $items[$i] = $this->buildEntry(
        $i,
        $entry['_key'],
        $entry['_value'],
        $additional_schema,
        $form_state,
        array_merge($ap_context, ['items', (string) $i]),
        $context_name,
        $property_pattern,
      );
    }

    // When adding, overwrite the last item with a blank entry.
    if ($is_adding && $item_count > 0) {
      $last = $item_count - 1;
      $items[$last] = $this->buildEntry(
        $last,
        '',
        NULL,
        $additional_schema,
        $form_state,
        array_merge($ap_context, ['items', (string) $last]),
        $context_name,
        $property_pattern,
      );
      $form_state->set($add_property, FALSE);
    }

    $element['items'] = $items;

    // "Add property" button.
    $element['array_actions'] = [
      '#type' => 'actions',
      'actions' => [
        'add' => [
          '#type' => 'submit',
          '#name' => $context_name,
          '#value' => $this->t('Add property'),
          '#submit' => [static::class . '::addProperty'],
          '#ajax' => [
            'callback' => [$this, 'additionalPropertiesCallback'],
            'wrapper' => $wrapper_id,
          ],
          '#attributes' => [
            'data-parent' => '__additional',
          ],
          '#limit_validation_errors' => [],
        ],
      ],
    ];

    return $element;
  }

  /**
   * Build a single additional property entry.
   *
   * @param int $index
   *   Entry index within the items array.
   * @param string $key
   *   The property name (dynamic key).
   * @param mixed $value_data
   *   The property value data.
   * @param object $additional_schema
   *   The additionalProperties schema.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $entry_context
   *   Context path for this entry.
   * @param string $parent_context_name
   *   Context name of the __additional container.
   * @param string|null $property_pattern
   *   Optional regex pattern for valid property names.
   *
   * @return array
   *   Render array for one entry.
   */
  protected function buildEntry(
    int $index,
    string $key,
    mixed $value_data,
    object $additional_schema,
    FormStateInterface $form_state,
    array $entry_context,
    string $parent_context_name,
    ?string $property_pattern,
  ): array {
    $entry_context_name = ArrayHelper::buildContextName($entry_context);

    $entry = [
      '#type' => 'fieldset',
      '#attributes' => [
        'data-parent' => '__additional',
        'class' => ['json-form-widget-additional-item'],
      ],
    ];

    // Dynamic key textfield.
    $key_element = [
      '#type' => 'textfield',
      '#title' => $this->t('Property name'),
      '#default_value' => $key,
      '#size' => 30,
    ];
    if ($property_pattern) {
      $key_element['#description'] = $this->t('Pattern: @pattern', ['@pattern' => $property_pattern]);
    }
    $entry['_key'] = $key_element;

    // Render sub-properties from the additionalProperties schema.
    if (isset($additional_schema->properties)) {
      $value_obj = is_object($value_data) ? $value_data : (object) ($value_data ?? []);
      foreach ($additional_schema->properties as $prop_name => $prop_schema) {
        $type = $prop_schema->type ?? 'string';
        $prop_value = $value_obj->$prop_name ?? NULL;
        $entry[$prop_name] = $this->builder->getFormElement(
          $type,
          ['name' => $prop_name, 'schema' => $prop_schema],
          $prop_value,
          $additional_schema,
          $form_state,
          $entry_context,
        );
      }
    }

    // Remove button.
    $entry['actions'] = [
      '#type' => 'actions',
      'remove' => [
        '#type' => 'submit',
        '#name' => $entry_context_name,
        '#value' => $this->t('Remove'),
        '#submit' => [static::class . '::removeProperty'],
        '#ajax' => [
          'callback' => [$this, 'additionalPropertiesCallback'],
          'wrapper' => $parent_context_name . '-fieldset-wrapper',
        ],
        '#attributes' => [
          'data-parent' => '__additional',
          'data-ap-context' => $parent_context_name,
        ],
        '#limit_validation_errors' => [],
      ],
    ];

    return $entry;
  }

  /**
   * AJAX callback: return the __additional wrapper element.
   *
   * Traverses the rebuilt form's #array_parents to find the __additional
   * container, following the same pattern as ArrayHelper.
   */
  public function additionalPropertiesCallback(array &$form, FormStateInterface $form_state): array {
    $button = $form_state->getTriggeringElement();
    $button_heritage = $button['#array_parents'];
    $button_parent = $button['#attributes']['data-parent'];

    $target_element = $form;
    foreach ($button_heritage as $button_ancestor) {
      $target_element = $target_element[$button_ancestor];
      if ($button_ancestor === $button_parent) {
        return $target_element;
      }
    }

    throw new \RuntimeException('Failed to find wrapper element for additional properties button.');
  }

  /**
   * Submit handler: add a new property entry.
   */
  public static function addProperty(array &$form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement();
    $context_name = $button['#name'];

    $count_property = self::buildAPStateProperty(self::AP_COUNT, $context_name);
    $item_count = $form_state->get($count_property) ?? 0;
    $item_count++;
    $form_state->set($count_property, $item_count);

    $add_property = self::buildAPStateProperty(self::AP_ADD, $context_name);
    $form_state->set($add_property, TRUE);

    $form_state->setRebuild();
  }

  /**
   * Submit handler: remove a property entry.
   */
  public static function removeProperty(array &$form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement();
    $ap_context = $button['#attributes']['data-ap-context'];
    $parents = $button['#parents'];

    // #parents ends with: [..., 'items', INDEX, 'actions', 'remove']
    // Remove last 3 elements to get path to items array, extract index.
    $count = count($parents);
    if ($count < 4) {
      return;
    }
    $index = $parents[$count - 3];
    $items_parents = array_slice($parents, 0, $count - 3);

    $user_input = $form_state->getUserInput();
    $key_exists = NULL;
    $input_values = &NestedArray::getValue($user_input, $items_parents, $key_exists);

    if ($key_exists && is_array($input_values)) {
      unset($input_values[$index]);
      $input_values = array_values($input_values);
    }

    $form_state->setUserInput($user_input);

    $count_property = self::buildAPStateProperty(self::AP_COUNT, $ap_context);
    $form_state->set($count_property, count($input_values ?? []));

    $form_state->setRebuild();
  }

  /**
   * Get or initialize the item count for additional properties.
   *
   * @param string $context_name
   *   Context identifier.
   * @param int $data_count
   *   Number of items from existing data.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return int
   *   Current item count.
   */
  protected function getAPItemCount(string $context_name, int $data_count, FormStateInterface $form_state): int {
    $count_property = self::buildAPStateProperty(self::AP_COUNT, $context_name);
    $item_count = $form_state->get($count_property);
    if (!isset($item_count)) {
      $item_count = $data_count;
      $form_state->set($count_property, $item_count);
    }
    return $item_count;
  }

  /**
   * Build a FormState property key for additional properties tracking.
   *
   * @param string $name
   *   Property name (e.g., 'count', 'add').
   * @param string $context_name
   *   Context identifier.
   *
   * @return array
   *   FormState property path.
   */
  public static function buildAPStateProperty(string $name, string $context_name): array {
    return [self::AP_STATE, $context_name, $name];
  }

}
