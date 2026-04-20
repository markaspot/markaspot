<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

use Drupal\Component\Render\Markup;
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
   * Splits a body string into sanitized, paragraph-delimited blocks.
   *
   * Each paragraph is passed through Xss::filterAdmin() and wrapped in
   * a Markup object so Twig renders the HTML without double-escaping.
   * filterAdmin() strips script elements and event-handler attributes
   * while preserving formatting tags (<p>, <strong>, <a href>, etc.) --
   * appropriate for operator-authored ECA templates that embed
   * citizen-submitted token values like [node:body].
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
        $out[] = Markup::create(Xss::filterAdmin($paragraph));
      }
    }
    return $out;
  }

}
