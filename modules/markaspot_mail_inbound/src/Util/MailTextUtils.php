<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Util;

/**
 * Pure, stateless text helpers for the inbound mail pipeline.
 *
 * All methods operate on attacker-controlled input. No method here may
 * produce output that is later rendered as HTML: everything is plain text.
 */
final class MailTextUtils {

  /**
   * Decodes a MIME encoded-word header (RFC 2047) to UTF-8.
   *
   * @param string $value
   *   The raw header value, possibly containing =?charset?enc?...?= words.
   *
   * @return string
   *   The decoded UTF-8 string.
   */
  public static function decodeMimeHeader(string $value): string {
    if ($value === '' || !str_contains($value, '=?')) {
      return $value;
    }
    $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($decoded === FALSE || $decoded === '') {
      $decoded = mb_decode_mimeheader($value);
    }
    return $decoded;
  }

  /**
   * Converts an HTML body to readable plain text.
   *
   * Removes script/style/head blocks including their content, converts
   * block-level breaks to newlines, strips all remaining tags and decodes
   * entities. The result is plain text and safe to store with the
   * plain_text format.
   *
   * @param string $html
   *   The HTML input.
   *
   * @return string
   *   Plain text.
   */
  public static function htmlToText(string $html): string {
    if ($html === '') {
      return '';
    }
    // Drop content-bearing, non-visible containers entirely.
    $text = preg_replace('/<(script|style|head|title|template)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? '';
    // Comments (including conditional Outlook comments).
    $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? '';
    // Block-level closers and explicit breaks become newlines.
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? '';
    $text = preg_replace('/<\/(p|div|li|tr|h[1-6]|blockquote|pre|table)>/i', "\n", $text) ?? '';
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return self::normalizeWhitespace($text);
  }

  /**
   * Normalizes plain text: strips control chars, collapses blank lines.
   *
   * Newlines and tabs are preserved; all other control and format
   * characters (including RTL override and zero-width tricks) are removed.
   *
   * @param string $text
   *   The input text.
   *
   * @return string
   *   Normalized text.
   */
  public static function normalizeWhitespace(string $text): string {
    // Remove control/format characters except \n and \t.
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    // Normalize line endings.
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // Collapse runs of spaces/tabs.
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
    // Trim trailing spaces per line and collapse 3+ newlines to 2.
    $text = preg_replace('/ ?\n ?/', "\n", $text) ?? '';
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? '';
    return trim($text);
  }

  /**
   * Sanitizes an email subject for use as a node title.
   *
   * Strips all control and format characters, collapses whitespace and
   * truncates to the given length on a UTF-8 boundary.
   *
   * @param string $subject
   *   The decoded subject.
   * @param int $maxLength
   *   Maximum length in characters.
   *
   * @return string
   *   The sanitized subject; empty string when nothing usable remains.
   */
  public static function sanitizeSubject(string $subject, int $maxLength = 255): string {
    $subject = preg_replace('/\p{C}+/u', ' ', $subject) ?? '';
    $subject = preg_replace('/\s+/u', ' ', $subject) ?? '';
    $subject = trim($subject);
    if (mb_strlen($subject) > $maxLength) {
      $subject = rtrim(mb_substr($subject, 0, $maxLength - 1)) . "\u{2026}";
    }
    return $subject;
  }

  /**
   * Normalizes a Message-ID style header value.
   *
   * @param string $id
   *   The raw value, e.g. "<abc@example.com>".
   *
   * @return string
   *   The id without angle brackets and surrounding whitespace.
   */
  public static function normalizeMessageId(string $id): string {
    return trim($id, " \t\r\n<>");
  }

  /**
   * Checks whether an email address matches an allow/block list entry set.
   *
   * Supported entry forms (case-insensitive):
   * - "user@example.com": exact address match.
   * - "@example.com" or "*@example.com" or "example.com": domain match.
   *
   * @param string $address
   *   The email address to test.
   * @param string[] $list
   *   The list entries.
   *
   * @return bool
   *   TRUE when any entry matches.
   */
  public static function matchesAddressList(string $address, array $list): bool {
    $address = mb_strtolower(trim($address));
    if ($address === '') {
      return FALSE;
    }
    $domain = str_contains($address, '@') ? substr($address, (int) strrpos($address, '@') + 1) : '';
    foreach ($list as $entry) {
      $entry = mb_strtolower(trim((string) $entry));
      if ($entry === '') {
        continue;
      }
      if (str_starts_with($entry, '*@')) {
        $entry = substr($entry, 1);
      }
      if (str_starts_with($entry, '@')) {
        if ($domain !== '' && $domain === substr($entry, 1)) {
          return TRUE;
        }
        continue;
      }
      if (str_contains($entry, '@')) {
        if ($entry === $address) {
          return TRUE;
        }
        continue;
      }
      // Bare domain entry.
      if ($domain !== '' && $domain === $entry) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
