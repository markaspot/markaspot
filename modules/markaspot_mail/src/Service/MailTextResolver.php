<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Utility\Token;
use Drupal\language\ConfigurableLanguageManagerInterface;

/**
 * Resolves admin-editable mail text templates with per-field fallback.
 *
 * Single source of truth for the override-then-default-then-empty chain
 * that used to be copy-pasted as a private resolveFromConfig() method in
 * ResubmissionRequestBuilder, WorkspaceVerificationBuilder and
 * WorkspaceWelcomeBuilder, and mirrors the same array-union merge
 * _markaspot_fastmap_mail_template() (markaspot_fastmap.module) uses for
 * its two-field (subject/body) template: a language config override wins
 * per top-level field that is actually present in it; any field missing
 * from the override falls back to the active default-language config;
 * a field missing from both resolves to an empty string (or an empty
 * list for the sequence-typed body_blocks field), leaving the caller to
 * decide its own hardcoded fallback.
 *
 * resolveField() serves single-field templates (markaspot_resubmission
 * .mail:resubmit_request, markaspot_fastmap.mail:workspace_verification /
 * workspace_welcome — two fields each: subject + body). resolve() serves
 * the six-slot markaspot_mail.texts notification templates (subject,
 * headline, intro, body_blocks, cta_label, preheader). Both share the
 * identical merge; resolve() is not implemented in terms of repeated
 * resolveField() calls purely to avoid re-reading config twice per slot.
 */
class MailTextResolver {

  /**
   * Slot keys read by resolve() / replaceTokens() for notification mails.
   */
  private const NOTIFICATION_SLOTS = ['subject', 'headline', 'intro', 'body_blocks', 'cta_label', 'preheader'];

  /**
   * Mail-safe HTML tags allowed in the `intro` and `body_blocks` slots.
   *
   * Single source of truth for markaspot_mail's Xss::filter allowlist:
   * \Drupal\markaspot_mail\Mail\SplitParagraphsTrait::splitParagraphs()
   * references this constant instead of keeping its own copy. Covers
   * operator formatting intent (headings, lists, inline emphasis, links)
   * without exposing the filterAdmin surface (<style>, <iframe>,
   * <object>, etc., which filterAdmin permits but mail bodies never need).
   */
  public const MAIL_ALLOWED_TAGS = [
    'p', 'br', 'strong', 'em', 'b', 'i', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'span',
  ];

  /**
   * Scalar slots rendered `|raw` by mail-card-transactional.html.twig.
   *
   * Subject/headline/cta_label/preheader render as plain/escaped text in
   * that template, so only these need HTML sanitizing before token
   * replacement. body_blocks is handled separately in replaceTokens()
   * because it is a list, not a scalar slot.
   */
  private const HTML_SLOTS = ['intro'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConfigurableLanguageManagerInterface $languageManager,
    private readonly Token $token,
  ) {}

  /**
   * Resolves a single field of a config template, override then default.
   *
   * @param string $configName
   *   The config object name, e.g. 'markaspot_fastmap.mail'.
   * @param string $key
   *   The top-level key inside that config, e.g. 'workspace_verification'.
   * @param string $field
   *   The field to read from the resolved template, e.g. 'subject'.
   * @param string $langcode
   *   The langcode to resolve the language config override for.
   *
   * @return string
   *   The resolved field value, or an empty string when neither the
   *   override nor the default config has it set.
   */
  public function resolveField(string $configName, string $key, string $field, string $langcode): string {
    $merged = $this->mergedTemplate($configName, $key, $langcode);
    return (string) ($merged[$field] ?? '');
  }

