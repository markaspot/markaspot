<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\node\NodeInterface;

/**
 * Shared authorization policy for AI duplicate data.
 *
 * Used by the JSON API controller and the Drupal review form, so both paths
 * enforce the same rules: node access for the operation, tenant scope of the
 * reviewer and the tenant's duplicate-detection opt-in, for BOTH reports of a
 * match. A missing, deleted or foreign report denies the whole match.
 *
 * Match records carry no cache tags and tenant scope depends on group
 * memberships, so every result from this policy is per user and uncacheable.
 */
class DuplicateMatchAccessPolicy {

  use JurisdictionIdResolverTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected DuplicateDetectionService $duplicateDetection,
    protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {}

  /**
   * Route access for the duplicate review form.
   *
   * @param mixed $match
   *   The raw match ID from the route.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account requesting the route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed only when the account may view both reports of the match.
   */
  public function accessReviewRoute(mixed $match, AccountInterface $account): AccessResultInterface {
    if (!is_numeric($match)) {
      return $this->deny('Invalid duplicate match.');
    }
    return $this->matchIdAccess((int) $match, 'view', $account);
  }

  /**
   * Checks access to a stored match by its ID.
   *
   * Missing and inaccessible matches produce the same result, so the response
   * does not reveal whether a foreign match exists.
   */
  public function matchIdAccess(int $matchId, string $operation, AccountInterface $account): AccessResultInterface {
    $match = $this->duplicateDetection->getMatch($matchId);
    if ($match === NULL) {
      return $this->deny('Duplicate match is not available.');
    }
    return $this->matchAccess($match, $operation, $account);
  }

  /**
   * Checks access to both reports of a match record.
   *
   * @param array $match
   *   The match record with source_nid and match_nid.
   * @param string $operation
   *   The node operation: 'view' to read, 'update' to review.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   */
  public function matchAccess(array $match, string $operation, AccountInterface $account): AccessResultInterface {
    if (!$this->isDuplicateDetectionEnabled()) {
      return $this->deny('Duplicate detection is disabled.');
    }

    foreach (['source_nid', 'match_nid'] as $key) {
      $nid = (int) ($match[$key] ?? 0);
      $node = $nid > 0 ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
      if (!$node instanceof NodeInterface || !$this->canAccessNode($node, $operation, $account)) {
        return $this->deny('Duplicate match is not available.');
      }
    }

    return AccessResult::allowed()->cachePerUser()->setCacheMaxAge(0);
  }

  /**
   * Checks node access, tenant scope and tenant opt-in for one report.
   */
  public function canAccessNode(NodeInterface $node, string $operation, AccountInterface $account): bool {
    if ($node->bundle() !== 'service_request') {
      return FALSE;
    }
    // Any write also needs read: Group can grant "update any" on a draft the
    // account may not even view, and a reviewer must see what it decides on.
    $operations = $operation === 'view' ? ['view'] : ['view', $operation];
    foreach ($operations as $op) {
      if (!$node->access($op, $account)) {
        return FALSE;
      }
    }

    return $this->canAccessJurisdiction($this->getJurisdictionIdForNode($node), $account)
      && $this->isDuplicateDetectionEnabledForNode($node);
  }

  /**
   * Checks access for a service request node ID.
   */
  public function canAccessNodeId(int $nid, string $operation, AccountInterface $account): bool {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    return $node instanceof NodeInterface && $this->canAccessNode($node, $operation, $account);
  }

  /**
   * Checks the global duplicate detection task toggle.
   */
  public function isDuplicateDetectionEnabled(): bool {
    return (bool) $this->configFactory->get('markaspot_ai.settings')
      ->get('duplicate_detection.enabled');
  }

  /**
   * Checks if the account can see all jurisdictions.
   */
  public function canSeeAllJurisdictions(AccountInterface $account): bool {
    return (int) $account->id() === 1
      || $account->hasPermission('administer nodes');
  }

  /**
   * Checks if the account administers the given jurisdiction scope.
   */
  public function canAccessJurisdiction(?int $jurisdictionId, AccountInterface $account): bool {
    if ($jurisdictionId === NULL) {
      return FALSE;
    }
    if ($this->canSeeAllJurisdictions($account)) {
      return TRUE;
    }
    if (!in_array('tenant_admin', $account->getRoles(), TRUE)) {
      return FALSE;
    }

    $memberships = GroupMembership::loadByUser($account, $this->jurisdictionRoleIds('tenant_admin'));
    foreach ($memberships as $membership) {
      $managedGroup = $membership->getGroup();
      if (!$this->isJurisdictionGroup($managedGroup)) {
        continue;
      }
      $managedId = (int) $managedGroup->id();
      $scopeIds = $this->hierarchyResolver
        ? $this->hierarchyResolver->getDescendantIds($managedId)
        : [$managedId];
      if (in_array($jurisdictionId, $scopeIds, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Resolves the jurisdiction of a report, as used by the access checks.
   */
  public function jurisdictionIdForNode(NodeInterface $node): ?int {
    return $this->getJurisdictionIdForNode($node);
  }

  /**
   * Resolves the jurisdiction of a report.
   */
  protected function getJurisdictionIdForNode(NodeInterface $node): ?int {
    return _markaspot_ai_get_jurisdiction_id_for_node($node);
  }

  /**
   * Checks the tenant's AI and duplicate-detection opt-in for a report.
   */
  protected function isDuplicateDetectionEnabledForNode(NodeInterface $node): bool {
    return _markaspot_ai_is_duplicate_detection_enabled_for_node($node);
  }

  /**
   * Builds an uncacheable per-user denial.
   */
  protected function deny(string $reason): AccessResultInterface {
    return AccessResult::forbidden($reason)->cachePerUser()->setCacheMaxAge(0);
  }

}
