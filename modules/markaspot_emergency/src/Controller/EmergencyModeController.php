<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\markaspot_emergency\Support\LiteCategoryCompatibility;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP controller for jurisdiction-scoped emergency mode status.
 */
class EmergencyModeController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    AccountInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
    EmergencyModeService $emergencyService,
    EntityRepositoryInterface $entityRepository,
  ) {
    $this->configFactory = $configFactory;
    $this->currentUser = $currentUser;
    $this->logger = $loggerFactory->get('markaspot_emergency');
    $this->emergencyService = $emergencyService;
    $this->entityRepository = $entityRepository;
  }

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Emergency mode service.
   *
   * @var \Drupal\markaspot_emergency\Service\EmergencyModeService
   */
  protected EmergencyModeService $emergencyService;

  /**
   * Entity translation resolver.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('logger.factory'),
      $container->get('markaspot_emergency.service'),
      $container->get('entity.repository'),
    );
  }

  /**
   * Returns the canonical status payload for one root jurisdiction.
   */
  public function getStatus(Request $request): JsonResponse {
    try {
      $rootId = $this->emergencyService->resolveRootJurisdictionId(
        $this->getQueryJurisdiction($request),
      );
    }
    catch (\InvalidArgumentException $exception) {
      $response = new JsonResponse(['error' => $exception->getMessage()], 400);
      $response->headers->set('Cache-Control', 'no-store');
      return $response;
    }

    $config = $this->configFactory->get('markaspot_emergency.settings');
    try {
      $state = $this->emergencyService->getModeState($rootId);
      $policy = $this->emergencyService->getPolicy($rootId);
    }
    catch (\RuntimeException $exception) {
      $this->logger->error('Emergency runtime State is ambiguous: @message', [
        '@message' => $exception->getMessage(),
      ]);
      $response = new JsonResponse(['error' => 'Emergency runtime State is unavailable.'], 503);
      $response->headers->set('Cache-Control', 'no-store');
      return $response;
    }
    $active = $state['status'] === 'active';
    try {
      $availableCategories = array_map(
        fn(TermInterface $term): array => $this->mapCategory($term),
        $this->emergencyService->getAvailableCategoryTerms($rootId),
      );
    }
    catch (\RuntimeException $exception) {
      $this->logger->error('Emergency category scope failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      $response = new JsonResponse(['error' => 'Emergency categories cannot be scoped safely.'], 503);
      $response->headers->set('Cache-Control', 'no-store');
      return $response;
    }
    usort(
      $availableCategories,
      static fn(array $left, array $right): int => $left['weight'] <=> $right['weight']
        ?: strcmp($left['label'], $right['label']),
    );

    $payload = [
      'contract_version' => 2,
      'jurisdiction_id' => $rootId,
      'revision' => $state['revision'],
      'emergency_mode' => $active,
      'status' => $state['status'],
      'mode_type' => $state['mode_type'],
      'lite_ui' => $state['lite_ui'],
      'force_redirect' => $state['force_redirect'],
      'available_categories' => $availableCategories,
      'allowed_urls' => $policy['allowed_urls'],
      'banner' => $this->getBannerData($config, $active, $state['mode_type'], $policy),
    ];

    if ($this->currentUser->hasPermission('view emergency status')) {
      $payload['details'] = [
        'emergency_mode' => [
          'jurisdiction_id' => $state['jurisdiction_id'],
          'status' => $state['status'],
          'activated_at' => $state['activated_at'],
          'mode_type' => $state['mode_type'],
          'force_redirect' => $state['force_redirect'],
          'lite_ui' => $state['lite_ui'],
          'revision' => $state['revision'],
        ],
        'auto_deactivate' => $policy['auto_deactivate'],
        'network_detection' => $policy['network_detection'],
        'maintenance' => $policy['maintenance'],
      ];
      if ($this->currentUser->hasPermission('administer emergency mode')) {
        $payload['details']['emergency_mode']['activated_by'] = $state['activated_by'];
        $payload['details']['restore_queue_count'] = count($state['snapshot']);
      }
    }

    $response = new CacheableJsonResponse($payload);
    $response->headers->set('Cache-Control', 'no-store');
    $cacheability = (new CacheableMetadata())
      ->addCacheTags([
        'config:markaspot_emergency.settings',
        'taxonomy_term_list:service_category',
        EmergencyModeService::CACHE_TAG,
        EmergencyModeService::cacheTag($rootId),
      ])
      ->addCacheContexts([
        'url.query_args:jurisdiction_id',
        'user.permissions',
        'languages:language_content',
        'languages:language_interface',
      ])
      ->setCacheMaxAge(5);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Activates a mode for trusted in-process callers.
   *
   * No route exposes this method. The explicit permission check is retained so
   * a future route cannot accidentally make it public.
   */
  public function activate(Request $request): JsonResponse {
    if (!$this->currentUser->hasPermission('administer emergency mode')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    $data = is_array($data) ? $data : [];
    try {
      $jurisdictionId = $this->validateJurisdictionValue($data['jurisdiction_id'] ?? NULL);
      $rootId = $this->emergencyService->resolveRootJurisdictionId($jurisdictionId);
      $policy = $this->emergencyService->getPolicy($rootId);
      $modeType = (string) ($data['mode_type'] ?? 'disaster');
      $state = $this->emergencyService->activate(
        modeType: $modeType,
        forceRedirect: (bool) ($data['force_redirect'] ?? ($modeType === 'maintenance'
          ? $policy['maintenance']['force_redirect']
          : $policy['force_redirect'])),
        liteUi: (bool) ($data['lite_ui'] ?? $policy['lite_ui']),
        unpublishCategories: (bool) ($data['unpublish_categories'] ?? ($modeType === 'maintenance'
          ? $policy['maintenance']['unpublish_non_selected']
          : $policy['unpublish_regular'])),
        createEmergencyCategories: (bool) ($data['create_emergency_categories'] ?? TRUE),
        jurisdictionId: $rootId,
      );
    }
    catch (\InvalidArgumentException | \LogicException | \RuntimeException $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], 400);
    }

    return new JsonResponse(['status' => 'success', 'emergency_mode' => $state]);
  }

  /**
   * Deactivates a mode for trusted in-process callers.
   */
  public function deactivate(Request $request): JsonResponse {
    if (!$this->currentUser->hasPermission('administer emergency mode')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    $data = is_array($data) ? $data : [];
    try {
      $state = $this->emergencyService->deactivate(
        $this->validateJurisdictionValue($data['jurisdiction_id'] ?? NULL),
      );
    }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], 400);
    }

    return new JsonResponse(['status' => 'success', 'emergency_mode' => $state]);
  }

  /**
   * Renders the lightweight SOS handoff for one jurisdiction.
   */
  public function sosRedirect(Request $request) {
    try {
      $rootId = $this->emergencyService->resolveRootJurisdictionId(
        $this->getQueryJurisdiction($request),
      );
      $isActive = $this->emergencyService->isActive($rootId);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      return $this->redirect('<front>');
    }

    if (!$isActive) {
      return $this->redirect('<front>');
    }

    return [
      '#markup' => '<div class="emergency-sos-active">'
        . '<h1>' . $this->t('Emergency Mode Active') . '</h1>'
        . '<p>' . $this->t('The system is currently in emergency mode. Please use the emergency reporting categories.') . '</p>'
        . '<a href="/" class="button">' . $this->t('Go to Emergency Reporting') . '</a>'
        . '</div>',
      '#attached' => [
        'library' => ['markaspot_emergency/emergency-styles'],
      ],
      '#cache' => [
        'tags' => [
          EmergencyModeService::CACHE_TAG,
          EmergencyModeService::cacheTag($rootId),
        ],
        'contexts' => [
          'languages:language_interface',
          'url.query_args:jurisdiction_id',
        ],
        'max-age' => 5,
      ],
    ];
  }

  /**
   * Returns banner data for the supplied runtime mode.
   */
  public function getBannerData(
    $config,
    bool $emergencyActive,
    ?string $modeType = NULL,
    ?array $policy = NULL,
  ): ?array {
    $bannerConfig = isset($policy['banner']) && is_array($policy['banner'])
      ? $policy['banner']
      : (array) ($config->get('banner') ?: []);
    if (!($bannerConfig['enabled'] ?? FALSE)) {
      return NULL;
    }

    $modeType ??= (string) ($policy['mode_type'] ?? ($config->get('emergency_mode.mode_type') ?: 'disaster'));
    $message = trim((string) ($bannerConfig['message'] ?? ''));
    $conditions = (array) ($bannerConfig['display_conditions'] ?? []);
    $maintenanceMode = $emergencyActive && $modeType === 'maintenance';
    $shouldDisplay = FALSE;

    if ($conditions['always_visible'] ?? FALSE) {
      $shouldDisplay = TRUE;
    }
    elseif (($conditions['emergency_mode_only'] ?? FALSE) && $emergencyActive) {
      $shouldDisplay = TRUE;
    }
    elseif (($conditions['maintenance_mode'] ?? TRUE) && $maintenanceMode) {
      $shouldDisplay = TRUE;
      if ($message === '') {
        $message = trim((string) ($policy['maintenance']['banner_text'] ?? $config->get('maintenance.banner_text') ?? ''));
      }
    }
    elseif ($emergencyActive && !$maintenanceMode) {
      $shouldDisplay = TRUE;
    }

    if (!$shouldDisplay || $message === '') {
      return NULL;
    }

    $level = (string) ($bannerConfig['level'] ?? 'info');
    if ($emergencyActive && $level === 'info') {
      $level = match ($modeType) {
        'disaster' => 'extreme',
        'crisis' => 'severe',
        'maintenance' => 'warning',
        default => 'moderate',
      };
    }

    return [
      'message' => $message,
      'level' => $level,
      'title' => (string) ($bannerConfig['title'] ?? ''),
      'mode_type' => $modeType,
      'emergency_active' => $emergencyActive,
    ];
  }

  /**
   * Maps a category term to the stable public contract.
   */
  private function mapCategory(TermInterface $term): array {
    $translated = $this->entityRepository->getTranslationFromContext($term);
    if ($translated instanceof TermInterface) {
      $term = $translated;
    }

    $color = NULL;
    if ($term->hasField('field_category_hex') && !$term->get('field_category_hex')->isEmpty()) {
      $color = (string) $term->get('field_category_hex')->value;
    }
    elseif ($term->hasField('field_color') && !$term->get('field_color')->isEmpty()) {
      $item = $term->get('field_color')->first();
      $color = $item && $item->get('color')
        ? $item->get('color')->getString()
        : (string) ($item->value ?? '');
    }

    $icon = $term->hasField('field_category_icon') && !$term->get('field_category_icon')->isEmpty()
      ? (string) $term->get('field_category_icon')->value
      : NULL;
    $serviceCode = $term->hasField('field_service_code') && !$term->get('field_service_code')->isEmpty()
      ? (string) $term->get('field_service_code')->value
      : NULL;
    $liteCompatible = LiteCategoryCompatibility::isCompatibleTerm($term);
    $label = $term->label();

    return [
      'id' => (int) $term->id(),
      'uuid' => $term->uuid(),
      'service_code' => $serviceCode,
      'name' => $label,
      'label' => $label,
      'weight' => (int) $term->getWeight(),
      'color' => $color,
      'icon' => $icon,
      'lite_compatible' => $liteCompatible,
    ];
  }

  /**
   * Returns a scalar jurisdiction query parameter or rejects malformed input.
   */
  private function getQueryJurisdiction(Request $request): string|int|null {
    $parameters = $request->query->all();
    return $this->validateJurisdictionValue($parameters['jurisdiction_id'] ?? NULL);
  }

  /**
   * Validates an external jurisdiction identifier before strict service calls.
   */
  private function validateJurisdictionValue(mixed $value): string|int|null {
    if ($value === NULL || is_string($value) || is_int($value)) {
      return $value;
    }
    throw new \InvalidArgumentException('The jurisdiction_id parameter must be a scalar ID or slug.');
  }

}
