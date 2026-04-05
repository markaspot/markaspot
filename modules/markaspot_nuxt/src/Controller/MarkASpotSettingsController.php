<?php

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
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

  use JurisdictionIdResolverTrait;

  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Constructs a MarkASpotSettingsController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchy_resolver
   *   The jurisdiction hierarchy resolver.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->hierarchyResolver = $hierarchy_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('stream_wrapper_manager'),
      $container->get('markaspot_group.hierarchy_resolver')
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
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
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
    // Add config cache tag. 'config:core.extension' invalidates when modules
    // are installed/uninstalled (ensures feature flags update immediately).
    $cache_metadata->addCacheTags([
      'config:markaspot_nuxt.settings',
      'config:core.extension',
    ]);

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
    // SECURITY: mapbox_token, fallback_api_key, and frontend config are
    // intentionally excluded. API keys must stay server-side (Nuxt ENV).
    // See: markaspot/markaspot-ui#133
    $settings = [
      // Map configuration - styles only, no keys.
      'mapbox_style' => $nuxt_config->get('mapbox_style'),
      'mapbox_style_dark' => $nuxt_config->get('mapbox_style_dark'),
      'osm_custom_attribution' => $nuxt_config->get('osm_custom_attribution'),
      'osm_custom_tile_url' => $nuxt_config->get('osm_custom_tile_url'),
      // Fallback style configuration - no keys.
      'fallback_style' => $nuxt_config->get('fallback_style'),
      'fallback_style_dark' => $nuxt_config->get('fallback_style_dark'),
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
    // Group entities invalidate 'group_list' (not bundle-specific 'group_list:jur'),
    // so we must use the generic tag for proper cache invalidation.
    $cache_metadata->addCacheTags(['group_list']);

    if ($jurisdiction_param) {
      // Accept both slugs and numeric IDs for backwards compatibility
      // (embed URLs, ENV vars). Uses the same resolver as getFontsCss()
      // and getOrganisations().
      $resolved_id = $this->resolveJurisdictionId($jurisdiction_param, $jur_type);
      if ($resolved_id !== null) {
        $group = $this->entityTypeManager->getStorage('group')->load($resolved_id);
        if ($group && $group->isPublished()) {
          $cache_metadata->addCacheTags(['group:' . $group->id()]);
        } else {
          $group = NULL;
        }
      }
    }

    // If a specific jurisdiction was requested but not found, return 404.
    // Do NOT fall back to default (prevents tenant enumeration via ID brute-force).
    if ($group === NULL && $jurisdiction_param) {
      $error_response = new CacheableJsonResponse(['error' => 'Jurisdiction not found'], 404);
      $error_response->addCacheableDependency($cache_metadata);
      return $error_response;
    }

    // Default to first published jurisdiction group only when NO param specified.
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

    // Resolve root jurisdiction ID for taxonomy filtering.
    // Child jurisdictions inherit the parent's service catalog (categories, statuses).
    $taxonomyJurisdictionId = $group
      ? $this->hierarchyResolver->getRootJurisdictionId((int) $group->id())
      : NULL;

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
          'taxonomyJurisdictionId' => $taxonomyJurisdictionId,
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
          'responseVisibility',
          'embed',
          'dashboard',
        ];
        foreach ($config_keys as $key) {
          if (!empty($jurisdiction_config[$key])) {
            $settings[$key] = $jurisdiction_config[$key];
          }
        }

        // Sync map center/zoom from jurisdiction config to top-level keys.
        // The frontend reads center_lat/center_lng/zoom_initial at top level,
        // but jurisdictions store these inside the nested 'map' object.
        if (!empty($settings['map'])) {
          $map = $settings['map'];
          // Sync nested style keys used by jurisdiction field_nuxt_config.
          if (!empty($map['style'])) {
            $settings['mapbox_style'] = $map['style'];
            if (empty($settings['fallback_style'])) {
              $settings['fallback_style'] = $map['style'];
            }
          }
          if (!empty($map['styleDark'])) {
            $settings['mapbox_style_dark'] = $map['styleDark'];
            if (empty($settings['fallback_style_dark'])) {
              $settings['fallback_style_dark'] = $map['styleDark'];
            }
          }
          // Handle center in various formats:
          // - Object: map.center = {lat: ..., lng: ...}
          // - Array: map.center = [lng, lat] (GeoJSON/MapLibre convention)
          if (!empty($map['center']) && is_array($map['center'])) {
            if (isset($map['center']['lat'], $map['center']['lng'])) {
              $settings['center_lat'] = $map['center']['lat'];
              $settings['center_lng'] = $map['center']['lng'];
            }
            elseif (isset($map['center'][0], $map['center'][1])) {
              $settings['center_lat'] = $map['center'][1];
              $settings['center_lng'] = $map['center'][0];
            }
          }
          // Handle separate key format: map.centerLat / map.centerLng.
          if (array_key_exists('centerLat', $map) && $map['centerLat'] !== null) {
            $settings['center_lat'] = $map['centerLat'];
          }
          if (array_key_exists('centerLng', $map) && $map['centerLng'] !== null) {
            $settings['center_lng'] = $map['centerLng'];
          }
          // Handle zoom overrides (multiple naming conventions).
          if (!empty($map['zoomInitial'])) {
            $settings['zoom_initial'] = $map['zoomInitial'];
          }
          elseif (!empty($map['zoomLevel'])) {
            $settings['zoom_initial'] = $map['zoomLevel'];
          }
        }

        // Sync geocoding settings from jurisdiction config.
        // features.geocoding.country/region override global geocoding settings.
        if (!empty($settings['features']['geocoding'])) {
          $geo = $settings['features']['geocoding'];
          if (!empty($geo['country'])) {
            $settings['geocoding_country'] = $geo['country'];
          }
          if (!empty($geo['region'])) {
            $settings['geocoding_region'] = $geo['region'];
          }
        }
      }
    }

    // Enforce feature flags based on installed modules.
    // If a module is not installed, force the feature to FALSE regardless
    // of what field_nuxt_config says. This prevents the frontend from
    // exposing routes for features whose backend modules are absent.
    $module_feature_map = [
      'markaspot_ai' => 'aiAnalysis',
      'markaspot_stats' => 'statistics',
      'markaspot_dashboard' => 'dashboard',
      'markaspot_vision' => 'photoReporting',
      'markaspot_privacy' => 'privacyBlur',
      'markaspot_feedback' => 'feedback',
      'markaspot_passwordless' => 'passwordless',
      'markaspot_emergency' => 'emergency',
      'markaspot_contact' => 'contactForm',
    ];
    if (!isset($settings['features'])) {
      $settings['features'] = [];
    }
    $module_handler = $this->moduleHandler();
    foreach ($module_feature_map as $module => $feature) {
      if (!$module_handler->moduleExists($module)) {
        $settings['features'][$feature] = FALSE;
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

      // Add operator data for legal pages (Impressum, Privacy).
      // TMG §5 requires this data to be publicly accessible.
      $operator = [];
      if ($group->hasField('field_platform_name') && !$group->get('field_platform_name')->isEmpty()) {
        $operator['name'] = $group->get('field_platform_name')->value;
      }
      if ($group->hasField('field_jurisdiction_e_mail') && !$group->get('field_jurisdiction_e_mail')->isEmpty()) {
        $operator['email'] = $group->get('field_jurisdiction_e_mail')->value;
      }
      if ($group->hasField('field_jurisdiction_address') && !$group->get('field_jurisdiction_address')->isEmpty()) {
        $address = $group->get('field_jurisdiction_address')->first();
        if ($address) {
          $operator['address'] = [
            'organization' => $address->organization ?? '',
            'address_line1' => $address->address_line1 ?? '',
            'locality' => $address->locality ?? '',
            'postal_code' => $address->postal_code ?? '',
            'country_code' => $address->country_code ?? '',
          ];
        }
      }
      if ($group->hasField('field_legal_notice') && !$group->get('field_legal_notice')->isEmpty()) {
        $operator['legalNotice'] = $group->get('field_legal_notice')->value;
      }
      if ($group->hasField('field_privacy_policy') && !$group->get('field_privacy_policy')->isEmpty()) {
        $operator['privacyPolicy'] = $group->get('field_privacy_policy')->value;
      }
      if (!empty($operator)) {
        $settings['operator'] = $operator;
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
        elseif (isset($boundary_data['type']) && in_array($boundary_data['type'], ['Polygon', 'MultiPolygon'], TRUE)) {
          // Wrap raw geometry in FeatureCollection for frontend compatibility.
          $settings['boundary'] = [
            'type' => 'FeatureCollection',
            'features' => [[
              'type' => 'Feature',
              'properties' => [],
              'geometry' => $boundary_data,
            ]],
          ];
        }
      }
    }

    // Load jurisdiction-filtered categories and statuses.
    // $taxonomyJurisdictionId (resolved above) points to the root jurisdiction
    // so child jurisdictions inherit the parent's service catalog.
    // The original $group is passed to loadServices() so child jurisdictions
    // can further filter categories via field_service_categories.
    $settings['services'] = $this->loadServices($taxonomyJurisdictionId, $group);
    $settings['statuses'] = $this->loadStatuses($taxonomyJurisdictionId);

    // Invalidate when taxonomy terms change (category or status edits).
    $cache_metadata->addCacheTags([
      'taxonomy_term_list:service_category',
      'taxonomy_term_list:service_status',
    ]);

    // Allow other modules to alter the settings before response.
    $this->moduleHandler()->alter('markaspot_nuxt_settings', $settings, $group);

    // Return the configuration as a cacheable JSON response.
    $response = new CacheableJsonResponse($settings);
    $response->addCacheableDependency($cache_metadata);
    return $response;
  }

  /**
   * Loads service categories, optionally filtered by jurisdiction.
   *
   * Categories are loaded from the root jurisdiction's taxonomy terms.
   * When a child jurisdiction group is provided and has explicit category
   * restrictions (field_service_categories), the result is filtered to
   * only include allowed categories plus their parent terms for hierarchy.
   *
   * @param int|null $jurisdictionId
   *   The root jurisdiction ID for taxonomy term filtering, or NULL for all.
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The original group entity (may be a child jurisdiction). Used to apply
   *   category allow-list filtering via getAllowedCategoryIds().
   */
  private function loadServices(?int $jurisdictionId, ?GroupInterface $group = NULL): array {
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();
    $properties = ['vid' => 'service_category', 'status' => 1];
    if ($jurisdictionId && FieldStorageConfig::loadByName('taxonomy_term', 'field_jurisdiction')) {
      $properties['field_jurisdiction'] = $jurisdictionId;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties($properties);

    // Apply child jurisdiction category allow-list if configured.
    // Root jurisdictions and children without restrictions return NULL (show all).
    if ($group) {
      $allowedIds = $this->hierarchyResolver->getAllowedCategoryIds((int) $group->id());
      if ($allowedIds !== null) {
        $allowedSet = array_flip($allowedIds);
        // Collect parent TIDs of allowed terms so hierarchy stays intact.
        $allowedParentTids = [];
        foreach ($terms as $tid => $term) {
          if (isset($allowedSet[$tid])) {
            $pid = (int) $term->get('parent')->target_id;
            if ($pid > 0) {
              $allowedParentTids[$pid] = $pid;
            }
          }
        }
        // Keep terms that are either directly allowed or serve as parents.
        $terms = array_filter($terms, function ($term) use ($allowedSet, $allowedParentTids) {
          $tid = (int) $term->id();
          return isset($allowedSet[$tid]) || isset($allowedParentTids[$tid]);
        });
      }
    }

    // Collect parent TIDs from already-loaded entities (no extra queries).
    $parent_tids = [];
    foreach ($terms as $term) {
      $pid = (int) $term->get('parent')->target_id;
      if ($pid > 0) {
        $parent_tids[$pid] = $pid;
      }
    }
    // Batch-load parent terms in a single query.
    $parent_terms = !empty($parent_tids)
      ? $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($parent_tids)
      : [];

    $services = [];
    foreach ($terms as $term) {
      // Use translated term if available.
      if ($term->hasTranslation($langcode)) {
        $term = $term->getTranslation($langcode);
      }
      $service = [
        'service_code' => $term->get('field_service_code')->value,
        'service_name' => $term->getName(),
        'category_hex' => $term->get('field_category_hex')->color ?? NULL,
        'category_icon' => $term->get('field_category_icon')->value ?? NULL,
        'tid' => (int) $term->id(),
        'weight' => (int) $term->getWeight(),
      ];
      // Include parent for hierarchy (from pre-loaded batch).
      $pid = (int) $term->get('parent')->target_id;
      if ($pid > 0 && isset($parent_terms[$pid])) {
        $parent = $parent_terms[$pid];
        if ($parent->hasTranslation($langcode)) {
          $parent = $parent->getTranslation($langcode);
        }
        $service['parent_tid'] = (int) $parent->id();
        $service['parent_name'] = $parent->getName();
      }
      $services[] = $service;
    }

    // Sort by weight.
    usort($services, fn($a, $b) => $a['weight'] <=> $b['weight']);

    return $services;
  }

  /**
   * Loads service statuses with their Open311 mapping.
   */
  private function loadStatuses(?int $jurisdictionId = NULL): array {
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();
    $properties = ['vid' => 'service_status', 'status' => 1];
    if ($jurisdictionId && FieldStorageConfig::loadByName('taxonomy_term', 'field_jurisdiction')) {
      $properties['field_jurisdiction'] = $jurisdictionId;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties($properties);

    $statuses = [];
    foreach ($terms as $term) {
      if ($term->hasTranslation($langcode)) {
        $term = $term->getTranslation($langcode);
      }
      $statuses[] = [
        'name' => $term->getName(),
        'tid' => (int) $term->id(),
        'status_hex' => $term->get('field_status_hex')->color ?? NULL,
        'status_icon' => $term->get('field_status_icon')->value ?? NULL,
        'open311_mapping' => $term->hasField('field_open311_mapping') ? $term->get('field_open311_mapping')->value : NULL,
        'weight' => (int) $term->getWeight(),
      ];
    }

    usort($statuses, fn($a, $b) => $a['weight'] <=> $b['weight']);

    return $statuses;
  }

  /**
   * Returns form display settings, including field settings and media reference info.
   *
   * Cached with appropriate cache tags - automatically invalidates when:
   * - Entity form display configuration changes
   * - Field configurations are updated
   * - Field storage configurations are updated.
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
    $cache_metadata->addCacheTags(['group_list']);
    $cache_metadata->addCacheTags(['config:markaspot_open311.settings']);
    // Set max-age for HTTP caching (1 hour).
    $cache_metadata->setCacheMaxAge(3600);

    foreach ($groups as $group) {
      $slug = NULL;
      if ($group->hasField('field_slug') && !$group->get('field_slug')->isEmpty()) {
        $slug = $group->get('field_slug')->value;
      }

      $parentId = NULL;
      if ($group->hasField('field_parent_jurisdiction')
          && !$group->get('field_parent_jurisdiction')->isEmpty()) {
        $parentId = (int) $group->get('field_parent_jurisdiction')->target_id;
      }

      $jurisdictions[] = [
        'id' => (int) $group->id(),
        'uuid' => $group->uuid(),
        'name' => $group->label(),
        'slug' => $slug,
        'isDefault' => $first,
        'parentId' => $parentId,
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
   * Returns host-to-mode mappings for all jurisdictions.
   *
   * Lightweight endpoint used by the Nuxt server middleware to determine
   * whether a given hostname should run in 'citizen' or 'full' app mode.
   * Only returns the auth.allowedHosts and auth.publicHosts arrays from
   * each jurisdiction's field_nuxt_config.
   */
  public function getJurisdictionHosts() {
    $open311_config = $this->configFactory->get('markaspot_open311.settings');
    $jur_type = $open311_config->get('jurisdiction_group_type') ?? 'jur';

    $group_ids = $this->entityTypeManager->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $jur_type)
      ->condition('status', 1)
      ->sort('id', 'ASC')
      ->execute();

    $groups = $this->entityTypeManager->getStorage('group')->loadMultiple($group_ids);

    $host_mappings = [];

    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheTags(['group_list']);
    $cache_metadata->setCacheMaxAge(300);

    foreach ($groups as $group) {
      $cache_metadata->addCacheableDependency($group);

      if (!$group->hasField('field_nuxt_config') || $group->get('field_nuxt_config')->isEmpty()) {
        continue;
      }

      $config = json_decode($group->get('field_nuxt_config')->value, TRUE);
      if (empty($config['auth'])) {
        continue;
      }

      $host_mappings[] = [
        'jurisdiction_id' => (int) $group->id(),
        'name' => $group->label(),
        'allowedHosts' => $config['auth']['allowedHosts'] ?? [],
        'publicHosts' => $config['auth']['publicHosts'] ?? [],
      ];
    }

    $response = new CacheableJsonResponse($host_mappings);
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
   * When a 'jurisdiction' query parameter is provided (numeric group ID),
   * only organisations belonging to that jurisdiction are returned.
   * This filters via field_jurisdiction on the org group entity.
   *
   * Cached with group entity cache tags - automatically invalidates when any
   * organisation group is created, updated, or deleted.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request, may contain 'jurisdiction' query parameter.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   List of organisations with id (uuid), numericId, and label.
   */
  public function getOrganisations(Request $request): CacheableJsonResponse {
    // Load group type settings from config (supports legacy naming).
    $open311_config = $this->configFactory->get('markaspot_open311.settings');
    $org_type = $open311_config->get('organisation_group_type') ?? 'org';
    $jur_type = $open311_config->get('jurisdiction_group_type') ?? 'jur';

    // Build cache metadata early so it can be attached even on error responses.
    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheTags(['group_list']);
    $cache_metadata->addCacheTags(['config:markaspot_open311.settings']);
    $cache_metadata->addCacheContexts(['url.query_args:jurisdiction', 'user']);
    $cache_metadata->setCacheMaxAge(3600);

    // Optional jurisdiction filter: only return orgs directly assigned to this jur.
    // Strict filtering: no hierarchy traversal, only exact match.
    // Supports both numeric IDs and slugs (e.g. "amsterdam").
    $jurisdiction_param = $request->query->get('jurisdiction');
    $jurisdiction_id = $this->resolveJurisdictionId($jurisdiction_param, $jur_type);

    // Validate jurisdiction: must be a published group of the correct jur type.
    // Prevents cross-tenant enumeration by rejecting unknown or invalid IDs.
    if ($jurisdiction_id !== null) {
      $jur_group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
      if (!$jur_group || !$jur_group->isPublished() || $jur_group->bundle() !== $jur_type) {
        $response = new CacheableJsonResponse([
          'organisations' => [],
          'count' => 0,
        ]);
        $response->addCacheableDependency($cache_metadata);
        return $response;
      }
      // Add cache dependency on the jurisdiction group itself.
      $cache_metadata->addCacheableDependency($jur_group);
    }

    // Role-based filtering: privileged users see all orgs,
    // regular users only see orgs they are a member of.
    $account = $this->currentUser();
    $privileged_roles = ['moderator', 'administrator', 'editorial_board'];
    $is_privileged = !empty(array_intersect($privileged_roles, $account->getRoles()));

    // Non-privileged users: restrict to their own org memberships.
    if (!$is_privileged) {
      $membership_storage = $this->entityTypeManager->getStorage('group_relationship');
      $membership_ids = $membership_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('entity_id', $account->id())
        ->condition('type', $org_type . '-group_membership')
        ->execute();

      if (empty($membership_ids)) {
        $response = new CacheableJsonResponse([
          'organisations' => [],
          'count' => 0,
        ]);
        $cache_metadata->addCacheContexts(['user']);
        $response->addCacheableDependency($cache_metadata);
        return $response;
      }

      $memberships = $membership_storage->loadMultiple($membership_ids);
      $user_org_ids = [];
      foreach ($memberships as $membership) {
        $user_org_ids[] = $membership->getGroupId();
      }
      $user_org_ids = array_unique($user_org_ids);
    }

    // EntityQuery with accessCheck(FALSE) is intentional here:
    // Route-level '_user_is_logged_in' gates entry, role-based filtering
    // is applied above, and the query is scoped by type, status, and
    // validated jurisdiction ID.
    $query = $this->entityTypeManager->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $org_type)
      ->condition('status', 1)
      ->sort('label', 'ASC');

    if ($jurisdiction_id !== null) {
      $query->condition('field_jurisdiction', $jurisdiction_id);
    }

    // Apply user's org membership filter for non-privileged users.
    if (!$is_privileged && !empty($user_org_ids)) {
      $query->condition('id', $user_org_ids, 'IN');
    }

    $group_ids = $query->execute();

    // Fallback to 'organisation' type if no groups found with configured type.
    if (empty($group_ids) && $org_type === 'org') {
      $org_type = 'organisation';
      $query = $this->entityTypeManager->getStorage('group')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $org_type)
        ->condition('status', 1)
        ->sort('label', 'ASC');

      // Only filter by jurisdiction if the legacy bundle has the field.
      if ($jurisdiction_id !== NULL && FieldStorageConfig::loadByName('group', 'field_jurisdiction')) {
        $query->condition('field_jurisdiction', $jurisdiction_id);
      }

      $group_ids = $query->execute();
    }

    $groups = $this->entityTypeManager->getStorage('group')->loadMultiple($group_ids);

    $organisations = [];

    foreach ($groups as $group) {
      $organisations[] = [
        'id' => $group->uuid(),
        'numericId' => (int) $group->id(),
        'label' => $group->label(),
      ];

      $cache_metadata->addCacheableDependency($group);
    }

    $response = new CacheableJsonResponse([
      'organisations' => $organisations,
      'count' => count($organisations),
    ]);

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

    $resolved_id = $this->resolveJurisdictionId($jurisdiction_param, $jur_type);
    if ($resolved_id !== null) {
      $loaded_group = $this->entityTypeManager->getStorage('group')->load($resolved_id);
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
      //    Primary: theme.customCss inside field_nuxt_config.
      //    Secondary: field_custom_css standalone field.
      $custom_css = trim($nuxt_config['theme']['customCss'] ?? '');
      if (!$custom_css && $group->hasField('field_custom_css') && !$group->get('field_custom_css')->isEmpty()) {
        $custom_css = trim($group->get('field_custom_css')->value ?? '');
      }
      if ($custom_css) {
        $css_parts[] = $custom_css;
      }

      // 2. CSS custom properties from theme.fonts.
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
