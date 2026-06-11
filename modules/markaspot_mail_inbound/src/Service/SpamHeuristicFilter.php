<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\markaspot_mail_inbound\Dto\InboundMessage;

/**
 * Pure, stateless heuristic that flags non-report machine mail.
 *
 * Detects auto-replies, out-of-office notices, bulk and mailing-list traffic
 * from their standard headers, plus the trivial empty-body / empty-subject
 * case, so the triage inbox holds only genuine citizen mails.
 *
 * Design principle (from the rebuild doc): be CONSERVATIVE. A false positive
 * silently drops a real citizen report, which is the worst outcome of this
 * pipeline, so every rule here matches only unambiguous machine markers. When
 * in doubt, this returns NULL (not spam) and the mail is staged for a human.
 *
 * All input is attacker-controlled. The headers are routing signals only;
 * nothing here is rendered.
 */
final class SpamHeuristicFilter {

  /**
   * Reasons reported when a message is classified as non-report mail.
   *
   * Returned to the caller for logging only; never contains message content.
   */
  public const REASON_AUTO_SUBMITTED = 'Auto-Submitted header indicates an automated message';
  public const REASON_AUTO_RESPONSE_SUPPRESS = 'X-Auto-Response-Suppress header present (auto-reply)';
  public const REASON_PRECEDENCE = 'Precedence header indicates bulk, list or junk mail';
  public const REASON_MAILING_LIST = 'Mailing-list headers present (List-Id / List-Unsubscribe)';
  public const REASON_EMPTY = 'Empty subject and empty body';

  /**
   * Classifies a parsed message as a non-report (machine) mail or not.
   *
   * @param \Drupal\markaspot_mail_inbound\Dto\InboundMessage $message
   *   The parsed message.
   *
   * @return string|null
   *   A short, log-safe reason string when the message should NOT be staged
   *   (it is an auto-reply, bulk, list or empty machine mail); NULL when the
   *   message looks like a genuine citizen report and must be staged.
   */
  public function classify(InboundMessage $message): ?string {
    $headers = $message->autoResponseHeaders;

    // RFC 3834: Auto-Submitted with any value other than "no" is an automated
    // message (auto-replied / auto-generated / auto-notified). "no" or absent
    // means a human sent it.
    $autoSubmitted = trim($headers['auto-submitted'] ?? '');
    if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
      return self::REASON_AUTO_SUBMITTED;
    }

    // Microsoft / Exchange auto-reply suppression marker. Its mere presence on
    // an inbound mail signals an auto-response (OOF, DR, NDR, RN, NRN, AutoReply).
    if (trim($headers['x-auto-response-suppress'] ?? '') !== '') {
      return self::REASON_AUTO_RESPONSE_SUPPRESS;
    }

    // Precedence: bulk | list | junk are the conventional markers of mass and
    // list mail. "Precedence: first-class" / "normal" (and any other value) is
    // left to stage to avoid dropping a real report.
    $precedence = trim($headers['precedence'] ?? '');
    if (in_array($precedence, ['bulk', 'list', 'junk'], TRUE)) {
      return self::REASON_PRECEDENCE;
    }

    // RFC 2369 mailing-list markers. A citizen report is point-to-point and
    // carries neither.
    if (trim($headers['list-id'] ?? '') !== '' || trim($headers['list-unsubscribe'] ?? '') !== '') {
      return self::REASON_MAILING_LIST;
    }

    // A mail with neither a subject nor any body has no report content to
    // triage. Both must be empty: a subject-only or body-only mail can still
    // be a terse but genuine report and is staged.
    if (trim($message->subject) === '' && trim($message->textBody) === '' && trim($message->htmlBody) === '') {
      return self::REASON_EMPTY;
    }

    return NULL;
  }

}
