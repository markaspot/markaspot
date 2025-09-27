<?php

namespace Drupal\markaspot_emergency\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

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
   * Constructs a new EmergencyModeController object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('markaspot_emergency');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('logger.factory')
    );
  }

  /**
   * Get emergency mode status.
   */
  public function getStatus() {
    $config = $this->configFactory->get('markaspot_emergency.settings');

    $status = (string) $config->get('emergency_mode.status');
    $active = $status === 'active';

    // Build list of currently available categories for the UI.
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    if ($active) {
      // Emergency mode: only emergency categories.
      $query->condition('field_emergency_category', TRUE);
    }
    else {
      // Normal mode: non-emergency categories (field false or missing).
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
          // Color field stores 'color' property; fall back to value if present.
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
        elseif ($term->hasField('field_icon') && !$term->get('field_icon')->isEmpty()) {
          $icon = (string) $term->get('field_icon')->value;
        }

        $available_categories[] = [
          'id' => (int) $term->id(),
          'name' => $term->label(),
          'weight' => (int) $term->get('weight')->value,
          'color' => $color,
          'icon' => $icon,
        ];
      }
      // Keep deterministic order for UI.
      usort($available_categories, fn($a, $b) => $a['weight'] <=> $b['weight'] ?: strcmp($a['name'], $b['name']));
    }

    $payload = [
      // Simple flags for frontend consumption.
      'emergency_mode' => $active,
      'status' => $status,
      'mode_type' => (string) $config->get('emergency_mode.mode_type'),
      'lite_ui' => (bool) $config->get('emergency_mode.lite_ui'),
      'available_categories' => $available_categories,

      // Detailed structure for advanced clients.
      'details' => [
        'emergency_mode' => [
          'status' => $status,
          'mode_type' => $config->get('emergency_mode.mode_type'),
          'force_redirect' => $config->get('emergency_mode.force_redirect'),
          'lite_ui' => $config->get('emergency_mode.lite_ui'),
          'activated_at' => $config->get('emergency_mode.activated_at'),
          'activated_by' => $config->get('emergency_mode.activated_by'),
        ],
        'auto_deactivate' => [
          'enabled' => $config->get('auto_deactivate.enabled'),
          'duration' => $config->get('auto_deactivate.duration'),
        ],
        'network_detection' => [
          'enabled' => $config->get('network_detection.enabled'),
          'auto_switch_threshold' => $config->get('network_detection.auto_switch_threshold'),
        ],
      ],
    ];

    // Admin-only: expose restore queue size to assist operations dashboards.
    if ($this->currentUser->hasPermission('administer emergency mode')) {
      $snapshot = \Drupal::state()->get('markaspot_emergency.original_published_tids', []);
      $payload['details']['restore_queue_count'] = is_array($snapshot) ? count($snapshot) : 0;
    }

    $response = new JsonResponse($payload);

    // Avoid stale frontend status by disabling caching at the HTTP level.
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');

    return $response;
  }

  /**
   * Activate emergency mode.
   */
  public function activate(Request $request) {
    $data = json_decode($request->getContent(), TRUE) ?? [];

    // Check permissions.
    if (!$this->currentUser->hasPermission('administer emergency mode')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $config = $this->configFactory->getEditable('markaspot_emergency.settings');

    // Update configuration.
    $config
      ->set('emergency_mode.status', 'active')
      ->set('emergency_mode.mode_type', $data['mode_type'] ?? 'disaster')
      ->set('emergency_mode.force_redirect', $data['force_redirect'] ?? TRUE)
      ->set('emergency_mode.lite_ui', $data['lite_ui'] ?? TRUE)
      ->set('emergency_mode.activated_at', time())
      ->set('emergency_mode.activated_by', $this->currentUser->id())
      ->save();

    // Snapshot and unpublish regular categories if requested.
    if ($data['unpublish_categories'] ?? $config->get('categories.unpublish_regular')) {
      $state = \Drupal::state();
      $key = 'markaspot_emergency.original_published_tids';
      // Only capture once if not already set.
      if (empty($state->get($key))) {
        $original_tids = $this->getRegularPublishedTermIds();
        $state->set($key, $original_tids);
      }
      $this->unpublishRegularCategories();
    }

    // Create/publish emergency categories.
    if ($data['create_emergency_categories'] ?? TRUE) {
      $this->createEmergencyCategories();
    }

    $this->logger->notice('Emergency mode activated by user @user (ID: @uid)', [
      '@user' => $this->currentUser->getDisplayName(),
      '@uid' => $this->currentUser->id(),
    ]);

    return new JsonResponse([
      'status' => 'success',
      'message' => 'Emergency mode activated',
      'emergency_mode' => [
        'status' => 'active',
        'activated_at' => time(),
        'activated_by' => $this->currentUser->id(),
      ],
    ]);
  }

  /**
   * Deactivate emergency mode.
   */
  public function deactivate(Request $request = NULL) {
    $data = $request ? json_decode($request->getContent(), TRUE) ?? [] : [];

    // Check permissions (only if called via HTTP request).
    if ($request && !$this->currentUser->hasPermission('administer emergency mode')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $config = $this->configFactory->getEditable('markaspot_emergency.settings');

    // Update configuration.
    $config
      ->set('emergency_mode.status', 'off')
      ->set('emergency_mode.activated_at', NULL)
      ->set('emergency_mode.activated_by', NULL)
      ->save();

    // Restore categories if requested.
    if ($data['restore_categories'] ?? $config->get('categories.restore_on_deactivation')) {
      $this->unpublishEmergencyCategories();
      $this->restoreRegularCategories();
    }

    $this->logger->notice('Emergency mode deactivated by user @user (ID: @uid)', [
      '@user' => $this->currentUser->getDisplayName(),
      '@uid' => $this->currentUser->id(),
    ]);

    if ($request) {
      return new JsonResponse([
        'status' => 'success',
        'message' => 'Emergency mode deactivated',
        'emergency_mode' => [
          'status' => 'off',
        ],
      ]);
    }
  }

  /**
   * Unpublish all regular categories (non-emergency).
   */
  protected function unpublishRegularCategories() {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $this->getRegularPublishedTermIds();
    if (!empty($tids)) {
      $terms = $storage->loadMultiple($tids);
      foreach ($terms as $term) {
        $term->set('status', 0);
        $term->save();
      }
      $this->logger->info('Unpublished @count regular categories.', [
        '@count' => count($terms),
      ]);
    }
  }

  /**
   * Restore regular categories to published state from State API.
   */
  protected function restoreRegularCategories() {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $state = \Drupal::state();
    $key = 'markaspot_emergency.original_published_tids';
    $tids = $state->get($key, []);
    if (!empty($tids)) {
      $terms = $storage->loadMultiple($tids);
      foreach ($terms as $term) {
        if ($term) {
          $term->set('status', 1);
          $term->save();
        }
      }
      $this->logger->info('Restored @count regular categories to published status.', [
        '@count' => count($terms),
      ]);
      // Clear snapshot after successful restoration.
      $state->delete($key);
    }
  }

  /**
   * Unpublish all emergency categories.
   */
  protected function unpublishEmergencyCategories() {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('field_emergency_category', TRUE)
      ->condition('status', 1)
      ->accessCheck(FALSE);

    $tids = $query->execute();
    if (!empty($tids)) {
      $terms = $storage->loadMultiple($tids);
      foreach ($terms as $term) {
        $term->set('status', 0);
        $term->save();
      }
      $this->logger->info('Unpublished @count emergency categories.', [
        '@count' => count($terms),
      ]);
    }
  }

  /**
   * Get IDs of currently published regular categories.
   *
   * Regular means terms that either do not have the emergency flag or have it set to FALSE.
   */
  protected function getRegularPublishedTermIds(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    $regular = $query->orConditionGroup()
      ->notExists('field_emergency_category')
      ->condition('field_emergency_category', FALSE);
    $query->condition($regular);

    return array_values($query->execute());
  }

  /**
   * Create or publish emergency categories from presets.
   */
  protected function createEmergencyCategories() {
    $config = $this->configFactory->get('markaspot_emergency.settings');
    $presets = $config->get('categories.emergency_presets');

    if (empty($presets)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $created_count = 0;
    $published_count = 0;

    foreach ($presets as $preset) {
      // Check if emergency category already exists.
      $existing = $storage->loadByProperties([
        'vid' => 'service_category',
        'name' => $preset['name'],
        'field_emergency_category' => TRUE,
      ]);

      if (!empty($existing)) {
        // Category exists, just publish it.
        $term = reset($existing);
        $term->set('status', 1);
        // Update optional metadata if fields exist.
        if ($term->hasField('field_icon') && !empty($preset['icon'])) {
          $term->set('field_icon', $preset['icon']);
        }
        if ($term->hasField('field_category_icon') && !empty($preset['icon'])) {
          $term->set('field_category_icon', $preset['icon']);
        }
        if ($term->hasField('field_color') && !empty($preset['color'])) {
          $term->set('field_color', $preset['color']);
        }
        if ($term->hasField('field_category_hex') && !empty($preset['color'])) {
          $term->set('field_category_hex', $preset['color']);
        }
        $term->save();
        $published_count++;
      }
      else {
        // Create new emergency category.
        $term = $storage->create([
          'vid' => 'service_category',
          'name' => $preset['name'],
          'status' => 1,
          'weight' => $preset['weight'],
          'field_emergency_category' => TRUE,
        ]);
        // Set optional fields if present on the bundle.
        if ($term->hasField('field_icon') && !empty($preset['icon'])) {
          $term->set('field_icon', $preset['icon']);
        }
        if ($term->hasField('field_category_icon') && !empty($preset['icon'])) {
          $term->set('field_category_icon', $preset['icon']);
        }
        if ($term->hasField('field_color') && !empty($preset['color'])) {
          $term->set('field_color', $preset['color']);
        }
        if ($term->hasField('field_category_hex') && !empty($preset['color'])) {
          $term->set('field_category_hex', $preset['color']);
        }
        $term->save();
        $created_count++;
      }
    }

    if ($created_count > 0) {
      $this->logger->info('Created @count new emergency categories.', [
        '@count' => $created_count,
      ]);
    }

    if ($published_count > 0) {
      $this->logger->info('Published @count existing emergency categories.', [
        '@count' => $published_count,
      ]);
    }
  }

  /**
   * SOS redirect handler for emergency access.
   */
  public function sosRedirect() {
    $config = $this->configFactory->get('markaspot_emergency.settings');

    // If emergency mode is active, show emergency UI
    if ($config->get('emergency_mode.status') === 'active') {
      return [
        '#markup' => '<div class="emergency-sos-active">
          <h1>Emergency Mode Active</h1>
          <p>The system is currently in emergency mode. Please use the emergency reporting categories.</p>
          <a href="/" class="button">Go to Emergency Reporting</a>
        </div>',
        '#attached' => [
          'library' => ['markaspot_emergency/emergency-styles'],
        ],
      ];
    }

    // If not active, redirect to normal homepage
    return $this->redirect('<front>');
  }

}
