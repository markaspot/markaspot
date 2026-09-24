<?php

namespace Drupal\markaspot_icons;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\iconify_field\Service\IconResolverInterface;

/**
 * Service for migrating icon field data between different formats.
 *
 * The Nuxt UI renders Iconify names only, so every icon field value is
 * normalized to "i-lucide-<name>". Legacy notations (FontAwesome classes,
 * bare names, "set:name" pairs, "lucide:<name>") are rewritten only when the
 * target is known to exist. Anything else is kept and reported.
 */
class IconMigrationService {

  use StringTranslationTrait;

  /**
   * Icon fields covered when field discovery is unavailable.
   *
   * With the entity field manager, every fa_icon_class field is covered.
   */
  private const ICON_FIELDS = [
    'node' => ['field_page_icon'],
    'taxonomy_term' => ['field_category_icon', 'field_status_icon'],
  ];

  /**
   * The field type that stores icon names rendered by the Nuxt UI.
   */
  private const ICON_FIELD_TYPE = 'fa_icon_class';

  /**
   * A Lucide or FontAwesome icon name.
   */
  private const NAME_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

  /**
   * FontAwesome style and modifier classes that carry no icon name.
   */
  private const FONTAWESOME_MODIFIER_PATTERN = '/^(?:fa|fas|far|fab|fal|fad|fat|fa-(?:solid|regular|brands|light|thin|duotone|fw|lg|xs|sm|[1-9]x|10x|spin|pulse|border|inverse|pull-(?:left|right)|flip-(?:horizontal|vertical)|rotate-(?:90|180|270)))$/';

  /**
   * Iconify collections other than Lucide that the Nuxt UI renders.
   */
  private const RENDERED_FOREIGN_COLLECTIONS = ['heroicons'];

  /**
   * Known Lucide aliases and renamed icons.
   *
   * Iconify's resolver only accepts canonical names from the icons collection.
   * It does not resolve the aliases shipped in the collection metadata.
   */
  private const LUCIDE_ALIASES = [
    'alert-circle' => 'circle-alert',
    'alert-triangle' => 'triangle-alert',
    'check-circle' => 'circle-check-big',
    'check-circle-2' => 'circle-check',
    'child' => 'baby',
    'help-circle' => 'circle-question-mark',
    'home' => 'house',
    'minus-circle' => 'circle-minus',
    'more-horizontal' => 'ellipsis',
    'more-vertical' => 'ellipsis-vertical',
    'pause-circle' => 'circle-pause',
    'parking-circle' => 'circle-parking',
    'play-circle' => 'circle-play',
    'plus-circle' => 'circle-plus',
    'stop-circle' => 'circle-stop',
    'tree' => 'trees',
    'unlock' => 'lock-open',
    'user-circle' => 'circle-user',
    'x-circle' => 'circle-x',
  ];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The optional Iconify resolver.
   *
   * @var \Drupal\iconify_field\Service\IconResolverInterface|null
   */
  protected ?IconResolverInterface $iconResolver;

  /**
   * The optional entity field manager used to discover icon fields.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|null
   */
  protected ?EntityFieldManagerInterface $entityFieldManager;

