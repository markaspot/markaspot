<?php

namespace Drupal\markaspot_request_id\Service;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for the request ID generator service.
 */
interface RequestIdGeneratorInterface {

  /**
   * Generates a unique request ID for a jurisdiction.
   *
   * Uses per-jurisdiction sequence tracking with optional yearly rollover.
   * Child jurisdictions share the root parent's counter.
   *
   * @param int|null $jurisdictionId
   *   The root jurisdiction group ID, or NULL for single-tenant (uses 0).
   *
   * @return string
   *   The formatted request ID (e.g., "42-2026").
   */
  public function generateRequestId(?int $jurisdictionId = NULL): string;

  /**
   * Resolves the root jurisdiction ID from a service request node.
   *
   * Derives the jurisdiction from the node's category term's
   * field_jurisdiction, then resolves to root via hierarchy resolver
   * if markaspot_group is installed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The root jurisdiction group ID, or NULL if not determinable.
   */
  public function resolveJurisdictionFromNode(EntityInterface $node): ?int;

}
