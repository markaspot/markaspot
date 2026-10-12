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
 * Provenance is persisted in the paragraph's behavior settings: new originals
 * are marked on creation, existing ones by markaspot_ai_update_11902(), and
 * markaspot_ai_paragraph_presave() keeps the mark through every later save,
 * so editing the text (even removing the marker) never lifts the exclusion.
 * The fixed marker text stays a fallback for originals not yet migrated;
 * matching it anywhere only errs towards exclusion.
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
   * Paragraph bundle of internal remarks.
   */
  public const BUNDLE = 'internal_remark';

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
   * Records system provenance on a retained-original remark.
   *
   * Writes the serialized field as well: Paragraph::preSave() serializes the
   * behavior settings before hook_paragraph_presave() runs, so a mark set from
   * that hook would otherwise never reach storage.
   */
  public static function mark(ParagraphInterface $paragraph): void {
    $paragraph->setBehaviorSettings(self::SETTINGS_KEY, [
      'pii_original' => TRUE,
      'exclude_from_ai' => TRUE,
    ]);
    $paragraph->set('behavior_settings', serialize($paragraph->getAllBehaviorSettings()));
  }

  /**
   * Whether the persisted provenance mark is complete.
   */
  public static function isMarked(ParagraphInterface $paragraph): bool {
    return (bool) $paragraph->getBehaviorSetting(self::SETTINGS_KEY, 'pii_original', FALSE)
      && (bool) $paragraph->getBehaviorSetting(self::SETTINGS_KEY, 'exclude_from_ai', FALSE);
  }

  /**
   * Whether the current text carries the system marker.
   */
  public static function carriesMarker(ParagraphInterface $paragraph): bool {
    if (!$paragraph->hasField('field_internal_remark_text') || $paragraph->get('field_internal_remark_text')->isEmpty()) {
      return FALSE;
    }
    return str_contains((string) $paragraph->get('field_internal_remark_text')->value, self::MARKER);
  }

  /**
   * Whether a remark holds a system-retained PII original.
   */
  public static function isPiiOriginal(ParagraphInterface $paragraph): bool {
    return (bool) $paragraph->getBehaviorSetting(self::SETTINGS_KEY, 'pii_original', FALSE)
      || self::carriesMarker($paragraph);
  }

  /**
   * Whether a remark must stay out of every AI provider prompt.
   *
   * Exclusion is entity-wide: a host may still reference an older revision
   * of a remark (entity_reference_revisions keeps target_revision_id), and
   * that revision can predate the mark while holding the same PII. Such a
   * revision is excluded when the default revision of the same paragraph is.
   */
  public static function isExcludedFromAi(ParagraphInterface $paragraph): bool {
    if (self::revisionExcluded($paragraph)) {
      return TRUE;
    }
    $default = self::defaultRevisionOf($paragraph);
    return $default !== NULL && self::revisionExcluded($default);
  }

  /**
   * Whether this very revision is marked, migrated or carries the marker.
   */
  private static function revisionExcluded(ParagraphInterface $paragraph): bool {
    return (bool) $paragraph->getBehaviorSetting(self::SETTINGS_KEY, 'exclude_from_ai', FALSE)
      // Legacy field_notes was never prompt input. Moving it into paragraphs
      // must not silently expose historical free text to an external AI.
      || (bool) $paragraph->getBehaviorSetting(self::LEGACY_NOTES_KEY, 'exclude_from_ai', FALSE)
      || self::isPiiOriginal($paragraph);
  }

  /**
   * Loads the default revision when the given one is not it.
   *
   * Only reached for an unflagged revision, so ordinary remarks on their
   * default revision cost nothing. Entity storage caches the load per request.
   */
  private static function defaultRevisionOf(ParagraphInterface $paragraph): ?ParagraphInterface {
    if ($paragraph->isNew() || $paragraph->isDefaultRevision() || !\Drupal::hasContainer()) {
      return NULL;
    }
    $default = \Drupal::entityTypeManager()->getStorage('paragraph')->load($paragraph->id());
    if (!$default instanceof ParagraphInterface || $default->getRevisionId() === $paragraph->getRevisionId()) {
      return NULL;
    }
    return $default;
  }

  /**
   * Keeps the provenance mark on a remark that is being saved.
   *
   * Once a remark was an original (marked, or still carrying the marker), it
   * stays one: neither an edited text nor overwritten behavior settings can
   * lift its AI exclusion.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The remark about to be saved.
   * @param \Drupal\paragraphs\ParagraphInterface|null $original
   *   The stored version, NULL for a new paragraph.
   */
  public static function preserveOnSave(ParagraphInterface $paragraph, ?ParagraphInterface $original): void {
    if ($paragraph->bundle() !== self::BUNDLE || self::isMarked($paragraph)) {
      return;
    }
    if (self::isPiiOriginal($paragraph) || ($original !== NULL && self::isPiiOriginal($original))) {
      self::mark($paragraph);
    }
  }

}
