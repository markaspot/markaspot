<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Public contract for workspace visibility decisions.
 *
 * Introduced so callers (Open311 resources, Drupal access hooks, the Nuxt
 * tenant settings controller) can typehint against a stable interface and
 * drop defensive method_exists() probes that hid real wiring bugs behind
 * silent fallthroughs.
 */
interface WorkspaceVisibilityInterface {

  /**
   * Gets the visibility mode for a workspace/jurisdiction.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return string
   *   One of: 'public', 'submission_only', 'authenticated', 'blocked'.
   */
  public function getVisibility(int $groupId): string;

  /**
   * Gets jurisdictions with an explicitly restrictive visibility value.
   *
   * @return int[]
   *   Restricted jurisdiction group IDs.
   */
  public function getRestrictedJurisdictionIds(): array;

  /**
   * Gets jurisdictions the supplied account may not read.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   *
   * @return int[]
   *   Jurisdiction group IDs whose requests must be excluded.
   */
  public function getUnreadableJurisdictionIds(AccountInterface $account): array;

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
  public function allowsReadFor(AccountInterface $account, int $groupId): bool;

  /**
   * Whether anonymous users can view requests in this workspace.
   */
  public function canAnonymousView(int $groupId): bool;

  /**
   * Whether anonymous users can submit requests to this workspace.
   */
  public function canAnonymousSubmit(int $groupId): bool;

  /**
   * Whether the workspace is fully blocked.
   */
  public function isBlocked(int $groupId): bool;

  /**
   * Whether any jurisdiction relevant to a submission attempt is blocked.
   *
   * Probes the claimed jurisdiction and every boundary-resolved candidate
   * (including ancestors of the deepest match) so a claim-public/coords-in-
   * blocked-child attack does not slip through.
   */
  public function isBlockedForSubmission(?int $claimedJurisdictionId, ?float $lat, ?float $lng): bool;

  /**
   * Resets cached visibility values.
   */
  public function resetCache(?int $groupId = NULL): void;

}
