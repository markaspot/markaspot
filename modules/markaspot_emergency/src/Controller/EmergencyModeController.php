<?php

namespace Drupal\markaspot_emergency\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for emergency mode operations.
 */
class EmergencyModeController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The emergency mode service.
   *
   * @var \Drupal\markaspot_emergency\Service\EmergencyModeService
   */
  protected EmergencyModeService $emergencyService;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Constructs a new EmergencyModeController object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    EmergencyModeService $emergency_service,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('markaspot_emergency');
    $this->emergencyService = $emergency_service;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('logger.factory'),
      $container->get('markaspot_emergency.service'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * Get emergency mode status.
   *
   * Responds with the canonical emergency status payload. The `details`
   * subtree is only included when the caller has 'view emergency status'.
   * Response is cacheable with a 30-second max-age; status changes invalidate
   * the custom cache tag 'markaspot_emergency:status'.
   */
  public function getStatus(?Request $request = NULL) {
    $config = $this->configFactory->get('markaspot_emergency.settings');

    $status = $this->emergencyService->getStatus();
    $active = $this->emergencyService->isActive();
    $modeType = (string) $config->get('emergency_mode.mode_type');

    // Jurisdiction filter: only apply if the field exists on the bundle (B5).
    $jurisdictionId = NULL;
    if ($request && $this->emergencyService->hasJurisdictionField()) {
      $raw = $request->query->get('jurisdiction_id');
      if ($raw !== NULL) {
        $jurisdictionId = (int) $raw;
      }
    }

    // Build available-categories list.
    $available_categories = $this->buildAvailableCategories($active, $jurisdictionId);

    // Banner data.
    $banner = $this->getBannerData($config, $active);

    // Determine force_redirect from the config key matching the mode type.
    // Maintenance has its own toggle; other modes use emergency_mode key.
    if ($modeType === 'maintenance') {
      $forceRedirect = (bool) $config->get('maintenance.force_redirect');
    }
    else {
      $forceRedirect = (bool) $config->get('emergency_mode.force_redirect');
    }

    $payload = [
      'emergency_mode' => $active,
      'status' => $status,
      'mode_type' => $modeType,
      'lite_ui' => (bool) $config->get('emergency_mode.lite_ui'),
      'force_redirect' => $forceRedirect,
      'available_categories' => $available_categories,
      'allowed_urls' => (array) ($config->get('allowed_urls') ?: []),
      'banner' => $banner,
    ];

    // Details subtree: only for callers with the view permission (H5).
    if ($this->currentUser->hasPermission('view emergency status')) {
      $activatedAt = $this->emergencyService->getActivatedAt();
      $activatedBy = $this->emergencyService->getActivatedBy();

      $payload['details'] = [
        'emergency_mode' => [
          'status' => $status,
          'mode_type' => $modeType,
          // Use the already-resolved $forceRedirect (mode-type-aware).
          'force_redirect' => $forceRedirect,
          'lite_ui' => (bool) $config->get('emergency_mode.lite_ui'),
          'activated_at' => $activatedAt,
          'activated_by' => $activatedBy,
        ],
        'auto_deactivate' => [
          'enabled' => (bool) $config->get('auto_deactivate.enabled'),
          'duration' => (int) ($config->get('auto_deactivate.duration') ?? 72),
        ],
        'network_detection' => [
          'enabled' => (bool) $config->get('network_detection.enabled'),
          'auto_switch_threshold' => (string) ($config->get('network_detection.auto_switch_threshold') ?? '2g'),
        ],
        'maintenance' => [
          'force_redirect' => (bool) $config->get('maintenance.force_redirect'),
          'banner_text' => (string) ($config->get('maintenance.banner_text') ?: ''),
        ],
      ];

      // Expose restore queue size for operations dashboards.
      if ($this->currentUser->hasPermission('administer emergency mode')) {
        $snapshotKey = $this->emergencyService->getSnapshotKey($jurisdictionId);
        $snapshot = $this->state->get($snapshotKey, []);
        $payload['details']['restore_queue_count'] = is_array($snapshot) ? count($snapshot) : 0;
      }
    }

    // Build cacheable response.
    $response = new CacheableJsonResponse($payload);
    $response->headers->set('Cache-Control', 'no-store');

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([
      'config:markaspot_emergency.settings',
      'taxonomy_term_list:service_category',
      EmergencyModeService::CACHE_TAG,
    ]);
    $cacheability->addCacheContexts([
      'url.query_args:jurisdiction_id',
      'user.permissions',
    ]);
    // 5s TTL as a fallback only. Tag-based invalidation
    // (markaspot_emergency:status) is the primary freshness guarantee and fires
    // immediately when emergency state changes. The short TTL is a safety net
    // in case cache invalidation is delayed or bypassed.
    $cacheability->setCacheMaxAge(5);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Activate emergency mode via HTTP.
   */
  public function activate(?Request $request = NULL) {
    $data = $request ? json_decode($request->getContent(), TRUE) ?? [] : [];

    if ($request !== NULL && !$this->currentUser->hasPermission('administer emergency mode')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $jurisdictionId = isset($data['jurisdiction_id']) ? (int) $data['jurisdiction_id'] : NULL;
    $config = $this->configFactory->get('markaspot_emergency.settings');

    $this->emergencyService->activate(
      $data['mode_type'] ?? 'disaster',
      (bool) ($data['force_redirect'] ?? TRUE),
      (bool) ($data['lite_ui'] ?? TRUE),
      (bool) ($data['unpublish_categories'] ?? $config->get('categories.unpublish_regular')),
      (bool) ($data['create_emergency_categories'] ?? TRUE),
      $jurisdictionId
    );

    return new JsonResponse([
      'status' => 'success',
      'message' => 'Emergency mode activated',
      'emergency_mode' => [
        'status' => 'active',
        'activated_at' => $this->emergencyService->getActivatedAt(),
        'activated_by' => $this->emergencyService->getActivatedBy(),
      ],
    ]);
  }

  /**
   * Deactivate emergency mode via HTTP.
   */
  public function deactivate(?Request $request = NULL) {
    $data = $request ? json_decode($request->getContent(), TRUE) ?? [] : [];

    if ($request !== NULL && !$this->currentUser->hasPermission('administer emergency mode')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $jurisdictionId = isset($data['jurisdiction_id']) ? (int) $data['jurisdiction_id'] : NULL;
    $config = $this->configFactory->get('markaspot_emergency.settings');
    $restoreCategories = (bool) ($data['restore_categories'] ?? $config->get('categories.restore_on_deactivation'));

    $this->emergencyService->deactivate($restoreCategories, $jurisdictionId);

    return new JsonResponse([
      'status' => 'success',
      'message' => 'Emergency mode deactivated',
      'emergency_mode' => ['status' => 'off'],
    ]);
  }

  /**
   * SOS redirect handler for emergency access.
   */
  public function sosRedirect() {
    if ($this->emergencyService->isActive()) {
      return [
        '#markup' => '<div class="emergency-sos-active">'
          . '<h1>' . $this->t('Emergency Mode Active') . '</h1>'
          . '<p>' . $this->t('The system is currently in emergency mode. Please use the emergency reporting categories.') . '</p>'
          . '<a href="/" class="button">' . $this->t('Go to Emergency Reporting') . '</a>'
          . '</div>',
        '#attached' => [
          'library' => ['markaspot_emergency/emergency-styles'],
        ],
      ];
    }

    return $this->redirect('<front>');
  }

  /**
   * Builds the available-categories list for the status response.
   */
  protected function buildAvailableCategories(bool $active, ?int $jurisdictionId = NULL): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    // Jurisdiction scoping: only add the condition when the field exists.
    if ($jurisdictionId && $this->emergencyService->hasJurisdictionField()) {
      $query->condition('field_jurisdiction', $jurisdictionId);
    }

    if ($active) {
      $query->condition('field_emergency_category', TRUE);
    }
    else {
      $group = $query->orConditionGroup()
        ->notExists('field_emergency_category')
        ->condition('field_emergency_category', FALSE);
      $query->condition($group);
    }

    $tids = $query->execute();
    $available_categories = [];

    if (!empty($tids)) {
      $terms = $storage->loadMultiple($tids);
      foreach ($terms as $term) {
        $color = NULL;
        $icon = NULL;

        if ($term->hasField('field_category_hex') && !$term->get('field_category_hex')->isEmpty()) {
          $color = (string) $term->get('field_category_hex')->value;
        }
        elseif ($term->hasField('field_color') && !$term->get('field_color')->isEmpty()) {
          $item = $term->get('field_color')->first();
          if ($item && $item->get('color')) {
            $color = $item->get('color')->getString();
          }
          elseif ($item && isset($item->value)) {
            $color = (string) $item->value;
          }
        }

        if ($term->hasField('field_category_icon') && !$term->get('field_category_icon')->isEmpty()) {
          $icon = (string) $term->get('field_category_icon')->value;
        }

        $available_categories[] = [
          'id' => (int) $term->id(),
          'name' => $term->label(),
          'weight' => (int) $term->get('weight')->value,
          'color' => $color,
          'icon' => $icon,
        ];
      }

      usort($available_categories, fn($a, $b) => $a['weight'] <=> $b['weight'] ?: strcmp($a['name'], $b['name']));
    }

    return $available_categories;
  }

  /**
   * Get banner data based on configuration and current mode.
   */
  public function getBannerData($config, bool $emergency_active): ?array {
    $banner_config = $config->get('banner') ?: [];

    if (!($banner_config['enabled'] ?? FALSE)) {
      return NULL;
    }

    $message = trim($banner_config['message'] ?? '');
    $conditions = $banner_config['display_conditions'] ?? [];
    $mode_type = (string) $config->get('emergency_mode.mode_type');
    $maintenance_mode = $mode_type === 'maintenance';

    $should_display = FALSE;

    if ($conditions['always_visible'] ?? FALSE) {
      $should_display = TRUE;
    }
    elseif (($conditions['emergency_mode_only'] ?? FALSE) && $emergency_active) {
      $should_display = TRUE;
    }
    elseif (($conditions['maintenance_mode'] ?? TRUE) && $maintenance_mode) {
      $should_display = TRUE;
      // Fall back to maintenance.banner_text when the banner message is empty.
      if (empty($message)) {
        $message = trim($config->get('maintenance.banner_text') ?? '');
      }
    }
    elseif ($emergency_active && !$maintenance_mode) {
      $should_display = TRUE;
    }

    // Both banner message and maintenance fallback are empty: nothing to show.
    if (empty($message)) {
      return NULL;
    }

    if (!$should_display) {
      return NULL;
    }

    $level = $banner_config['level'] ?? 'info';
    if ($emergency_active && $level === 'info') {
      switch ($mode_type) {
        case 'disaster':
          $level = 'extreme';
          break;

        case 'crisis':
          $level = 'severe';
          break;

        case 'maintenance':
          $level = 'warning';
          break;

        default:
          $level = 'moderate';
          break;
      }
    }

    return [
      'message' => $message,
      'level' => $level,
      'title' => $banner_config['title'] ?? '',
      'mode_type' => $mode_type,
      'emergency_active' => $emergency_active,
    ];
  }

}
