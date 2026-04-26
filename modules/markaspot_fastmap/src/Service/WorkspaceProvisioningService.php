<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
        'cs' => 'Vytvořeno',
        'nl' => 'Aangemaakt',
        'fr' => 'Créé',
        'es' => 'Creado',
        'pl' => 'Utworzony',
        'it' => 'Creato',
        'pt' => 'Criado',
        'da' => 'Oprettet',
        'fi' => 'Luotu',
        'nb' => 'Opprettet',
        'sv' => 'Skapad',
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
        'cs' => 'Hotovo',
        'nl' => 'Afgerond',
        'fr' => 'Terminé',
        'es' => 'Hecho',
        'pl' => 'Zakończony',
        'it' => 'Completato',
        'pt' => 'Concluído',
        'da' => 'Afsluttet',
        'fi' => 'Valmis',
        'nb' => 'Fullført',
        'sv' => 'Klar',
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
    'obstacle' => 'i-lucide-triangle-alert',
    'tactile' => 'i-lucide-hand',
    'building' => 'i-lucide-building-2',
    'heat' => 'i-lucide-thermometer-sun',
    'shade' => 'i-lucide-tree-pine',
    'fountain' => 'i-lucide-cup-soda',
    'surface' => 'i-lucide-square',
    'roof' => 'i-lucide-house',
    'tree' => 'i-lucide-tree-deciduous',
    'erosion' => 'i-lucide-mountain',
    'sign' => 'i-lucide-signpost',
    'bridge' => 'i-lucide-cable-car',
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
    'roadkill' => 'i-lucide-circle-alert',
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
    'cs' => [
      ['title' => 'Rozbitá pouliční lampa', 'description' => 'Pouliční lampa na tomto místě již několik dní nesvítí.'],
      ['title' => 'Výtluk na hlavní silnici', 'description' => 'Na vozovce se vytvořil velký výtluk, který komplikuje dopravu.'],
      ['title' => 'Poškozený chodník', 'description' => 'Dlaždice chodníku jsou popraskané a nerovné, hrozí zakopnutí.'],
      ['title' => 'Přeplněný odpadkový koš', 'description' => 'Veřejný odpadkový koš na tomto místě je přeplněný a je třeba jej vyprázdnit.'],
      ['title' => 'Graffiti na budově', 'description' => 'Na fasádě budovy na tomto místě je graffiti.'],
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

  private const ALLOWED_LANGS = [
    'en', 'de', 'cs', 'nl', 'fr', 'es', 'ar', 'da', 'fi',
    'hu', 'it', 'nb', 'pl', 'pt', 'sv', 'tr', 'uk',
  ];

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
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
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
    $statusTranslations = $data['status_translations'] ?? [];
    $aiSystemPrompt = isset($data['ai_system_prompt']) ? mb_substr(trim($data['ai_system_prompt']), 0, 2000) : '';
    $startPageContent = $data['start_page'] ?? NULL;
    $startPageTranslations = $data['start_page_translations'] ?? [];

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

    $multilingualCategories = $this->normalizeCategories($categories, $requestedLang);
    if (empty($multilingualCategories)) {
      throw new \RuntimeException('categories must contain at least one non-empty string');
    }

    $defaultLang = (is_string($requestedLang) && in_array($requestedLang, self::ALLOWED_LANGS, TRUE))
      ? $requestedLang
      : array_key_first($multilingualCategories);
    $defaultCategories = $this->getDefaultCategories($multilingualCategories, $defaultLang);

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
      // English must always be available (used as fallback for term machine names).
      $availableLanguages = array_unique(array_merge(['en', $defaultLang], array_keys($multilingualCategories)));

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
      if ($aiSystemPrompt) {
        $groupFields['field_ai_system_prompt'] = $aiSystemPrompt;
      }
      if ($boundaryJson) {
        $groupFields['field_boundary'] = $boundaryJson;
      }
      elseif ($lat && $lng) {
        // Generate a 1km radius circle as default boundary.
        $groupFields['field_boundary'] = json_encode(
          $this->generateCircleBoundary($lat, $lng, 1.0),
          JSON_UNESCAPED_UNICODE
        );
      }
      $group = $groupStorage->create($groupFields);
      $group->save();

      // All new workspaces start with an expiry date. The expiry is cleared
      // later when the Stripe webhook confirms successful payment.
      $config = $this->configFactory->get('markaspot_fastmap.settings');
      if (!empty($data['selected_tier'])) {
        // User selected a tier from pricing: longer grace period for checkout.
        $graceDays = (int) ($config->get('checkout_grace_days') ?? 14);
      }
      else {
        // Demo/test ride: shorter expiry.
        $graceDays = (int) ($config->get('demo_expiry_days') ?? 5);
      }
      $group->set('field_expiry_date', $this->time->getRequestTime() + ($graceDays * 86400));
      $group->save();

      $groupId = (int) $group->id();

      // 2. Create status terms (custom or default).
      if (is_array($customStatuses) && !empty($customStatuses)) {
        $this->createCustomStatusTerms($termStorage, $groupId, $defaultLang, $customStatuses, $statusTranslations, $availableLanguages);
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

      // 8. Create welcome start page.
      try {
        $this->createStartPage($group, $name, $defaultLang, $startPageContent, $startPageTranslations, $availableLanguages);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to create start page for @name: @msg', [
          '@name' => $name,
          '@msg' => $e->getMessage(),
        ]);
        // Don't rethrow - workspace is still usable without a start page.
      }

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

      // 1. Delete nodes belonging to this jurisdiction.
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      foreach (['service_request', 'page'] as $bundle) {
        $nids = $nodeStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $bundle)
          ->condition('field_jurisdiction', $groupId)
          ->execute();
        if (!empty($nids)) {
          $chunks = array_chunk($nids, 50);
          foreach ($chunks as $chunk) {
            $nodeStorage->delete($nodeStorage->loadMultiple($chunk));
          }
        }
      }

      // 2. Collect members before deleting relationships.
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

      // 3. Delete taxonomy terms belonging to this group.
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

      // 4. Delete users that have no other group memberships.
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

      // 5. Delete group entity.
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
        'classicReporting' => FALSE,
        'search' => ['enabled' => TRUE, 'mode' => 'fuzzy', 'minLength' => 2],
        'boundaries' => ['enabled' => TRUE, 'showBoundaryOnMap' => TRUE],
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
   *
   * The term is saved in the workspace's default language whenever a
   * localized name is available in self::DEFAULT_STATUSES. If the default
   * language has no localized name, the term falls back to English as
   * primary langcode so that the "Default" tab in the status admin reads
   * a natural English label instead of an English string mis-tagged as
   * the workspace language. All other available languages are added as
   * secondary translations.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $termStorage
   *   The taxonomy term storage.
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param string $defaultLang
   *   The workspace's default language code.
   * @param string[] $languages
   *   All workspace language codes (including $defaultLang and 'en').
   */
  private function createStatusTerms(EntityStorageInterface $termStorage, int $groupId, string $defaultLang, array $languages): void {
    $weight = 0;
    foreach (self::DEFAULT_STATUSES as $status) {
      $hasDefaultTranslation = isset($status['name'][$defaultLang]);
      $primaryLang = $hasDefaultTranslation ? $defaultLang : 'en';
      $primaryName = $status['name'][$primaryLang] ?? $status['name']['en'];

      if (!$hasDefaultTranslation && $defaultLang !== 'en') {
        $this->logger->warning('Default status "@name" has no translation for @lang; falling back to English as primary langcode.', [
          '@name' => $status['name']['en'],
          '@lang' => $defaultLang,
        ]);
      }

      $term = $termStorage->create([
        'vid' => 'service_status',
        'name' => $primaryName,
        'langcode' => $primaryLang,
        'weight' => $weight++,
        'field_status_hex' => ['color' => $status['hex']],
        'field_status_icon' => $status['icon'],
        'field_open311_mapping' => $status['mapping'],
        'field_jurisdiction' => ['target_id' => $groupId],
      ]);
      $term->save();

      foreach ($languages as $lang) {
        if ($lang === $primaryLang) {
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
   * @param array $statusTranslations
   *   Optional translations keyed by language code, each containing an array
   *   of translated status names in the same order as $statuses.
   *   Example: ['de' => ['Erstellt', 'Offen', 'Erledigt']].
   */
  private function createCustomStatusTerms(EntityStorageInterface $termStorage, int $groupId, string $defaultLang, array $statuses, array $statusTranslations = [], array $availableLanguages = []): void {
    $weight = 0;
    $index = 0;
    foreach ($statuses as $status) {
      $name = mb_substr(trim($status['name'] ?? ''), 0, 255);
      $hex = $status['hex'] ?? '#808080';
      $icon = $status['icon'] ?? 'i-lucide-circle';
      $mapping = $status['mapping'] ?? 'open';

      if (!$name) {
        $index++;
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

      // Add translations for this status term.
      if (!empty($statusTranslations)) {
        foreach ($statusTranslations as $lang => $translatedNames) {
          if ($lang === $defaultLang) {
            continue;
          }
          if (!empty($availableLanguages) && !in_array($lang, $availableLanguages, TRUE)) {
            continue;
          }
          $translatedName = $translatedNames[$index] ?? NULL;
          if ($translatedName && $term->isTranslatable()) {
            $translation = $term->addTranslation($lang, ['name' => $translatedName]);
            $translation->save();
          }
        }
      }

      $index++;
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
    $defaultCategories = $this->getDefaultCategories($multilingualCategories, $defaultLang);

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
  private function normalizeCategories(array $categories, string $requestedLang = ''): array {
    $firstValue = reset($categories);
    if (is_string($firstValue)) {
      $valid = array_filter($categories, fn($c) => is_string($c) && trim($c) !== '');
      $valid = array_map(fn($c) => mb_substr(trim($c), 0, 255), $valid);
      $lang = (is_string($requestedLang) && in_array($requestedLang, self::ALLOWED_LANGS, TRUE))
        ? $requestedLang
        : 'en';
      return $valid ? [$lang => array_values($valid)] : [];
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
   * Returns the category list for the default language, with fallback.
   *
   * Falls back to the first available language when $defaultLang
   * has no category data in $multilingualCategories.
   */
  private function getDefaultCategories(array $multilingualCategories, string $defaultLang): array {
    return $multilingualCategories[$defaultLang]
      ?? $multilingualCategories[array_key_first($multilingualCategories)];
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
   * Welcome page templates per language.
   */
  private const START_PAGE_TEMPLATES = [
    'en' => [
      'title' => 'Welcome to %name',
      'body' => '<p>Welcome to <strong>%name</strong>, your citizen reporting platform.</p>'
        . '<p>Use the map to browse existing reports or create a new one. '
        . 'Select a category, pin the location, and describe the issue. '
        . 'Your report helps make %name better for everyone.</p>',
    ],
    'de' => [
      'title' => 'Willkommen bei %name',
      'body' => '<p>Willkommen bei <strong>%name</strong>, Ihrer Plattform für Bürgeranliegen.</p>'
        . '<p>Nutzen Sie die Karte, um bestehende Meldungen zu sehen oder eine neue zu erstellen. '
        . 'Wählen Sie eine Kategorie, markieren Sie den Standort und beschreiben Sie das Anliegen. '
        . 'Ihre Meldung hilft, %name für alle zu verbessern.</p>',
    ],
    'cs' => [
      'title' => 'Vítejte v %name',
      'body' => '<p>Vítejte v <strong>%name</strong>, vaší platformě pro občanská hlášení.</p>'
        . '<p>Pomocí mapy můžete procházet existující hlášení nebo vytvořit nové. '
        . 'Vyberte kategorii, označte místo a popište problém. '
        . 'Vaše hlášení pomáhá zlepšovat %name pro všechny.</p>',
    ],
    'fr' => [
      'title' => 'Bienvenue sur %name',
      'body' => '<p>Bienvenue sur <strong>%name</strong>, votre plateforme de signalement citoyen.</p>'
        . '<p>Utilisez la carte pour consulter les signalements existants ou en créer un nouveau. '
        . 'Choisissez une catégorie, indiquez l\'emplacement et décrivez le problème. '
        . 'Votre signalement contribue à améliorer %name pour tous.</p>',
    ],
    'es' => [
      'title' => 'Bienvenido a %name',
      'body' => '<p>Bienvenido a <strong>%name</strong>, su plataforma de reportes ciudadanos.</p>'
        . '<p>Use el mapa para ver reportes existentes o crear uno nuevo. '
        . 'Seleccione una categoría, marque la ubicación y describa el problema. '
        . 'Su reporte ayuda a mejorar %name para todos.</p>',
    ],
    'nl' => [
      'title' => 'Welkom bij %name',
      'body' => '<p>Welkom bij <strong>%name</strong>, uw platform voor burgermeldingen.</p>'
        . '<p>Gebruik de kaart om bestaande meldingen te bekijken of een nieuwe aan te maken. '
        . 'Kies een categorie, markeer de locatie en beschrijf het probleem. '
        . 'Uw melding helpt om %name voor iedereen te verbeteren.</p>',
    ],
    'it' => [
      'title' => 'Benvenuti su %name',
      'body' => '<p>Benvenuti su <strong>%name</strong>, la vostra piattaforma per le segnalazioni dei cittadini.</p>'
        . '<p>Usate la mappa per consultare le segnalazioni esistenti o crearne una nuova. '
        . 'Scegliete una categoria, indicate la posizione e descrivete il problema. '
        . 'La vostra segnalazione contribuisce a migliorare %name per tutti.</p>',
    ],
    'pt' => [
      'title' => 'Bem-vindo ao %name',
      'body' => '<p>Bem-vindo ao <strong>%name</strong>, a sua plataforma de participação cidadã.</p>'
        . '<p>Use o mapa para consultar ocorrências existentes ou criar uma nova. '
        . 'Selecione uma categoria, marque a localização e descreva o problema. '
        . 'A sua ocorrência ajuda a melhorar %name para todos.</p>',
    ],
    'pl' => [
      'title' => 'Witamy w %name',
      'body' => '<p>Witamy w <strong>%name</strong>, platformie zgłoszeń obywatelskich.</p>'
        . '<p>Użyj mapy, aby przeglądać istniejące zgłoszenia lub utworzyć nowe. '
        . 'Wybierz kategorię, zaznacz lokalizację i opisz problem. '
        . 'Twoje zgłoszenie pomaga ulepszać %name dla wszystkich.</p>',
    ],
    'da' => [
      'title' => 'Velkommen til %name',
      'body' => '<p>Velkommen til <strong>%name</strong>, din platform for borgerhenvendelser.</p>'
        . '<p>Brug kortet til at se eksisterende henvendelser eller oprette en ny. '
        . 'Vælg en kategori, markér stedet og beskriv problemet. '
        . 'Din henvendelse er med til at gøre %name bedre for alle.</p>',
    ],
    'tr' => [
      'title' => '%name platformuna hoş geldiniz',
      'body' => '<p><strong>%name</strong> vatandaş bildirim platformuna hoş geldiniz.</p>'
        . '<p>Haritayı kullanarak mevcut bildirimleri inceleyin veya yeni bir bildirim oluşturun. '
        . 'Bir kategori seçin, konumu işaretleyin ve sorunu açıklayın. '
        . 'Bildiriminiz %name platformunu herkes için daha iyi hale getirmeye yardımcı olur.</p>',
    ],
    'uk' => [
      'title' => 'Ласкаво просимо до %name',
      'body' => '<p>Ласкаво просимо до <strong>%name</strong>, вашої платформи для повідомлень громадян.</p>'
        . '<p>Використовуйте карту, щоб переглянути існуючі повідомлення або створити нове. '
        . 'Оберіть категорію, вкажіть місце та опишіть проблему. '
        . 'Ваше повідомлення допомагає покращити %name для всіх.</p>',
    ],
    'ar' => [
      'title' => 'مرحباً بكم في %name',
      'body' => '<p>مرحباً بكم في <strong>%name</strong>، منصتكم للبلاغات المدنية.</p>'
        . '<p>استخدموا الخريطة لتصفح البلاغات الحالية أو إنشاء بلاغ جديد. '
        . 'اختاروا فئة، حددوا الموقع وصفوا المشكلة. '
        . 'بلاغكم يساعد في تحسين %name للجميع.</p>',
    ],
    'de-ls' => [
      'title' => 'Willkommen bei %name',
      'body' => '<p>Willkommen bei <strong>%name</strong>. Hier können Sie Meldungen machen.</p>'
        . '<p>Schauen Sie auf der Karte, was andere gemeldet haben. '
        . 'Oder machen Sie eine neue Meldung. '
        . 'Wählen Sie ein Thema, zeigen Sie den Ort und beschreiben Sie das Problem. '
        . 'Ihre Meldung hilft, %name für alle besser zu machen.</p>',
    ],
  ];

  /**
   * Creates a promoted welcome page for a new workspace.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param string $name
   *   The workspace name.
   * @param string $defaultLang
   *   The default language code.
   * @param array|null $startPageContent
   *   Optional AI-generated content with 'title' and 'body' keys.
   *   Falls back to static templates if not provided.
   * @param array $startPageTranslations
   *   Optional translations keyed by language code, each containing
   *   'title' and 'body' keys. Example:
   *   ['de' => ['title' => '...', 'body' => '...']].
   * @param array $availableLanguages
   *   All workspace language codes, used for template fallback.
   */
  private function createStartPage(GroupInterface $group, string $name, string $defaultLang, ?array $startPageContent = NULL, array $startPageTranslations = [], array $availableLanguages = []): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $groupId = (int) $group->id();

    // Prefer AI-generated content from the onboarding chat.
    if (!empty($startPageContent['title']) && !empty($startPageContent['body'])) {
      $title = mb_substr($startPageContent['title'], 0, 255);
      $body = mb_substr($startPageContent['body'], 0, 2000);
    }
    else {
      $template = self::START_PAGE_TEMPLATES[$defaultLang]
        ?? self::START_PAGE_TEMPLATES['en'];
      $title = str_replace('%name', $name, $template['title']);
      $body = str_replace('%name', $name, $template['body']);
    }

    $node = $nodeStorage->create([
      'type' => 'page',
      'langcode' => $defaultLang,
      'title' => $title,
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
      'uid' => 1,
      'promote' => TRUE,
      'sticky' => TRUE,
      'status' => TRUE,
      'field_jurisdiction' => ['target_id' => $groupId],
    ]);
    $node->save();

    // Add AI-generated translations for the start page.
    if (!empty($startPageTranslations)) {
      foreach ($startPageTranslations as $lang => $translation) {
        if ($lang === $defaultLang || !$node->isTranslatable()) {
          continue;
        }
        if (!empty($availableLanguages) && !in_array($lang, $availableLanguages, TRUE)) {
          continue;
        }
        $transTitle = mb_substr($translation['title'] ?? '', 0, 255);
        $transBody = mb_substr($translation['body'] ?? '', 0, 2000);
        if ($transTitle && $transBody) {
          $nodeTranslation = $node->addTranslation($lang, [
            'title' => $transTitle,
            'body' => ['value' => $transBody, 'format' => 'basic_html'],
          ]);
          $nodeTranslation->save();
        }
      }
    }

    // Fallback to generic templates for untranslated languages.
    foreach ($availableLanguages as $lang) {
      if ($lang === $defaultLang || isset($startPageTranslations[$lang])) {
        continue;
      }
      $template = self::START_PAGE_TEMPLATES[$lang] ?? NULL;
      if ($template && $node->isTranslatable()) {
        $fallbackTitle = str_replace('%name', $name, $template['title']);
        $fallbackBody = str_replace('%name', $name, $template['body']);
        $nodeTranslation = $node->addTranslation($lang, [
          'title' => $fallbackTitle,
          'body' => ['value' => $fallbackBody, 'format' => 'basic_html'],
        ]);
        $nodeTranslation->save();
      }
    }

    // Add group relationship if the plugin is installed.
    try {
      $group->addRelationship($node, 'group_node:page');
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not add page group relationship: @msg', ['@msg' => $e->getMessage()]);
    }

    $this->logger->info('Created start page for workspace @name (group @id).', [
      '@name' => $name,
      '@id' => $groupId,
    ]);
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
    // Read the authoritative map center from the group's stored nuxt_config
    // rather than from $data, which may contain stale or geocoded coordinates
    // that differ from the intended workspace center.
    [$centerLng, $centerLat] = $this->extractMapCenter($group);
    $boundary = $this->extractBoundaryGeometry($group);
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
   * Extracts the map center [lng, lat] from a group's stored nuxt_config.
   *
   * Falls back to [0.0, 0.0] if nuxt_config is missing or malformed.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The provisioned group entity.
   *
   * @return array{float, float}
   *   Map center as [lng, lat].
   */
  private function extractMapCenter(GroupInterface $group): array {
    if ($group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
      $json = $group->get('field_nuxt_config')->value;
      $config = json_decode($json, TRUE);
      $center = $config['map']['center'] ?? NULL;
      if (is_array($center) && isset($center[0], $center[1]) && is_numeric($center[0]) && is_numeric($center[1])) {
        return [(float) $center[0], (float) $center[1]];
      }
    }
    return [0.0, 0.0];
  }

  /**
   * Extracts the boundary geometry array from a group's stored field_boundary.
   *
   * Unwraps the GeoJSON FeatureCollection wrapper and returns the raw
   * Polygon/MultiPolygon geometry, or NULL if unavailable.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The provisioned group entity.
   *
   * @return array|null
   *   GeoJSON geometry array or NULL.
   */
  private function extractBoundaryGeometry(GroupInterface $group): ?array {
    if (!$group->hasField('field_boundary') || $group->get('field_boundary')->isEmpty()) {
      return NULL;
    }
    $json = $group->get('field_boundary')->value;
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    // Unwrap FeatureCollection → first feature geometry.
    if (($decoded['type'] ?? '') === 'FeatureCollection') {
      $geometry = $decoded['features'][0]['geometry'] ?? NULL;
      if (is_array($geometry) && in_array($geometry['type'] ?? '', ['Polygon', 'MultiPolygon'], TRUE)) {
        return $geometry;
      }
    }
    // Accept bare Polygon/MultiPolygon geometry directly.
    if (in_array($decoded['type'] ?? '', ['Polygon', 'MultiPolygon'], TRUE)) {
      return $decoded;
    }
    return NULL;
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

    // Fallback: ~500m radius around center.
    // 1 degree latitude ~ 111km, so 500m ~ 0.0045 degrees.
    // Longitude offset adjusted by cos(lat). Guard against poles.
    $latOffset = 0.0045;
    $lngOffset = abs($centerLat) < 89.9 ? 0.0045 / cos(deg2rad($centerLat)) : 0.0045;

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
   * Generates a GeoJSON Polygon circle around a center point.
   *
   * @param float $lat
   *   Center latitude.
   * @param float $lng
   *   Center longitude.
   * @param float $radiusKm
   *   Radius in kilometers.
   * @param int $points
   *   Number of vertices (default 32).
   *
   * @return array
   *   GeoJSON Polygon geometry.
   */
  private function generateCircleBoundary(float $lat, float $lng, float $radiusKm, int $points = 32): array {
    $coords = [];
    $latOffset = $radiusKm / 111.0;
    $lngOffset = abs($lat) < 89.9 ? $radiusKm / (111.0 * cos(deg2rad($lat))) : $latOffset;

    for ($i = 0; $i < $points; $i++) {
      $angle = 2 * M_PI * $i / $points;
      $coords[] = [
        round($lng + $lngOffset * cos($angle), 6),
        round($lat + $latOffset * sin($angle), 6),
      ];
    }
    // Close the ring.
    $coords[] = $coords[0];

    return [
      'type' => 'Polygon',
      'coordinates' => [$coords],
    ];
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
