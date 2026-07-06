<?php

namespace Drupal\markaspot_escalation\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Interface for the escalation and delegation service.
 *
 * Handles routing of service requests between jurisdictions (escalation)
 * and organisations (delegation) within the Mark-a-Spot group hierarchy.
 */
interface EscalationServiceInterface {

  /**
   * Escalates a service request to a target group.
   *
   * Organisation targets move the request to the parent org using delegation
   * mechanics. Jurisdiction targets move the request to a higher jurisdiction,
   * clear the organisation assignment, add a jurisdiction group relationship,
   * and set the escalation fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node to escalate.
   * @param int $targetGroupId
   *   The target organisation or jurisdiction group ID.
   * @param string $note
   *   The escalation note text for the internal remark.
   *
   * @throws \InvalidArgumentException
   *   If the target group does not exist or is not a valid escalation target.
   */
  public function escalateRequest(NodeInterface $node, int $targetGroupId, string $note): void;

  /**
   * Resolves the escalation target group for a service request.
   *
   * Resolution order:
   * 1. Current organisation's nearest parent organisation.
   * 2. Category-level jurisdiction override on the category term.
   * 3. Fallback jurisdiction hierarchy traversal:
   *    - Re-escalation (field_escalation set): parent of current escalation
   *      jurisdiction.
   *    - First escalation (field_escalation empty): parent of the org's jur.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The target group ID, or NULL if no target can be determined.
   */
  public function resolveEscalationTarget(NodeInterface $node): ?int;

  /**
   * Resolves the current jurisdiction group for a service request.
   *
   * Escalated requests use field_escalation. Non-escalated requests use the
   * same source-jurisdiction lookup as escalation target resolution.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL if none can be determined.
   */
  public function resolveRequestJurisdictionId(NodeInterface $node): ?int;

  /**
   * Checks whether escalation and delegation notes are required for a request.
   *
   * The policy is read from the effective request jurisdiction, not from the
   * currently displayed route jurisdiction. Missing jurisdiction context,
   * missing configuration, invalid JSON, or a non-TRUE feature flag all keep
   * notes optional.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return bool
   *   TRUE if the request jurisdiction requires notes.
   */
  public function isDelegationNoteRequired(NodeInterface $node): bool;

  /**
   * Delegates a service request to a target organisation.
   *
   * Reassigns the request to a different organisation within or below the
   * current jurisdiction, adds an internal remark, optionally clears escalation
   * state, and sends email notification.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node to delegate.
   * @param int $targetOrgId
   *   The target organisation group ID.
   * @param string $note
   *   The delegation note text for the internal remark.
   *
   * @throws \InvalidArgumentException
   *   If the target group does not exist or is not an organisation.
   */
  public function delegateRequest(NodeInterface $node, int $targetOrgId, string $note): void;

  /**
   * Checks whether a user can escalate a given service request.
   *
   * Validates configuration, node state, escalation target availability,
   * group membership, and permission.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return bool
   *   TRUE if the user is allowed to escalate this request.
   */
  public function canEscalate(NodeInterface $node, AccountInterface $account): bool;

  /**
   * Checks whether a user can delegate a given service request.
   *
   * Validates configuration, node state, group membership, and permission.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return bool
   *   TRUE if the user is allowed to delegate this request.
   */
  public function canDelegate(NodeInterface $node, AccountInterface $account): bool;

}
