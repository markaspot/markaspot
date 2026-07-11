<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Service;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_emergency\Support\LiteCategoryCompatibility;
use Drupal\taxonomy\TermInterface;

/**
 * Manages jurisdiction-scoped emergency mode state and category availability.
 */
class EmergencyModeService {

  use JurisdictionIdResolverTrait;

  /**
   * Legacy global state key retained for update/fallback compatibility.
   */
  public const STATE_STATUS = 'markaspot_emergency.status';

  /**
   * Legacy global state key retained for update/fallback compatibility.
   */
  public const STATE_ACTIVATED_AT = 'markaspot_emergency.activated_at';

  /**
   * Legacy global state key retained for update/fallback compatibility.
   */
  public const STATE_ACTIVATED_BY = 'markaspot_emergency.activated_by';

  /**
   * State key prefix for one complete mode record per root jurisdiction.
   */
  public const STATE_PREFIX = 'markaspot_emergency.jurisdiction.';

  /**
   * State key containing root jurisdiction IDs with active mode records.
   */
  public const STATE_ACTIVE_INDEX = 'markaspot_emergency.active_jurisdictions';

  /**
   * State key prefix for operational policy per root jurisdiction.
   */
  public const STATE_POLICY_PREFIX = 'markaspot_emergency.policy.';

  /**
   * Generic compatibility cache tag.
   */
  public const CACHE_TAG = 'markaspot_emergency:status';

  /**
   * Supported mode identifiers.
   */
  public const MODES = ['disaster', 'crisis', 'maintenance'];

  /**
   * Lock lifetime for category/state transitions.
   */
  // A transition saves scoped taxonomy terms one by one so entity hooks keep
  // running. Large catalogs can legitimately take longer than the default
  // lock lifetime; keep enough headroom to prevent overlapping transitions.
  private const LOCK_TTL = 300.0;

  private const MAX_HIERARCHY_DEPTH = 50;

  /**
   * Constructs the emergency mode service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected AccountInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
    protected JurisdictionHierarchyResolverInterface $hierarchyResolver,
    protected LockBackendInterface $lock,
    protected Connection $database,
    protected LanguageManagerInterface $languageManager,
    protected SerializationInterface $serializer,
  ) {
    $this->logger = $loggerFactory->get('markaspot_emergency');
  }

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Resolves a numeric ID or slug to its root jurisdiction ID.
   *
   * With no identifier, legacy single-tenant installs resolve to scope 0 and
   * one-root installs resolve to their only root. Multi-tenant installs fail
   * closed instead of silently applying a global transition.
   *
   * @throws \InvalidArgumentException
   *   When the identifier is invalid or an unscoped multi-tenant request is
   *   attempted.
   */
  public function resolveRootJurisdictionId(
    string|int|null $identifier,
    bool $allowImplicit = TRUE,
  ): int {
    return $this->resolveJurisdictionContext($identifier, $allowImplicit)['root_id'];
  }

  /**
   * Resolves both the requested jurisdiction and its root jurisdiction.
   *
   * @return array{requested_id: int, root_id: int}
   *   Canonical numeric context. Scope 0 represents a legacy installation
   *   without jurisdiction groups.
   */
  public function resolveJurisdictionContext(
    string|int|null $identifier,
    bool $allowImplicit = TRUE,
  ): array {
    $rootIds = $this->getRootJurisdictionIds();
    if ($rootIds === [] && $this->hasJurisdictionGroups()) {
      throw new \InvalidArgumentException('Jurisdiction groups exist, but no valid root jurisdiction can be resolved.');
    }

    // Scope 0 is the explicit sentinel for installations without jurisdiction
    // groups. It must never be accepted when real tenant roots exist.
    if ($identifier === 0 || $identifier === '0') {
      if ($rootIds !== []) {
        throw new \InvalidArgumentException('Jurisdiction 0 is only valid on installations without jurisdiction groups.');
      }
      return ['requested_id' => 0, 'root_id' => 0];
    }

    if ($identifier !== NULL && $identifier !== '') {
      $resolved = $this->resolveJurisdictionId($identifier);
      if ($resolved === NULL && (is_int($identifier) || ctype_digit($identifier))) {
        $resolved = $this->resolveAddressableUnpublishedRoot(
          (int) $identifier,
          $rootIds,
        );
      }
      if ($resolved === NULL) {
        throw new \InvalidArgumentException('Unknown or invalid jurisdiction.');
      }

      $root = $this->hierarchyResolver->getRootJurisdictionId($resolved);
      if ($root === NULL) {
        throw new \InvalidArgumentException('Unable to resolve the root jurisdiction.');
      }
      if (!in_array($root, $rootIds, TRUE)) {
        throw new \InvalidArgumentException('The jurisdiction hierarchy does not resolve to a configured root jurisdiction.');
      }
      return ['requested_id' => $resolved, 'root_id' => $root];
    }

    if (!$allowImplicit) {
      throw new \InvalidArgumentException('The jurisdiction_id parameter is required.');
    }

    if (count($rootIds) > 1) {
      throw new \InvalidArgumentException('The jurisdiction_id parameter is required for multi-tenant installations.');
    }

    $resolved = $rootIds[0] ?? 0;
    return ['requested_id' => $resolved, 'root_id' => $resolved];
  }

