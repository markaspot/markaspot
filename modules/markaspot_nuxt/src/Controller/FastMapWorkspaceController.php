<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Creates FastMap workspaces (jurisdiction groups with categories and statuses).
 */
class FastMapWorkspaceController extends ControllerBase {

  /**
   * Default status terms every workspace gets, with translations.
   */
  private const DEFAULT_STATUSES = [
    [
      'name' => ['en' => 'New', 'de' => 'Neu'],
      'hex' => '#8B0000',
      'icon' => 'i-lucide-hand',
      'mapping' => 'initial',
    ],
    [
      'name' => ['en' => 'Open', 'de' => 'Offen'],
      'hex' => '#FFA500',
      'icon' => 'i-heroicons-cog-6-tooth',
      'mapping' => 'open',
    ],
    [
      'name' => ['en' => 'Closed', 'de' => 'Geschlossen'],
      'hex' => '#008000',
      'icon' => 'i-lucide-check',
      'mapping' => 'closed',
    ],
  ];

  /**
   * Category icon defaults by keyword heuristic.
   */
  private const CATEGORY_ICONS = [
    'flood' => 'i-lucide-droplets',
    'damage' => 'i-lucide-hammer',
    'power' => 'i-lucide-zap-off',
    'road' => 'i-lucide-construction',
    'missing' => 'i-lucide-search',
    'aid' => 'i-lucide-heart-handshake',
    'crossing' => 'i-lucide-traffic-cone',
    'visibility' => 'i-lucide-eye-off',
    'speed' => 'i-lucide-gauge',
    'sidewalk' => 'i-lucide-footprints',
    'bike' => 'i-lucide-bike',
    'ramp' => 'i-lucide-accessibility',
    'elevator' => 'i-lucide-arrow-up-down',
    'curb' => 'i-lucide-minus',
    'obstacle' => 'i-lucide-alert-triangle',
    'tactile' => 'i-lucide-hand',
    'building' => 'i-lucide-building-2',
    'heat' => 'i-lucide-thermometer-sun',
    'shade' => 'i-lucide-tree-pine',
    'fountain' => 'i-lucide-cup-soda',
    'surface' => 'i-lucide-square',
    'roof' => 'i-lucide-home',
    'tree' => 'i-lucide-tree-deciduous',
    'erosion' => 'i-lucide-mountain',
    'sign' => 'i-lucide-signpost',
    'bridge' => 'i-lucide-bridge',
    'litter' => 'i-lucide-trash-2',
    'trail' => 'i-lucide-map-pin',
    'crack' => 'i-lucide-split',
    'moisture' => 'i-lucide-droplet',
    'safety' => 'i-lucide-shield-alert',
    'material' => 'i-lucide-box',
    'plan' => 'i-lucide-file-warning',
    'clean' => 'i-lucide-sparkles',
    'light' => 'i-lucide-lightbulb',
    'vandal' => 'i-lucide-spray-can',
    'green' => 'i-lucide-trees',
    'playground' => 'i-lucide-baby',
    'noise' => 'i-lucide-volume-2',
    'idea' => 'i-lucide-lightbulb',
    'wildlife' => 'i-lucide-rabbit',
    'pollution' => 'i-lucide-cloud-rain',
    'dump' => 'i-lucide-trash',
    'species' => 'i-lucide-leaf',
    'amphibian' => 'i-lucide-bug',
    'roadkill' => 'i-lucide-alert-circle',
  ];

  /**
   * Color palette for auto-assigning category colors.
   */
  private const CATEGORY_COLORS = [
    '#E53E3E', '#DD6B20', '#D69E2E', '#38A169',
    '#319795', '#3182CE', '#5A67D8', '#805AD5',
    '#D53F8C', '#718096', '#2B6CB0', '#C05621',
  ];

