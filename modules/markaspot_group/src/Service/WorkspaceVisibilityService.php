<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary;

/**
 * Determines workspace visibility and anonymous access rules.
 *
 * Uses static caching to avoid repeated group loads during the same request
 * (critical for hook_node_access which runs per-entity).
 */
class WorkspaceVisibilityService implements WorkspaceVisibilityInterface {

  /**
   * The visibility values this service supports.
   */
  private const VALID_VISIBILITIES = [
    'public',
    'submission_only',
    'authenticated',
    'blocked',
  ];

  /**
   * The visibility values that deny anonymous reads.
   */
  private const RESTRICTED_VISIBILITIES = [
    'submission_only',
    'authenticated',
    'blocked',
  ];

  /**
   * Jurisdiction role suffixes that bypass read visibility restrictions.
   */
  private const ELEVATED_JURISDICTION_ROLE_SUFFIXES = [
    'admin',
    'editorial',
    'moderator',
    'tenant_admin',
  ];

  /**
   * Static cache of visibility values keyed by group ID.
   *
   * @var array<int, string>
   */
  protected array $cache = [];

  /**
   * Cached restrictive jurisdiction IDs for this request.
   *
   * @var int[]|null
   */
  protected ?array $restrictedJurisdictionIds = NULL;

  /**
   * Cached elevated jurisdiction IDs keyed by account ID.
   *
   * @var array<int, int[]>
   */
  protected array $elevatedJurisdictionIds = [];

  /**
   * Cached page visibility grant IDs keyed by account ID.
   *
   * @var array<int, int[]>
   */
  protected array $pageViewGrantIds = [];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Gets the visibility mode for a workspace/jurisdiction.
   *
   * Missing, empty AND unrecognised values deliberately fail open to
   * "public". An accidentally disabled tier feature costs revenue; an
   * accidentally closed visibility level would take a public reporting
   * platform away from residents. This fail-open default is intentionally
   * opposite to the fail-closed tier backstop. Existing enterprise and
   * municipal workspaces gain this field during updates without a data
   * backfill, so preserving public access for their empty values is a
   * deployment-safety requirement.
   *
   * A stored value outside the supported set (say "Public" from a CSV import,
   * or " blocked" with stray whitespace) is a data error, not an intent to
   * close a workspace. It is therefore neither trimmed nor lowercased nor
   * guessed into a restrictive mode: it resolves to public, and an operator
   * has to store a supported value to restrict anything.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return string
   *   One of: 'public', 'submission_only', 'authenticated', 'blocked'.
   */
  public function getVisibility(int $groupId): string {
    if (isset($this->cache[$groupId])) {
      return $this->cache[$groupId];
    }

    $this->cache[$groupId] = 'public';
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if ($group && $group->hasField('field_visibility') && !$group->get('field_visibility')->isEmpty()) {
      $stored = $group->get('field_visibility')->value;
      if (is_string($stored) && in_array($stored, self::VALID_VISIBILITIES, TRUE)) {
        $this->cache[$groupId] = $stored;
      }
    }

    return $this->cache[$groupId];
  }

  /**
   * Gets jurisdictions with an explicitly restrictive visibility value.
   *
   * This hot-path lookup uses one EntityQuery for explicit restrictions;
   * missing and empty values do not match and therefore retain the fail-open
   * public contract. The result is cached for the request because JSON:API
   * executes separate count and data queries. Existing group lifecycle hooks
   * invalidate it together with the per-group visibility cache.
   *
   * @return int[]
   *   Restricted jurisdiction group IDs.
   */
  public function getRestrictedJurisdictionIds(): array {
    if ($this->restrictedJurisdictionIds !== NULL) {
      return $this->restrictedJurisdictionIds;
    }

    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('group');
    if (!array_key_exists('field_visibility', $definitions)) {
      // The field arrives with markaspot_group_update_11946(). Querying it
      // before that update throws, which would turn every anonymous request
      // list into a 500 instead of a working public map. Without the field no
      // workspace can carry a restrictive mode anyway, so the fail-open
      // answer is an empty exclusion list.
      return $this->restrictedJurisdictionIds = [];
    }

    $candidates = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->jurisdictionGroupType())
      ->condition('field_visibility', self::RESTRICTED_VISIBILITIES, 'IN')
      ->execute();

