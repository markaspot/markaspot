<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Orchestrates splitting a service request into a linked sibling request.
 */
interface SplitRequestServiceInterface {

  /**
   * Resolves a service request node's jurisdiction group ID.
   *
   * Mirrors the InboundMailAccessControlHandler / ModerationService pattern:
   * resolve via the group_relationship plugin group_node:service_request,
   * falling back to field_jurisdiction when no relationship is found.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL when it cannot be resolved.
   */
  public function resolveJurisdictionForNode(NodeInterface $node): ?int;

  /**
   * Checks whether a category term belongs to the given jurisdiction.
   *
   * @param int $categoryTid
   *   The service_category taxonomy term ID.
   * @param int $jurisdictionId
   *   The jurisdiction group ID the category must belong to.
   *
   * @return bool
   *   TRUE when the term exists, is a service_category term, and its
   *   field_jurisdiction matches $jurisdictionId exactly.
   */
  public function isCategoryInJurisdiction(int $categoryTid, int $jurisdictionId): bool;

  /**
   * Splits a source service request into a new linked child request.
   *
   * Creates the child with ONE insert save (pre-populating a provenance
   * status note so the presave auto-note is suppressed), appends a
   * provenance note to the source with ONE save (no status change), and
   * records the link.
   *
   * @param \Drupal\node\NodeInterface $source
   *   The original service request node.
   * @param array $payload
   *   Normalized split payload with keys: category_tid (int), title
   *   (string), description (string), media_ids (int[]), copy_reporter
   *   (bool), notify_citizen (bool).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user performing the split.
   *
   * @return array{child: \Drupal\node\NodeInterface, original: \Drupal\node\NodeInterface}
   *   The saved child and original nodes.
   */
  public function split(NodeInterface $source, array $payload, AccountInterface $account): array;

}