  /**
   * Maximum number of categories per workspace.
   */
  private const MAX_CATEGORIES = 30;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * Creates a new FastMap workspace.
   *
   * POST /api/fastmap/create-workspace
   * Body: { api_key, name, slug, categories[], lat, lng, zoom?, template? }
   */
  public function createWorkspace(Request $request): JsonResponse {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (!$data) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    // Validate API key (stored in services_api_key_auth module).
    $expectedKey = $this->config('services_api_key_auth.api_key.nuxt')->get('key');
    $apiKey = $data['api_key'] ?? $request->query->get('api_key');
    if (!$expectedKey || !$apiKey || !hash_equals($expectedKey, (string) $apiKey)) {
      return new JsonResponse(['error' => 'Invalid API key'], 403);
    }

    // Validate required fields.
    $name = mb_substr(trim($data['name'] ?? ''), 0, 255);
    $slug = trim($data['slug'] ?? '');
    $categories = $data['categories'] ?? [];
    $lat = max(-90.0, min(90.0, (float) ($data['lat'] ?? 0)));
    $lng = max(-180.0, min(180.0, (float) ($data['lng'] ?? 0)));
    $zoom = (int) ($data['zoom'] ?? 13);
    $template = $data['template'] ?? 'civic-report';
    $requestedLang = $data['language'] ?? '';
    $boundary = $data['boundary'] ?? NULL;

    if (!$name || !$slug) {
      return new JsonResponse(['error' => 'name and slug are required'], 400);
    }

    if (!preg_match('/^[a-z0-9-]{2,30}$/', $slug)) {
      return new JsonResponse(['error' => 'slug must be 2-30 chars, lowercase alphanumeric and hyphens'], 400);
    }

    if (empty($categories) || !is_array($categories)) {
      return new JsonResponse(['error' => 'categories must be provided'], 400);
    }

    // Categories can be Record<lang, string[]> (multilingual) or string[] (legacy).
    $multilingualCategories = $this->normalizeCategories($categories);
    if (empty($multilingualCategories)) {
      return new JsonResponse(['error' => 'categories must contain at least one non-empty string'], 400);
    }

    // Determine default language: prefer explicitly requested language,
    // fall back to first key in the categories map.
    $allowedLangs = ['en', 'de', 'nl', 'fr', 'es'];
    $defaultLang = (is_string($requestedLang) && in_array($requestedLang, $allowedLangs, TRUE) && isset($multilingualCategories[$requestedLang]))
      ? $requestedLang
      : array_key_first($multilingualCategories);
    $defaultCategories = $multilingualCategories[$defaultLang];

    if (count($defaultCategories) > self::MAX_CATEGORIES) {
      return new JsonResponse(['error' => 'Maximum ' . self::MAX_CATEGORIES . ' categories allowed'], 400);
    }

    // Check slug uniqueness.
    $groupStorage = $this->entityTypeManager()->getStorage('group');
    $existing = $groupStorage->loadByProperties(['field_slug' => $slug]);
    if (!empty($existing)) {
      return new JsonResponse(['error' => 'Slug already taken'], 409);
    }

    $transaction = $this->database->startTransaction();

    try {
      $termStorage = $this->entityTypeManager()->getStorage('taxonomy_term');

      // 1. Create the Group entity.
      $availableLanguages = array_keys($multilingualCategories);
      $nuxtConfig = $this->buildNuxtConfig($name, $slug, $lat, $lng, $zoom, $template, $availableLanguages, $defaultLang);

      // Wrap boundary geometry in a GeoJSON FeatureCollection for field_boundary.
      $boundaryJson = NULL;
      if (is_array($boundary) && in_array($boundary['type'] ?? '', ['Polygon', 'MultiPolygon'], TRUE)) {
        $feature = [
          'type' => 'FeatureCollection',
          'features' => [
            [
              'type' => 'Feature',
              'properties' => ['name' => $name],
              'geometry' => $boundary,
            ],
          ],
        ];
        $boundaryJson = json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }

      $groupFields = [
        'type' => 'jur',
        'label' => $name,
        'uid' => 1,
        'field_slug' => $slug,
        'field_platform_name' => $name,
        'field_nuxt_config' => json_encode($nuxtConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ];
      if ($boundaryJson) {
        $groupFields['field_boundary'] = $boundaryJson;
      }
      $group = $groupStorage->create($groupFields);
      $group->save();

      $groupId = (int) $group->id();

      // 2. Create status terms (with translations).
      $this->createStatusTerms($termStorage, $groupId, $defaultLang, $availableLanguages);

      // 3. Create category terms (with translations).
      $categoryTermIds = $this->createCategoryTerms($termStorage, $groupId, $multilingualCategories, $defaultLang);

      // 4. Assign categories to the group.
      $group->set('field_service_categories', array_map(fn($tid) => ['target_id' => $tid], $categoryTermIds));
      $group->save();

      return new JsonResponse([
        'id' => $groupId,
        'slug' => $slug,
        'name' => $name,
        'url' => '/' . $slug,
        'categories' => count($categoryTermIds),
      ], 201);

    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->getLogger('fastmap')->error('Workspace creation failed: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Workspace creation failed'], 500);
    }
  }

