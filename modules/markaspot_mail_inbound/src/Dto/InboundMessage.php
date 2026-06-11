<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Dto;

/**
 * Transport-agnostic value object for a parsed inbound email.
 *
 * Produced by MailParserService from a raw RFC822/MIME string and consumed
 * by MailIngestOrchestrator. All values originate from an untrusted source
 * (the citizen's mail client or an attacker); consumers must treat every
 * property as hostile input.
 */
final class InboundMessage {

  /**
   * Constructs an InboundMessage.
   *
   * @param string $fromAddress
   *   The sender email address (lowercased), empty when missing.
   * @param string $fromName
   *   The decoded sender display name, empty when missing.
   * @param string[] $toAddresses
   *   All recipient addresses (To, Cc, Delivered-To), lowercased.
   * @param string $subject
   *   The MIME-decoded subject line (raw, not yet sanitized).
   * @param string $textBody
   *   The plain text body, empty when the message has no text part.
   * @param string $htmlBody
   *   The HTML body, empty when the message has no HTML part.
   * @param string $messageId
   *   The normalized Message-ID (no angle brackets), empty when missing.
   * @param string $inReplyTo
   *   The normalized In-Reply-To message id, empty when missing.
   * @param string[] $references
   *   Normalized References message ids, oldest first.
   * @param int|null $date
   *   The Date header as unix timestamp, NULL when missing or unparsable.
   * @param \Drupal\markaspot_mail_inbound\Dto\InboundAttachment[] $attachments
   *   The attachments.
   * @param array<string, string> $autoResponseHeaders
   *   Lowercased automated-mail signalling headers used by the spam / not-a-
   *   report heuristic, keyed by lowercased header name (auto-submitted,
   *   precedence, x-auto-response-suppress, list-id, list-unsubscribe). Each
   *   value is the trimmed header value. Additive: defaults to an empty array
   *   so existing callers (and tests) constructing an InboundMessage without
   *   it keep working unchanged.
   */
  public function __construct(
    public readonly string $fromAddress,
    public readonly string $fromName,
    public readonly array $toAddresses,
    public readonly string $subject,
    public readonly string $textBody,
    public readonly string $htmlBody,
    public readonly string $messageId,
    public readonly string $inReplyTo,
    public readonly array $references,
    public readonly ?int $date,
    public readonly array $attachments,
    public readonly array $autoResponseHeaders = [],
  ) {
  }

  /**
   * Returns all threading candidate message ids (In-Reply-To + References).
   *
   * @return string[]
   *   Unique, non-empty message ids.
   */
  public function getThreadingIds(): array {
    $ids = $this->references;
    if ($this->inReplyTo !== '') {
      $ids[] = $this->inReplyTo;
    }
    return array_values(array_unique(array_filter($ids)));
  }

}
