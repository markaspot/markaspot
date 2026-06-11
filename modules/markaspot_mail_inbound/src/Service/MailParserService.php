<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\markaspot_mail_inbound\Dto\InboundAttachment;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\markaspot_mail_inbound\Exception\MailParseException;
use Drupal\markaspot_mail_inbound\Util\MailTextUtils;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

/**
 * Parses a raw RFC822/MIME message string into an InboundMessage DTO.
 *
 * Uses webklex/php-imap (pure PHP, no ext-imap) in standalone mode via
 * Message::fromString(), so the same code path serves IMAP-fetched messages
 * and .eml files ingested through Drush. Header MIME decoding (RFC 2047) is
 * normalized through MailTextUtils because webklex leaves some encoded
 * words untouched when ext-imap is absent.
 */
class MailParserService {

  /**
   * Builds the webklex parser config used for standalone parsing.
   *
   * Mirrors the IMAP fetch options in MailboxFetcher: soft_fail + a
   * fallback_date so a malformed Date header (e.g. "total-kaputtes-datum")
   * resolves to the fallback instead of throwing InvalidMessageDateException.
   * Without this, MailboxFetcher would enqueue such a mail (it sets the same
   * options on the IMAP client) but MailParserService would re-parse the raw
   * source here without them and drop the mail at parse time.
   *
   * Config::make() merges the vendor imap.php defaults (masks, decoders) that
   * Message::fromString() requires, then overlays our options - the bare
   * Config constructor would omit the masks and throw MaskNotFoundException.
   */
  protected function parserConfig(): Config {
    return Config::make([
      'options' => [
        'soft_fail' => TRUE,
        'fallback_date' => '01.01.1970 00:00:00',
      ],
    ]);
  }

  /**
   * Parses a raw message.
   *
   * @param string $raw
   *   The raw RFC822/MIME message source.
   *
   * @return \Drupal\markaspot_mail_inbound\Dto\InboundMessage
   *   The parsed message.
   *
   * @throws \Drupal\markaspot_mail_inbound\Exception\MailParseException
   *   When the message cannot be parsed at all.
   */
  public function parse(string $raw): InboundMessage {
    if (trim($raw) === '') {
      throw new MailParseException('Empty message source.');
    }

    try {
      $message = Message::fromString($raw, $this->parserConfig());
    }
    catch (\Throwable $e) {
      // Never include message content in the exception: it ends up in logs.
      throw new MailParseException('Unable to parse raw message: ' . $e->getMessage(), 0, $e);
    }

    [$fromAddress, $fromName] = $this->extractFrom($message);

    return new InboundMessage(
      fromAddress: $fromAddress,
      fromName: $fromName,
      toAddresses: $this->extractRecipients($message),
      subject: MailTextUtils::decodeMimeHeader($this->attributeToString($message, 'subject')),
      textBody: $this->safeString(fn() => $message->getTextBody()),
      htmlBody: $this->safeString(fn() => $message->getHTMLBody()),
      messageId: MailTextUtils::normalizeMessageId($this->attributeToString($message, 'message_id')),
      inReplyTo: MailTextUtils::normalizeMessageId($this->attributeToString($message, 'in_reply_to')),
      references: $this->extractReferences($message),
      date: $this->extractDate($message),
      attachments: $this->extractAttachments($message),
      autoResponseHeaders: $this->extractAutoResponseHeaders($message),
    );
  }

  /**
   * Extracts the headers the spam / not-a-report heuristic inspects.
   *
   * These headers (RFC 3834 Auto-Submitted, the de-facto Precedence and
   * Microsoft X-Auto-Response-Suppress, and RFC 2369 List-Id / List-Unsubscribe)
   * are the standard machine-detectable markers of auto-replies, bulk mail and
   * mailing lists. They are routing signals only: the values are never
   * rendered and only the heuristic reads them.
   *
   * @return array<string, string>
   *   Lowercased header values keyed by lowercased header name. Absent headers
   *   are omitted.
   */
  protected function extractAutoResponseHeaders(Message $message): array {
    $keys = [
      'auto_submitted' => 'auto-submitted',
      'precedence' => 'precedence',
      'x_auto_response_suppress' => 'x-auto-response-suppress',
      'list_id' => 'list-id',
      'list_unsubscribe' => 'list-unsubscribe',
    ];
    $headers = [];
    foreach ($keys as $webklexKey => $normalizedName) {
      $value = $this->attributeToString($message, $webklexKey);
      if ($value !== '') {
        $headers[$normalizedName] = mb_strtolower($value);
      }
    }
    return $headers;
  }