  /**
   * Returns root jurisdiction labels for administrative selectors.
   *
   * @return array<int, string>
   *   Options keyed by root jurisdiction ID.
   */
  public function getRootJurisdictionOptions(): array {
    $ids = $this->getRootJurisdictionIds();
    if ($ids === []) {
      return [0 => 'Default site'];
    }

    $groups = $this->entityTypeManager->getStorage('group')->loadMultiple($ids);
    $options = [];
    foreach ($ids as $id) {
      if (isset($groups[$id])) {
        $options[$id] = $groups[$id]->label();
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Returns a complete normalized mode record.
   */
  public function getModeState(string|int|null $jurisdiction = NULL): array {
    $rootId = $this->resolveRootJurisdictionId($jurisdiction);
    return $this->getModeStateByRoot($rootId);
  }

  /**
   * Returns whether emergency mode is active for one root jurisdiction.
   */
  public function isActive(string|int|null $jurisdiction = NULL): bool {
    return $this->getStatus($jurisdiction) === 'active';
  }

  /**
   * Returns the status for one root jurisdiction.
   */
  public function getStatus(string|int|null $jurisdiction = NULL): string {
    return $this->getModeState($jurisdiction)['status'];
  }

  /**
   * Returns the activation timestamp for one root jurisdiction.
   */
  public function getActivatedAt(string|int|null $jurisdiction = NULL): ?int {
    return $this->getModeState($jurisdiction)['activated_at'];
  }

  /**
   * Returns the activating user ID for one root jurisdiction.
   */
  public function getActivatedBy(string|int|null $jurisdiction = NULL): ?int {
    return $this->getModeState($jurisdiction)['activated_by'];
  }

  /**
   * Returns the active mode type for one root jurisdiction.
   */
  public function getModeType(string|int|null $jurisdiction = NULL): string {
    return $this->getModeState($jurisdiction)['mode_type'];
  }

  /**
   * Returns the current mode revision for one root jurisdiction.
   */
  public function getRevision(string|int|null $jurisdiction = NULL): int {
    return $this->getModeState($jurisdiction)['revision'];
  }

  /**
   * Returns normalized operational policy for one root jurisdiction.
   */
  public function getPolicy(string|int|null $jurisdiction = NULL): array {
    $rootId = $this->resolveRootJurisdictionId($jurisdiction);
    return $this->publicPolicy($this->getPolicyByRoot($rootId));
  }

  /**
   * Returns deployable defaults for an admin form without a selected root.
   */
  public function getPolicyDefaults(): array {
    return $this->publicPolicy($this->normalizePolicy(0, $this->getDefaultPolicy(0), FALSE));
  }

  /**
   * Persists operational policy without changing current runtime State.
   *
   * Policy lives in State rather than deployable Config because it is selected
   * per tenant during an incident. The shared root transition lock prevents a
   * save from interleaving with a category/profile transition.
   */
  public function savePolicy(string|int $jurisdiction, array $policy): array {
    $rootId = $this->resolveRootJurisdictionId($jurisdiction, FALSE);
    $lockName = $this->stateKey($rootId);
    if (!$this->lock->acquire($lockName, self::LOCK_TTL)) {
      throw new \RuntimeException('The emergency profile is changing. Reload the settings and try again.');
    }

    try {
      $normalized = $this->normalizePolicy(
        $rootId,
        $this->mergePolicyInput($this->getPolicyByRoot($rootId), $policy),
      );
      $this->state->set($this->policyKey($rootId), $normalized);
    }
    finally {
      $this->lock->release($lockName);
    }

    $this->invalidateStatusCache($rootId);
    return $this->publicPolicy($normalized);
  }

  /**
   * Resolves a citizen submission without reading mutable emergency State.
   *
   * This runs at the request boundary before entity storage opens its SQL
   * transaction. The returned IDs are authoritative input for the final
   * persistence guard and prevent a client-selected root from choosing a lock.
   *
   * @return array{
   *   root_id: int,
   *   expected_revision: int,
   *   expected_status: string,
   *   require_lite_ui: bool,
   *   require_published_category: bool,
   *   require_lite_compatible_category: bool,
   *   category_id: int,
   *   category_jurisdiction_ids: int[],
   *   jurisdiction_id: int|null
   *   }
   *   Normalized persistence context.
   */
  public function prepareSubmissionGuard(
    ?int $expectedRootId,
    ?int $expectedRevision,
    string $categoryUuid,
    ?string $jurisdictionUuid,
    ?string $expectedStatus = NULL,
  ): array {
    if (($expectedRootId === NULL) !== ($expectedRevision === NULL)) {
      throw new \InvalidArgumentException('Emergency root and revision must be supplied together.');
    }
    if ($expectedStatus !== NULL
      && ($expectedRevision === NULL || !in_array($expectedStatus, ['active', 'off'], TRUE))) {
      throw new \InvalidArgumentException('The expected emergency status is invalid.');
    }
    $clientPinned = $expectedRevision !== NULL;
    $pinnedStatus = $clientPinned ? ($expectedStatus ?? 'active') : NULL;

    $categoryStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $categories = $categoryStorage->loadByProperties([
      'uuid' => $categoryUuid,
      'vid' => 'service_category',
    ]);
    $category = reset($categories);
    if (!$category instanceof TermInterface) {
      throw new \InvalidArgumentException('The selected category is unavailable.');
    }

    $categoryRootId = $this->resolveCategoryRoot($category);
    $jurisdictionId = NULL;
    if ($jurisdictionUuid !== NULL) {
      $jurisdictions = $this->entityTypeManager->getStorage('group')
        ->loadByProperties(['uuid' => $jurisdictionUuid]);
      $jurisdiction = reset($jurisdictions);
      if (!$jurisdiction instanceof GroupInterface || !$jurisdiction->isPublished()) {
        throw new \InvalidArgumentException('The selected jurisdiction is unavailable.');
      }
      $jurisdictionId = (int) $jurisdiction->id();
      $context = $this->resolveJurisdictionContext($jurisdictionId, FALSE);
      $resolvedRootId = $context['root_id'];
      if ($categoryRootId !== $resolvedRootId) {
        throw new \InvalidArgumentException('The selected category and jurisdiction belong to different root jurisdictions.');
      }
    }
    else {
      // The existing full frontend intentionally lets the backend choose the
      // most-specific jurisdiction from coordinates during node insert. Its
      // category is already root-scoped, so use that authoritative scope for
      // the pre-save guard without imposing a new JSON:API relationship.
      $resolvedRootId = $categoryRootId;
    }
    if ($expectedRootId !== NULL && $expectedRootId !== $resolvedRootId) {
      throw new \InvalidArgumentException('The submitted emergency root does not match the selected jurisdiction.');
    }
    if (!$clientPinned) {
      $state = $this->getModeStateByRoot($resolvedRootId);
      $expectedRevision = $state['revision'];
      $pinnedStatus = $state['status'];
    }
    if ($expectedRevision === NULL || $pinnedStatus === NULL) {
      throw new \LogicException('The emergency submission snapshot is unavailable.');
    }
    $requireLiteCompatibleCategory = $clientPinned && $pinnedStatus === 'active';
    if ($requireLiteCompatibleCategory
      && !LiteCategoryCompatibility::isCompatibleTerm($category)) {
      throw new \InvalidArgumentException('The selected category requires fields unavailable in the Lite emergency form.');
    }
    $categoryJurisdictionIds = [];
    if ($category->hasField('field_jurisdiction')) {
      foreach ($category->get('field_jurisdiction')->getValue() as $item) {
        $categoryJurisdictionIds[] = (int) ($item['target_id'] ?? 0);
      }
      $categoryJurisdictionIds = array_values(array_unique(array_filter(
        $categoryJurisdictionIds,
        static fn(int $id): bool => $id > 0,
      )));
      sort($categoryJurisdictionIds, SORT_NUMERIC);
    }

    return [
      'root_id' => $resolvedRootId,
      'expected_revision' => $expectedRevision,
      'expected_status' => $pinnedStatus,
      'require_lite_ui' => $clientPinned && $pinnedStatus === 'active',
      'require_published_category' => $clientPinned || $pinnedStatus === 'active',
      'require_lite_compatible_category' => $requireLiteCompatibleCategory,
      'category_id' => (int) $category->id(),
      'category_jurisdiction_ids' => $categoryJurisdictionIds,
      'jurisdiction_id' => $jurisdictionId,
    ];
  }

  /**
   * Locks a prepared citizen submission through its database transaction.
   *
   * Callers must invoke this from generic entity presave, after all node
   * presave work, while the SQL storage transaction is active. Mutable State
   * and publication rows use current locking reads after the root lock is held.
   * The post-transaction callback releases the root only after commit or
   * rollback, including nested saves from node insert hooks.
   *
   * @throws \InvalidArgumentException
   *   When the final entity no longer matches the prepared root scope.
   * @throws \LogicException
   *   When the pinned emergency revision is no longer current.
   * @throws \RuntimeException
   *   When a concurrent transition owns the root or State is unavailable.
   */
  public function acquireSubmissionGuard(
    int $rootId,
    ?int $expectedRevision,
    int $categoryId,
    array $categoryJurisdictionIds,
    ?int $jurisdictionId,
    ?string $expectedStatus = NULL,
    bool $requireLiteUi = FALSE,
    bool $requirePublishedCategory = TRUE,
    bool $requireLiteCompatibleCategory = FALSE,
  ): void {
    if ($rootId < 0 || $categoryId <= 0) {
      throw new \InvalidArgumentException('The prepared emergency submission is invalid.');
    }
    if ($expectedRevision === NULL
      || $expectedStatus === NULL
      || !in_array($expectedStatus, ['active', 'off'], TRUE)) {
      throw new \InvalidArgumentException('The expected emergency status is invalid.');
    }
    $pinnedStatus = $expectedStatus;

    $lockName = $this->stateKey($rootId);
    $gateAcquired = $this->lock->acquire($lockName, self::LOCK_TTL);
    if (!$gateAcquired) {
      $this->lock->wait($lockName, 2);
      $gateAcquired = $this->lock->acquire($lockName, self::LOCK_TTL);
    }
    if (!$gateAcquired) {
      throw new \RuntimeException('The emergency profile is changing. Reload the Lite form and try again.');
    }

    try {
      if ($jurisdictionId !== NULL
        && $this->resolveFreshJurisdictionRoot($jurisdictionId, TRUE) !== $rootId) {
        throw new \InvalidArgumentException('The selected category and jurisdiction belong to different root jurisdictions.');
      }

      $state = $this->getFreshSubmissionState($rootId);
      if ($state['status'] !== $pinnedStatus
        || $state['revision'] !== $expectedRevision
        || ($requireLiteUi && $pinnedStatus === 'active' && !$state['lite_ui'])) {
        throw new \LogicException('The emergency profile changed before the report was saved.');
      }

      if ($requirePublishedCategory && !$this->isFreshlyPublishedCategory($categoryId)) {
        throw new \InvalidArgumentException('The selected category is not published in the selected jurisdiction.');
      }
      if ($requireLiteCompatibleCategory
        && !$this->isFreshlyLiteCompatibleCategory($categoryId)) {
        throw new \InvalidArgumentException('The selected category requires fields unavailable in the Lite emergency form.');
      }
      $freshCategoryJurisdictionIds = $this->getFreshCategoryJurisdictionIds($categoryId);
      sort($categoryJurisdictionIds, SORT_NUMERIC);
      if ($freshCategoryJurisdictionIds !== $categoryJurisdictionIds) {
        throw new \InvalidArgumentException('The selected category jurisdiction changed before the report was saved.');
      }
      foreach ($freshCategoryJurisdictionIds as $categoryJurisdictionId) {
        if ($this->resolveFreshJurisdictionRoot($categoryJurisdictionId) !== $rootId) {
          throw new \InvalidArgumentException('The selected category is outside the prepared root jurisdiction.');
        }
      }

      $this->registerSubmissionLockRelease($lockName);
    }
    catch (\Throwable $exception) {
      $this->lock->release($lockName);
      throw $exception;
    }
  }

  /**
   * Resolves an unmarked full-frontend write from its scoped category.
   */
  private function resolveCategoryRoot(TermInterface $category): int {
    if (!$this->hasJurisdictionField()
      || !$category->hasField('field_jurisdiction')
      || $category->get('field_jurisdiction')->isEmpty()) {
      return $this->resolveRootJurisdictionId(NULL);
    }

    $rootIds = [];
    foreach ($category->get('field_jurisdiction')->getValue() as $item) {
      $jurisdictionId = (int) ($item['target_id'] ?? 0);
      if ($jurisdictionId <= 0) {
        continue;
      }
      $rootIds[] = $this->resolveRootJurisdictionId($jurisdictionId, FALSE);
    }
    $rootIds = array_values(array_unique($rootIds));
    if (count($rootIds) !== 1) {
      throw new \InvalidArgumentException('The selected category does not resolve to exactly one root jurisdiction.');
    }
    return $rootIds[0];
  }

  /**
   * Activates a mode for one root jurisdiction.
   *
   * The exact pre-activation published term set is stored in the same State
   * record. Locking and a database transaction make the state/category switch
   * atomic for database-backed State and taxonomy storage.
   *
   * @return array
   *   The new normalized mode state.
   */
  public function activate(
    string $modeType = 'disaster',
    bool $forceRedirect = TRUE,
    bool $liteUi = TRUE,
    bool $unpublishCategories = TRUE,
    bool $createEmergencyCategories = TRUE,
    string|int|null $jurisdictionId = NULL,
    ?array $policy = NULL,
  ): array {
    if (!in_array($modeType, self::MODES, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unsupported emergency mode "%s".', $modeType));
    }
    if ($forceRedirect && !$liteUi) {
      throw new \InvalidArgumentException('Lite UI must be enabled when force redirect is active.');
    }

    $rootId = $this->resolveRootJurisdictionId($jurisdictionId);
    $indexLockName = self::STATE_ACTIVE_INDEX . '.transition';
    if (!$this->lock->acquire($indexLockName, self::LOCK_TTL)) {
      throw new \RuntimeException('Another emergency mode transition is already in progress.');
    }
    $lockName = self::STATE_PREFIX . $rootId;
    if (!$this->lock->acquire($lockName, self::LOCK_TTL)) {
      $this->lock->release($indexLockName);
      throw new \RuntimeException('Another emergency mode transition is already in progress.');
    }

    $previousRecord = $this->state->get($this->stateKey($rootId));
    $previousPolicyRecord = $this->state->get($this->policyKey($rootId));
    $previousIndex = (array) $this->state->get(self::STATE_ACTIVE_INDEX, []);
    $transaction = NULL;
    $newState = NULL;
    try {
      $transaction = $this->database->startTransaction('markaspot_emergency_activate');
      if ($policy !== NULL) {
        $this->state->set($this->policyKey($rootId), $this->normalizePolicy(
          $rootId,
          $this->mergePolicyInput($this->getPolicyByRoot($rootId), $policy),
        ));
      }
      $current = $this->getModeStateByRoot($rootId);
      $alreadyActive = $current['status'] === 'active';
      // A profile switch must keep the very first pre-activation snapshot so
      // deactivation restores exactly what existed before the incident.
      $snapshot = $alreadyActive
        ? $current['snapshot']
        : $this->getPublishedTermIds($rootId);
      $translationSnapshot = $alreadyActive
        ? $current['translation_snapshot']
        : $this->getTranslationPublicationSnapshot($rootId);
      $translationSnapshotCaptured = $alreadyActive
        ? $current['translation_snapshot_captured']
        : TRUE;
      if ($createEmergencyCategories) {
        $this->ensurePresetCategories($rootId);
      }

      $targetIds = $this->getModeTermIds($rootId, $modeType);
      if ($modeType === 'maintenance') {
        $configuredIds = array_values(array_filter(array_map(
          'intval',
          $this->getMaintenanceCategoryIds($rootId),
        )));
        $configuredIds = $this->filterTermIdsToScope($rootId, $configuredIds);
        $targetIds = array_values(array_unique(array_merge($targetIds, $configuredIds)));
      }

      if (!$unpublishCategories) {
        // A non-restrictive profile is still exact: original terms plus the
        // current target. This removes terms left behind by an older profile.
        $targetIds = array_values(array_unique(array_merge(
          $snapshot,
          $targetIds,
        )));
      }
      $transitionTimestamp = max(time(), (int) $current['changed_at'] + 1);
      if ($translationSnapshotCaptured) {
        $this->applyPublishedSet($rootId, $targetIds);
      }
      else {
        // A legacy incident only changed default translations. Keep profile
        // switches on that same surface until its original snapshot is
        // restored.
        $this->applyDefaultPublishedSet($rootId, $targetIds);
      }

      $newState = [
        'jurisdiction_id' => $rootId,
        'status' => 'active',
        'activated_at' => $alreadyActive ? $current['activated_at'] : $transitionTimestamp,
        'changed_at' => $transitionTimestamp,
        'activated_by' => (int) $this->currentUser->id(),
        'mode_type' => $modeType,
        'force_redirect' => $forceRedirect,
        'lite_ui' => $liteUi,
        'revision' => $current['revision'] + 1,
        'snapshot' => $snapshot,
        'translation_snapshot' => $translationSnapshot,
        'translation_snapshot_captured' => $translationSnapshotCaptured,
      ];
      $this->state->set($this->stateKey($rootId), $newState);
      $this->addActiveJurisdiction($rootId);

      // Committing before logging keeps a logger failure from rolling back the
      // taxonomy transaction after State has been written.
      unset($transaction);
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
        unset($transaction);
      }
      $this->restoreStateAfterFailure($rootId, $previousRecord, $previousIndex);
      if ($policy !== NULL) {
        $this->restorePolicyAfterFailure($rootId, $previousPolicyRecord);
      }
      throw $exception;
    }
    finally {
      $this->lock->release($lockName);
      $this->lock->release($indexLockName);
    }

    assert(is_array($newState));
    $this->invalidateStatusCache($rootId);
    $this->logger->notice('Emergency mode activated or switched for root jurisdiction @jurisdiction (type: @type, revision: @revision) by user @user (UID: @uid).', [
      '@jurisdiction' => $rootId,
      '@type' => $modeType,
      '@revision' => $newState['revision'],
      '@user' => $this->currentUser->getDisplayName(),
      '@uid' => $this->currentUser->id(),
    ]);
    return $newState;
  }

  /**
   * Deactivates a mode and restores the exact pre-activation published set.
   *
   * @return array
   *   The new normalized mode state.
   */
  public function deactivate(string|int|null $jurisdictionId = NULL): array {
    $rootId = $this->resolveRootJurisdictionId($jurisdictionId);
    $indexLockName = self::STATE_ACTIVE_INDEX . '.transition';
    if (!$this->lock->acquire($indexLockName, self::LOCK_TTL)) {
      throw new \RuntimeException('Another emergency mode transition is already in progress.');
    }
    $lockName = self::STATE_PREFIX . $rootId;
    if (!$this->lock->acquire($lockName, self::LOCK_TTL)) {
      $this->lock->release($indexLockName);
      throw new \RuntimeException('Another emergency mode transition is already in progress.');
    }

    $previousRecord = $this->state->get($this->stateKey($rootId));
    $previousIndex = (array) $this->state->get(self::STATE_ACTIVE_INDEX, []);
    $transaction = NULL;
    $newState = NULL;
    try {
      $transaction = $this->database->startTransaction('markaspot_emergency_deactivate');
      $current = $this->getModeStateByRoot($rootId);
      if ($current['status'] !== 'active') {
        unset($transaction);
        return $current;
      }

      if (!$current['translation_snapshot_captured']) {
        // Legacy active records changed only the loaded/default translation.
        // Preserve untouched non-default translations during their restore.
        $this->applyDefaultPublishedSet($rootId, $current['snapshot']);
      }
      else {
        $this->applyPublishedSet($rootId, $current['snapshot']);
        $this->restoreTranslationPublicationSnapshot(
          $rootId,
          $current['translation_snapshot'],
        );
      }
      $newState = [
        'jurisdiction_id' => $rootId,
        'status' => 'off',
        'activated_at' => NULL,
        'changed_at' => max(time(), (int) $current['changed_at'] + 1),
        'activated_by' => NULL,
        'mode_type' => $current['mode_type'],
        'force_redirect' => $current['force_redirect'],
        'lite_ui' => $current['lite_ui'],
        'revision' => $current['revision'] + 1,
        'snapshot' => [],
        'translation_snapshot' => [],
        'translation_snapshot_captured' => FALSE,
      ];
      $this->state->set($this->stateKey($rootId), $newState);
      $this->removeActiveJurisdiction($rootId);

      unset($transaction);
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
        unset($transaction);
      }
      $this->restoreStateAfterFailure($rootId, $previousRecord, $previousIndex);
      throw $exception;
    }
    finally {
      $this->lock->release($lockName);
      $this->lock->release($indexLockName);
    }

    assert(is_array($newState));
    $this->invalidateStatusCache($rootId);
    $this->logger->notice('Emergency mode deactivated for root jurisdiction @jurisdiction (revision: @revision) by user @user (UID: @uid).', [
      '@jurisdiction' => $rootId,
      '@revision' => $newState['revision'],
      '@user' => $this->currentUser->getDisplayName(),
      '@uid' => $this->currentUser->id(),
    ]);
    return $newState;
  }

  /**
   * Returns active root jurisdiction IDs from the maintained State index.
   *
   * @return int[]
   *   Active root jurisdiction IDs, with 0 representing a legacy single site.
   */
  public function getActiveJurisdictionIds(): array {
    $ids = array_values(array_unique(array_filter(array_map(
      'intval',
      (array) $this->state->get(self::STATE_ACTIVE_INDEX, []),
    ), static fn(int $id): bool => $id >= 0)));

    return array_values(array_filter(
      $ids,
      fn(int $id): bool => $this->getModeStateByRoot($id)['status'] === 'active',
    ));
  }

  /**
   * Returns published category terms available for the current mode.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Published service-category terms.
   */
  public function getAvailableCategoryTerms(int $rootId): array {
    $ids = $this->getPublishedTermIds($rootId);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids);
    return array_values(array_filter($terms, fn($term): bool => $term instanceof TermInterface));
  }

  /**
   * Returns category labels scoped to one root jurisdiction tree.
   *
   * @return array<int, string>
   *   Category labels keyed by term ID.
   */
  public function getScopedCategoryOptions(int $rootId): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadMultiple($this->queryTermIds($rootId, NULL));
    $options = [];
    foreach ($terms as $term) {
      if ($term instanceof TermInterface) {
        $options[(int) $term->id()] = $term->label();
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Returns effective modes for a category, including legacy fallback.
   *
   * A populated list field is authoritative. A legacy boolean-only category
   * remains available in both disaster and crisis until the update hook has
   * migrated it.
   *
   * @return string[]
   *   Supported mode identifiers.
   */
  public function getTermModes(TermInterface $term): array {
    if ($term->hasField('field_emergency_modes')) {
      if ($term->get('field_emergency_modes')->isEmpty()) {
        return [];
      }
      return array_values(array_filter(array_map(
        static fn(array $item): string => (string) ($item['value'] ?? ''),
        $term->get('field_emergency_modes')->getValue(),
      ), static fn(string $mode): bool => in_array($mode, self::MODES, TRUE)));
    }

    if ($term->hasField('field_emergency_category')
      && (bool) $term->get('field_emergency_category')->getString()) {
      return ['disaster', 'crisis'];
    }

    return [];
  }

  /**
   * Checks whether service categories can be scoped by jurisdiction.
   */
  public function hasJurisdictionField(): bool {
    $fields = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'service_category');
    return isset($fields['field_jurisdiction']);
  }

  /**
   * Returns the per-root cache tag used by status and CAP responses.
   */
  public static function cacheTag(int $rootId): string {
    return self::CACHE_TAG . ':' . $rootId;
  }

  /**
   * Returns the State key for one root jurisdiction.
   */
  public function getSnapshotKey(?int $jurisdictionId = NULL): string {
    return $this->stateKey($jurisdictionId ?? 0);
  }

  /**
   * Returns a normalized state record without resolving the supplied root ID.
   */
  private function getModeStateByRoot(int $rootId): array {
    $stored = $this->state->get($this->stateKey($rootId));

    // Single-site compatibility until update hooks have migrated old State.
    if (!is_array($stored)) {
      $legacyStatus = (string) $this->state->get(self::STATE_STATUS, 'off');
      if ($legacyStatus === 'active') {
        $rootIds = $this->getRootJurisdictionIds();
        $isUnambiguousRoot = ($rootId === 0 && $rootIds === [])
          || (count($rootIds) === 1 && $rootIds[0] === $rootId);
        if (!$isUnambiguousRoot) {
          throw new \RuntimeException('Legacy global emergency State cannot be assigned safely before database updates complete.');
        }
        $snapshotPrefix = 'markaspot_emergency.original_published_tids';
        $sentinel = new \stdClass();
        $exactSnapshot = $this->state->get($snapshotPrefix . '.' . $rootId, $sentinel);
        $globalSnapshot = $this->state->get($snapshotPrefix, $sentinel);
        $exactSnapshot = is_array($exactSnapshot)
          ? $this->normalizePublishedIds($exactSnapshot)
          : NULL;
        $globalSnapshot = is_array($globalSnapshot)
          ? $this->normalizePublishedIds($globalSnapshot)
          : NULL;
        if ($exactSnapshot !== NULL
          && $globalSnapshot !== NULL
          && $exactSnapshot !== $globalSnapshot) {
          throw new \RuntimeException('Legacy emergency snapshots disagree before database updates complete.');
        }
        $legacySnapshot = $exactSnapshot ?? $globalSnapshot;
        if ($legacySnapshot === NULL) {
          throw new \RuntimeException('Active legacy emergency State has no restore snapshot.');
        }
        $policy = $this->getPolicyByRoot($rootId);
        $stored = [
          'status' => 'active',
          'activated_at' => $this->state->get(self::STATE_ACTIVATED_AT),
          'changed_at' => $this->state->get(self::STATE_ACTIVATED_AT),
          'activated_by' => $this->state->get(self::STATE_ACTIVATED_BY),
          'mode_type' => $policy['mode_type'],
          'force_redirect' => $policy['force_redirect'],
          'lite_ui' => $policy['lite_ui'],
          'revision' => 1,
          'snapshot' => $legacySnapshot,
          'translation_snapshot' => [],
          'translation_snapshot_captured' => FALSE,
        ];
      }
    }

    $stored = is_array($stored) ? $stored : [];
    $modeType = (string) ($stored['mode_type'] ?? 'disaster');
    if (!in_array($modeType, self::MODES, TRUE)) {
      $modeType = 'disaster';
    }

    $policy = $this->getPolicyByRoot($rootId);
    return [
      'jurisdiction_id' => $rootId,
      'status' => ($stored['status'] ?? 'off') === 'active' ? 'active' : 'off',
      'activated_at' => isset($stored['activated_at']) ? (int) $stored['activated_at'] : NULL,
      'changed_at' => max(0, (int) ($stored['changed_at'] ?? $stored['activated_at'] ?? 0)),
      'activated_by' => isset($stored['activated_by']) ? (int) $stored['activated_by'] : NULL,
      'mode_type' => $modeType,
      'force_redirect' => array_key_exists('force_redirect', $stored)
        ? (bool) $stored['force_redirect']
        : $policy['force_redirect'],
      'lite_ui' => array_key_exists('lite_ui', $stored)
        ? (bool) $stored['lite_ui']
        : $policy['lite_ui'],
      'revision' => max(0, (int) ($stored['revision'] ?? 0)),
      'snapshot' => array_values(array_unique(array_filter(array_map(
        'intval',
        (array) ($stored['snapshot'] ?? []),
      )))),
      'translation_snapshot' => $this->normalizeTranslationPublicationSnapshot(
        $stored['translation_snapshot'] ?? [],
      ),
      'translation_snapshot_captured' => (bool) ($stored['translation_snapshot_captured'] ?? FALSE),
    ];
  }

  /**
   * Reads the submission-critical State with current row-locking semantics.
   *
   * StateInterface is request-cached and a normal select inside the node save
   * transaction can retain an older REPEATABLE READ snapshot. This path runs
   * after the root lock and deliberately reads the key_value row FOR UPDATE.
   *
   * @return array{status: string, revision: int, lite_ui: bool}
   *   Minimal live State used by the persistence guard.
   */
  private function getFreshSubmissionState(int $rootId): array {
    $missing = new \stdClass();
    $stored = $this->readFreshStateValue($this->stateKey($rootId), $missing);
    if ($stored === $missing) {
      $legacyStatus = $this->readFreshStateValue(self::STATE_STATUS, 'off');
      if ($legacyStatus === 'active') {
        throw new \RuntimeException('Legacy emergency State must be migrated before accepting reports.');
      }
      if ($legacyStatus !== 'off') {
        throw new \RuntimeException('Legacy emergency State is invalid.');
      }
      return [
        'status' => 'off',
        'revision' => 0,
        'lite_ui' => FALSE,
      ];
    }
    if (!is_array($stored)
      || !in_array($stored['status'] ?? NULL, ['active', 'off'], TRUE)
      || !is_int($stored['revision'] ?? NULL)
      || $stored['revision'] < 0
      || (($stored['status'] ?? NULL) === 'active'
        && !is_bool($stored['lite_ui'] ?? NULL))) {
      throw new \RuntimeException('Emergency runtime State is invalid.');
    }

    return [
      'status' => $stored['status'],
      'revision' => $stored['revision'],
      'lite_ui' => (bool) ($stored['lite_ui'] ?? FALSE),
    ];
  }

  /**
   * Reads and decodes one State key from the current locked database row.
   */
  private function readFreshStateValue(string $key, mixed $default): mixed {
    $value = $this->database->select('key_value', 'kv')
      ->fields('kv', ['value'])
      ->condition('collection', 'state')
      ->condition('name', $key)
      ->forUpdate()
      ->execute()
      ->fetchField();
    if ($value === FALSE) {
      return $default;
    }
    if (!is_string($value)) {
      throw new \RuntimeException('Emergency runtime State is unreadable.');
    }

    try {
      return $this->serializer->decode($value);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException('Emergency runtime State is unreadable.', 0, $exception);
    }
  }

  /**
   * Checks category publication with a current locking read.
   */
  private function isFreshlyPublishedCategory(int $categoryId): bool {
    $tid = $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid'])
      ->condition('tid', $categoryId)
      ->condition('vid', 'service_category')
      ->condition('status', 1)
      ->range(0, 1)
      ->forUpdate()
      ->execute()
      ->fetchField();
    return $tid !== FALSE && (int) $tid === $categoryId;
  }

  /**
   * Checks the current service definition with a locking read for Lite posts.
   */
  private function isFreshlyLiteCompatibleCategory(int $categoryId): bool {
    if (!$this->hasServiceDefinitionField()) {
      return TRUE;
    }

    $definitions = $this->database
      ->select('taxonomy_term__field_service_definition', 'd')
      ->fields('d', ['field_service_definition_value'])
      ->condition('entity_id', $categoryId)
      ->condition('deleted', 0)
      ->orderBy('delta', 'ASC')
      ->forUpdate()
      ->execute()
      ->fetchCol();
    foreach ($definitions as $definition) {
      if (!is_string($definition)
        || !LiteCategoryCompatibility::isCompatibleDefinition($definition)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Releases the root lock after the surrounding SQL transaction finishes.
   *
   * Drupal 10.2 introduced TransactionManagerInterface while the earlier
   * Connection callback remains available on supported older Drupal 10 cores.
   */
  private function registerSubmissionLockRelease(string $lockName): void {
    $callback = function (bool $success) use ($lockName): void {
      $this->lock->release($lockName);
    };

    if (method_exists($this->database, 'transactionManager')) {
      $manager = $this->database->transactionManager();
      if ($manager !== NULL) {
        $manager->addPostTransactionCallback($callback);
        return;
      }
    }
    if (method_exists($this->database, 'addRootTransactionEndCallback')) {
      call_user_func([$this->database, 'addRootTransactionEndCallback'], $callback);
      return;
    }

    throw new \LogicException('The database transaction callback is unavailable.');
  }

  /**
   * Reads the category jurisdiction targets with current locking semantics.
   *
   * @return int[]
   *   Sorted unique jurisdiction group IDs.
   */
  private function getFreshCategoryJurisdictionIds(int $categoryId): array {
    if (!$this->hasJurisdictionField()) {
      return [];
    }

    $ids = $this->database->select('taxonomy_term__field_jurisdiction', 'j')
      ->fields('j', ['field_jurisdiction_target_id'])
      ->condition('entity_id', $categoryId)
      ->condition('deleted', 0)
      ->orderBy('delta', 'ASC')
      ->forUpdate()
      ->execute()
      ->fetchCol();
    $ids = array_values(array_unique(array_filter(array_map(
      'intval',
      is_array($ids) ? $ids : [],
    ), static fn(int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  /**
   * Resolves one current jurisdiction path with locked hierarchy rows.
   *
   * @param int $groupId
   *   Starting jurisdiction group ID.
   * @param bool $requirePublishedStart
   *   Whether the submitted starting jurisdiction must remain published.
   */
  private function resolveFreshJurisdictionRoot(
    int $groupId,
    bool $requirePublishedStart = FALSE,
  ): ?int {
    if ($groupId <= 0) {
      return NULL;
    }

    $currentId = $groupId;
    $visited = [];
    for ($depth = 0; $depth <= self::MAX_HIERARCHY_DEPTH; $depth++) {
      if (isset($visited[$currentId])) {
        throw new \InvalidArgumentException('The jurisdiction hierarchy is circular.');
      }
      $visited[$currentId] = TRUE;

      $groupQuery = $this->database->select('groups_field_data', 'g')
        ->fields('g', ['id'])
        ->condition('id', $currentId)
        ->condition('type', $this->getJurisdictionGroupType());
      if ($depth === 0 && $requirePublishedStart) {
        $groupQuery->condition('status', 1);
      }
      $exists = $groupQuery
        ->range(0, 1)
        ->forUpdate()
        ->execute()
        ->fetchField();
      if ($exists === FALSE || (int) $exists !== $currentId) {
        return NULL;
      }

      $parentIds = $this->database->select('group__field_parent_jurisdiction', 'p')
        ->fields('p', ['field_parent_jurisdiction_target_id'])
        ->condition('entity_id', $currentId)
        ->condition('deleted', 0)
        ->orderBy('delta', 'ASC')
        ->forUpdate()
        ->execute()
        ->fetchCol();
      $parentIds = array_values(array_unique(array_filter(array_map(
        'intval',
        is_array($parentIds) ? $parentIds : [],
      ), static fn(int $id): bool => $id > 0)));
      if ($parentIds === []) {
        return $currentId;
      }
      if (count($parentIds) !== 1) {
        throw new \InvalidArgumentException('The jurisdiction hierarchy is ambiguous.');
      }
      $currentId = $parentIds[0];
    }

    throw new \InvalidArgumentException('The jurisdiction hierarchy is too deep.');
  }

  /**
   * Normalizes a set of published taxonomy term IDs.
   *
   * @return int[]
   *   Sorted unique positive term IDs.
   */
  private function normalizePublishedIds(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map(
      'intval',
      $ids,
    ), static fn(int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  /**
   * Captures publication status for every translation of scoped terms.
   *
   * @return array<int, array<string, bool>>
   *   Translation publication flags keyed by term ID and language code.
   */
  private function getTranslationPublicationSnapshot(int $rootId): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadMultiple($this->queryTermIds($rootId, NULL));
    $snapshot = [];
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface || !$term->isTranslatable()) {
        continue;
      }
      foreach (array_keys($term->getTranslationLanguages()) as $langcode) {
        if (!$term->hasTranslation($langcode)) {
          continue;
        }
        $snapshot[(int) $term->id()][$langcode] = $term
          ->getTranslation($langcode)
          ->isPublished();
      }
    }
    return $snapshot;
  }

  /**
   * Normalizes a translation publication snapshot loaded from State.
   *
   * @return array<int, array<string, bool>>
   *   Safe term/language publication flags.
   */
  private function normalizeTranslationPublicationSnapshot(mixed $snapshot): array {
    if (!is_array($snapshot)) {
      return [];
    }
    $normalized = [];
    foreach ($snapshot as $termId => $languages) {
      $termId = (int) $termId;
      if ($termId <= 0 || !is_array($languages)) {
        continue;
      }
      foreach ($languages as $langcode => $published) {
        if (is_string($langcode)
          && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $langcode)
          && is_bool($published)) {
          $normalized[$termId][$langcode] = $published;
        }
      }
    }
    return $normalized;
  }

  /**
   * Returns all published term IDs in a root jurisdiction scope.
   */
  private function getPublishedTermIds(int $rootId): array {
    return $this->queryTermIds($rootId, TRUE);
  }

  /**
   * Returns all term IDs assigned to a mode in a root jurisdiction scope.
   */
  private function getModeTermIds(int $rootId, string $modeType): array {
    $ids = $this->queryTermIds($rootId, NULL);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids);
    $matches = [];
    foreach ($terms as $term) {
      if ($term instanceof TermInterface && in_array($modeType, $this->getTermModes($term), TRUE)) {
        $matches[] = (int) $term->id();
      }
    }
    return $matches;
  }

  /**
   * Queries service-category IDs in one root jurisdiction scope.
   */
  private function queryTermIds(int $rootId, ?bool $published): array {
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'service_category')
      ->accessCheck(FALSE);
    if ($published !== NULL) {
      $query->condition('status', $published ? 1 : 0);
    }
    if ($rootId > 0) {
      if (!$this->hasJurisdictionField()) {
        throw new \RuntimeException('Cannot scope emergency categories because field_jurisdiction is missing.');
      }
      $resolvedJurisdictionIds = array_map(
        'intval',
        $this->hierarchyResolver->getTermJurisdictionIds($rootId),
      );
      if ($resolvedJurisdictionIds === []) {
        throw new \RuntimeException('Cannot resolve the jurisdiction tree for emergency categories.');
      }
      $jurisdictionIds = array_values(array_unique(array_merge(
        [$rootId],
        $resolvedJurisdictionIds,
      )));
      $query->condition('field_jurisdiction', $jurisdictionIds, 'IN');
    }
    return array_values(array_map('intval', $query->execute()));
  }

  /**
   * Filters configured TIDs to the selected root jurisdiction tree.
   */
  private function filterTermIdsToScope(int $rootId, array $termIds): array {
    if ($termIds === []) {
      return [];
    }
    $allowed = array_fill_keys($this->queryTermIds($rootId, NULL), TRUE);
    return array_values(array_filter(
      array_values(array_unique(array_map('intval', $termIds))),
      static fn(int $id): bool => isset($allowed[$id]),
    ));
  }

  /**
   * Changes term publication to exactly match the supplied set.
   */
  private function applyPublishedSet(int $rootId, array $publishedIds): void {
    $published = array_fill_keys(array_map('intval', $publishedIds), TRUE);
    $ids = $this->queryTermIds($rootId, NULL);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids);
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $shouldPublish = isset($published[(int) $term->id()]);
      if ($this->setAllTranslationPublication($term, $shouldPublish)) {
        $term->save();
      }
    }
  }

  /**
   * Restores a legacy term-level snapshot without touching other translations.
   */
  private function applyDefaultPublishedSet(int $rootId, array $publishedIds): void {
    $published = array_fill_keys(array_map('intval', $publishedIds), TRUE);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadMultiple($this->queryTermIds($rootId, NULL));
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $shouldPublish = isset($published[(int) $term->id()]);
      if ($term->isPublished() === $shouldPublish) {
        continue;
      }
      if ($shouldPublish) {
        $term->setPublished();
      }
      else {
        $term->setUnpublished();
      }
      $term->save();
    }
  }

  /**
   * Applies one publication flag to every existing term translation.
   */
  private function setAllTranslationPublication(TermInterface $term, bool $published): bool {
    $translations = [$term];
    if ($term->isTranslatable()) {
      $translations = [];
      foreach (array_keys($term->getTranslationLanguages()) as $langcode) {
        if (!$term->hasTranslation($langcode)) {
          continue;
        }
        $translation = $term->getTranslation($langcode);
        if ($translation instanceof TermInterface) {
          $translations[] = $translation;
        }
      }
      if ($translations === []) {
        $translations = [$term];
      }
    }

    $changed = FALSE;
    foreach ($translations as $translation) {
      if ($translation->isPublished() === $published) {
        continue;
      }
      if ($published) {
        $translation->setPublished();
      }
      else {
        $translation->setUnpublished();
      }
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Restores exact per-language publication flags captured before activation.
   *
   * @param int $rootId
   *   Root jurisdiction ID.
   * @param array<int, array<string, bool>> $snapshot
   *   Translation publication flags keyed by term ID and language code.
   */
  private function restoreTranslationPublicationSnapshot(int $rootId, array $snapshot): void {
    if ($snapshot === []) {
      return;
    }
    $allowed = array_fill_keys($this->queryTermIds($rootId, NULL), TRUE);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadMultiple(array_keys($snapshot));
    foreach ($snapshot as $termId => $languages) {
      $term = $terms[$termId] ?? NULL;
      if (!$term instanceof TermInterface
        || !isset($allowed[$termId])
        || !$term->isTranslatable()) {
        continue;
      }
      $changed = FALSE;
      foreach ($languages as $langcode => $published) {
        if (!$term->hasTranslation($langcode)) {
          continue;
        }
        $translation = $term->getTranslation($langcode);
        if (!$translation instanceof TermInterface
          || $translation->isPublished() === $published) {
          continue;
        }
        if ($published) {
          $translation->setPublished();
        }
        else {
          $translation->setUnpublished();
        }
        $changed = TRUE;
      }
      if ($changed) {
        $term->save();
      }
    }
  }

  /**
   * Creates or updates configured presets without publishing them directly.
   */
  private function ensurePresetCategories(int $rootId): void {
    $config = $this->configFactory->get('markaspot_emergency.settings');
    $presets = $config->getOriginal('categories.emergency_presets', FALSE);
    if (!is_array($presets)) {
      $presets = (array) $config->get('categories.emergency_presets');
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $seenCodes = [];

    foreach ($presets as $preset) {
      if (!is_array($preset) || empty($preset['name'])) {
        continue;
      }

      $serviceCode = trim((string) ($preset['service_code'] ?? ''));
      if ($serviceCode !== '') {
        if (isset($seenCodes[$serviceCode])) {
          throw new \RuntimeException(sprintf(
            'Emergency preset service code %s is configured more than once.',
            $serviceCode,
          ));
        }
        $seenCodes[$serviceCode] = TRUE;
      }

      if ($serviceCode !== '' && $this->hasServiceCodeField()) {
        $existing = $this->findScopedPresetTerms($rootId, $serviceCode);
      }
      else {
        $existing = $this->findScopedPresetTerms(
          $rootId,
          NULL,
          (string) $preset['name'],
        );
      }

      if (count($existing) > 1) {
        throw new \RuntimeException(sprintf(
          'Emergency preset service code %s is assigned more than once in root jurisdiction %d.',
          $serviceCode !== '' ? $serviceCode : '(unavailable)',
          $rootId,
        ));
      }

      $legacyAdopted = FALSE;
      // Existing 1.x presets were name-based and normally have no service
      // code. Adopt that exact scoped term before considering a new term.
      if ($existing === [] && $serviceCode !== '' && $this->hasServiceCodeField()) {
        $legacyMatches = $this->findScopedPresetTerms(
          $rootId,
          NULL,
          (string) $preset['name'],
          TRUE,
        );
        if (count($legacyMatches) > 1) {
          throw new \RuntimeException(sprintf(
            'Legacy emergency preset %s is ambiguous in root jurisdiction %d.',
            (string) $preset['name'],
            $rootId,
          ));
        }
        if ($legacyMatches !== []) {
          $legacyTerm = reset($legacyMatches);
          assert($legacyTerm instanceof TermInterface);
          $legacyCode = trim((string) $legacyTerm->get('field_service_code')->getString());
          if ($legacyCode !== '' && $legacyCode !== $serviceCode) {
            throw new \RuntimeException(sprintf(
              'Legacy emergency preset %s already owns conflicting service code %s.',
              (string) $preset['name'],
              $legacyCode,
            ));
          }
          $existing = $legacyMatches;
          $legacyAdopted = TRUE;
        }
      }
      $term = reset($existing);
      $isNew = !$term instanceof TermInterface;
      if (!$term instanceof TermInterface) {
        $values = [
          'vid' => 'service_category',
          'name' => (string) $preset['name'],
          'status' => 0,
          'weight' => (int) ($preset['weight'] ?? 0),
        ];
        if ($rootId > 0 && $this->hasJurisdictionField()) {
          $values['field_jurisdiction'] = $rootId;
        }
        $term = $storage->create($values);
      }

      // Once a preset has a stable code, taxonomy is authoritative. Operators
      // can change its label, translation, weight, modes, icon, or color and a
      // later activation must not silently restore the shipped seed values.
      if (!$isNew && !$legacyAdopted) {
        continue;
      }

      if ($term->hasField('field_service_code')
        && $term->get('field_service_code')->isEmpty()
        && $serviceCode !== '') {
        $term->set('field_service_code', $serviceCode);
      }
      if ($term->hasField('field_emergency_category')) {
        $term->set('field_emergency_category', TRUE);
      }
      if ($term->hasField('field_emergency_modes')
        && $term->get('field_emergency_modes')->isEmpty()) {
        $modes = array_values(array_filter(
          (array) ($preset['modes'] ?? ['disaster', 'crisis']),
          static fn(mixed $mode): bool => is_string($mode) && in_array($mode, self::MODES, TRUE),
        ));
        $term->set('field_emergency_modes', $modes);
      }
      if ($term->hasField('field_category_icon')
        && $term->get('field_category_icon')->isEmpty()
        && !empty($preset['icon'])) {
        $term->set('field_category_icon', (string) $preset['icon']);
      }
      if ($term->hasField('field_category_hex')
        && $term->get('field_category_hex')->isEmpty()
        && !empty($preset['color'])) {
        $term->set('field_category_hex', (string) $preset['color']);
      }
      $term->save();
    }
  }

  /**
   * Finds preset candidates inside one complete root jurisdiction tree.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Matching service-category terms keyed by term ID.
   */
  private function findScopedPresetTerms(
    int $rootId,
    ?string $serviceCode = NULL,
    ?string $name = NULL,
    bool $requireLegacyFlag = FALSE,
  ): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadMultiple($this->queryTermIds($rootId, NULL));

    return array_filter(
      $terms,
      static function (mixed $term) use (
        $serviceCode,
        $name,
        $requireLegacyFlag,
      ): bool {
        if (!$term instanceof TermInterface) {
          return FALSE;
        }
        if ($serviceCode !== NULL
          && (!$term->hasField('field_service_code')
            || trim((string) $term->get('field_service_code')->getString()) !== $serviceCode)) {
          return FALSE;
        }
        if ($name !== NULL && $term->getName() !== $name) {
          return FALSE;
        }
        return !$requireLegacyFlag
          || ($term->hasField('field_emergency_category')
            && (bool) $term->get('field_emergency_category')->getString());
      },
    );
  }

  /**
   * Adds one root jurisdiction to the active State index.
   */
  private function addActiveJurisdiction(int $rootId): void {
    $ids = array_map('intval', (array) $this->state->get(self::STATE_ACTIVE_INDEX, []));
    $ids[] = $rootId;
    $this->state->set(self::STATE_ACTIVE_INDEX, array_values(array_unique($ids)));
  }

  /**
   * Removes one root jurisdiction from the active State index.
   */
  private function removeActiveJurisdiction(int $rootId): void {
    $ids = array_map('intval', (array) $this->state->get(self::STATE_ACTIVE_INDEX, []));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $rootId));
    $this->state->set(self::STATE_ACTIVE_INDEX, $ids);
  }

  /**
   * Returns the per-root State key.
   */
  private function stateKey(int $rootId): string {
    return self::STATE_PREFIX . $rootId;
  }

  /**
   * Returns the operational policy State key for one root jurisdiction.
   */
  private function policyKey(int $rootId): string {
    return self::STATE_POLICY_PREFIX . $rootId;
  }

  /**
   * Returns normalized stored policy or root-specific install defaults.
   */
  private function getPolicyByRoot(int $rootId): array {
    $stored = $this->state->get($this->policyKey($rootId));
    return $this->normalizePolicy(
      $rootId,
      is_array($stored) ? $stored : $this->getDefaultPolicy($rootId),
      FALSE,
    );
  }

  /**
   * Builds root policy defaults from deployable module configuration.
   */
  private function getDefaultPolicy(int $rootId): array {
    $config = $this->configFactory->get('markaspot_emergency.settings');
    $banner = (array) ($config->get('banner') ?: []);
    $maintenanceIds = [];
    foreach ((array) $config->get('maintenance.jurisdictions') as $entry) {
      if (is_array($entry) && (int) ($entry['root_id'] ?? -1) === $rootId) {
        $maintenanceIds = (array) ($entry['show_only_categories'] ?? []);
        break;
      }
    }
    if ($rootId === 0 && $maintenanceIds === []) {
      $maintenanceIds = (array) ($config->get('maintenance.show_only_categories') ?: []);
    }

    return [
      'presentation_version' => 2,
      'mode_type' => (string) ($config->get('emergency_mode.mode_type') ?: 'disaster'),
      'force_redirect' => (bool) $config->get('emergency_mode.force_redirect'),
      'lite_ui' => (bool) $config->get('emergency_mode.lite_ui'),
      'unpublish_regular' => (bool) $config->get('categories.unpublish_regular'),
      'auto_deactivate' => [
        'enabled' => (bool) $config->get('auto_deactivate.enabled'),
        'duration' => (int) ($config->get('auto_deactivate.duration') ?: 72),
      ],
      'allowed_urls' => (array) ($config->get('allowed_urls') ?: []),
      'network_detection' => [
        'enabled' => (bool) $config->get('network_detection.enabled'),
        'auto_switch_threshold' => (string) ($config->get('network_detection.auto_switch_threshold') ?: '2g'),
      ],
      'maintenance' => [
        'unpublish_non_selected' => (bool) $config->get('maintenance.unpublish_non_selected'),
        'show_only_categories' => $maintenanceIds,
        'force_redirect' => (bool) $config->get('maintenance.force_redirect'),
        'banner_text' => (string) ($config->get('maintenance.banner_text') ?: ''),
        'banner_text_translations' => [],
      ],
      'banner' => array_replace($banner, [
        'message_translations' => [],
        'title_translations' => [],
      ]),
    ];
  }

  /**
   * Normalizes trusted operator policy and optionally enforces root term scope.
   */
  private function normalizePolicy(int $rootId, array $policy, bool $filterScope = TRUE): array {
    $defaults = $this->getDefaultPolicy($rootId);
    $presentationVersion = max(0, (int) ($policy['presentation_version'] ?? 0));
    $modeType = (string) ($policy['mode_type'] ?? $defaults['mode_type']);
    if (!in_array($modeType, self::MODES, TRUE)) {
      $modeType = 'disaster';
    }

    $auto = is_array($policy['auto_deactivate'] ?? NULL)
      ? $policy['auto_deactivate']
      : $defaults['auto_deactivate'];
    $maintenance = is_array($policy['maintenance'] ?? NULL)
      ? $policy['maintenance']
      : $defaults['maintenance'];
    $banner = is_array($policy['banner'] ?? NULL)
      ? $policy['banner']
      : $defaults['banner'];
    $conditions = is_array($banner['display_conditions'] ?? NULL)
      ? $banner['display_conditions']
      : (array) ($defaults['banner']['display_conditions'] ?? []);
    $network = is_array($policy['network_detection'] ?? NULL)
      ? $policy['network_detection']
      : $defaults['network_detection'];
    $maintenanceTextTranslations = $this->normalizePolicyTranslations(
      $maintenance['banner_text_translations'] ?? [],
    );
    $bannerMessageTranslations = $this->normalizePolicyTranslations(
      $banner['message_translations'] ?? [],
    );
    $bannerTitleTranslations = $this->normalizePolicyTranslations(
      $banner['title_translations'] ?? [],
    );
    if ($presentationVersion < 2) {
      if ($maintenanceTextTranslations === [] && array_key_exists('banner_text', $maintenance)) {
        $maintenanceTextTranslations[LanguageInterface::LANGCODE_NOT_SPECIFIED] = (string) $maintenance['banner_text'];
      }
      if ($bannerMessageTranslations === [] && array_key_exists('message', $banner)) {
        $bannerMessageTranslations[LanguageInterface::LANGCODE_NOT_SPECIFIED] = (string) $banner['message'];
      }
      if ($bannerTitleTranslations === [] && array_key_exists('title', $banner)) {
        $bannerTitleTranslations[LanguageInterface::LANGCODE_NOT_SPECIFIED] = (string) $banner['title'];
      }
    }

    $maintenanceIds = array_values(array_unique(array_filter(array_map(
      'intval',
      (array) ($maintenance['show_only_categories'] ?? $defaults['maintenance']['show_only_categories']),
    ), static fn(int $id): bool => $id > 0)));
    if ($filterScope) {
      $maintenanceIds = $this->filterTermIdsToScope($rootId, $maintenanceIds);
    }

    $allowedUrls = [];
    foreach ((array) ($policy['allowed_urls'] ?? $defaults['allowed_urls']) as $path) {
      if (!is_string($path)) {
        continue;
      }
      $path = trim($path);
      if ($path !== '' && $path !== '/' && str_starts_with($path, '/')) {
        $allowedUrls[] = $path;
      }
    }

    return [
      'presentation_version' => 2,
      'mode_type' => $modeType,
      'force_redirect' => (bool) ($policy['force_redirect'] ?? $defaults['force_redirect']),
      'lite_ui' => (bool) ($policy['lite_ui'] ?? $defaults['lite_ui']),
      'unpublish_regular' => (bool) ($policy['unpublish_regular'] ?? $defaults['unpublish_regular']),
      'auto_deactivate' => [
        'enabled' => (bool) ($auto['enabled'] ?? $defaults['auto_deactivate']['enabled']),
        'duration' => max(1, min(168, (int) ($auto['duration'] ?? $defaults['auto_deactivate']['duration']))),
      ],
      'allowed_urls' => array_values(array_unique($allowedUrls)),
      'network_detection' => [
        'enabled' => (bool) ($network['enabled'] ?? $defaults['network_detection']['enabled']),
        'auto_switch_threshold' => (string) ($network['auto_switch_threshold'] ?? $defaults['network_detection']['auto_switch_threshold']),
      ],
      'maintenance' => [
        'unpublish_non_selected' => (bool) ($maintenance['unpublish_non_selected'] ?? $defaults['maintenance']['unpublish_non_selected']),
        'show_only_categories' => $maintenanceIds,
        'force_redirect' => (bool) ($maintenance['force_redirect'] ?? $defaults['maintenance']['force_redirect']),
        'banner_text' => $this->resolvePolicyTranslation(
          $maintenanceTextTranslations,
          (string) $defaults['maintenance']['banner_text'],
        ),
        'banner_text_translations' => $maintenanceTextTranslations,
      ],
      'banner' => [
        'enabled' => (bool) ($banner['enabled'] ?? $defaults['banner']['enabled'] ?? FALSE),
        'message' => $this->resolvePolicyTranslation(
          $bannerMessageTranslations,
          (string) ($defaults['banner']['message'] ?? ''),
        ),
        'message_translations' => $bannerMessageTranslations,
        'level' => (string) ($banner['level'] ?? $defaults['banner']['level'] ?? 'info'),
        'title' => $this->resolvePolicyTranslation(
          $bannerTitleTranslations,
          (string) ($defaults['banner']['title'] ?? ''),
        ),
        'title_translations' => $bannerTitleTranslations,
        'display_conditions' => [
          'emergency_mode_only' => (bool) ($conditions['emergency_mode_only'] ?? FALSE),
          'maintenance_mode' => (bool) ($conditions['maintenance_mode'] ?? TRUE),
          'always_visible' => (bool) ($conditions['always_visible'] ?? FALSE),
        ],
      ],
    ];
  }

  /**
   * Merges form input and records presentation strings for the current locale.
   */
  private function mergePolicyInput(array $base, array $input): array {
    $merged = array_replace($base, $input);
    foreach (['auto_deactivate', 'network_detection', 'maintenance', 'banner'] as $section) {
      if (isset($input[$section]) && is_array($input[$section])) {
        $merged[$section] = array_replace(
          is_array($base[$section] ?? NULL) ? $base[$section] : [],
          $input[$section],
        );
      }
    }
    if (isset($input['banner']['display_conditions']) && is_array($input['banner']['display_conditions'])) {
      $merged['banner']['display_conditions'] = array_replace(
        is_array($base['banner']['display_conditions'] ?? NULL)
          ? $base['banner']['display_conditions']
          : [],
        $input['banner']['display_conditions'],
      );
    }

    $langcode = $this->currentPolicyLangcode();
    if (isset($input['maintenance'])
      && is_array($input['maintenance'])
      && array_key_exists('banner_text', $input['maintenance'])) {
      $translations = $this->normalizePolicyTranslations(
        $base['maintenance']['banner_text_translations'] ?? [],
      );
      $translations[$langcode] = (string) $input['maintenance']['banner_text'];
      $merged['maintenance']['banner_text_translations'] = $translations;
    }
    foreach (['message', 'title'] as $field) {
      if (isset($input['banner'])
        && is_array($input['banner'])
        && array_key_exists($field, $input['banner'])) {
        $translationKey = $field . '_translations';
        $translations = $this->normalizePolicyTranslations(
          $base['banner'][$translationKey] ?? [],
        );
        $translations[$langcode] = (string) $input['banner'][$field];
        $merged['banner'][$translationKey] = $translations;
      }
    }
    $merged['presentation_version'] = 2;
    return $merged;
  }

  /**
   * Removes internal translation maps from the public operational contract.
   */
  private function publicPolicy(array $policy): array {
    unset($policy['presentation_version']);
    unset($policy['maintenance']['banner_text_translations']);
    unset($policy['banner']['message_translations'], $policy['banner']['title_translations']);
    return $policy;
  }

  /**
   * Normalizes a locale-to-text State map.
   */
  private function normalizePolicyTranslations(mixed $translations): array {
    if (!is_array($translations)) {
      return [];
    }
    $normalized = [];
    foreach ($translations as $langcode => $text) {
      $langcode = strtolower(trim((string) $langcode));
      if (!preg_match('/^(?:[a-z]{2,3}(?:-[a-z0-9]{2,8})*|und)$/', $langcode)) {
        continue;
      }
      $normalized[$langcode] = (string) $text;
    }
    ksort($normalized);
    return $normalized;
  }

  /**
   * Resolves current, legacy and site-default presentation fallbacks.
   */
  private function resolvePolicyTranslation(array $translations, string $default): string {
    $current = $this->currentPolicyLangcode();
    if (array_key_exists($current, $translations)) {
      return $translations[$current];
    }
    if (array_key_exists(LanguageInterface::LANGCODE_NOT_SPECIFIED, $translations)) {
      return $translations[LanguageInterface::LANGCODE_NOT_SPECIFIED];
    }
    $defaultLangcode = strtolower($this->languageManager->getDefaultLanguage()->getId());
    if (array_key_exists($defaultLangcode, $translations)) {
      return $translations[$defaultLangcode];
    }
    return $default;
  }

  /**
   * Returns the normalized administrative interface language code.
   */
  private function currentPolicyLangcode(): string {
    return strtolower($this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)
      ->getId());
  }

  /**
   * Invalidates generic and root-specific status cache tags.
   */
  private function invalidateStatusCache(int $rootId): void {
    Cache::invalidateTags([self::CACHE_TAG, self::cacheTag($rootId)]);
  }

  /**
   * Returns canonical configured root jurisdiction IDs.
   *
   * @return int[]
   *   Unique root jurisdiction IDs.
   */
  private function getRootJurisdictionIds(): array {
    return array_values(array_unique(array_map(
      'intval',
      $this->hierarchyResolver->getAllRootJurisdictionIds(),
    )));
  }

  /**
   * Resolves a hidden root that is represented by a published descendant.
   *
   * Public jurisdiction catalogs intentionally omit unpublished root entities,
   * but expose their numeric root ID on each published child. Accept only that
   * exact shape. An unpublished child, unrelated group, cycle, or empty hidden
   * root remains unavailable.
   *
   * @param int $candidateId
   *   Numeric root candidate.
   * @param int[] $rootIds
   *   Canonical configured root IDs.
   *
   * @return int|null
   *   The addressable root ID, or NULL when it is not publicly represented.
   */
  private function resolveAddressableUnpublishedRoot(int $candidateId, array $rootIds): ?int {
    if ($candidateId <= 0 || !in_array($candidateId, $rootIds, TRUE)) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('group');
    $candidate = $storage->load($candidateId);
    if (!$candidate instanceof GroupInterface
      || $candidate->bundle() !== $this->getJurisdictionGroupType()
      || $candidate->isPublished()
      || $this->hierarchyResolver->getRootJurisdictionId($candidateId) !== $candidateId) {
      return NULL;
    }

    foreach ($this->hierarchyResolver->getDescendantIds($candidateId) as $descendantId) {
      $descendantId = (int) $descendantId;
      if ($descendantId === $candidateId) {
        continue;
      }
      $descendant = $storage->load($descendantId);
      if ($descendant instanceof GroupInterface
        && $descendant->bundle() === $this->getJurisdictionGroupType()
        && $descendant->isPublished()
        && $this->hierarchyResolver->getRootJurisdictionId($descendantId) === $candidateId) {
        return $candidateId;
      }
    }

    return NULL;
  }

  /**
   * Checks whether service categories expose stable Open311 service codes.
   */
  private function hasServiceCodeField(): bool {
    $fields = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'service_category');
    return isset($fields['field_service_code']);
  }

  /**
   * Checks whether service categories carry dynamic Open311 definitions.
   */
  private function hasServiceDefinitionField(): bool {
    $fields = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'service_category');
    return isset($fields['field_service_definition']);
  }

