<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

/**
 * Stores and retrieves links between service requests created by a split.
 *
 * Backed by the markaspot_request_links table. Direction is meaningful:
 * source_nid is always the original request, target_nid the split-off
 * child (unlike the pair-normalized markaspot_ai_duplicate_matches table).
 */
interface RequestLinkServiceInterface {

  /**
   * Records a link between an original request and its split-off child.
   *
   * @param int $sourceNid
   *   The original service request node ID.
   * @param int $targetNid
   *   The split-off child service request node ID.
   * @param int $uid
   *   The user who performed the split.
   * @param string $linkType
   *   The link relationship type. Defaults to 'split'.
   */
  public function storeLink(int $sourceNid, int $targetNid, int $uid, string $linkType = 'split'): void;

  /**
   * Gets every link row where the given node is either side of the pair.
   *
   * @param int $nid
   *   The node ID to look up, as either source_nid or target_nid.
   * @param string $linkType
   *   The link relationship type. Defaults to 'split'.
   *
   * @return array<int, array{source_nid: int, target_nid: int, link_type: string, uid: int, created: int}>
   *   The matching rows, newest first.
   */
  public function getLinksForNode(int $nid, string $linkType = 'split'): array;

  /**
   * Purges every link row where the given node is either side of the pair.
   *
   * @param int $nid
   *   The node ID being deleted.
   *
   * @return int
   *   The number of rows deleted.
   */
  public function deleteForNode(int $nid): int;

}
