<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Provisions and tears down FastMap workspaces.
 */
class WorkspaceProvisioningService implements WorkspaceProvisioningServiceInterface {

  /**
   * Default status terms every workspace gets, with translations.
   */
  private const DEFAULT_STATUSES = [
    [
      'name' => [
        'en' => 'Created',
        'de' => 'Erstellt',
        'nl' => 'Aangemaakt',
        'fr' => 'Créé',
        'es' => 'Creado',
        'pl' => 'Utworzony',
        'it' => 'Creato',
        'pt' => 'Criado',
        'da' => 'Oprettet',
        'tr' => 'Oluşturuldu',
        'uk' => 'Створено',
        'ar' => 'تم الإنشاء',
      ],
      'hex' => '#D97706',
      'icon' => 'i-lucide-plus-circle',
      'mapping' => 'initial',
    ],
    [
      'name' => [
        'en' => 'Done',
        'de' => 'Erledigt',
        'nl' => 'Afgerond',
        'fr' => 'Terminé',
        'es' => 'Hecho',
        'pl' => 'Zakończony',
        'it' => 'Completato',
        'pt' => 'Concluído',
        'da' => 'Afsluttet',
        'tr' => 'Tamamlandı',
        'uk' => 'Завершено',
        'ar' => 'منتهي',
      ],
      'hex' => '#059669',
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

  private const MAX_CATEGORIES = 30;

  private const DEMO_REQUEST_COUNT = 5;

  /**
   * Demo request templates per language.
   *
   * Each entry provides a title and description. The templates are cycled
   * through and paired with workspace categories. Languages without explicit
   * templates fall back to English.
   */
  private const DEMO_TEMPLATES = [
    'en' => [
      ['title' => 'Broken street light', 'description' => 'The street light at this location has been out for several days.'],
      ['title' => 'Pothole on main road', 'description' => 'A large pothole has formed on the road surface, causing problems for traffic.'],
      ['title' => 'Damaged sidewalk', 'description' => 'The sidewalk tiles are cracked and uneven, creating a tripping hazard.'],
      ['title' => 'Overflowing waste bin', 'description' => 'The public waste bin at this location is overflowing and needs to be emptied.'],
      ['title' => 'Graffiti on building', 'description' => 'There is graffiti on the facade of the building at this location.'],
    ],
    'de' => [
      ['title' => 'Defekte Straßenlaterne', 'description' => 'Die Straßenlaterne an diesem Standort ist seit mehreren Tagen ausgefallen.'],
      ['title' => 'Schlagloch auf der Hauptstraße', 'description' => 'Auf der Fahrbahn hat sich ein großes Schlagloch gebildet.'],
      ['title' => 'Beschädigter Gehweg', 'description' => 'Die Gehwegplatten sind gerissen und uneben, es besteht Stolpergefahr.'],
      ['title' => 'Überfüllter Mülleimer', 'description' => 'Der öffentliche Mülleimer an diesem Standort ist überfüllt und muss geleert werden.'],
      ['title' => 'Graffiti an Gebäude', 'description' => 'An der Fassade des Gebäudes befindet sich Graffiti.'],
    ],
    'nl' => [
      ['title' => 'Kapotte straatlantaarn', 'description' => 'De straatlantaarn op deze locatie is al meerdere dagen kapot.'],
      ['title' => 'Gat in de weg', 'description' => 'Er is een groot gat in het wegdek ontstaan.'],
      ['title' => 'Beschadigd trottoir', 'description' => 'De stoeptegels zijn gebarsten en ongelijk, er is struikelgevaar.'],
      ['title' => 'Overvolle prullenbak', 'description' => 'De openbare prullenbak op deze locatie is overvol.'],
      ['title' => 'Graffiti op gebouw', 'description' => 'Er is graffiti aangebracht op de gevel van het gebouw.'],
    ],
    'fr' => [
      ['title' => 'Lampadaire en panne', 'description' => "Le lampadaire a cet endroit est en panne depuis plusieurs jours."],
      ['title' => 'Nid-de-poule sur la route', 'description' => 'Un grand nid-de-poule est apparu sur la chaussee.'],
      ['title' => 'Trottoir endommage', 'description' => 'Les dalles du trottoir sont fissurees et inegales.'],
      ['title' => 'Poubelle debordante', 'description' => 'La poubelle publique a cet endroit deborde.'],
      ['title' => 'Graffiti sur batiment', 'description' => 'Il y a des graffitis sur la facade du batiment.'],
    ],
    'es' => [
      ['title' => 'Farola rota', 'description' => 'La farola en esta ubicacion lleva varios dias sin funcionar.'],
      ['title' => 'Bache en la calle', 'description' => 'Se ha formado un gran bache en la calzada.'],
      ['title' => 'Acera danada', 'description' => 'Las baldosas de la acera estan agrietadas y desniveladas.'],
      ['title' => 'Papelera desbordada', 'description' => 'La papelera publica en esta ubicacion esta desbordada.'],
      ['title' => 'Grafiti en edificio', 'description' => 'Hay grafitis en la fachada del edificio.'],
    ],
  ];

  /**
   * Status mapping distribution for demo requests.
   *
   * Defines the Open311 status mapping for each of the 5 demo requests.
   * If a mapping is not available (e.g. no "open" status term), falls back
   * to "initial".
   */
  private const DEMO_STATUS_MAPPINGS = ['initial', 'initial', 'initial', 'open', 'closed'];

  private const ALLOWED_LANGS = ['en', 'de', 'nl', 'fr', 'es', 'ar', 'da', 'it', 'pl', 'pt', 'tr', 'uk'];

  /**
   * Theme presets by template name.
   */
  private const THEME_TEMPLATES = [
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

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
    protected readonly LoggerInterface $logger,
    protected readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function provisionWorkspace(array $data): array {
    $name = mb_substr(trim($data['name'] ?? ''), 0, 255);
    $slug = trim($data['slug'] ?? '');
    $email = trim($data['email'] ?? '');
    $categories = $data['categories'] ?? [];
    $lat = max(-90.0, min(90.0, (float) ($data['lat'] ?? 0)));
    $lng = max(-180.0, min(180.0, (float) ($data['lng'] ?? 0)));
    $zoom = (int) ($data['zoom'] ?? 13);
    $template = $data['template'] ?? 'civic-report';
    $requestedLang = $data['language'] ?? '';
    $boundary = $data['boundary'] ?? NULL;
    $customStatuses = $data['statuses'] ?? NULL;

    // Validate.
    if (!$name || !$slug || !$email) {
      throw new \RuntimeException('name, slug and email are required');
    }

    if (!preg_match('/^[a-z0-9-]{2,30}$/', $slug)) {
      throw new \RuntimeException('slug must be 2-30 chars, lowercase alphanumeric and hyphens');
    }

    if (empty($categories) || !is_array($categories)) {
      throw new \RuntimeException('categories must be provided');
    }

    $multilingualCategories = $this->normalizeCategories($categories);
    if (empty($multilingualCategories)) {
      throw new \RuntimeException('categories must contain at least one non-empty string');
    }

    $defaultLang = (is_string($requestedLang) && in_array($requestedLang, self::ALLOWED_LANGS, TRUE) && isset($multilingualCategories[$requestedLang]))
      ? $requestedLang
      : array_key_first($multilingualCategories);
    $defaultCategories = $multilingualCategories[$defaultLang];

    if (count($defaultCategories) > self::MAX_CATEGORIES) {
      throw new \RuntimeException('Maximum ' . self::MAX_CATEGORIES . ' categories allowed');
    }

    // Slug uniqueness check.
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $existing = $groupStorage->loadByProperties(['field_slug' => $slug]);
    if (!empty($existing)) {
      throw new \RuntimeException('Slug already taken');
    }

    $transaction = $this->database->startTransaction();

    try {
      $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
      $availableLanguages = array_keys($multilingualCategories);

      // 0. Ensure all requested languages are installed in Drupal.
      $this->ensureLanguagesExist($availableLanguages);

      // 1. Create Group entity.
      $nuxtConfig = $this->buildNuxtConfig($name, $slug, $lat, $lng, $zoom, $template, $availableLanguages, $defaultLang);
      $boundaryJson = $this->buildBoundaryJson($boundary, $name);

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

      // 2. Create status terms (custom or default).
      if (is_array($customStatuses) && !empty($customStatuses)) {
        $this->createCustomStatusTerms($termStorage, $groupId, $defaultLang, $customStatuses);
      }
      else {
        $this->createStatusTerms($termStorage, $groupId, $defaultLang, $availableLanguages);
      }

      // 3. Create category terms.
      $categoryTermIds = $this->createCategoryTerms($termStorage, $groupId, $multilingualCategories, $defaultLang);

      // 4. Assign categories to group.
      $group->set('field_service_categories', array_map(fn($tid) => ['target_id' => $tid], $categoryTermIds));
      $group->save();

      // 5. Create tenant admin user.
      $user = $this->createTenantAdmin($email, $name);

      // 6. Add user as group member with admin role.
      $this->addGroupMembership($group, $user);

      // 7. Create demo service requests.
      $this->createDemoRequests($data, $group, $categoryTermIds, $groupId, $defaultLang);

      return [
        'group_id' => $groupId,
        'slug' => $slug,
        'name' => $name,
        'url' => '/' . $slug,
        'categories' => count($categoryTermIds),
        'user_id' => (int) $user->id(),
      ];
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Workspace provisioning failed: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Workspace provisioning failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function teardownWorkspace(int $groupId): void {
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $group = $groupStorage->load($groupId);

    if (!$group) {
      throw new \RuntimeException('Group not found: ' . $groupId);
    }

    $transaction = $this->database->startTransaction();

    try {
      $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
      $userStorage = $this->entityTypeManager->getStorage('user');

      // 1. Collect members before deleting relationships.
      $memberUserIds = [];
      $relationshipStorage = $this->entityTypeManager->getStorage('group_relationship');
      $memberships = $relationshipStorage->loadByProperties([
        'gid' => $groupId,
        'plugin_id' => 'group_membership',
      ]);
      foreach ($memberships as $membership) {
        $memberUserIds[] = (int) $membership->getEntity()->id();
        $membership->delete();
      }

      // 2. Delete taxonomy terms belonging to this group.
      foreach (['service_category', 'service_status'] as $vid) {
        $termIds = $termStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', $vid)
          ->condition('field_jurisdiction', $groupId)
          ->execute();
        if (!empty($termIds)) {
          $terms = $termStorage->loadMultiple($termIds);
          $termStorage->delete($terms);
        }
      }

      // 3. Delete users that have no other group memberships.
      foreach ($memberUserIds as $uid) {
        if ($uid <= 1) {
          continue;
        }
        $user = $userStorage->load($uid);
        if (!$user) {
          continue;
        }

        $otherMemberships = $this->entityTypeManager
          ->getStorage('group_relationship')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('entity_id', $uid)
          ->condition('plugin_id', 'group_membership')
          ->count()
          ->execute();

        if ((int) $otherMemberships === 0) {
          $user->delete();
        }
      }

      // 4. Delete group entity.
      $group->delete();

      $this->logger->info('Workspace torn down: group @id', ['@id' => $groupId]);
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Workspace teardown failed for group @id: @msg', [
        '@id' => $groupId,
        '@msg' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Workspace teardown failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Builds the Nuxt configuration JSON for a workspace.
   */
  private function buildNuxtConfig(string $name, string $slug, float $lat, float $lng, int $zoom, string $template, array $languages, string $defaultLang): array {
    $themeColors = self::THEME_TEMPLATES[$template] ?? self::THEME_TEMPLATES['civic-report'];

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
   * Builds a GeoJSON FeatureCollection from a boundary geometry.
   */
  private function buildBoundaryJson(mixed $boundary, string $name): ?string {
    if (!is_array($boundary) || !in_array($boundary['type'] ?? '', ['Polygon', 'MultiPolygon'], TRUE)) {
      return NULL;
    }

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

    return json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Creates default status terms for a workspace.
   */
  private function createStatusTerms(EntityStorageInterface $termStorage, int $groupId, string $defaultLang, array $languages): void {
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
   * Creates custom status terms from user-provided definitions.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $termStorage
   *   The taxonomy term storage.
   * @param int $groupId
   *   The group ID.
   * @param string $defaultLang
   *   The default language code.
   * @param array $statuses
   *   Array of status definitions with name, hex, icon, mapping.
   */
  private function createCustomStatusTerms(EntityStorageInterface $termStorage, int $groupId, string $defaultLang, array $statuses): void {
    $weight = 0;
    foreach ($statuses as $status) {
      $name = mb_substr(trim($status['name'] ?? ''), 0, 255);
      $hex = $status['hex'] ?? '#808080';
      $icon = $status['icon'] ?? 'i-lucide-circle';
      $mapping = $status['mapping'] ?? 'open';

      if (!$name) {
        continue;
      }

      $term = $termStorage->create([
        'vid' => 'service_status',
        'name' => $name,
        'langcode' => $defaultLang,
        'weight' => $weight++,
        'field_status_hex' => ['color' => $hex],
        'field_status_icon' => $icon,
        'field_open311_mapping' => $mapping,
        'field_jurisdiction' => ['target_id' => $groupId],
      ]);
      $term->save();
    }
  }

  /**
   * Creates category terms for a workspace.
   *
   * @return int[]
   *   Created term IDs.
   */
  private function createCategoryTerms(EntityStorageInterface $termStorage, int $groupId, array $multilingualCategories, string $defaultLang): array {
    $termIds = [];
    $weight = 0;
    $defaultCategories = $multilingualCategories[$defaultLang];

    foreach ($defaultCategories as $index => $categoryName) {
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
   *   Normalized multilingual categories keyed by language code.
   */
  private function normalizeCategories(array $categories): array {
    $firstValue = reset($categories);
    if (is_string($firstValue)) {
      $valid = array_filter($categories, fn($c) => is_string($c) && trim($c) !== '');
      $valid = array_map(fn($c) => mb_substr(trim($c), 0, 255), $valid);
      return $valid ? ['en' => array_values($valid)] : [];
    }

    $result = [];
    foreach ($categories as $lang => $names) {
      if (!is_string($lang) || !is_array($names)) {
        continue;
      }
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
   * Guesses an icon for a category based on keyword matching.
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

  /**
   * Creates or retrieves the tenant admin user.
   */
  private function createTenantAdmin(string $email, string $workspaceName): UserInterface {
    $userStorage = $this->entityTypeManager->getStorage('user');

    // Check if user already exists.
    $existing = $userStorage->loadByProperties(['mail' => $email]);
    if (!empty($existing)) {
      // Reuse existing account. Group-scoped permissions come from the
      // jur-tenant_admin group role on the membership, so no global Drupal
      // role is needed. This avoids privilege escalation across workspaces.
      return reset($existing);
    }

    $user = $userStorage->create([
      'name' => $email,
      'mail' => $email,
      'status' => 1,
    ]);
    $user->save();

    $this->logger->info('Created tenant admin @email for workspace @name', [
      '@email' => $email,
      '@name' => $workspaceName,
    ]);

    return $user;
  }

  /**
   * Adds a user as a group member with tenant admin role.
   */
  private function addGroupMembership(GroupInterface $group, UserInterface $user): void {
    $relationshipStorage = $this->entityTypeManager->getStorage('group_relationship');

    // Check for existing membership.
    $existing = $relationshipStorage->loadByProperties([
      'gid' => $group->id(),
      'entity_id' => $user->id(),
      'plugin_id' => 'group_membership',
    ]);

    if (!empty($existing)) {
      return;
    }

    $membership = $group->addRelationship($user, 'group_membership');
    $membership->set('group_roles', ['jur-tenant_admin']);
    $membership->save();
  }

  /**
   * Creates demo service request nodes for a newly provisioned workspace.
   *
   * Generates DEMO_REQUEST_COUNT nodes distributed across the workspace's
   * categories, with coordinates randomly placed within the boundary bounding
   * box (or a 2km radius of center if no boundary is available).
   *
   * @param array $data
   *   The original workspace provisioning data.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The provisioned group entity.
   * @param int[] $categoryTermIds
   *   Term IDs of the created service categories.
   * @param int $groupId
   *   The group entity ID.
   * @param string $lang
   *   The workspace default language code.
   */
  private function createDemoRequests(array $data, GroupInterface $group, array $categoryTermIds, int $groupId, string $lang): void {
    if (empty($categoryTermIds)) {
      return;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Resolve status term IDs by Open311 mapping for this jurisdiction.
    $statusMap = $this->resolveStatusTermIds($termStorage, $groupId);

    // Determine coordinate generation strategy.
    $boundary = $data['boundary'] ?? NULL;
    $centerLat = (float) ($data['lat'] ?? 0);
    $centerLng = (float) ($data['lng'] ?? 0);
    $bbox = $this->extractBbox($boundary, $centerLat, $centerLng);

    // Get localized templates (fall back to English).
    $templates = self::DEMO_TEMPLATES[$lang] ?? self::DEMO_TEMPLATES['en'];

    for ($i = 0; $i < self::DEMO_REQUEST_COUNT; $i++) {
      $categoryTid = $categoryTermIds[$i % count($categoryTermIds)];
      $template = $templates[$i % count($templates)];

      // Determine status for this demo request.
      $mapping = self::DEMO_STATUS_MAPPINGS[$i] ?? 'initial';
      $statusTid = $statusMap[$mapping] ?? $statusMap['initial'] ?? NULL;

      // Generate a random coordinate within the bounding box.
      [$lat, $lng] = $this->randomCoordinateInBbox($bbox);

      $values = [
        'type' => 'service_request',
        'langcode' => $lang,
        'title' => $template['title'],
        'body' => [
          'value' => $template['description'] . "\n\n[demo-content]",
          'format' => 'plain_text',
        ],
        'field_category' => ['target_id' => $categoryTid],
        'field_geolocation' => [
          'lat' => $lat,
          'lng' => $lng,
        ],
        'field_address' => $data['name'] ?? 'Demo Location',
        'uid' => 1,
        'field_jurisdiction' => ['target_id' => $groupId],
      ];

      if ($statusTid) {
        $values['field_status'] = ['target_id' => $statusTid];
      }

      $node = $nodeStorage->create($values);
      $node->save();
    }

    $this->logger->info('Created @count demo requests for workspace @name (group @id).', [
      '@count' => self::DEMO_REQUEST_COUNT,
      '@name' => $data['name'] ?? '',
      '@id' => $groupId,
    ]);
  }

  /**
   * Resolves status term IDs by Open311 mapping for a jurisdiction.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $termStorage
   *   The taxonomy term storage.
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return array<string, int>
   *   Map of Open311 mapping string to term ID.
   */
  private function resolveStatusTermIds(EntityStorageInterface $termStorage, int $groupId): array {
    $termIds = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_status')
      ->condition('field_jurisdiction', $groupId)
      ->execute();

    if (empty($termIds)) {
      return [];
    }

    $statusMap = [];
    $terms = $termStorage->loadMultiple($termIds);
    foreach ($terms as $term) {
      if ($term->hasField('field_open311_mapping') && !$term->get('field_open311_mapping')->isEmpty()) {
        $mapping = $term->get('field_open311_mapping')->value;
        // Only store the first term for each mapping (lowest weight wins).
        if (!isset($statusMap[$mapping])) {
          $statusMap[$mapping] = (int) $term->id();
        }
      }
    }

    return $statusMap;
  }

  /**
   * Extracts a bounding box from boundary geometry or center point.
   *
   * @param mixed $boundary
   *   The boundary geometry (Polygon/MultiPolygon) or NULL.
   * @param float $centerLat
   *   Center latitude (fallback).
   * @param float $centerLng
   *   Center longitude (fallback).
   *
   * @return array{float, float, float, float}
   *   Bounding box as [minLat, minLng, maxLat, maxLng].
   */
  private function extractBbox(mixed $boundary, float $centerLat, float $centerLng): array {
    if (is_array($boundary) && isset($boundary['coordinates'])) {
      $coords = $this->flattenCoordinates($boundary['coordinates']);
      if (!empty($coords)) {
        $lats = array_column($coords, 1);
        $lngs = array_column($coords, 0);
        return [min($lats), min($lngs), max($lats), max($lngs)];
      }
    }

    // Fallback: ~2km radius around center.
    // 1 degree latitude ~ 111km, so 2km ~ 0.018 degrees.
    // Longitude offset adjusted by cos(lat).
    $latOffset = 0.018;
    $lngOffset = $centerLat != 0 ? 0.018 / cos(deg2rad($centerLat)) : 0.018;

    return [
      $centerLat - $latOffset,
      $centerLng - $lngOffset,
      $centerLat + $latOffset,
      $centerLng + $lngOffset,
    ];
  }

  /**
   * Recursively flattens nested coordinate arrays to [lng, lat] pairs.
   *
   * @param array $coords
   *   Nested coordinate array from GeoJSON geometry.
   *
   * @return array<array{float, float}>
   *   Flat list of [lng, lat] pairs.
   */
  private function flattenCoordinates(array $coords, int $depth = 0): array {
    // Guard against deeply nested GeoJSON (max 8 levels, MultiPolygon needs 4).
    if ($depth > 8 || empty($coords)) {
      return [];
    }
    // Check if this is a coordinate pair [lng, lat].
    if (is_numeric($coords[0])) {
      return [$coords];
    }
    $result = [];
    foreach ($coords as $item) {
      if (is_array($item)) {
        foreach ($this->flattenCoordinates($item, $depth + 1) as $pair) {
          $result[] = $pair;
          // Cap at 50,000 coordinates to prevent memory exhaustion.
          if (count($result) > 50000) {
            return $result;
          }
        }
      }
    }
    return $result;
  }

  /**
   * Generates a random coordinate within a bounding box.
   *
   * @param array{float, float, float, float} $bbox
   *   Bounding box as [minLat, minLng, maxLat, maxLng].
   *
   * @return array{float, float}
   *   Random [lat, lng] within the box.
   */
  private function randomCoordinateInBbox(array $bbox): array {
    [$minLat, $minLng, $maxLat, $maxLng] = $bbox;
    $lat = $minLat + (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) * ($maxLat - $minLat);
    $lng = $minLng + (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) * ($maxLng - $minLng);
    return [round($lat, 6), round($lng, 6)];
  }

  /**
   * Ensures all requested languages are installed in Drupal.
   *
   * @param string[] $langcodes
   *   Language codes to ensure exist.
   */
  private function ensureLanguagesExist(array $langcodes): void {
    $installed = array_keys($this->languageManager->getLanguages());
    foreach ($langcodes as $langcode) {
      if (in_array($langcode, $installed, TRUE)) {
        continue;
      }
      ConfigurableLanguage::createFromLangcode($langcode)->save();
      $this->logger->info('Installed language @lang for workspace provisioning.', ['@lang' => $langcode]);
    }
  }

}