  /**
   * Icon mapping from FontAwesome to modern icon collections.
   *
   * @var array
   */
  protected $iconMappings = [
    'heroicons' => [
      // Waste/Trash.
      'fa-trash' => 'i-heroicons-trash',
      'fa-trash-o' => 'i-heroicons-trash',

      // Transportation/Infrastructure.
      // No direct equivalent, use generic.
      'fa-road' => 'i-heroicons-minus',
      'fa-car' => 'i-heroicons-truck',

      // Nature/Environment.
      // No direct tree, use nature-related.
      'fa-tree' => 'i-heroicons-beaker',
      'fa-tint' => 'i-heroicons-beaker',

      // Communication.
      'fa-comment' => 'i-heroicons-chat-bubble-left',
      'fa-comment-o' => 'i-heroicons-chat-bubble-left',

      // Status indicators.
      'fa-check' => 'i-heroicons-check',
      'fa-check-circle' => 'i-heroicons-check-circle',
      'fa-play-circle' => 'i-heroicons-play-circle',
      'fa-hand-stop-o' => 'i-heroicons-hand-raised',
      'fa-stack-overflow' => 'i-heroicons-archive-box',
      'fa-calendar-times-o' => 'i-heroicons-calendar-x-mark',

      // Buildings/Places.
      'fa-bank' => 'i-heroicons-building-office',
      'fa-building' => 'i-heroicons-building-office',
      'fa-home' => 'i-heroicons-home',
      'fa-hospital-o' => 'i-heroicons-building-office-2',

      // Utilities.
      'fa-heart-o' => 'i-heroicons-heart',
      'fa-heart' => 'i-heroicons-heart',
      'fa-star' => 'i-heroicons-star',
      'fa-star-o' => 'i-heroicons-star',

      // Default fallback.
      'default' => 'i-heroicons-exclamation-circle',
    ],
    // FontAwesome 4/5 names mapped by their FontAwesome glyph. Names whose
    // Lucide icon shows the same glyph (bug, truck, ...) need no entry.
    'lucide' => [
      // People/Users.
      'fa-male' => 'i-lucide-user',
      'fa-female' => 'i-lucide-user',
      'fa-user' => 'i-lucide-user',
      'fa-users' => 'i-lucide-users',
      'fa-walking' => 'i-lucide-footprints',
      'fa-street-view' => 'i-lucide-person-standing',
      'fa-universal-access' => 'i-lucide-accessibility',
      'fa-wheelchair' => 'i-lucide-accessibility',
      'fa-hands-helping' => 'i-lucide-handshake',

      // Objects/Tools.
      'fa-lightbulb-o' => 'i-lucide-lightbulb',
      'fa-lightbulb' => 'i-lucide-lightbulb',
      'fa-glass' => 'i-lucide-wine',
      'fa-paint-brush' => 'i-lucide-paintbrush',
      'fa-circle-o' => 'i-lucide-circle',
      'fa-circle' => 'i-lucide-circle-dot',
      'fa-wrench' => 'i-lucide-wrench',
      'fa-tools' => 'i-lucide-wrench',
      'fa-cog' => 'i-lucide-settings',
      'fa-cogs' => 'i-lucide-settings',
      'fa-map-marker' => 'i-lucide-map-pin',
      'fa-cube' => 'i-lucide-box',
      'fa-cubes' => 'i-lucide-boxes',
      'fa-bullhorn' => 'i-lucide-megaphone',
      'fa-volume-up' => 'i-lucide-volume-2',
      'fa-magic' => 'i-lucide-wand-sparkles',
      'fa-mobile' => 'i-lucide-smartphone',
      'fa-desktop' => 'i-lucide-monitor',
      'fa-television' => 'i-lucide-tv',
      'fa-couch' => 'i-lucide-sofa',
      'fa-chair' => 'i-lucide-armchair',
      'fa-smoking' => 'i-lucide-cigarette',
      'fa-skull-crossbones' => 'i-lucide-skull',
      'fa-tachometer' => 'i-lucide-gauge',
      'fa-dashboard' => 'i-lucide-gauge',
      'fa-thumb-tack' => 'i-lucide-pin',
      'fa-paw' => 'i-lucide-paw-print',
      'fa-cutlery' => 'i-lucide-utensils',
      'fa-birthday-cake' => 'i-lucide-cake',
      'fa-medkit' => 'i-lucide-briefcase-medical',
      'fa-heartbeat' => 'i-lucide-heart-pulse',
      'fa-money' => 'i-lucide-banknote',
      'fa-eur' => 'i-lucide-euro',
      'fa-euro' => 'i-lucide-euro',
      'fa-legal' => 'i-lucide-gavel',
      'fa-balance-scale' => 'i-lucide-scale',
      'fa-sitemap' => 'i-lucide-network',
      'fa-industry' => 'i-lucide-factory',

      // Nature/Environment.
      'fa-tree' => 'i-lucide-tree-pine',
      'fa-leaf' => 'i-lucide-leaf',
      'fa-pagelines' => 'i-lucide-leaf',
      'fa-seedling' => 'i-lucide-sprout',
      'fa-tint' => 'i-lucide-droplets',
      'fa-water' => 'i-lucide-waves',
      'fa-envira' => 'i-lucide-flower-2',
      'fa-soccer-ball-o' => 'i-lucide-circle-dot',
      'fa-futbol' => 'i-lucide-circle-dot',
      'fa-cloud' => 'i-lucide-cloud',

      // Weather.
      'fa-cloud-rain' => 'i-lucide-cloud-rain-wind',
      'fa-sun-o' => 'i-lucide-sun',
      'fa-snowflake-o' => 'i-lucide-snowflake',

      // Waste/Trash.
      'fa-trash' => 'i-lucide-trash',
      'fa-trash-o' => 'i-lucide-trash-2',
      'fa-trash-alt' => 'i-lucide-trash-2',
      'fa-dumpster' => 'i-lucide-trash-2',
      'fa-recycle' => 'i-lucide-recycle',

      // Transportation/Infrastructure.
      'fa-road' => 'i-lucide-construction',
      'fa-car' => 'i-lucide-car',
      'fa-automobile' => 'i-lucide-car',
      'fa-car-side' => 'i-lucide-car',
      'fa-taxi' => 'i-lucide-car-taxi-front',
      'fa-motorcycle' => 'i-lucide-motorbike',
      'fa-bicycle' => 'i-lucide-bike',
      'fa-parking' => 'i-lucide-circle-parking',
      'fa-bus' => 'i-lucide-bus',
      // Lucide aliases "train" to tram-front.
      'fa-train' => 'i-lucide-tram-front',
      'fa-subway' => 'i-lucide-train-front-tunnel',
      'fa-traffic-light' => 'i-lucide-traffic-cone',
      'fa-map-signs' => 'i-lucide-signpost',
      'fa-location-arrow' => 'i-lucide-navigation',

      // Time.
      'fa-clock-o' => 'i-lucide-clock',
      'fa-clock' => 'i-lucide-clock',
      'fa-calendar' => 'i-lucide-calendar',
      'fa-calendar-times-o' => 'i-lucide-calendar-x',
      'fa-hourglass-half' => 'i-lucide-hourglass',

      // Communication.
      'fa-comment' => 'i-lucide-message-circle',
      'fa-comment-o' => 'i-lucide-message-circle',
      'fa-comments' => 'i-lucide-messages-square',
      'fa-envelope' => 'i-lucide-mail',
      'fa-phone' => 'i-lucide-phone',
      'fa-address-card' => 'i-lucide-contact',

      // Status indicators.
      'fa-check' => 'i-lucide-check',
      'fa-check-circle' => 'i-lucide-circle-check-big',
      'fa-check-circle-o' => 'i-lucide-circle-check-big',
      // The FontAwesome check sits inside the box, unlike square-check-big.
      'fa-check-square' => 'i-lucide-square-check',
      'fa-play-circle' => 'i-lucide-circle-play',
      'fa-play-circle-o' => 'i-lucide-circle-play',
      'fa-pause-circle' => 'i-lucide-circle-pause',
      'fa-stop-circle' => 'i-lucide-circle-stop',
      'fa-stop' => 'i-lucide-square',
      'fa-hand-stop-o' => 'i-lucide-hand',
      'fa-hand-paper' => 'i-lucide-hand',
      'fa-hand-paper-o' => 'i-lucide-hand',
      'fa-thumbs-o-up' => 'i-lucide-thumbs-up',
      'fa-stack-overflow' => 'i-lucide-archive',
      'fa-archive' => 'i-lucide-archive',
      'fa-spinner' => 'i-lucide-loader',
      'fa-refresh' => 'i-lucide-refresh-cw',
      'fa-sync' => 'i-lucide-refresh-cw',
      'fa-exchange' => 'i-lucide-arrow-left-right',
      'fa-random' => 'i-lucide-shuffle',
      'fa-share-square' => 'i-lucide-share',
      'fa-arrow-circle-right' => 'i-lucide-circle-arrow-right',
      'fa-times' => 'i-lucide-x',
      'fa-times-circle' => 'i-lucide-circle-x',
      'fa-exclamation' => 'i-lucide-triangle-alert',
      'fa-exclamation-circle' => 'i-lucide-circle-alert',
      'fa-exclamation-triangle' => 'i-lucide-triangle-alert',
      'fa-warning' => 'i-lucide-triangle-alert',
      'fa-info' => 'i-lucide-info',
      'fa-info-circle' => 'i-lucide-info',
      'fa-question' => 'i-lucide-circle-question-mark',
      'fa-question-circle' => 'i-lucide-circle-question-mark',

      // Pages/Documents.
      'fa-file-text' => 'i-lucide-file-text',
      'fa-file-alt' => 'i-lucide-file-text',
      // FontAwesome 4 bar-chart has axes, chart-no-axes-* has none. The
      // FontAwesome 5 chart-bar (horizontal bars) is Lucide chart-bar.
      'fa-bar-chart' => 'i-lucide-chart-column',
      'fa-line-chart' => 'i-lucide-chart-line',
      'fa-chart-line' => 'i-lucide-chart-line',
      'fa-pie-chart' => 'i-lucide-chart-pie',
      'fa-chart-pie' => 'i-lucide-chart-pie',
      'fa-area-chart' => 'i-lucide-chart-area',
      'fa-sign-in' => 'i-lucide-log-in',
      'fa-sign-out' => 'i-lucide-log-out',
      'fa-edit' => 'i-lucide-square-pen',

      // Buildings/Places.
      'fa-bank' => 'i-lucide-building-2',
      'fa-building' => 'i-lucide-building',
      'fa-building-o' => 'i-lucide-building',
      'fa-home' => 'i-lucide-house',
      'fa-hospital-o' => 'i-lucide-building-2',
      'fa-university' => 'i-lucide-landmark',

      // Miscellaneous.
      'fa-heart' => 'i-lucide-heart',
      'fa-heart-o' => 'i-lucide-heart',
      'fa-star' => 'i-lucide-star',
      'fa-star-o' => 'i-lucide-star',
      'fa-flag' => 'i-lucide-flag',
      'fa-flag-o' => 'i-lucide-flag',
      'fa-bolt' => 'i-lucide-zap',
      'fa-fire' => 'i-lucide-flame',
      'fa-camera' => 'i-lucide-camera',
      'fa-image' => 'i-lucide-image',
      'fa-file' => 'i-lucide-file',
      'fa-folder' => 'i-lucide-folder',
      'fa-search' => 'i-lucide-search',
      'fa-eye' => 'i-lucide-eye',
      'fa-eye-slash' => 'i-lucide-eye-off',
      'fa-lock' => 'i-lucide-lock',
      'fa-unlock' => 'i-lucide-lock-open',
      'fa-key' => 'i-lucide-key',
      'fa-bell' => 'i-lucide-bell',
      'fa-bell-o' => 'i-lucide-bell',
      'fa-bell-slash' => 'i-lucide-bell-off',

      // Default fallback.
      'default' => 'i-lucide-circle-alert',
    ],
    'fa6-solid' => [
      // Direct FontAwesome 6 mapping.
      'fa-trash' => 'i-fa6-solid-trash-can',
      'fa-trash-o' => 'i-fa6-solid-trash-can',
      'fa-road' => 'i-fa6-solid-road',
      'fa-tree' => 'i-fa6-solid-tree',
      'fa-tint' => 'i-fa6-solid-droplet',
      'fa-comment' => 'i-fa6-solid-comment',
      'fa-comment-o' => 'i-fa6-solid-comment',
      'fa-check' => 'i-fa6-solid-check',
      'fa-check-circle' => 'i-fa6-solid-circle-check',
      'fa-play-circle' => 'i-fa6-solid-circle-play',
      'fa-hand-stop-o' => 'i-fa6-solid-hand',
      'fa-bank' => 'i-fa6-solid-building-columns',
      'fa-building' => 'i-fa6-solid-building',
      'fa-home' => 'i-fa6-solid-house',
      'fa-heart-o' => 'i-fa6-solid-heart',
      'fa-heart' => 'i-fa6-solid-heart',
      'default' => 'i-fa6-solid-circle-exclamation',
    ],
  ];