  /**
   * Checks for jurisdiction groups when the hierarchy has no usable roots.
   */
  private function hasJurisdictionGroups(): bool {
    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->condition('type', $this->getJurisdictionGroupType())
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    return $ids !== [];
  }

  /**
   * Returns maintenance categories configured for one root jurisdiction.
   *
   * @return int[]
   *   Scoped category term IDs.
   */
  private function getMaintenanceCategoryIds(int $rootId): array {
    return $this->getPolicyByRoot($rootId)['maintenance']['show_only_categories'];
  }

  /**
   * Restores State API values if a database-backed transition fails.
   */
  private function restoreStateAfterFailure(int $rootId, mixed $record, array $activeIndex): void {
    try {
      if (is_array($record)) {
        $this->state->set($this->stateKey($rootId), $record);
      }
      else {
        $this->state->delete($this->stateKey($rootId));
      }
      $this->state->set(self::STATE_ACTIVE_INDEX, $activeIndex);
    }
    catch (\Throwable $exception) {
      $this->logger->critical('Emergency transition failed and State rollback also failed for root jurisdiction @jurisdiction: @message', [
        '@jurisdiction' => $rootId,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Restores per-root policy if an atomic policy/profile activation fails.
   */
  private function restorePolicyAfterFailure(int $rootId, mixed $record): void {
    try {
      if (is_array($record)) {
        $this->state->set($this->policyKey($rootId), $record);
      }
      else {
        $this->state->delete($this->policyKey($rootId));
      }
    }
    catch (\Throwable $exception) {
      $this->logger->critical('Emergency transition failed and policy rollback also failed for root jurisdiction @jurisdiction: @message', [
        '@jurisdiction' => $rootId,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
