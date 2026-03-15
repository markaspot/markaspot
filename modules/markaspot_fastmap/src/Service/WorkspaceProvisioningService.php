<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupInterface;
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
      $storage = $this->entityTypeManager->getStorage('configurable_language');
      $language = $storage->create(['id' => $langcode]);
      $language->save();
      $this->logger->info('Installed language @lang for workspace provisioning.', ['@lang' => $langcode]);
    }
  }

}