  /**
   * Constructs a IconMigrationService object.
   *
   * The renderer and the render cache are no longer used: icon availability is
   * read from the Lucide collection, and entity saves invalidate the entity and
   * list cache tags. Both keep their position so containers compiled with the
   * former argument list still construct this service until the next rebuild.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, MessengerInterface $messenger, ?IconResolverInterface $icon_resolver = NULL, ?RendererInterface $renderer = NULL, ?CacheBackendInterface $render_cache = NULL, ?EntityFieldManagerInterface $entity_field_manager = NULL) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;
    $this->iconResolver = $icon_resolver;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Validates icon fields and optionally repairs every reported value.
   *
   * Covers every fa_icon_class field on every entity type, including all
   * translations of translatable fields.
   *
   * @param bool $fix
   *   Whether safe planned repairs should be persisted.
   *
   * @return array<int, array<string, mixed>>
   *   One report row per invalid or unverifiable icon value.
   */
  public function validateAndRepair(bool $fix = FALSE): array {
    $report = [];

    foreach ($this->getIconFieldMap() as $entityTypeId => $fieldNames) {
      if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
        continue;
      }

      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $query = $storage->getQuery()->accessCheck(FALSE);
      $hasIcon = $query->orConditionGroup();
      foreach ($fieldNames as $fieldName) {
        $hasIcon->exists($fieldName);
      }
      $ids = $query->condition($hasIcon)->execute();

      foreach (array_chunk($ids, 100, TRUE) as $idChunk) {
        foreach ($storage->loadMultiple($idChunk) as $entity) {
          if ($entity instanceof ContentEntityInterface) {
            array_push($report, ...$this->repairEntityIcons($entity, $fieldNames, $fix));
          }
        }
        $storage->resetCache($idChunk);
      }
    }

