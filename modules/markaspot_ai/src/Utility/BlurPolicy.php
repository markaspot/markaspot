<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Utility;

use Psr\Log\LoggerInterface;

/**
 * Shared hosting policy, independent of enabled Drupal modules.
 */
final class BlurPolicy {

  /**
   * Whether this request has already warned about an unfamiliar value.
   */
  private static bool $warned = FALSE;

  /**
   * Reads the hosting requirement, failing closed for unfamiliar values.
   */
  public static function mode(LoggerInterface $logger): string {
    $value = strtolower(trim((string) getenv('MARKASPOT_BLUR_REQUIRED')));
    if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], TRUE) && $value[0] === substr($value, -1)) {
      $value = trim(substr($value, 1, -1));
    }
    if ($value === '') {
      // Only canonical ENV signals hosting; config and VISION_BLUR_URL do not.
      return trim((string) getenv('MARKASPOT_BLUR_URL')) !== '' ? 'auto-required' : 'auto-unprotected';
    }
    $disabled = ['0', 'false', 'no', 'off'];
    if (!in_array($value, $disabled, TRUE) && !in_array($value, ['1', 'true', 'yes', 'on'], TRUE) && !self::$warned) {
      $logger->warning('Unrecognized MARKASPOT_BLUR_REQUIRED value; enforcing blur for this request.');
      self::$warned = TRUE;
    }
    return in_array($value, $disabled, TRUE) ? 'off' : 'strict';
  }

  /**
   * Reports whether the effective mode requires preprocessing.
   */
  public static function isRequired(LoggerInterface $logger): bool {
    return in_array(self::mode($logger), ['strict', 'auto-required'], TRUE);
  }

  /**
   * Checks environment-only credentials, including the existing legacy key.
   */
  public static function hasEnvironmentCredentials(): bool {
    return trim((string) getenv('MARKASPOT_BLUR_URL')) !== ''
      && (trim((string) getenv('MARKASPOT_BLUR_API_KEY')) !== '' || trim((string) getenv('AI_API_KEY')) !== '');
  }

}
