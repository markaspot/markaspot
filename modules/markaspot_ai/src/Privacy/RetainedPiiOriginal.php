<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Privacy;

use Drupal\paragraphs\ParagraphInterface;

/**
 * Provenance of the unredacted report text kept by PII redaction.
 *
 * PII redaction replaces the public body and retains the original in an
 * internal remark for staff review (markaspot/markaspot-ui#344). Staff may
 * read that original, but it must never become input of an external AI
 * provider again: it is exactly the text the redaction kept out of the
 * pipeline. Every prompt builder that reads internal remarks decides through
 * isExcludedFromAi(), the single place that knows which remarks are off
 * limits.
 *
 * New originals carry explicit behavior-settings metadata. Originals written
 * before that metadata existed are recognised by the fixed system marker the
 * redaction has always prefixed them with, so no update hook has to rewrite
 * stored remarks. Matching the marker anywhere in the text only errs towards
 * exclusion.
 */
final class RetainedPiiOriginal {

  /**
   * Behavior-settings key of the provenance metadata.
   */
  public const SETTINGS_KEY = 'markaspot_ai';

  /**
   * Fixed prefix of every retained original, never translated.
   */
  public const MARKER = 'Original text (PII removed by system):';

  /**
   * Behavior-settings key of migrated legacy notes (LegacyNotesMigration).
   */
  private const LEGACY_NOTES_KEY = 'markaspot_legacy_notes';

  /**
   * Builds the remark text that retains the unredacted original.
   */
  public static function remarkText(string $original): string {
    return self::MARKER . "\n\n" . $original;
  }

  /**
   * Records system provenance on a new retained-original remark.
   */
  public static function mark(ParagraphInterface $paragraph): void {
    $paragraph->setBehaviorSettings(self::SETTINGS_KEY, [
      'pii_original' => TRUE,
      'exclude_from_ai' => TRUE,
    ]);
  }

  /**
   * Whether a remark holds a system-retained PII original.
   */
  public static function isPiiOriginal(ParagraphInterface $paragraph): bool {
    if ($paragraph->getBehaviorSetting(self::SETTINGS_KEY, 'pii_original', FALSE)) {
      return TRUE;
    }
    if (!$paragraph->hasField('field_internal_remark_text') || $paragraph->get('field_internal_remark_text')->isEmpty()) {
      return FALSE;
    }
    return str_contains((string) $paragraph->get('field_internal_remark_text')->value, self::MARKER);
  }

  /**
   * Whether a remark must stay out of every AI provider prompt.
   */
  public static function isExcludedFromAi(ParagraphInterface $paragraph): bool {
    return (bool) $paragraph->getBehaviorSetting(self::SETTINGS_KEY, 'exclude_from_ai', FALSE)
      // Legacy field_notes was never prompt input. Moving it into paragraphs
      // must not silently expose historical free text to an external AI.
      || (bool) $paragraph->getBehaviorSetting(self::LEGACY_NOTES_KEY, 'exclude_from_ai', FALSE)
      || self::isPiiOriginal($paragraph);
  }

}
