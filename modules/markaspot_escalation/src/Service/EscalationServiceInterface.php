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
   * Escalates a service request to a target jurisdiction.
   *
   * Moves the request from its current organisation/jurisdiction to a higher
   * jurisdiction, clears the organisation assignment, adds an internal remark,
   * creates a new group relationship, and sends email notification.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node to escalate.
   * @param int $targetJurId
   *   The target jurisdiction group ID.
   * @param string $note
   *   The escalation note text for the internal remark.
   *
   * @throws \InvalidArgumentException
   *   If the target group does not exist or is not a jurisdiction.
   */
  public function escalateRequest(NodeInterface $node, int $targetJurId, string $note): void;

  /**
   * Resolves the escalation target jurisdiction for a service request.
   *
   * Resolution order:
   * 1. Category-level override: field_escalation_target on the category term.
   * 2. Fallback hierarchy traversal:
   *    - Re-escalation (field_escalation set): parent of current escalation jur.
   *    - First escalation (field_escalation empty): parent of the org's jur.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The target jurisdiction group ID, or NULL if no target can be determined.
   */
  public function resolveEscalationTarget(NodeInterface $node): ?int;

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
