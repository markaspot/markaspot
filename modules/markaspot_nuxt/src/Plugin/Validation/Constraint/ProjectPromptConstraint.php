<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Bounds the image-analysis project context of a jurisdiction.
 *
 * Same rules as the onboarding (validateProjectPrompt() in the FastMap
 * layer): at most 2000 characters after trimming, no control characters
 * other than tab, line feed and carriage return. Only new or changed values
 * are checked, so a longer prompt saved before this rule keeps working.
 * Line endings count as line feeds, like in the browser.
 *
 * Entity validation only runs where a form or custom code validates the
 * group; the shipped JSON:API resource does not expose the field. Code that
 * writes the field directly cleans the value with normalize() instead.
 */
#[Constraint(id: 'MarkaspotProjectPrompt', label: new TranslatableMarkup('Image-analysis project context'))]
final class ProjectPromptConstraint extends SymfonyConstraint {

  /**
   * Longest accepted context, in characters after trimming.
   */
  public const MAX_LENGTH = 2000;

  /**
   * Control characters the onboarding refuses; tab, LF and CR are allowed.
   */
  public const CONTROL_CHARACTERS = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u';

  /**
   * Violation message for a context that is too long.
   */
  public string $tooLongMessage = 'The image-analysis context can have at most @max characters (@length given).';

  /**
   * Violation message for control characters.
   */
  public string $controlCharacterMessage = 'The image-analysis context must be plain text without control characters.';

  /**
   * Cleans a project context for code that writes the field directly.
   *
   * Provisioning and seeding save the group without validation, so they get
   * the same bounds here: invalid UTF-8 bytes become question marks,
   * refused control characters are dropped, line endings become line feeds,
   * and the trimmed text is capped at MAX_LENGTH characters.
   */
  public static function normalize(string $prompt): string {
    $prompt = self::lineFeeds(mb_scrub($prompt, 'UTF-8'));
    $prompt = (string) preg_replace(self::CONTROL_CHARACTERS, '', $prompt);
    return mb_substr(trim($prompt), 0, self::MAX_LENGTH);
  }

  /**
   * Turns CRLF line endings, as browsers submit them, into line feeds.
   */
  public static function lineFeeds(string $prompt): string {
    return str_replace("\r\n", "\n", $prompt);
  }

}