  /**
   * Extracts sender address and display name.
   *
   * @return array{0: string, 1: string}
   *   Lowercased address and decoded display name.
   */
  protected function extractFrom(Message $message): array {
    $address = '';
    $name = '';
    try {
      $from = $message->getFrom()->first();
      if (is_object($from)) {
        $address = mb_strtolower(trim((string) ($from->mail ?? '')));
        $name = MailTextUtils::decodeMimeHeader(trim((string) ($from->personal ?? '')));
      }
    }
    catch (\Throwable) {
      // Missing or malformed From header: leave empty, creator rejects it.
    }
    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
      $address = '';
    }
    return [$address, MailTextUtils::normalizeWhitespace($name)];
  }

  /**
   * Extracts all recipient addresses (To, Cc, Delivered-To), lowercased.
   *
   * @return string[]
   *   Unique recipient addresses.
   */
  protected function extractRecipients(Message $message): array {
    $addresses = [];
    foreach (['to', 'cc', 'delivered_to', 'x_original_to'] as $headerKey) {
      try {
        $attribute = $message->getHeader()?->get($headerKey);
        if ($attribute === NULL) {
          continue;
        }
        foreach ($attribute->all() as $value) {
          if (is_object($value) && isset($value->mail)) {
            $candidate = mb_strtolower(trim((string) $value->mail));
          }
          else {
            $candidate = mb_strtolower(trim((string) $value, " \t\r\n<>"));
          }
          if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $addresses[] = $candidate;
          }
        }
      }
      catch (\Throwable) {
        continue;
      }
    }
    return array_values(array_unique($addresses));
  }

  /**
   * Extracts the References header as normalized message ids.
   *
   * @return string[]
   *   Message ids without angle brackets, oldest first.
   */
  protected function extractReferences(Message $message): array {
    $references = [];
    try {
      $attribute = $message->getHeader()?->get('references');
      if ($attribute !== NULL) {
        foreach ($attribute->all() as $value) {
          // A single header line may carry several space-separated ids.
          foreach (preg_split('/\s+/', (string) $value) ?: [] as $candidate) {
            $candidate = MailTextUtils::normalizeMessageId($candidate);
            if ($candidate !== '') {
              $references[] = $candidate;
            }
          }
        }
      }
    }
    catch (\Throwable) {
      // Malformed header: treat as no references.
    }
    return array_values(array_unique($references));
  }

  /**
   * Extracts the Date header as a unix timestamp.
   */
  protected function extractDate(Message $message): ?int {
    try {
      $date = $message->getDate()->toDate();
      return $date instanceof \DateTimeInterface || method_exists($date, 'getTimestamp')
        ? (int) $date->getTimestamp()
        : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Extracts attachments into DTOs.
   *
   * @return \Drupal\markaspot_mail_inbound\Dto\InboundAttachment[]
   *   The attachments. Inline images count as attachments too.
   */
  protected function extractAttachments(Message $message): array {
    $attachments = [];
    try {
      foreach ($message->getAttachments() as $attachment) {
        $content = (string) ($attachment->content ?? '');
        if ($content === '') {
          continue;
        }
        $attachments[] = new InboundAttachment(
          filename: MailTextUtils::normalizeWhitespace(MailTextUtils::decodeMimeHeader((string) ($attachment->name ?? ''))),
          mimeType: mb_strtolower(trim((string) ($attachment->getMimeType() ?? ''))),
          content: $content,
          size: strlen($content),
        );
      }
    }
    catch (\Throwable) {
      // Attachment extraction failure must not lose the report text.
    }
    return $attachments;
  }

  /**
   * Reads a header attribute as string, tolerating missing headers.
   */
  protected function attributeToString(Message $message, string $key): string {
    try {
      $attribute = $message->getHeader()?->get($key);
      return $attribute === NULL ? '' : trim((string) $attribute);
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Invokes a body getter, mapping failures to an empty string.
   *
   * @param callable(): mixed $getter
   *   The getter closure.
   */
  protected function safeString(callable $getter): string {
    try {
      return (string) $getter();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
