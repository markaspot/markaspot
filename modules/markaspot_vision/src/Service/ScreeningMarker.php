<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Service;

/**
 * Verifies a real blur checkpoint against its source and current policy.
 */
final class ScreeningMarker {

  /**
   * Only a successful blur under the applicable policy can be reused.
   */
  public static function accepts(array $marker, string|false $hash, bool $required): bool {
    return $hash !== FALSE
      && ($marker['privacy_screened'] ?? FALSE) === TRUE
      && ($marker['processed'] ?? FALSE) === TRUE
      && ($marker['source_sha256'] ?? '') === $hash
      && (!$required || ($marker['blur_required'] ?? FALSE) === TRUE);
  }

}
