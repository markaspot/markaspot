<?php

declare(strict_types=1);

namespace Drupal\markaspot_cap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;

/**
 * Tracks feed membership changes that are not visible in current entries.
 */
final class CapFeedMutationTracker implements CapFeedMutationTrackerInterface {

  public const STATE_PREFIX = 'markaspot_cap.feed_changed.';

  private const LOCK_TTL = 30.0;

  /**
   * Root locks already held until the current SQL transaction finishes.
   *
   * @var array<string, true>
   */
  private array $transactionLocks = [];

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly LockBackendInterface $lock,
    private readonly CapFeedStateStoreInterface $feedStateStore,
    private readonly Connection $database,
  ) {}

  /**
   * Marks roots affected by an approved request or category mutation.
   */
  public function markEntityChanged(
    ContentEntityInterface $entity,
    ?ContentEntityInterface $original = NULL,
  ): void {
    $isRequest = $entity->getEntityTypeId() === 'node'
      && $entity->bundle() === 'service_request';
    $isCategory = $entity->getEntityTypeId() === 'taxonomy_term'
      && $entity->bundle() === 'service_category';
    if (!$isRequest && !$isCategory) {
      return;
    }

    if ($isRequest
      && !$this->isCapApproved($entity)
      && !$this->isCapApproved($original)) {
      return;
    }

    $rootIds = array_merge(
      $this->entityRootIds($entity),
      $original ? $this->entityRootIds($original) : [],
    );
    $this->markRootsChanged($rootIds);
  }

  /**
   * Advances every supplied root timestamp monotonically.
   *
   * @param int[] $rootIds
   *   Root jurisdiction IDs.
   */
  public function markRootsChanged(array $rootIds): void {
    $rootIds = array_values(array_filter(
      array_unique(array_map('intval', $rootIds)),
      static fn(int $rootId): bool => $rootId >= 0,
    ));
    if ($rootIds === []) {
      return;
    }

    foreach ($rootIds as $rootId) {
      $key = self::stateKey($rootId);
      $lockName = $key . '.transition';
      if (isset($this->transactionLocks[$lockName])) {
        continue;
      }
      $acquired = $this->lock->acquire($lockName, self::LOCK_TTL);
      if (!$acquired) {
        $this->lock->wait($lockName, 2);
        $acquired = $this->lock->acquire($lockName, self::LOCK_TTL);
      }
      if (!$acquired) {
        throw new \RuntimeException(sprintf(
          'CAP feed timestamp for root jurisdiction %d is currently locked.',
          $rootId,
        ));
      }

      $releaseImmediately = TRUE;
      try {
        $this->feedStateStore->advance($key, $this->time->getRequestTime());
        if ($this->database->inTransaction()) {
          $this->transactionLocks[$lockName] = TRUE;
          $this->registerTransactionLockRelease($lockName);
          $releaseImmediately = FALSE;
        }
      }
      finally {
        if ($releaseImmediately) {
          $this->lock->release($lockName);
        }
      }
    }
  }

  /**
   * Holds a CAP root lock through the surrounding commit or rollback.
   */
  private function registerTransactionLockRelease(string $lockName): void {
    $callback = function (bool $success) use ($lockName): void {
      unset($this->transactionLocks[$lockName]);
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

    unset($this->transactionLocks[$lockName]);
    throw new \LogicException('The database transaction callback is unavailable.');
  }

  /**
   * Returns the mutation floor for one root feed.
   */
  public function getChangedAt(int $rootId): int {
    return max(0, (int) $this->state->get(self::stateKey($rootId), 0));
  }

  /**
   * Returns the State key for one root feed timestamp.
   */
  public static function stateKey(int $rootId): string {
    return self::STATE_PREFIX . $rootId;
  }

  /**
   * Checks whether a service request is approved for CAP publication.
   */
  private function isCapApproved(?ContentEntityInterface $entity): bool {
    return $entity !== NULL
      && $entity->hasField('field_cap_publish')
      && (bool) $entity->get('field_cap_publish')->getString();
  }

  /**
   * Resolves every jurisdiction reference to its root.
   *
   * @return int[]
   *   Root IDs. Unscoped legacy entities conservatively invalidate all roots.
   */
  private function entityRootIds(ContentEntityInterface $entity): array {
    $jurisdictionIds = [];
    if ($entity->hasField('field_jurisdiction')) {
      foreach ($entity->get('field_jurisdiction')->getValue() as $item) {
        $id = (int) ($item['target_id'] ?? 0);
        if ($id > 0) {
          $jurisdictionIds[] = $id;
        }
      }
    }

    $rootIds = [];
    foreach (array_unique($jurisdictionIds) as $jurisdictionId) {
      $rootId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      if ($rootId !== NULL) {
        $rootIds[] = (int) $rootId;
      }
    }
    if ($rootIds !== []) {
      return array_values(array_unique($rootIds));
    }

    $allRoots = array_values(array_unique(array_map(
      'intval',
      $this->hierarchyResolver->getAllRootJurisdictionIds(),
    )));
    return $allRoots !== [] ? $allRoots : [0];
  }

}
