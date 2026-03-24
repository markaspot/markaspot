<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Service;

/**
 * Interface for the moderation service.
 *
 * Provides methods for creating, querying, and managing content flags
 * on citizen report service requests.
 */
interface ModerationServiceInterface {

  /**
   * Creates a new flag for a service request.
   *
   * @param string $serviceRequestId
   *   The Open311 service request ID.
   * @param string $reason
   *   The flag reason (spam, offensive, personal, location, other).
   * @param string|null $details
   *   Optional details (max 500 characters).
   * @param string $ipHash
   *   SHA-256 hash of the reporter's IP address.
   * @param string|null $sessionHash
   *   SHA-256 hash of the reporter's session ID.
   *
   * @return int
   *   The created flag ID.
   *
   * @throws \InvalidArgumentException
   *   If no node exists for the given service request ID.
   */
  public function createFlag(string $serviceRequestId, string $reason, ?string $details, string $ipHash, ?string $sessionHash): int;

  /**
   * Gets flagged service requests for the given jurisdictions.
   *
   * @param array $jurisdictionIds
   *   Array of jurisdiction group IDs.
   * @param array $filters
   *   Optional filters: reason, min_count, start_date, end_date.
   * @param int $limit
   *   Maximum number of results.
   * @param int $offset
   *   Result offset for pagination.
   *
   * @return array
   *   Array of flagged request data objects.
   */
  public function getFlaggedRequests(array $jurisdictionIds, array $filters = [], int $limit = 50, int $offset = 0): array;

  /**
   * Gets the count of distinct flagged nodes for the given jurisdictions.
   *
   * @param array $jurisdictionIds
   *   Array of jurisdiction group IDs.
   *
   * @return int
   *   Number of distinct nodes with active flags.
   */
  public function getFlagCountForJurisdictions(array $jurisdictionIds): int;

  /**
   * Gets all flags for a specific service request node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return array
   *   Array of flag records.
   */
  public function getFlagsForRequest(int $nid): array;

  /**
   * Dismisses all active flags for a service request node.
   *
   * @param int $nid
   *   The node ID.
   * @param int $uid
   *   The UID of the moderator performing the dismissal.
   */
  public function dismissFlags(int $nid, int $uid): void;

  /**
   * Hides (unpublishes) a service request and dismisses its flags.
   *
   * @param int $nid
   *   The node ID.
   * @param int $uid
   *   The UID of the moderator performing the action.
   */
  public function hideRequest(int $nid, int $uid): void;

}