  /**
   * Build minimal field_nuxt_config for a new workspace.
   */
  private function buildNuxtConfig(string $name, string $slug, float $lat, float $lng, int $zoom, string $template, array $languages = ['en'], string $defaultLang = 'en'): array {
    $themeColors = $this->getThemeForTemplate($template);

    return [
      'client' => [
        'name' => $name,
        'shortName' => $slug,
      ],
      'theme' => $themeColors,
      'features' => [
        'photoReporting' => TRUE,
        'classicReporting' => TRUE,
        'search' => ['enabled' => TRUE, 'mode' => 'fuzzy', 'minLength' => 2],
        'boundaries' => ['enabled' => TRUE],
        'privacyNotice' => ['enabled' => TRUE, 'modal' => TRUE],
        'formFirst' => ['mobileLayout' => 'bottomSheet', 'defaultTab' => 'photo'],
      ],
      'ui' => [
        'headerHeight' => '64px',
        'sidebar' => ['width' => '420px', 'enabled' => TRUE],
        'bottomSheet' => [
          'position' => 'medium',
          'minimumHeight' => 140,
          'showHandle' => TRUE,
          'autoCollapseOnMapTap' => TRUE,
          'autoCollapseOnMapMove' => TRUE,
        ],
      ],
      'media' => ['maxFiles' => 3, 'maxFileSize' => 20],
      'languages' => ['available' => array_values($languages), 'default' => $defaultLang],
      'map' => [
        'center' => [$lng, $lat],
        'zoomInitial' => $zoom,
        'zoomLevel' => $zoom,
        'loadMarkersOnInit' => TRUE,
        'enableBoundsFiltering' => TRUE,
        'markers' => ['iconSize' => 24, 'strokeWidth' => 2, 'radius' => 15],
        'clusters' => ['strokeWidth' => 4],
        'deferredMap' => ['enabled' => TRUE, 'preloadData' => TRUE, 'limit' => 100],
        'controls' => [
          'zoom' => ['enabled' => TRUE, 'position' => 'centerRight'],
          'geolocation' => ['position' => 'centerRight'],
          'theme' => ['enabled' => TRUE, 'position' => 'centerRight'],
          'attribution' => ['enabled' => TRUE, 'position' => 'bottomRight'],
        ],
      ],
      'navigation' => [
        'mode' => 'simple',
      ],
      'filters' => [
        'status' => ['enabled' => TRUE],
        'category' => ['enabled' => TRUE],
      ],
    ];
  }

  /**
   * Get theme colors based on template.
   */
  private function getThemeForTemplate(string $template): array {
    $themes = [
      'crisis-map' => ['primary' => 'red', 'secondary' => 'orange', 'neutral' => 'slate'],
      'civic-report' => ['primary' => 'blue', 'secondary' => 'cyan', 'neutral' => 'slate'],
      'safe-routes' => ['primary' => 'amber', 'secondary' => 'orange', 'neutral' => 'stone'],
      'access-map' => ['primary' => 'violet', 'secondary' => 'purple', 'neutral' => 'slate'],
      'climate-watch' => ['primary' => 'orange', 'secondary' => 'amber', 'neutral' => 'stone'],
      'trail-watch' => ['primary' => 'green', 'secondary' => 'emerald', 'neutral' => 'stone'],
      'eco-map' => ['primary' => 'emerald', 'secondary' => 'teal', 'neutral' => 'slate'],
      'neighbourhood' => ['primary' => 'cyan', 'secondary' => 'sky', 'neutral' => 'slate'],
      'construction-watch' => ['primary' => 'yellow', 'secondary' => 'amber', 'neutral' => 'stone'],
    ];

    return $themes[$template] ?? $themes['civic-report'];
  }

