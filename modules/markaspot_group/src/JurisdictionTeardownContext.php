<?php

namespace Drupal\markaspot_group;

/**
 * Marks jurisdictions whose complete teardown is running in this process.
 *
 * Integrity guards (e.g. "keep one status per Open311 mapping") protect a
 * living jurisdiction. While the whole jurisdiction is being removed they
 * would block the teardown itself, so guards ask this context and stand
 * down for exactly the jurisdiction being torn down. The state is
 * process-local and set only by code, never by request input.
 */
final class JurisdictionTeardownContext {

  /**
   * Jurisdiction group IDs being torn down, as keys.
   *
   * @var array<int, true>
   */
  private static array $active = [];

  /**
   * Marks the jurisdiction as being torn down.
   *
   * Pair every call with leave() in a finally block.
   */
  public static function enter(int $jurisdictionId): void {
    self::$active[$jurisdictionId] = TRUE;
  }

  /**
   * Clears the teardown mark of the jurisdiction.
   */
  public static function leave(int $jurisdictionId): void {
    unset(self::$active[$jurisdictionId]);
  }

  /**
   * Whether the jurisdiction is currently being torn down.
   */
  public static function isActive(int $jurisdictionId): bool {
    return $jurisdictionId > 0 && isset(self::$active[$jurisdictionId]);
  }

}