    // Warm the storage cache in one query so the strict re-check below does
    // not turn into one load per candidate. Normally there are no candidates
    // at all; a spam wave plus an operator running the auto-block command can
    // push the count into the hundreds on the shared platform.
    if ($candidates !== []) {
      $this->entityTypeManager->getStorage('group')->loadMultiple(array_values($candidates));
    }

    $restricted = [];
    foreach ($candidates as $candidate) {
      $candidate = (int) $candidate;
      // Database collations commonly compare case-insensitively and ignore
      // trailing spaces, so the SQL condition can match values that
      // getVisibility() resolves to public. Re-apply the strict whitelist so
      // both paths agree; the loop only touches workspaces that an operator
      // actually restricted, which is normally none.
      if (in_array($this->getVisibility($candidate), self::RESTRICTED_VISIBILITIES, TRUE)) {
        $restricted[] = $candidate;
      }
    }

    return $this->restrictedJurisdictionIds = $restricted;
  }

  /**
   * Gets jurisdictions the supplied account may not read.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   *
   * @return int[]
   *   Jurisdiction group IDs whose requests must be excluded.
   */
  public function getUnreadableJurisdictionIds(AccountInterface $account): array {
    if ($this->hasSiteBypass($account)) {
      return [];
    }

    return array_values(array_filter(
      $this->getRestrictedJurisdictionIds(),
      fn(int $groupId): bool => !$this->allowsReadFor($account, $groupId),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function getUnreadablePageJurisdictionIds(AccountInterface $account): array {
    if ($this->hasSiteBypass($account)) {
      return [];
    }

    return array_values(array_filter(
      $this->getRestrictedJurisdictionIds(),
      fn(int $groupId): bool => !$this->allowsPageReadFor($account, $groupId),
    ));
  }

  /**
   * Whether an account can read requests in a workspace.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE when the account may read requests in the workspace.
   */
  public function allowsReadFor(AccountInterface $account, int $groupId): bool {
    if ($this->hasSiteBypass($account)) {
      return TRUE;
    }

    if ($account->isAnonymous()) {
      return $this->canAnonymousView($groupId);
    }

    if (!$this->isBlocked($groupId)) {
      return TRUE;
    }

    return in_array(
      $groupId,
      $this->getElevatedJurisdictionIds($account),
      TRUE,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function allowsPageReadFor(AccountInterface $account, int $groupId): bool {
    if ($this->hasSiteBypass($account)
      || $this->getVisibility($groupId) === 'public') {
      return TRUE;
    }

    if ($account->isAnonymous() || $this->isBlocked($groupId)) {
      return FALSE;
    }

    return in_array($groupId, $this->getPageViewGrantIds($account), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getPageViewGrantIds(AccountInterface $account): array {
    if ($this->hasSiteBypass($account)) {
      return [0];
    }

    $accountId = (int) $account->id();
    if ($accountId <= 0) {
      return [];
    }
    if (isset($this->pageViewGrantIds[$accountId])) {
      return $this->pageViewGrantIds[$accountId];
    }

    $groupType = $this->jurisdictionGroupType();
    $jurisdictionIds = [];
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $group = $membership->getGroup();
      if ($group instanceof GroupInterface
        && $group->bundle() === $groupType
        && in_array($this->getVisibility((int) $group->id()), [
          'submission_only',
          'authenticated',
        ], TRUE)) {
        $jurisdictionIds[] = (int) $group->id();
      }
    }

    return $this->pageViewGrantIds[$accountId] = array_values(array_unique(
      $jurisdictionIds,
    ));
  }

  /**
   * Whether anonymous users can view requests in this workspace.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE only for 'public' workspaces.
   */
  public function canAnonymousView(int $groupId): bool {
    return $this->getVisibility($groupId) === 'public';
  }

  /**
   * Whether anonymous users can submit requests to this workspace.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE for 'public' and 'submission_only' workspaces.
   */
  public function canAnonymousSubmit(int $groupId): bool {
    $visibility = $this->getVisibility($groupId);
    return in_array($visibility, ['public', 'submission_only'], TRUE);
  }

  /**
   * Whether the workspace is fully blocked.
   *
   * Blocked workspaces reject public access and regular tenant writes. Site
   * administrators can still unblock them through privileged operations.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE when the workspace visibility is 'blocked'.
   */
  public function isBlocked(int $groupId): bool {
    return $this->getVisibility($groupId) === 'blocked';
  }

  /**
   * Resets cached visibility values.
   *
   * @param int|null $groupId
   *   Optional group ID to invalidate. When omitted, the full request-local
   *   cache is cleared.
   */
  public function resetCache(?int $groupId = NULL): void {
    $this->restrictedJurisdictionIds = NULL;
    $this->pageViewGrantIds = [];

    if ($groupId === NULL) {
      $this->cache = [];
      return;
    }

    unset($this->cache[$groupId]);
  }

  /**
   * Gets jurisdictions where an account holds an elevated group role.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  protected function getElevatedJurisdictionIds(AccountInterface $account): array {
    $accountId = (int) $account->id();
    if ($accountId <= 0) {
      return [];
    }
    if (isset($this->elevatedJurisdictionIds[$accountId])) {
      return $this->elevatedJurisdictionIds[$accountId];
    }

    $groupType = $this->jurisdictionGroupType();
    $roleIds = [];
    foreach (self::ELEVATED_JURISDICTION_ROLE_SUFFIXES as $suffix) {
      $roleIds[] = $groupType . '-' . $suffix;
      $roleIds[] = 'jur-' . $suffix;
    }

    $jurisdictionIds = [];
    foreach (GroupMembership::loadByUser(
      $account,
      array_values(array_unique($roleIds)),
    ) as $membership) {
      $group = $membership->getGroup();
      if ($group instanceof GroupInterface && $group->bundle() === $groupType) {
        $jurisdictionIds[] = (int) $group->id();
      }
    }

    return $this->elevatedJurisdictionIds[$accountId] = array_values(
      array_unique($jurisdictionIds),
    );
  }

  /**
   * Checks whether an account bypasses workspace read restrictions site-wide.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   *
   * @return bool
   *   TRUE for user 1 and accounts with the administrator site role.
   */
  protected function hasSiteBypass(AccountInterface $account): bool {
    return (int) $account->id() === 1
      || in_array('administrator', $account->getRoles(), TRUE);
  }

  /**
   * Checks whether any jurisdiction matching the supplied context is blocked.
   *
   * Used by the Open311 POST path to defeat the boundary-claim backdoor where
   * a client claims a public parent workspace while the coordinates land in a
   * blocked child. We probe the claim plus every child of the configured
   * jurisdiction group type that contains the coordinates.
   *
   * The lookup is intentionally conservative: if any candidate is blocked, the
   * caller short-circuits with 403. False positives (multiple overlapping
   * boundaries where only one is blocked) are acceptable; missing a blocked
   * tenant is not.
   *
   * @param int|null $claimedJurisdictionId
   *   The jurisdiction ID supplied by the caller, or NULL when implicit.
   * @param float|null $lat
   *   Latitude from the request, or NULL when not supplied.
   * @param float|null $lng
   *   Longitude from the request, or NULL when not supplied.
   *
   * @return bool
   *   TRUE when the claimed jurisdiction or any boundary-resolved candidate
   *   is currently blocked.
   */
  public function isBlockedForSubmission(?int $claimedJurisdictionId, ?float $lat, ?float $lng): bool {
    if ($claimedJurisdictionId !== NULL && $claimedJurisdictionId > 0 && $this->isBlocked($claimedJurisdictionId)) {
      return TRUE;
    }

    if ($lat === NULL || $lng === NULL || ($lat === 0.0 && $lng === 0.0)) {
      return FALSE;
    }

    $candidates = $this->resolveBoundaryCandidates($lat, $lng);
    foreach ($candidates as $candidateId) {
      if ($this->isBlocked($candidateId)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns jurisdiction group IDs whose boundary contains the coordinates.
   *
   * Every boundary match plus every ancestor in each parent chain. A blocked
   * parent or overlapping sibling must not be hidden by a public match.
   * Boundary resolution lives here instead of in markaspot_fastmap so
   * enterprise stacks receive the same submission protection without enabling
   * the SaaS module.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lng
   *   Longitude.
   *
   * @return int[]
   *   Matching jurisdiction group IDs, deepest first, then ancestors.
   */
  protected function resolveBoundaryCandidates(float $lat, float $lng): array {
    $matchedIds = $this->resolveMatchingBoundaryJurisdictionIds($lat, $lng);
    if ($matchedIds === []) {
      return [];
    }

    $groupStorage = $this->entityTypeManager->getStorage('group');
    $candidates = [];
    $visited = [];
    foreach ($matchedIds as $matchedId) {
      $cursor = $matchedId;
      for ($i = 0; $i < 16; $i++) {
        if ($cursor <= 0 || isset($visited[$cursor])) {
          break;
        }
        $candidates[] = $cursor;
        $visited[$cursor] = TRUE;

        $group = $groupStorage->load($cursor);
        if (!$group || !$group->hasField('field_parent_jurisdiction')
          || $group->get('field_parent_jurisdiction')->isEmpty()) {
          break;
        }
        $cursor = (int) $group->get('field_parent_jurisdiction')->target_id;
      }
    }

    return $candidates;
  }

  /**
   * Returns every jurisdiction boundary containing the coordinates.
   *
   * Returning every match is intentional. Boundaries may overlap, and a
   * public sibling must never hide a blocked sibling based on query order.
   *
   * @return int[]
   *   Matching jurisdiction group IDs.
   */
  protected function resolveMatchingBoundaryJurisdictionIds(float $lat, float $lng): array {
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $groupIds = $groupStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->jurisdictionGroupType())
      ->exists('field_boundary')
      ->execute();

    if ($groupIds === []) {
      return [];
    }

    // Loading and GeoJSON-parsing every bounded workspace at once is fine for
    // a handful of jurisdictions and expensive for a large portfolio, and this
    // runs on report submission. Batch the loads instead of capping the total:
    // a truncated scan could miss a blocked workspace and wrongly permit a
    // submission, which is the one direction a block check must not fail in.
    //
    // Batching bounds the size of the transient result arrays, not the peak
    // memory: the storage keeps every loaded entity in its caches for the rest
    // of the request. Do not "fix" that with resetCache() here. On group,
    // which is revisionable and persistently cacheable, that call deletes
    // shared cache_entity entries and writes a cache tag invalidation per ID,
    // so one submission would evict warm entries for every other request.
    $matchedIds = [];
    foreach (array_chunk(array_values($groupIds), 100) as $batch) {
      foreach ($groupStorage->loadMultiple($batch) as $group) {
        if (!$group instanceof GroupInterface
          || !$group->hasField('field_parent_jurisdiction')
          || $group->get('field_parent_jurisdiction')->isEmpty()
          || !$group->hasField('field_boundary')
          || $group->get('field_boundary')->isEmpty()) {
          continue;
        }

        $boundary = GeoJsonBoundary::fromJson((string) $group->get('field_boundary')->value);
        if ($boundary && $boundary->contains($lng, $lat)) {
          $matchedIds[] = (int) $group->id();
        }
      }
    }

    return $matchedIds;
  }

  /**
   * Gets the configured jurisdiction group bundle.
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');
    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
