<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index;

/**
 * One projected service request: the index row plus its child table rows.
 */
final class RequestIndexRecord {

  /**
   * Constructs a projected record.
   *
   * @param int $nid
   *   Service request node ID.
   * @param array<string, int|string|null> $row
   *   Column values of markaspot_request_index, without nid and projected.
   * @param list<int> $organisations
   *   Distinct field_organisation group IDs in delta order.
   * @param list<int> $jurisdictions
   *   Distinct field_jurisdiction group IDs in delta order.
   */
  public function __construct(
    public readonly int $nid,
    public readonly array $row,
    public readonly array $organisations,
    public readonly array $jurisdictions,
  ) {}

}
