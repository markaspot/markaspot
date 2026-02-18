<?php

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
   * Cached with appropriate cache tags - automatically invalidates when:
   * - markaspot_nuxt.settings config is changed
   * - Jurisdiction group entities are created, updated, or deleted
   * - Logo or font files are updated
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request, may contain 'jurisdiction' query parameter.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The configuration settings in JSON format.
   */
  public function getMarkASpotSettings(Request $request) {
    // Build cache metadata.
    $cache_metadata = new CacheableMetadata();
    // Set max-age for HTTP caching (1 hour).
    $cache_metadata->setCacheMaxAge(3600);
    // Add cache contexts for query params that affect the response.
    $cache_metadata->addCacheContexts([
      'url.query_args:exclude',
      'url.query_args:jurisdiction',
    ]);

    // Load the 'markaspot_nuxt.settings' configuration.
    $nuxt_config = $this->configFactory->get('markaspot_nuxt.settings');
    // Add config cache tag.
    $cache_metadata->addCacheTags(['config:markaspot_nuxt.settings']);

    // Load group type settings from markaspot_open311 (supports legacy naming).
    $open311_config = $this->configFactory->get('markaspot_open311.settings');
    $cache_metadata->addCacheTags(['config:markaspot_open311.settings']);
    $jur_type = $open311_config->get('jurisdiction_group_type') ?? 'jur';

    if (!$nuxt_config || $nuxt_config->isNew()) {
      $response = new CacheableJsonResponse(['error' => 'Configuration not found'], 404);
      $response->addCacheableDependency($cache_metadata);
      return $response;
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
      // Geocoding configuration (applies to Mapbox, Photon, Nominatim).
      'geocoding_country' => $nuxt_config->get('geocoding_country') ?: '',
      'geocoding_region' => $nuxt_config->get('geocoding_region') ?: '',
    ];

    // Load jurisdiction-specific configuration from group entity.
    // Supports both numeric ID and URL slug for routing.
    $jurisdiction_param = $request->query->get('jurisdiction');
    $group = NULL;

    // Add list cache tag for when groups are added/removed.
    $cache_metadata->addCacheTags(['group_list:' . $jur_type]);

    if ($jurisdiction_param) {
      // Try numeric ID first.
      if (is_numeric($jurisdiction_param)) {
        $loaded_group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_param);
        // Only use group if it's published.
        if ($loaded_group && $loaded_group->isPublished()) {
          $group = $loaded_group;
        }
      }
      // Try slug lookup (validate format first).
      if (!$group && preg_match('/^[a-z0-9_-]{1,64}$/i', $jurisdiction_param)) {
        $groups = $this->entityTypeManager->getStorage('group')->loadByProperties([
          'type' => $jur_type,
          'field_slug' => $jurisdiction_param,
          'status' => 1,
        ]);
        $group = reset($groups) ?: NULL;
      }
    }

    // Default to first published jurisdiction group (by ID) if none specified or found.
    if ($group === NULL) {
      $group_ids = $this->entityTypeManager->getStorage('group')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $jur_type)
        ->condition('status', 1)
        ->sort('id', 'ASC')
        ->range(0, 1)
        ->execute();
      if (!empty($group_ids)) {
        $group = $this->entityTypeManager->getStorage('group')->load(reset($group_ids));
      }
    }

    // Add cache dependency on the loaded group entity.
    if ($group) {
      $cache_metadata->addCacheableDependency($group);
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
        // Keys must match the nuxt_config.schema.json properties.
        $config_keys = [
          'client',
          'theme',
          'features',
          'languages',
          'ui',
          'media',
          'map',
          'navigation',
          'filters',
          'forms',
          'privacy',
          'i18n',
          'groupTypes',
          'systemNotice',
        ];
        foreach ($config_keys as $key) {
          if (!empty($jurisdiction_config[$key])) {
            $settings[$key] = $jurisdiction_config[$key];
          }
        }
      }
    }

    // Add groupTypes if not set from jurisdiction config.
    // Auto-detect from markaspot_open311 config (supports legacy 'organisation' naming).
    if (empty($settings['groupTypes'])) {
      $org_type = $open311_config->get('organisation_group_type') ?? 'org';

      // Check if org type exists, fallback to 'organisation'.
      $org_type_exists = $this->entityTypeManager->getStorage('group_type')->load($org_type);
      if (!$org_type_exists && $org_type === 'org') {
        $legacy_type = $this->entityTypeManager->getStorage('group_type')->load('organisation');
        if ($legacy_type) {
          $org_type = 'organisation';
        }
      }

      $settings['groupTypes'] = [
        'organisation' => $org_type,
        'jurisdiction' => $jur_type,
      ];
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
          // Add cache dependency on file entity.
          $cache_metadata->addCacheableDependency($file);
        }
      }

      if ($group->hasField('field_logo_dark') && !$group->get('field_logo_dark')->isEmpty()) {
        $file = $group->get('field_logo_dark')->entity;
        if ($file) {
          $logos['dark'] = $getRelativePath($file);
          // Add cache dependency on file entity.
          $cache_metadata->addCacheableDependency($file);
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
          // Add cache dependency on file entity.
          $cache_metadata->addCacheableDependency($file);
        }
      }
      if ($group->hasField('field_font_body') && !$group->get('field_font_body')->isEmpty()) {
        $file = $group->get('field_font_body')->entity;
        if ($file) {
          $fonts['bodyUrl'] = $getRelativePath($file);
          // Add cache dependency on file entity.
          $cache_metadata->addCacheableDependency($file);
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
    // Skip if 'exclude=boundary' query param is set (for faster initial page load).
    $exclude = $request->query->get('exclude');
    $excludeBoundary = $exclude === 'boundary' || (is_array($exclude) && in_array('boundary', $exclude));
    if (!$excludeBoundary && $group && $group->hasField('field_boundary') && !$group->get('field_boundary')->isEmpty()) {
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

    // Return the configuration as a cacheable JSON response.
    $response = new CacheableJsonResponse($settings);
    $response->addCacheableDependency($cache_metadata);
    return $response;
  }

  /**
   * Returns form display settings, including field settings and media reference info.
   *
   * Cached with appropriate cache tags - automatically invalidates when:
   * - Entity form display configuration changes
   * - Field configurations are updated
   * - Field storage configurations are updated
   *
   * @param string $entity_type
   *   The entity type (e.g., node, user).
   * @param string $bundle
   *   The bundle (e.g., article, page).
   * @param string $form_mode
   *   The form mode (e.g., default, teaser).
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The form display settings in JSON format.
   */
  public function getFormModeSettings($entity_type, $bundle, $form_mode) {
    // Build cache metadata.
    $cache_metadata = new CacheableMetadata();
    // Set max-age for HTTP caching (1 hour).
    $cache_metadata->setCacheMaxAge(3600);

    // Load the form display for the given entity type, bundle, and form mode.
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load("{$entity_type}.{$bundle}.{$form_mode}");

    // Check if the form display exists.
    if (!$form_display) {
      // Add cache tag for when this form display might be created.
      $cache_metadata->addCacheTags(["config:core.entity_form_display.{$entity_type}.{$bundle}.{$form_mode}"]);
      $response = new CacheableJsonResponse(['error' => 'Form mode not found'], 404);
      $response->addCacheableDependency($cache_metadata);
      return $response;
    }

    // Add cache dependency on the form display entity.
    $cache_metadata->addCacheableDependency($form_display);

    // Prepare an array to store field settings and other related data.
    $fields = [];

    // Loop through each field in the form display and collect its settings.
    foreach ($form_display->getComponents() as $field_name => $component) {
      // Load the field config and field storage for additional details.
      $field_config = FieldConfig::loadByName($entity_type, $bundle, $field_name);
      $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);

      // Add cache dependencies on field config entities.
      if ($field_config) {
        $cache_metadata->addCacheableDependency($field_config);
      }
      if ($field_storage) {
        $cache_metadata->addCacheableDependency($field_storage);
      }

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

    // Return the form display settings with fields and groups as a cacheable JSON response.
    $response = new CacheableJsonResponse([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'form_mode' => $form_mode,
      'fields' => $fields,
      'field_groups' => $field_groups,
    ]);
    $response->addCacheableDependency($cache_metadata);
    return $response;
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
   * Cached with field storage config cache tags - automatically invalidates
   * when the field storage configuration is updated.
   *
   * @param string $entity_type
   *   The entity type (e.g., node, user).
   * @param string $field_name
   *   The field name (e.g., field_party, field_oktoberfest).
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The field options in JSON format.
   */
  public function getFieldOptions($entity_type, $field_name) {
    // Build cache metadata.
    $cache_metadata = new CacheableMetadata();
    // Set max-age for HTTP caching (1 hour).
    $cache_metadata->setCacheMaxAge(3600);

    // Load the field storage to get allowed_values.
    $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);

    if (!$field_storage) {
      // Add cache tag for when this field storage might be created.
      $cache_metadata->addCacheTags(["config:field.storage.{$entity_type}.{$field_name}"]);
      $response = new CacheableJsonResponse(['error' => 'Field not found'], 404);
      $response->addCacheableDependency($cache_metadata);
      return $response;
    }

    // Add cache dependency on the field storage entity.
    $cache_metadata->addCacheableDependency($field_storage);

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

    $response = new CacheableJsonResponse([
      'field_name' => $field_name,
      'field_type' => $field_type,
      'options' => $options,
    ]);
    $response->addCacheableDependency($cache_metadata);
    return $response;
  }

  /**
   * Returns available jurisdictions for URL routing.
   *
   * Used by the frontend to determine available jurisdictions and enable
   * slug-based URL routing when multiple jurisdictions exist.
   *
   * Cached with group entity cache tags - automatically invalidates when any
   * jurisdiction group is created, updated, or deleted.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   List of jurisdictions with id, name, slug, and isDefault flag.
   */
  public function getJurisdictions() {
    // Load group type setting from markaspot_open311 (supports legacy naming).
    $open311_config = $this->configFactory->get('markaspot_open311.settings');
    $jur_type = $open311_config->get('jurisdiction_group_type') ?? 'jur';

    // Use EntityQuery with explicit sorting to ensure consistent ordering.
    // The first published jurisdiction (lowest ID) is treated as default.
    // Only published jurisdictions are returned.
    $group_ids = $this->entityTypeManager->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $jur_type)
      ->condition('status', 1)
      ->sort('id', 'ASC')
      ->execute();

    $groups = $this->entityTypeManager->getStorage('group')->loadMultiple($group_ids);

    $jurisdictions = [];
    $first = TRUE;

    // Build cache metadata with tags from all loaded groups.
    $cache_metadata = new CacheableMetadata();
    // List cache tag for when groups are added/removed.
    $cache_metadata->addCacheTags(['group_list:' . $jur_type]);
    $cache_metadata->addCacheTags(['config:markaspot_open311.settings']);
    // Set max-age for HTTP caching (1 hour).
    $cache_metadata->setCacheMaxAge(3600);

    foreach ($groups as $group) {
      $slug = NULL;
      if ($group->hasField('field_slug') && !$group->get('field_slug')->isEmpty()) {
        $slug = $group->get('field_slug')->value;
      }

      $jurisdictions[] = [
        'id' => (int) $group->id(),
        'name' => $group->label(),
        'slug' => $slug,
        'isDefault' => $first,
      ];
      $first = FALSE;

      // Add cache tag for each individual group entity.
      $cache_metadata->addCacheableDependency($group);
    }

    $response = new CacheableJsonResponse([
      'jurisdictions' => $jurisdictions,
      'count' => count($jurisdictions),
      'hasMultiple' => count($jurisdictions) > 1,
    ]);

    // Attach cache metadata to response.
    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Returns available organisations for the dashboard.
   *
   * Used by the frontend dashboard to populate organisation dropdowns
   * for filtering and assignment purposes.
   *
   * Supports both 'org' (new) and 'organisation' (legacy) group types.
   * The type can be configured via markaspot_open311.settings.organisation_group_type.
   *
   * Cached with group entity cache tags - automatically invalidates when any
   * organisation group is created, updated, or deleted.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   List of organisations with id (uuid), numericId, and label.
   */
  public function getOrganisations(): CacheableJsonResponse {
    // Load organisation group type from config (supports legacy naming).
    // Defaults to 'org', falls back to 'organisation' if no groups found.
    $open311_config = $this->configFactory->get('markaspot_open311.settings');
    $org_type = $open311_config->get('organisation_group_type') ?? 'org';

    // Use EntityQuery with explicit sorting to ensure consistent ordering.
    // Only published organisations are returned.
    $group_ids = $this->entityTypeManager->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $org_type)
      ->condition('status', 1)
      ->sort('label', 'ASC')
      ->execute();

    // Fallback to 'organisation' type if no groups found with configured type.
    if (empty($group_ids) && $org_type === 'org') {
      $org_type = 'organisation';
      $group_ids = $this->entityTypeManager->getStorage('group')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $org_type)
        ->condition('status', 1)
        ->sort('label', 'ASC')
        ->execute();
    }

    $groups = $this->entityTypeManager->getStorage('group')->loadMultiple($group_ids);

    $organisations = [];

    // Build cache metadata with tags from all loaded groups.
    $cache_metadata = new CacheableMetadata();
    // List cache tag for when groups are added/removed.
    $cache_metadata->addCacheTags(['group_list:org', 'group_list:organisation']);
    $cache_metadata->addCacheTags(['config:markaspot_open311.settings']);
    // Set max-age for HTTP caching (1 hour).
    $cache_metadata->setCacheMaxAge(3600);

    foreach ($groups as $group) {
      $organisations[] = [
        'id' => $group->uuid(),
        'numericId' => (int) $group->id(),
        'label' => $group->label(),
      ];

      // Add cache tag for each individual group entity.
      $cache_metadata->addCacheableDependency($group);
    }

    $response = new CacheableJsonResponse([
      'organisations' => $organisations,
      'count' => count($organisations),
    ]);

    // Attach cache metadata to response.
    $response->addCacheableDependency($cache_metadata);

    return $response;
  }

  /**
   * Returns custom font CSS for a jurisdiction as text/css.
   *
   * Combines @font-face declarations (from field_custom_css) with
   * CSS custom properties (--font-heading, --font-body) derived from
   * the jurisdiction's font configuration. Designed to be loaded as a
   * <link rel="stylesheet"> from the Nuxt SSR plugin, which makes it
   * immune to client-side JS hydration overwriting inline style tags.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request, may contain 'jurisdiction' query parameter.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   CSS response with @font-face and custom property declarations.
   */
  public function getFontsCss(Request $request): Response {
    $open311_config = $this->configFactory->get('markaspot_open311.settings');
    $jur_type = $open311_config->get('jurisdiction_group_type') ?? 'jur';
    $jurisdiction_param = $request->query->get('jurisdiction');
    $group = NULL;

    if ($jurisdiction_param && is_numeric($jurisdiction_param)) {
      $loaded_group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_param);
      if ($loaded_group && $loaded_group->isPublished()) {
        $group = $loaded_group;
      }
    }

    if ($group === NULL) {
      $group_ids = $this->entityTypeManager->getStorage('group')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $jur_type)
        ->condition('status', 1)
        ->sort('id', 'ASC')
        ->range(0, 1)
        ->execute();
      if (!empty($group_ids)) {
        $group = $this->entityTypeManager->getStorage('group')->load(reset($group_ids));
      }
    }

    $css_parts = [];

    if ($group) {
      // Read field_nuxt_config JSON for theme.customCss and theme.fonts.
      $nuxt_config = [];
      if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
        $nuxt_config = json_decode($group->get('field_nuxt_config')->value ?? '{}', TRUE) ?: [];
      }

      // 1. @font-face declarations.
      //    Primary: theme.customCss inside field_nuxt_config (may contain @font-face rules).
      //    Secondary: field_custom_css standalone field.
      $custom_css = trim($nuxt_config['theme']['customCss'] ?? '');
      if (!$custom_css && $group->hasField('field_custom_css') && !$group->get('field_custom_css')->isEmpty()) {
        $custom_css = trim($group->get('field_custom_css')->value ?? '');
      }
      if ($custom_css) {
        $css_parts[] = $custom_css;
      }

      // 2. CSS custom properties (--font-heading, --font-body) from theme.fonts.
      $fonts = $nuxt_config['theme']['fonts'] ?? [];
      $font_vars = [];

      if (!empty($fonts['heading'])) {
        // Sanitize: allow only font-name safe characters.
        $heading = preg_replace('/[^a-zA-Z0-9\s\-\'",]/', '', (string) $fonts['heading']);
        if ($heading) {
          $font_vars[] = "  --font-heading: \"{$heading}\", system-ui, sans-serif;";
        }
      }
      if (!empty($fonts['body'])) {
        $body = preg_replace('/[^a-zA-Z0-9\s\-\'",]/', '', (string) $fonts['body']);
        if ($body) {
          $font_vars[] = "  --font-body: \"{$body}\", system-ui, sans-serif;";
        }
      }

      if ($font_vars) {
        $css_parts[] = "html:root {\n" . implode("\n", $font_vars) . "\n}";
      }
    }

    $css = implode("\n\n", $css_parts);

    return new Response($css, 200, [
      'Content-Type' => 'text/css; charset=UTF-8',
      'Cache-Control' => 'public, max-age=86400',
      'Access-Control-Allow-Origin' => '*',
      'Vary' => 'Accept-Encoding',
    ]);
  }

}
