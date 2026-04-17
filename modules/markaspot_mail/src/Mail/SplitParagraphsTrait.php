<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

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
   * Splits a body string into paragraph-delimited blocks.
   *
   * @param string $body
   *   The body text. Blank-line-separated paragraphs.
   *
   * @return list<string>
   *   Trimmed non-empty paragraphs in input order.
   */
  protected function splitParagraphs(string $body): array {
    $raw = preg_split("/\n\s*\n/", $body) ?: [$body];
    $out = [];
    foreach ($raw as $paragraph) {
      $paragraph = trim($paragraph);
      if ($paragraph !== '') {
        $out[] = $paragraph;
      }
    }
    return $out;
  }

}
