<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Xss;

/**
 * Splits a pre-rendered mail body into paragraph-delimited blocks.
 *
 * Five builders (EcaAction, Escalation, Moderation, GroupOrg,
 * Resubmission) receive a flat text body and need to split it on blank
 * lines so the first paragraph lands in content.intro and the rest in
 * content.body_blocks. The split is identical everywhere: /\n\s*\n/ with
 * trim + empty-drop, so it lives here.
 */
trait SplitParagraphsTrait {

  /**
   * Mail-safe HTML tags for body paragraphs.
   *
   * Covers operator formatting intent (headings, lists, inline emphasis,
   * links) without exposing the filterAdmin surface (<style>, <iframe>,
   * <object>, etc. which filterAdmin permits but mail bodies never need).
   */
  private const MAIL_ALLOWED_TAGS = [
    'p', 'br', 'strong', 'em', 'b', 'i', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'span',
  ];

  /**
   * Splits a body string into sanitized, paragraph-delimited blocks.
   *
   * Each paragraph is filtered with an explicit mail-safe tag whitelist and
   * wrapped in a Markup object so Twig renders the HTML without double-
   * escaping. The tag list covers operator formatting intent while blocking
   * citizen-submitted token content (e.g. [node:body]) from injecting
   * script, style, or object elements into mail output.
   *
   * @param string $body
   *   The body text. Blank-line-separated paragraphs.
   *
   * @return list<\Drupal\Component\Render\MarkupInterface>
   *   Trimmed non-empty paragraphs in input order, each sanitized.
   */
  protected function splitParagraphs(string $body): array {
    $raw = preg_split("/\n\s*\n/", $body) ?: [$body];
    $out = [];
    foreach ($raw as $paragraph) {
      $paragraph = trim($paragraph);
      if ($paragraph !== '') {
        $out[] = Markup::create(Xss::filter($paragraph, self::MAIL_ALLOWED_TAGS));
      }
    }
    return $out;
  }

}