  /**
   * Create default status terms for a jurisdiction with translations.
   */
  private function createStatusTerms($termStorage, int $groupId, string $defaultLang, array $languages): void {
    $weight = 0;
    foreach (self::DEFAULT_STATUSES as $status) {
      $defaultName = $status['name'][$defaultLang] ?? $status['name']['en'];
      $term = $termStorage->create([
        'vid' => 'service_status',
        'name' => $defaultName,
        'langcode' => $defaultLang,
        'weight' => $weight++,
        'field_status_hex' => ['color' => $status['hex']],
        'field_status_icon' => $status['icon'],
        'field_open311_mapping' => $status['mapping'],
        'field_jurisdiction' => ['target_id' => $groupId],
      ]);
      $term->save();

      // Add translations for other languages.
      foreach ($languages as $lang) {
        if ($lang === $defaultLang) {
          continue;
        }
        $translatedName = $status['name'][$lang] ?? NULL;
        if ($translatedName && $term->isTranslatable()) {
          $translation = $term->addTranslation($lang, ['name' => $translatedName]);
          $translation->save();
        }
      }
    }
  }

  /**
   * Create category terms for a jurisdiction with translations.
   *
   * @param mixed $termStorage
   *   The taxonomy term storage.
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param array $multilingualCategories
   *   Categories keyed by language: ['en' => [...], 'de' => [...]].
   * @param string $defaultLang
   *   The default language code.
   *
   * @return int[]
   *   The created term IDs.
   */
  private function createCategoryTerms($termStorage, int $groupId, array $multilingualCategories, string $defaultLang): array {
    $termIds = [];
    $weight = 0;
    $defaultCategories = $multilingualCategories[$defaultLang];

    foreach ($defaultCategories as $index => $categoryName) {
      // Use English name for icon guessing (more keywords match).
      $enName = $multilingualCategories['en'][$index] ?? $categoryName;
      $icon = $this->guessIcon($enName);
      $color = self::CATEGORY_COLORS[$index % count(self::CATEGORY_COLORS)];
      $code = (string) ($index + 1);

      $term = $termStorage->create([
        'vid' => 'service_category',
        'name' => $categoryName,
        'langcode' => $defaultLang,
        'weight' => $weight++,
        'field_service_code' => $code,
        'field_category_hex' => ['color' => $color],
        'field_category_icon' => $icon,
        'field_jurisdiction' => ['target_id' => $groupId],
      ]);
      $term->save();
      $termIds[] = (int) $term->id();

      // Add translations for other languages.
      foreach ($multilingualCategories as $lang => $langCategories) {
        if ($lang === $defaultLang) {
          continue;
        }
        $translatedName = $langCategories[$index] ?? NULL;
        if ($translatedName && $term->isTranslatable()) {
          $translation = $term->addTranslation($lang, ['name' => $translatedName]);
          $translation->save();
        }
      }
    }

    return $termIds;
  }

  /**
   * Normalize categories input to Record<lang, string[]>.
   *
   * Accepts either:
   * - ['en' => ['Cat A', 'Cat B'], 'de' => ['Kat A', 'Kat B']]
   * - ['Cat A', 'Cat B'] (legacy, treated as 'en')
   *
   * @return array<string, string[]>
   *   Validated multilingual categories.
   */
  private function normalizeCategories(array $categories): array {
    // Check if it's a legacy flat array (string values at top level).
    $firstValue = reset($categories);
    if (is_string($firstValue)) {
      // Legacy format: flat string array -> wrap as 'en'.
      $valid = array_filter($categories, fn($c) => is_string($c) && trim($c) !== '');
      $valid = array_map(fn($c) => mb_substr(trim($c), 0, 255), $valid);
      return $valid ? ['en' => array_values($valid)] : [];
    }

    // Multilingual format: Record<lang, string[]>.
    $result = [];
    foreach ($categories as $lang => $names) {
      if (!is_string($lang) || !is_array($names)) {
        continue;
      }
      // Only allow known language codes.
      if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang)) {
        continue;
      }
      $valid = [];
      foreach ($names as $name) {
        if (is_string($name) && trim($name) !== '') {
          $valid[] = mb_substr(trim($name), 0, 255);
        }
      }
      if (!empty($valid)) {
        $result[$lang] = $valid;
      }
    }

    return $result;
  }

  /**
   * Guess an icon for a category name based on keyword matching.
   */
  private function guessIcon(string $categoryName): string {
    $lower = strtolower($categoryName);
    foreach (self::CATEGORY_ICONS as $keyword => $icon) {
      if (str_contains($lower, $keyword)) {
        return $icon;
      }
    }
    return 'i-lucide-circle-dot';
  }

}
