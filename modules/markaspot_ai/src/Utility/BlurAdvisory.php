<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Utility;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;

/**
 * Shared advisory wording and daily throttle for image-bearing AI requests.
 */
final class BlurAdvisory {

  /**
   * Timestamp shared by both modules, across requests and logger channels.
   */
  public const STATE_KEY = 'markaspot_ai.blur_advisory_last_warning';

  /**
   * Explains unprotected operation without including deployment details.
   */
  public static function message(): TranslatableMarkup {
    return new TranslatableMarkup('Citizen photos are sent to the configured AI provider without blurring faces and licence plates; consider operating a blur service and setting MARKASPOT_BLUR_URL; set MARKASPOT_BLUR_REQUIRED=0 to acknowledge this deliberately (for example when a local model keeps images on your own server).');
  }

  /**
   * Explains the required environment configuration without exposing values.
   */
  public static function errorMessage(): TranslatableMarkup {
    return new TranslatableMarkup('Image blurring is required, but its environment URL or API key is missing. Set MARKASPOT_BLUR_URL and MARKASPOT_BLUR_API_KEY. Images cannot be sent to the AI provider.');
  }

  /**
   * Logs at most once per day, only when unprotected images will be sent.
   */
  public static function warn(StateInterface $state, LoggerInterface $logger, int $now, string $mode, bool $enabled): void {
    if ($mode !== 'auto-unprotected' || $enabled) {
      return;
    }
    $last = $state->get(self::STATE_KEY);
    if ($last !== NULL && $now - (int) $last < 86400) {
      return;
    }
    $logger->warning(self::message()->getUntranslatedString());
    $state->set(self::STATE_KEY, $now);
  }

}
