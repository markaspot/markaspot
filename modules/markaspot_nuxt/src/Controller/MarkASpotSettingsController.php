<?php

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Controller for Mark-a-Spot settings API.
 */
class MarkASpotSettingsController extends ControllerBase {

  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * Constructs a MarkASpotSettingsController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->streamWrapperManager = $stream_wrapper_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('stream_wrapper_manager')
    );
  }

  /**
   * Returns Mark-a-Spot configuration settings as JSON.
   *
   * Loads base settings from markaspot_nuxt.settings config and merges
   * jurisdiction-specific configuration from group entities. Supports
   * jurisdiction lookup by numeric ID or URL slug.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request, may contain 'jurisdiction' query parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The configuration settings in JSON format.
   */
  public function getMarkASpotSettings(Request $request) {
    // Load the 'markaspot_nuxt.settings' configuration.
    $nuxt_config = $this->configFactory->get('markaspot_nuxt.settings');

    if (!$nuxt_config || $nuxt_config->isNew()) {
      return new JsonResponse(['error' => 'Configuration not found'], 404);
    }

    // Build settings array from markaspot_nuxt.settings.
    $settings = [
      // Frontend configuration.
      'frontend' => [
        'base_url' => $nuxt_config->get('frontend_base_url'),
        'enabled' => $nuxt_config->get('frontend_enabled'),
        'cors_enabled' => $nuxt_config->get('api_cors_enabled'),
      ],
      // Map configuration - Mapbox/MapLibre.
      'mapbox_token' => $nuxt_config->get('mapbox_token'),
      'mapbox_style' => $nuxt_config->get('mapbox_style'),
      'mapbox_style_dark' => $nuxt_config->get('mapbox_style_dark'),
      'osm_custom_attribution' => $nuxt_config->get('osm_custom_attribution'),
      'osm_custom_tile_url' => $nuxt_config->get('osm_custom_tile_url'),
      // Fallback style configuration.
      'fallback_style' => $nuxt_config->get('fallback_style'),
      'fallback_style_dark' => $nuxt_config->get('fallback_style_dark'),
      'fallback_api_key' => $nuxt_config->get('fallback_api_key'),
      'fallback_attribution' => $nuxt_config->get('fallback_attribution'),
      // Map position.
      'zoom_initial' => $nuxt_config->get('zoom_initial') ?: 13,
      'center_lat' => $nuxt_config->get('center_lat'),
      'center_lng' => $nuxt_config->get('center_lng'),
    ];

    // Load jurisdiction-specific configuration from group entity.
    // Supports both numeric ID and URL slug for routing.
    $jurisdiction_param = $request->query->get('jurisdiction');
    $group = NULL;

    if ($jurisdiction_param) {
      // Try numeric ID first.
      if (is_numeric($jurisdiction_param)) {
        $group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_param);
      }
      // Try slug lookup (validate format first).
      if (!$group && preg_match('/^[a-z0-9_-]{1,64}$/i', $jurisdiction_param)) {
        $groups = $this->entityTypeManager->getStorage('group')->loadByProperties([
          'type' => 'jur',
          'field_slug' => $jurisdiction_param,
        ]);
        $group = reset($groups) ?: NULL;
      }
    }

    // Default to first jurisdiction group if none specified or found.
    if ($group === NULL) {
      $groups = $this->entityTypeManager->getStorage('group')->loadByProperties(['type' => 'jur']);
      $group = reset($groups);
    }

    if ($group && $group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $nuxt_json = $group->get('field_nuxt_config')->value;
      $jurisdiction_config = json_decode($nuxt_json, TRUE);

      if (is_array($jurisdiction_config)) {
        // Add jurisdiction info.
        $settings['jurisdiction'] = [
          'id' => (int) $group->id(),
          'name' => $group->label(),
          'slug' => $group->hasField('field_slug') && !$group->get('field_slug')->isEmpty()
            ? $group->get('field_slug')->value
            : NULL,
        ];

        // Merge jurisdiction config into settings.
        // These override/extend the base settings.
        foreach (['client', 'theme', 'features', 'languages', 'ui', 'media', 'i18n'] as $key) {
          if (!empty($jurisdiction_config[$key])) {
            $settings[$key] = $jurisdiction_config[$key];
          }
        }
      }
    }

    // Add file URLs from group's file fields if available.
    // Return relative paths - frontend will proxy through /api/images/ or /api/fonts/.
    if ($group) {
      // Helper to convert file URI to relative path.
      $getRelativePath = function ($file) {
        $uri = $file->getFileUri();
        // Get the stream wrapper (e.g., public://)
        $scheme = $this->streamWrapperManager->getScheme($uri);
        if ($scheme === 'public') {
          // public://fonts/file.woff2 -> /sites/default/files/fonts/file.woff2.
          $target = $this->streamWrapperManager->getTarget($uri);
          $publicPath = PublicStream::basePath();
          return '/' . $publicPath . '/' . $target;
        }
        // Fallback: extract path from URI.
        return '/' . str_replace('://', '/', $uri);
      };

      $logos = [];

      if ($group->hasField('field_logo_light') && !$group->get('field_logo_light')->isEmpty()) {
        $file = $group->get('field_logo_light')->entity;
        if ($file) {
          $logos['light'] = $getRelativePath($file);
        }
      }

      if ($group->hasField('field_logo_dark') && !$group->get('field_logo_dark')->isEmpty()) {
        $file = $group->get('field_logo_dark')->entity;
        if ($file) {
          $logos['dark'] = $getRelativePath($file);
        }
      }

      // Merge logos into theme settings.
      if (!empty($logos)) {
        if (!isset($settings['theme'])) {
          $settings['theme'] = [];
        }
        $settings['theme']['logos'] = $logos;
      }

      // Add custom CSS from field_custom_css.
      if ($group->hasField('field_custom_css') && !$group->get('field_custom_css')->isEmpty()) {
        $custom_css = $group->get('field_custom_css')->value;
        if (!empty(trim($custom_css))) {
          if (!isset($settings['theme'])) {
            $settings['theme'] = [];
          }
          $settings['theme']['customCss'] = $custom_css;
        }
      }

      // Add font URLs from file fields (relative paths for frontend proxy).
      $fonts = [];
      if ($group->hasField('field_font_heading') && !$group->get('field_font_heading')->isEmpty()) {
        $file = $group->get('field_font_heading')->entity;
        if ($file) {
          $fonts['headingUrl'] = $getRelativePath($file);
        }
      }
      if ($group->hasField('field_font_body') && !$group->get('field_font_body')->isEmpty()) {
        $file = $group->get('field_font_body')->entity;
        if ($file) {
          $fonts['bodyUrl'] = $getRelativePath($file);
        }
      }
      if (!empty($fonts)) {
        if (!isset($settings['theme'])) {
          $settings['theme'] = [];
        }
        if (!isset($settings['theme']['fonts'])) {
          $settings['theme']['fonts'] = [];
        }
        $settings['theme']['fonts'] = array_merge($settings['theme']['fonts'], $fonts);
      }
    }

    // Add boundary GeoJSON from group's field_boundary if available.
    if ($group && $group->hasField('field_boundary') && !$group->get('field_boundary')->isEmpty()) {
      $boundary_json = $group->get('field_boundary')->value;
      // Strip HTML tags as safety measure.
      $boundary_json = strip_tags($boundary_json);
      $boundary_data = json_decode($boundary_json, TRUE);
      if (is_array($boundary_data)) {
        // Ensure boundary is a FeatureCollection (wrap single Feature if needed)
        if (isset($boundary_data['type']) && $boundary_data['type'] === 'Feature') {
          $settings['boundary'] = [
            'type' => 'FeatureCollection',
            'features' => [$boundary_data],
          ];
        }
        elseif (isset($boundary_data['type']) && $boundary_data['type'] === 'FeatureCollection') {
          $settings['boundary'] = $boundary_data;
        }
        else {
          $settings['boundary'] = $boundary_data;
        }
      }
    }

    // Return the configuration as a JSON response.
    return new JsonResponse($settings);
  }

  /**
   * Returns form display settings, including field settings and media reference info.
   *
   * @param string $entity_type
   *   The entity type (e.g., node, user).
   * @param string $bundle
   *   The bundle (e.g., article, page).
   * @param string $form_mode
   *   The form mode (e.g., default, teaser).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The form display settings in JSON format.
   */
  public function getFormModeSettings($entity_type, $bundle, $form_mode) {
    // Load the form display for the given entity type, bundle, and form mode.
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load("{$entity_type}.{$bundle}.{$form_mode}");

    // Check if the form display exists.
    if (!$form_display) {
      return new JsonResponse(['error' => 'Form mode not found'], 404);
    }

    // Prepare an array to store field settings and other related data.
    $fields = [];

    // Loop through each field in the form display and collect its settings.
    foreach ($form_display->getComponents() as $field_name => $component) {
      // Load the field config and field storage for additional details.
      $field_config = FieldConfig::loadByName($entity_type, $bundle, $field_name);
      $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);

      if ($field_config) {
        $field_data = [
          'label' => $field_config->getLabel(),
          'description' => $field_config->getDescription(),
          'required' => $field_config->isRequired(),
          'cardinality' => $field_storage ? $field_storage->getCardinality() : NULL,
          'field_type' => $field_config->getType(),
          'default_value' => $field_config->getDefaultValueLiteral(),
          'settings' => $field_config->getSettings(),
          'widget' => $component['type'] ?? NULL,
          'widget_settings' => $component['settings'] ?? [],
          'display_settings' => $form_display->getComponent($field_name) ?? [],
          'validation' => $this->getFieldValidation($field_config),
        ];

        // For list fields, include allowed_values from field storage.
        if ($field_storage && in_array($field_config->getType(), ['list_integer', 'list_float', 'list_string'])) {
          $storage_settings = $field_storage->getSettings();
          if (!empty($storage_settings['allowed_values'])) {
            // Allowed values are already in [value => label] format.
            $field_data['settings']['allowed_values'] = $storage_settings['allowed_values'];
          }
        }

        // If the field is a reference to media, include media-specific data.
        if ($field_config->getType() === 'entity_reference' && $field_config->getSetting('target_type') === 'media') {
          $field_data['reference_type'] = 'media';
          $field_data['media_types'] = $this->getReferencedMediaTypes($field_config);
        }

        $fields[$field_name] = $field_data;
      }
    }

    // Get field groups from third_party_settings.
    $field_groups = [];
    $third_party_settings = $form_display->getThirdPartySettings('field_group');
    if (!empty($third_party_settings)) {
      foreach ($third_party_settings as $group_name => $group_config) {
        $field_groups[$group_name] = [
          'id' => $group_name,
          'label' => $group_config['label'] ?? $group_name,
          'type' => $group_config['format_type'] ?? 'fieldset',
          'weight' => $group_config['weight'] ?? 0,
          'region' => $group_config['region'] ?? 'content',
          'parent' => $group_config['parent_name'] ?? NULL,
          'children' => $group_config['children'] ?? [],
          'settings' => $group_config['format_settings'] ?? [],
        ];
      }
    }

    // Return the form display settings with fields and groups as a JSON response.
    return new JsonResponse([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'form_mode' => $form_mode,
      'fields' => $fields,
      'field_groups' => $field_groups,
    ]);
  }

  /**
   * Retrieves validation settings for a field.
   *
   * @param \Drupal\field\Entity\FieldConfig $field_config
   *   The field configuration entity.
   *
   * @return array
   *   An array of validation constraints for the field.
   */
  private function getFieldValidation(FieldConfig $field_config) {
    $validation_settings = [];

    // Retrieve validation constraints for the field.
    $constraints = $field_config->getConstraints();
    foreach ($constraints as $constraint_name => $constraint_settings) {
      $validation_settings[] = [
        'name' => $constraint_name,
        'settings' => $constraint_settings,
      ];
    }

    return $validation_settings;
  }

  /**
   * Retrieves detailed information about media types referenced by the field, including cardinality.
   *
   * @param \Drupal\field\Entity\FieldConfig $field_config
   *   The field configuration entity.
   *
   * @return array
   *   A detailed array of information about media types referenced by this field.
   */
  private function getReferencedMediaTypes(FieldConfig $field_config) {
    $media_details = [];

    // Check if the field is a media reference field.
    if ($field_config->getSetting('target_type') == 'media') {
      $allowed_media_types = $field_config->getSetting('handler_settings')['target_bundles'] ?? [];

      // Load the media bundle configuration to get detailed information.
      $media_storage = $this->entityTypeManager->getStorage('media_type');
      foreach ($allowed_media_types as $media_bundle => $enabled) {
        $media_type = $media_storage->load($media_bundle);
        if ($media_type) {
          // Fetch field storage for the media entity to retrieve cardinality.
          $field_storage = FieldStorageConfig::loadByName('media', $media_bundle);
          $cardinality = $field_storage ? $field_storage->getCardinality() : NULL;

          // Collect detailed information about the media type.
          $media_details[] = [
            'id' => $media_type->id(),
            'label' => $media_type->label(),
            'description' => $media_type->get('description'),
            'field_map' => $media_type->get('field_map'),
            'status' => $media_type->status() ? 'enabled' : 'disabled',
            'cardinality' => $cardinality,
          ];
        }
      }
    }

    return $media_details;
  }

  /**
   * Returns field options (allowed_values) for a specific field.
   *
   * This is useful for conditional fields that aren't in the form display
   * but need their options loaded dynamically.
   *
   * @param string $entity_type
   *   The entity type (e.g., node, user).
   * @param string $field_name
   *   The field name (e.g., field_party, field_oktoberfest).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The field options in JSON format.
   */
  public function getFieldOptions($entity_type, $field_name) {
    // Load the field storage to get allowed_values.
    $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);

    if (!$field_storage) {
      return new JsonResponse(['error' => 'Field not found'], 404);
    }

    $field_type = $field_storage->getType();
    $settings = $field_storage->getSettings();
    $options = [];

    // Handle list fields (list_integer, list_float, list_string).
    if (in_array($field_type, ['list_integer', 'list_float', 'list_string'])) {
      if (!empty($settings['allowed_values'])) {
        foreach ($settings['allowed_values'] as $item) {
          if (isset($item['value']) && isset($item['label'])) {
            $options[$item['value']] = $item['label'];
          }
        }
      }
    }

    return new JsonResponse([
      'field_name' => $field_name,
      'field_type' => $field_type,
      'options' => $options,
    ]);
  }

}