    return $report;
  }

  /**
   * Inspects an icon using the Lucide collection when necessary.
   *
   * @param string $icon
   *   The stored icon value.
   *
   * @return array<string, mixed>
   *   The validation and repair plan.
   */
  public function inspectIconValue(string $icon): array {
    $analysis = $this->analyzeIconValue($icon);
    if ($analysis['target'] !== NULL || $analysis['candidate'] === NULL) {
      return $this->buildRepairPlan($icon, $analysis, NULL);
    }

    try {
      $resolvable = $this->isLucideIcon($analysis['candidate']);
    }
    catch (\Throwable) {
      $plan = $this->buildRepairPlan($icon, $analysis, NULL);
      $plan['issue'] = 'resolver-error';
      return $plan;
    }

    return $this->buildRepairPlan($icon, $analysis, $resolvable);
  }

  /**
   * Creates a deterministic repair plan for one stored icon value.
   *
   * Mapped and aliased names are always fixable. A name that is only valid if
   * Lucide ships it unchanged is rewritten when $resolvable is TRUE. Passing
   * NULL models a missing resolver: such values are reported, never changed.
   *
   * @param string $icon
   *   The stored icon value.
   * @param bool|null $resolvable
   *   Whether the derived Lucide name exists, or NULL if unknown.
   *
   * @return array<string, mixed>
   *   Keys are issue, replacement, repair, and fixable.
   */
  public function planIconRepair(string $icon, ?bool $resolvable = NULL): array {
    return $this->buildRepairPlan($icon, $this->analyzeIconValue($icon), $resolvable);
  }

  /**
   * Builds the repair plan from an analyzed icon value.
   *
   * @param string $icon
   *   The stored icon value.
   * @param array{kind: string, candidate: ?string, target: ?string, repair: ?string} $analysis
   *   The result of analyzeIconValue().
   * @param bool|null $resolvable
   *   Whether the candidate name exists in Lucide, or NULL if unknown.
   *
   * @return array<string, mixed>
   *   Keys are issue, replacement, repair, and fixable.
   */
  private function buildRepairPlan(string $icon, array $analysis, ?bool $resolvable): array {
    $kind = $analysis['kind'];
    if ($kind === 'foreign') {
      return $this->plan(NULL, NULL, 'none', FALSE);
    }
    if ($kind === 'unrecognized') {
      return $this->plan('unrecognized', NULL, 'manual-review', FALSE);
    }

    $isLucide = $kind === 'lucide' || $kind === 'lucide-notation';
    if ($analysis['target'] !== NULL) {
      $replacement = 'i-lucide-' . $analysis['target'];
      if ($replacement === $icon) {
        return $this->plan(NULL, NULL, 'none', FALSE);
      }
      $issue = $isLucide && $analysis['repair'] === 'alias' ? 'lucide-alias' : $kind;
      return $this->plan($issue, $replacement, (string) $analysis['repair'], TRUE);
    }

    if ($resolvable === TRUE) {
      if ($kind === 'lucide') {
        return $this->plan(NULL, NULL, 'none', FALSE);
      }
      return $this->plan($kind, 'i-lucide-' . $analysis['candidate'], 'notation', TRUE);
    }

    if ($resolvable === FALSE) {
      return $this->plan($isLucide ? 'lucide-unresolved' : $kind, NULL, 'manual-review', FALSE);
    }

    return $this->plan($isLucide ? 'resolver-unavailable' : $kind, NULL, 'manual-review', FALSE);
  }

  /**
   * Formats a repair plan.
   *
   * @return array<string, mixed>
   *   Keys are issue, replacement, repair, and fixable.
   */
  private function plan(?string $issue, ?string $replacement, string $repair, bool $fixable): array {
    return [
      'issue' => $issue,
      'replacement' => $replacement,
      'repair' => $repair,
      'fixable' => $fixable,
    ];
  }

  /**
   * Classifies a stored value and resolves it through mappings and aliases.
   *
   * Kinds: "lucide" (canonical i-lucide-*), "lucide-notation" (lucide:*,
   * other casing or whitespace), "fontawesome" (fa-*, "fas fa-*", fa:*),
   * "legacy-name" (bare names and "set:name" pairs), "foreign" (other Iconify
   * collections the UI renders) and "unrecognized".
   *
   * @return array{kind: string, candidate: ?string, target: ?string, repair: ?string}
   *   The candidate is the Lucide name to verify when no target was found.
   */
  private function analyzeIconValue(string $icon): array {
    $normalized = strtolower(trim($icon));
    $name = self::NAME_PATTERN;

    if (preg_match("/^(?:i-lucide-|lucide:)($name)$/", $normalized, $matches)) {
      $kind = $normalized === $icon && str_starts_with($icon, 'i-lucide-') ? 'lucide' : 'lucide-notation';
      $alias = $this->findLucideAlias($matches[1]);
      return $this->analysis($kind, $matches[1], $alias, $alias !== NULL ? 'alias' : NULL);
    }
    if (str_starts_with($normalized, 'i-lucide-') || str_starts_with($normalized, 'lucide:')) {
      return $this->analysis('unrecognized');
    }
    if (str_starts_with($normalized, 'i-') ||
      preg_match('/^(?:' . implode('|', self::RENDERED_FOREIGN_COLLECTIONS) . "):$name$/", $normalized)) {
      return $this->analysis('foreign');
    }

    $fontAwesomeName = $this->extractFontAwesomeName($normalized);
    if ($fontAwesomeName !== NULL) {
      return $this->resolveLegacyName('fontawesome', $fontAwesomeName);
    }
    // "map:signs" is what the Nuxt UI derives from "fas fa-map-signs".
    if (preg_match("/^($name):($name)$/", $normalized, $matches)) {
      return $this->resolveLegacyName('legacy-name', $matches[1] . '-' . $matches[2]);
    }
    if (preg_match("/^$name$/", $normalized)) {
      return $this->resolveLegacyName('legacy-name', $normalized);
    }

    return $this->analysis('unrecognized');
  }

  /**
   * Formats an icon value analysis.
   *
   * @return array{kind: string, candidate: ?string, target: ?string, repair: ?string}
   *   The analysis.
   */
  private function analysis(string $kind, ?string $candidate = NULL, ?string $target = NULL, ?string $repair = NULL): array {
    return [
      'kind' => $kind,
      'candidate' => $candidate,
      'target' => $target,
      'repair' => $repair,
    ];
  }

  /**
   * Extracts the icon name from FontAwesome class notations.
   *
   * Accepts "fa-x", "fas fa-x", "fa fa-x fa-fw", "fa-solid fa-x" and "fa:x".
   */
  private function extractFontAwesomeName(string $normalized): ?string {
    $name = self::NAME_PATTERN;
    if (preg_match("/^(?:fa|fas|far|fab):($name)$/", $normalized, $matches)) {
      return $matches[1];
    }

    $names = [];
    foreach (preg_split('/\s+/', $normalized) ?: [] as $class) {
      if (preg_match(self::FONTAWESOME_MODIFIER_PATTERN, $class)) {
        continue;
      }
      if (!preg_match("/^fa-($name)$/", $class, $matches)) {
        return NULL;
      }
      $names[] = $matches[1];
    }

    return count($names) === 1 ? $names[0] : NULL;
  }

  /**
   * Resolves a FontAwesome-era name through the mapping table and aliases.
   *
   * Bare names and "set:name" pairs may already be Lucide names, so they keep
   * Lucide semantics: an existing icon or Lucide alias wins over the table.
   * FontAwesome classes keep FontAwesome semantics: the table wins, since
   * e.g. fa-bolt is a lightning bolt, while Lucide "bolt" is a screw bolt.
   *
   * FontAwesome "-o" (outline) and "-alt" suffixes are retried without the
   * suffix. No Lucide name ends in either, so the stripped name is also the
   * candidate to verify against the collection.
   *
   * @return array{kind: string, candidate: ?string, target: ?string, repair: ?string}
   *   The analysis.
   */
  private function resolveLegacyName(string $kind, string $name): array {
    if ($kind === 'legacy-name') {
      try {
        $isLucide = $this->isLucideIcon($name);
      }
      catch (\Throwable) {
        $isLucide = NULL;
      }
      if ($isLucide === TRUE) {
        return $this->analysis($kind, $name, $name, 'notation');
      }
      $alias = $this->findLucideAlias($name);
      if ($alias !== NULL) {
        return $this->analysis($kind, $name, $alias, 'alias');
      }
    }

    $stripped = preg_replace('/-(?:o|alt)$/', '', $name) ?? $name;
    foreach (array_unique([$name, $stripped]) as $candidate) {
      $mapped = $this->iconMappings['lucide']['fa-' . $candidate] ?? NULL;
      if ($mapped !== NULL) {
        $target = substr($mapped, strlen('i-lucide-'));
        return $this->analysis($kind, $candidate, self::LUCIDE_ALIASES[$target] ?? $target, 'mapping');
      }
      $alias = $this->findLucideAlias($candidate);
      if ($alias !== NULL) {
        return $this->analysis($kind, $candidate, $alias, 'alias');
      }
    }

    return $this->analysis($kind, $stripped);
  }

  /**
   * Finds a canonical Lucide name in fixed and collection-provided aliases.
   */
  private function findLucideAlias(string $name): ?string {
    if (isset(self::LUCIDE_ALIASES[$name])) {
      return self::LUCIDE_ALIASES[$name];
    }
    if (!$this->iconResolver) {
      return NULL;
    }

    try {
      $collection = $this->iconResolver->loadCollection('lucide');
      $parent = $collection['aliases'][$name]['parent'] ?? NULL;
    }
    catch (\Throwable) {
      return NULL;
    }

    return is_string($parent) && $parent !== '' ? $parent : NULL;
  }

  /**
   * Checks whether Lucide ships an icon under this canonical name.
   *
   * Uses the same collection data as IconResolver::getIcon(), without
   * rendering or writing render cache entries.
   *
   * @return bool|null
   *   NULL when no resolver or Lucide collection is available.
   */
  private function isLucideIcon(string $name): ?bool {
    if (!$this->iconResolver) {
      return NULL;
    }

    $collection = $this->iconResolver->loadCollection('lucide');
    if (empty($collection['icons'])) {
      return NULL;
    }

    return isset($collection['icons'][$name]);
  }

  /**
   * Validates and optionally repairs the icon fields of one entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to check.
   * @param string[] $fieldNames
   *   The icon field names of the entity type.
   * @param bool $fix
   *   Whether safe planned repairs should be saved.
   *
   * @return array<int, array<string, mixed>>
   *   One report row per invalid or unverifiable icon value.
   */
  private function repairEntityIcons(ContentEntityInterface $entity, array $fieldNames, bool $fix): array {
    $report = [];
    $changed = FALSE;

    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      $translation = $entity->getTranslation($langcode);

      foreach ($fieldNames as $fieldName) {
        if (!$translation->hasField($fieldName)) {
          continue;
        }

        $field = $translation->get($fieldName);
        if (!$field->getFieldDefinition()->isTranslatable() &&
          $langcode !== $entity->language()->getId()) {
          continue;
        }

        foreach ($field as $delta => $item) {
          $value = (string) ($item->value ?? '');
          if (trim($value) === '') {
            continue;
          }

          $plan = $this->inspectIconValue($value);
          if ($plan['issue'] === NULL) {
            continue;
          }

          $replacement = $plan['replacement'];
          $action = !empty($plan['fixable']) ? 'would-fix' : 'unresolved';
          if ($fix && !empty($plan['fixable']) && is_string($replacement)) {
            $item->set('value', $replacement);
            $changed = TRUE;
            $action = 'fixed';
          }

          $report[] = [
            'entity_type' => $entity->getEntityTypeId(),
            'entity_id' => (int) $entity->id(),
            'label' => (string) $translation->label(),
            'langcode' => $langcode,
            'field' => $fieldName,
            'delta' => $delta,
            'value' => $value,
            'replacement' => $replacement,
            'issue' => $plan['issue'],
            'repair' => $plan['repair'],
            'action' => $action,
          ];
        }
      }
    }

    if ($fix && $changed) {
      // Data repair, not an editorial change: keep changed timestamps and
      // revision/moderation handling out of it.
      $entity->setSyncing(TRUE);
      try {
        $entity->save();
      }
      catch (\Throwable $e) {
        // One entity a presave guard rejects must not abort the whole run.
        $this->loggerFactory->get('markaspot_icons')->error('Icon repair could not save @entity_type @id (@label): @message', [
          '@entity_type' => $entity->getEntityTypeId(),
          '@id' => $entity->id(),
          '@label' => (string) $entity->label(),
          '@message' => $e->getMessage(),
        ]);
        foreach ($report as &$row) {
          if ($row['action'] === 'fixed') {
            $row['action'] = 'error';
          }
        }
        unset($row);
      }
    }

    return $report;
  }

  /**
   * Gets the icon fields to check, keyed by entity type ID.
   *
   * @return array<string, string[]>
   *   Field names keyed by entity type ID.
   */
  private function getIconFieldMap(): array {
    if (!$this->entityFieldManager) {
      return self::ICON_FIELDS;
    }

    $map = [];
    foreach ($this->entityFieldManager->getFieldMapByFieldType(self::ICON_FIELD_TYPE) as $entityTypeId => $fields) {
      $map[$entityTypeId] = array_keys($fields);
    }
    return $map;
  }

  /**
   * Gets icon mapping preview for the UI.
   */
  public function getIconMappingPreview($direction, $target_collection) {
    if ($direction === 'fa_to_iconify') {
      return $this->iconMappings[$target_collection] ?? [];
    }

    // For iconify_to_fa, reverse the mapping.
    $mappings = $this->iconMappings[$target_collection] ?? [];
    return array_flip($mappings);
  }

  /**
   * Gets description for an icon.
   */
  public function getIconDescription($icon) {
    $descriptions = [
      'i-heroicons-trash' => 'Trash/Waste',
      'i-heroicons-chat-bubble-left' => 'Communication',
      'i-heroicons-check' => 'Completed',
      'i-heroicons-building-office' => 'Building/Office',
      'i-lucide-trash-2' => 'Waste Management',
      'i-lucide-tree-pine' => 'Nature/Trees',
      'i-fa6-solid-trash-can' => 'FontAwesome Trash',
    ];

    return $descriptions[$icon] ?? 'Icon';
  }

  /**
   * Batch processing callback for migration.
   */
  public static function batchProcess($entity_type, $direction, $target_collection, $dry_run, &$context) {
    $service = \Drupal::service('markaspot_icons.migration');

    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $service->getEntityCount($entity_type);
      $context['results']['processed'] = 0;
      $context['results']['updated'] = 0;
      $context['results']['dry_run'] = $dry_run;
    }

    $entities = $service->getEntitiesForMigration($entity_type, $context['sandbox']['progress'], 50);

    foreach ($entities as $entity) {
      $service->migrateEntityIcons($entity, $direction, $target_collection, $dry_run);
      $context['sandbox']['progress']++;
      $context['results']['processed']++;
    }

    if ($context['sandbox']['progress'] < $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
    else {
      $context['finished'] = 1;
    }

    $context['message'] = t('Processed @current out of @max entities.', [
      '@current' => $context['sandbox']['progress'],
      '@max' => $context['sandbox']['max'],
    ]);
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      if ($results['dry_run']) {
        $messenger->addMessage(t('Dry run completed. Processed @count entities.', [
          '@count' => $results['processed'],
        ]));
      }
      else {
        $messenger->addMessage(t('Migration completed successfully. Updated @count entities.', [
          '@count' => $results['processed'],
        ]));
      }
    }
    else {
      $messenger->addError(t('Migration finished with errors.'));
    }
  }

  /**
   * Gets count of entities to migrate.
   */
  protected function getEntityCount($entity_type) {
    $storage = $this->entityTypeManager->getStorage($entity_type);

    if ($entity_type === 'taxonomy_term') {
      $query = $storage->getQuery()
        ->condition('vid', ['service_category', 'service_status'], 'IN')
        ->accessCheck(FALSE);
    }
    else {
      $query = $storage->getQuery()->accessCheck(FALSE);
    }

    return $query->count()->execute();
  }

  /**
   * Gets entities for migration in batches.
   */
  protected function getEntitiesForMigration($entity_type, $offset, $limit) {
    $storage = $this->entityTypeManager->getStorage($entity_type);

    if ($entity_type === 'taxonomy_term') {
      $query = $storage->getQuery()
        ->condition('vid', ['service_category', 'service_status'], 'IN')
        ->range($offset, $limit)
        ->accessCheck(FALSE);
    }
    else {
      $query = $storage->getQuery()
        ->range($offset, $limit)
        ->accessCheck(FALSE);
    }

    $ids = $query->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Migrates icons for a single entity.
   */
  protected function migrateEntityIcons($entity, $direction, $target_collection, $dry_run) {
    $icon_fields = $this->getIconFieldMap()[$entity->getEntityTypeId()] ?? [];
    $updated = FALSE;

    foreach ($icon_fields as $field_name) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $current_value = $entity->get($field_name)->value;
      if (empty($current_value)) {
        continue;
      }

      $new_value = $this->convertIcon($current_value, $direction, $target_collection);

      if ($new_value !== $current_value) {
        if (!$dry_run) {
          $entity->set($field_name, $new_value);
          $updated = TRUE;
        }

        $this->loggerFactory->get('markaspot_icons')->info('Icon migration: @old → @new (Entity: @id)', [
          '@old' => $current_value,
          '@new' => $new_value,
          '@id' => $entity->id(),
        ]);
      }
    }

    if ($updated && !$dry_run) {
      $entity->save();
    }
  }

  /**
   * Converts an icon from one format to another.
   *
   * Values that are not FontAwesome classes are never replaced by a
   * collection default, so already migrated icons survive a re-run.
   */
  protected function convertIcon($icon, $direction, $target_collection) {
    if ($direction === 'fa_to_iconify') {
      if ($target_collection === 'lucide') {
        $plan = $this->inspectIconValue((string) $icon);
        return !empty($plan['fixable']) && is_string($plan['replacement']) ? $plan['replacement'] : $icon;
      }
      if (!str_starts_with((string) $icon, 'fa-')) {
        return $icon;
      }
      $mappings = $this->iconMappings[$target_collection] ?? [];
      return $mappings[$icon] ?? $mappings['default'] ?? $icon;
    }
    elseif ($direction === 'iconify_to_fa') {
      // Reverse conversion: find FA icon that maps to this iconify icon.
      $mappings = $this->iconMappings[$target_collection] ?? [];
      $reversed = array_flip($mappings);
      return $reversed[$icon] ?? $icon;
    }

    return $icon;
  }

}