  /**
   * Resolves all six notification-mail slots for a markaspot_mail.texts key.
   *
   * @param string $configName
   *   The config object name, e.g. 'markaspot_mail.texts'.
   * @param string $key
   *   The top-level key inside that config, e.g. 'report_confirmation'.
   * @param string $langcode
   *   The langcode to resolve the language config override for.
   *
   * @return array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string}
   *   All six slots, always present. Missing values resolve to an empty
   *   string ('' for scalars, [] for body_blocks).
   */
  public function resolve(string $configName, string $key, string $langcode): array {
    $merged = $this->mergedTemplate($configName, $key, $langcode);

    $result = [];
    foreach (self::NOTIFICATION_SLOTS as $slot) {
      if ($slot === 'body_blocks') {
        $blocks = $merged[$slot] ?? NULL;
        $result[$slot] = is_array($blocks) ? array_values(array_map('strval', $blocks)) : [];
        continue;
      }
      $result[$slot] = (string) ($merged[$slot] ?? '');
    }

    /** @var array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string} $result */
    return $result;
  }

  /**
   * Runs Drupal token replacement over every slot produced by resolve().
   *
   * Scalar slots are replaced directly; body_blocks is replaced entry by
   * entry so a token expanding to multiple lines never merges adjacent
   * paragraphs. Slots that are already empty are left untouched instead of
   * paying for a no-op token_replace() call.
   *
   * intro and each body_blocks entry are run through Xss::filter(
   * MAIL_ALLOWED_TAGS) BEFORE token replacement: these two slots are the
   * only ones rendered `|raw` by mail-card-transactional.html.twig, so an
   * admin-authored <script>/<img onerror>/<iframe> in the static template
   * text would otherwise reach citizen inboxes unfiltered. Filtering
   * before, not after, token replacement matters because [node:...]
   * bracket syntax is untouched by Xss::filter (it only strips/allows
   * HTML tags) but a token value inserted first could itself contain
   * literal `<` characters that then get walked by the filter as if the
   * admin had typed them.
   *
   * @param array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string} $slots
   *   The slot array as produced by resolve().
   * @param array<string, mixed> $tokenData
   *   Token replacement data, e.g. ['node' => $node].
   * @param string $langcode
   *   The langcode to replace tokens for.
   *
   * @return array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string}
   *   The same slot shape with tokens replaced.
   */
  public function replaceTokens(array $slots, array $tokenData, string $langcode): array {
    $options = ['langcode' => $langcode, 'clear' => TRUE];

    foreach (self::NOTIFICATION_SLOTS as $slot) {
      if ($slot === 'body_blocks') {
        continue;
      }
      $value = $slots[$slot] ?? '';
      if ($value === '') {
        continue;
      }
      if (in_array($slot, self::HTML_SLOTS, TRUE)) {
        $value = Xss::filter($value, self::MAIL_ALLOWED_TAGS);
      }
      $slots[$slot] = (string) $this->token->replace($value, $tokenData, $options);
    }

    $blocks = $slots['body_blocks'] ?? [];
    if (is_array($blocks) && $blocks !== []) {
      $slots['body_blocks'] = array_map(
        fn(string $block): string => (string) $this->token->replace(
          Xss::filter($block, self::MAIL_ALLOWED_TAGS),
          $tokenData,
          $options
        ),
        $blocks
      );
    }

    /** @var array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string} $slots */
    return $slots;
  }

  /**
   * Merges the language config override and the default config for a key.
   *
   * Array union (override + default): a field present in the override
   * (even as an explicit empty string) wins; a field entirely absent from
   * the override falls back to the default. Identical mechanism to
   * _markaspot_fastmap_mail_template()'s first merge pass, generalized to
   * an arbitrary field set instead of the fixed subject/body pair.
   *
   * @return array<string, mixed>
   *   The merged template array. May be missing keys entirely when neither
   *   source has them; callers coerce per field.
   */
  private function mergedTemplate(string $configName, string $key, string $langcode): array {
    $override = $this->languageManager
      ->getLanguageConfigOverride($langcode, $configName)
      ->get($key);
    $default = $this->configFactory
      ->get($configName)
      ->get($key);

    return (is_array($override) ? $override : []) + (is_array($default) ? $default : []);
  }

}
