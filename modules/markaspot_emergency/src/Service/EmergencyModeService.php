<?php

namespace Drupal\markaspot_emergency\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;

/**
 * Service for managing emergency mode state and category handling.
 */
class EmergencyModeService {

  /**
   * State key for the emergency status.
   */
  const STATE_STATUS = 'markaspot_emergency.status';

  /**
   * State key for activated_at timestamp.
   */
  const STATE_ACTIVATED_AT = 'markaspot_emergency.activated_at';

  /**
   * State key for activated_by user ID.
   */
  const STATE_ACTIVATED_BY = 'markaspot_emergency.activated_by';

  /**
   * Cache tag invalidated on every status change.
   */
  const CACHE_TAG = 'markaspot_emergency:status';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

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
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new EmergencyModeService.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    AccountInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('markaspot_emergency');
  }

  /**
   * Returns whether emergency mode is currently active.
   */
  public function isActive(): bool {
    return $this->getStatus() === 'active';
  }

  /**
   * Returns the current status string (off / active).
   */
  public function getStatus(): string {
    return (string) ($this->state->get(self::STATE_STATUS, 'off'));
  }

  /**
   * Returns the timestamp at which emergency mode was activated, or NULL.
   */
  public function getActivatedAt(): ?int {
    $value = $this->state->get(self::STATE_ACTIVATED_AT);
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * Returns the UID of the user who activated emergency mode, or NULL.
   */
  public function getActivatedBy(): ?int {
    $value = $this->state->get(self::STATE_ACTIVATED_BY);
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * Activates emergency mode.
   *
   * @param string $modeType
   *   The mode type: 'disaster', 'crisis', or 'maintenance'.
   * @param bool $forceRedirect
   *   Whether to force-redirect users to the lite UI.
   * @param bool $liteUi
   *   Whether to enable the lite UI.
   * @param bool $unpublishCategories
   *   Whether to unpublish regular categories.
   * @param bool $createEmergencyCategories
   *   Whether to create/publish emergency preset categories.
   * @param int|null $jurisdictionId
   *   Optional jurisdiction scope.
   */
  public function activate(
    string $modeType = 'disaster',
    bool $forceRedirect = TRUE,
    bool $liteUi = TRUE,
    bool $unpublishCategories = TRUE,
    bool $createEmergencyCategories = TRUE,
    ?int $jurisdictionId = NULL,
  ): void {
    $config = $this->configFactory->getEditable('markaspot_emergency.settings');

    // Write runtime state to State API.
    $this->state->set(self::STATE_STATUS, 'active');
    $this->state->set(self::STATE_ACTIVATED_AT, time());
    $this->state->set(self::STATE_ACTIVATED_BY, (int) $this->currentUser->id());

    // Write mode type and display options to config (policy, not state).
    $config
      ->set('emergency_mode.mode_type', $modeType)
      ->set('emergency_mode.force_redirect', $forceRedirect)
      ->set('emergency_mode.lite_ui', $liteUi)
      ->save();

    $stateKey = $this->getSnapshotKey($jurisdictionId);

    if ($modeType === 'maintenance') {
      $keepTids = (array) ($config->get('maintenance.show_only_categories') ?: []);
      $hideOthers = (bool) $config->get('maintenance.unpublish_non_selected');

      if ($hideOthers) {
        if (empty($this->state->get($stateKey))) {
          $this->state->set($stateKey, $this->getAllPublishedTermIds($jurisdictionId));
        }
        $this->unpublishNonSelectedCategories($keepTids, $jurisdictionId);
      }

      if (!empty($keepTids)) {
        $this->publishSelectedCategories($keepTids);
      }
    }
    else {
      if ($unpublishCategories) {
        if (empty($this->state->get($stateKey))) {
          $this->state->set($stateKey, $this->getRegularPublishedTermIds($jurisdictionId));
        }
        $this->unpublishRegularCategories($jurisdictionId);
      }

      if ($createEmergencyCategories) {
        $this->createEmergencyCategories($jurisdictionId);
      }
    }

    Cache::invalidateTags([self::CACHE_TAG]);

    $this->logger->notice('Emergency mode activated (type: @type) by user @user (UID: @uid).', [
      '@type' => $modeType,
      '@user' => $this->currentUser->getDisplayName(),
      '@uid' => $this->currentUser->id(),
    ]);
  }

  /**
   * Deactivates emergency mode.
   *
   * @param bool $restoreCategories
   *   Whether to restore previously snapshotted categories.
   * @param int|null $jurisdictionId
   *   Optional jurisdiction scope.
   */
  public function deactivate(
    bool $restoreCategories = TRUE,
    ?int $jurisdictionId = NULL,
  ): void {
    $config = $this->configFactory->get('markaspot_emergency.settings');
    $lastModeType = (string) $config->get('emergency_mode.mode_type');

    // Clear runtime state.
    $this->state->delete(self::STATE_STATUS);
    $this->state->delete(self::STATE_ACTIVATED_AT);
    $this->state->delete(self::STATE_ACTIVATED_BY);

    if ($restoreCategories) {
      if ($lastModeType !== 'maintenance') {
        $this->unpublishEmergencyCategories($jurisdictionId);
      }
      $this->restoreRegularCategories($jurisdictionId);
    }

    Cache::invalidateTags([self::CACHE_TAG]);

    $this->logger->notice('Emergency mode deactivated by user @user (UID: @uid).', [
      '@user' => $this->currentUser->getDisplayName(),
      '@uid' => $this->currentUser->id(),
    ]);
  }

  /**
   * Checks whether the field_jurisdiction field exists on service_category.
   *
   * Used to decide whether jurisdiction scoping is available.
   */
  public function hasJurisdictionField(): bool {
    $fields = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'service_category');
    return isset($fields['field_jurisdiction']);
  }

  /**
   * Gets the jurisdiction-scoped state key for the TID snapshot.
   */
  public function getSnapshotKey(?int $jurisdictionId = NULL): string {
    $key = 'markaspot_emergency.original_published_tids';
    if ($jurisdictionId) {
      $key .= '.' . $jurisdictionId;
    }
    return $key;
  }

  /**
   * Unpublishes all regular (non-emergency) categories.
   */
  protected function unpublishRegularCategories(?int $jurisdictionId = NULL): void {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $this->getRegularPublishedTermIds($jurisdictionId);
    if (!empty($tids)) {
      $terms = $storage->loadMultiple($tids);
      foreach ($terms as $term) {
        $term->set('status', 0);
        $term->save();
      }
      $this->logger->info('Unpublished @count regular categories.', ['@count' => count($terms)]);
    }
  }

  /**
   * Restores regular categories from the State snapshot.
   */
  public function restoreRegularCategories(?int $jurisdictionId = NULL): void {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $key = $this->getSnapshotKey($jurisdictionId);
    $tids = $this->state->get($key, []);
    if (!empty($tids)) {
      $terms = $storage->loadMultiple($tids);
      foreach ($terms as $term) {
        if ($term) {
          $term->set('status', 1);
          $term->save();
        }
      }
      $this->logger->info('Restored @count categories from snapshot.', ['@count' => count($terms)]);
      $this->state->delete($key);
    }
  }

  /**
   * Unpublishes all emergency categories.
   */
  protected function unpublishEmergencyCategories(?int $jurisdictionId = NULL): void {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('field_emergency_category', TRUE)
      ->condition('status', 1)
      ->accessCheck(FALSE);

    if ($jurisdictionId) {
      $query->condition('field_jurisdiction', $jurisdictionId);
    }

    $tids = $query->execute();
    if (!empty($tids)) {
      $terms = $storage->loadMultiple($tids);
      foreach ($terms as $term) {
        $term->set('status', 0);
        $term->save();
      }
      $this->logger->info('Unpublished @count emergency categories.', ['@count' => count($terms)]);
    }
  }

  /**
   * Publishes the selected TIDs.
   */
  protected function publishSelectedCategories(array $tids): void {
    $tids = array_values(array_filter(array_map('intval', $tids)));
    if (!$tids) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadMultiple($tids);
    foreach ($terms as $term) {
      if ($term->get('vid')->value !== 'service_category') {
        continue;
      }
      if (!$term->isPublished()) {
        $term->set('status', 1);
        $term->save();
      }
    }
  }

  /**
   * Unpublishes all published categories except the provided TIDs.
   */
  protected function unpublishNonSelectedCategories(array $keepTids, ?int $jurisdictionId = NULL): void {
    $keep = array_values(array_unique(array_filter(array_map('intval', $keepTids))));
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    if ($jurisdictionId) {
      $query->condition('field_jurisdiction', $jurisdictionId);
    }
    if ($keep) {
      $query->condition('tid', $keep, 'NOT IN');
    }
    $tounpublish = $query->execute();
    if (!empty($tounpublish)) {
      $terms = $storage->loadMultiple($tounpublish);
      foreach ($terms as $term) {
        $term->set('status', 0);
        $term->save();
      }
      $this->logger->info('Unpublished @count non-selected maintenance categories.', ['@count' => count($terms)]);
    }
  }

  /**
   * Returns IDs of currently published regular (non-emergency) categories.
   */
  protected function getRegularPublishedTermIds(?int $jurisdictionId = NULL): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    if ($jurisdictionId) {
      $query->condition('field_jurisdiction', $jurisdictionId);
    }

    $regular = $query->orConditionGroup()
      ->notExists('field_emergency_category')
      ->condition('field_emergency_category', FALSE);
    $query->condition($regular);

    return array_values($query->execute());
  }

  /**
   * Returns IDs of all currently published categories.
   */
  protected function getAllPublishedTermIds(?int $jurisdictionId = NULL): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'service_category')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    if ($jurisdictionId) {
      $query->condition('field_jurisdiction', $jurisdictionId);
    }
    return array_values($query->execute());
  }

  /**
   * Creates or publishes emergency categories from config presets.
   */
  protected function createEmergencyCategories(?int $jurisdictionId = NULL): void {
    $config = $this->configFactory->get('markaspot_emergency.settings');
    $presets = $config->get('categories.emergency_presets');

    if (empty($presets)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $createdCount = 0;
    $publishedCount = 0;

    foreach ($presets as $preset) {
      $existProps = [
        'vid' => 'service_category',
        'name' => $preset['name'],
        'field_emergency_category' => TRUE,
      ];
      if ($jurisdictionId) {
        $existProps['field_jurisdiction'] = $jurisdictionId;
      }
      $existing = $storage->loadByProperties($existProps);

      if (!empty($existing)) {
        $term = reset($existing);
        $term->set('status', 1);
        if ($term->hasField('field_category_icon') && !empty($preset['icon'])) {
          $term->set('field_category_icon', $preset['icon']);
        }
        if ($term->hasField('field_category_hex') && !empty($preset['color'])) {
          $term->set('field_category_hex', $preset['color']);
        }
        $term->save();
        $publishedCount++;
      }
      else {
        $values = [
          'vid' => 'service_category',
          'name' => $preset['name'],
          'status' => 1,
          'weight' => $preset['weight'] ?? 0,
          'field_emergency_category' => TRUE,
        ];
        if ($jurisdictionId) {
          $values['field_jurisdiction'] = $jurisdictionId;
        }
        $term = $storage->create($values);
        if ($term->hasField('field_category_icon') && !empty($preset['icon'])) {
          $term->set('field_category_icon', $preset['icon']);
        }
        if ($term->hasField('field_category_hex') && !empty($preset['color'])) {
          $term->set('field_category_hex', $preset['color']);
        }
        $term->save();
        $createdCount++;
      }
    }

    if ($createdCount > 0) {
      $this->logger->info('Created @count new emergency categories.', ['@count' => $createdCount]);
    }
    if ($publishedCount > 0) {
      $this->logger->info('Published @count existing emergency categories.', ['@count' => $publishedCount]);
    }
  }

}
