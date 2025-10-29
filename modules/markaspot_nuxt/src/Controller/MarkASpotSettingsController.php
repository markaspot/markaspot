<?php

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

class MarkASpotSettingsController extends ControllerBase {
  protected $entityTypeManager;
  protected $configFactory;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  public function getMarkASpotSettings() {
    // Load the 'markaspot_nuxt.settings' configuration.
    $nuxt_config = $this->configFactory->get('markaspot_nuxt.settings');

    if (!$nuxt_config || $nuxt_config->isNew()) {
      return new JsonResponse(['error' => 'Configuration not found'], 404);
    }

    // Build settings array from markaspot_nuxt.settings
    $settings = [
      // Frontend configuration
      'frontend' => [
        'base_url' => $nuxt_config->get('frontend_base_url'),
        'enabled' => $nuxt_config->get('frontend_enabled'),
        'cors_enabled' => $nuxt_config->get('api_cors_enabled'),
      ],
      // Map configuration - Mapbox/MapLibre
      'mapbox_token' => $nuxt_config->get('mapbox_token'),
      'mapbox_style' => $nuxt_config->get('mapbox_style'),
      'mapbox_style_dark' => $nuxt_config->get('mapbox_style_dark'),
      'osm_custom_attribution' => $nuxt_config->get('osm_custom_attribution'),
      'osm_custom_tile_url' => $nuxt_config->get('osm_custom_tile_url'),
      // Fallback style configuration
      'fallback_style' => $nuxt_config->get('fallback_style'),
      'fallback_style_dark' => $nuxt_config->get('fallback_style_dark'),
      'fallback_api_key' => $nuxt_config->get('fallback_api_key'),
      'fallback_attribution' => $nuxt_config->get('fallback_attribution'),
      // Map position
      'zoom_initial' => $nuxt_config->get('zoom_initial') ?: 13,
      'center_lat' => $nuxt_config->get('center_lat'),
      'center_lng' => $nuxt_config->get('center_lng'),
    ];

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
          'cardinality' => $field_storage ? $field_storage->getCardinality() : null,
          'field_type' => $field_config->getType(),
          'default_value' => $field_config->getDefaultValueLiteral(),
          'settings' => $field_config->getSettings(),
          'widget' => $component['type'] ?? null,
          'widget_settings' => $component['settings'] ?? [],
          'display_settings' => $form_display->getComponent($field_name) ?? [],
          'validation' => $this->getFieldValidation($field_config),
        ];

        // If the field is a reference to media, include media-specific data.
        if ($field_config->getType() === 'entity_reference' && $field_config->getSetting('target_type') === 'media') {
          $field_data['reference_type'] = 'media';
          $field_data['media_types'] = $this->getReferencedMediaTypes($field_config);
        }

        $fields[$field_name] = $field_data;
      }
    }

    // Return the form display settings with fields as a JSON response.
    return new JsonResponse([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'form_mode' => $form_mode,
      'fields' => $fields,
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
          $cardinality = $field_storage ? $field_storage->getCardinality() : null;

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
}
