<?php

declare(strict_types=1);

namespace Drupal\markaspot_cap\Service;

/**
 * Advances CAP feed State from a current transaction-locked database row.
 */
interface CapFeedStateStoreInterface {

  /**
   * Advances one feed timestamp monotonically.
   */
  public function advance(string $key, int $requestTime): int;

}
