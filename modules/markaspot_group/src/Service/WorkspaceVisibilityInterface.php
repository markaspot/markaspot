<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;

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
   *   Public, submission_only, form_only, authenticated, or blocked.
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
   * Gets jurisdictions whose pages the supplied account may not read.
   *
   * @return int[]
   *   Restricted jurisdiction group IDs not readable by the account.
   */
  public function getUnreadablePageJurisdictionIds(AccountInterface $account): array;

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
   * Gets form-only jurisdictions where the account may read some reports.
   *
   * @return int[]
   *   Explicit jurisdiction IDs; org-scoped access remains restricted.
   */
  public function getReportViewJurisdictionIds(AccountInterface $account): array;

  /**
   * Gets the report organisation restriction in a form-only jurisdiction.
   *
   * @return int[]|null
   *   NULL for full jurisdiction access, [] for denied, or allowed org IDs.
   */
  public function getFormOnlyOrganisationScope(AccountInterface $account, int $groupId): ?array;

  /**
   * Whether the current or supplied request uses public API-key credentials.
   */
  public function requestUsesPublicApiKey(?Request $request = NULL): bool;

  /**
   * Checks report visibility including its responsible organisation.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing account.
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param int[] $organisationIds
   *   The report's authoritative organisation field target IDs.
   */
  public function allowsReportReadFor(AccountInterface $account, int $groupId, array $organisationIds): bool;

  /**
   * Whether an account can read pages in a workspace.
   */
  public function allowsPageReadFor(AccountInterface $account, int $groupId): bool;

  /**
   * Gets page visibility grant IDs for an account.
   *
   * @return int[]
   *   Jurisdiction IDs for members, or grant ID zero for site admins.
   */
  public function getPageViewGrantIds(AccountInterface $account): array;

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
