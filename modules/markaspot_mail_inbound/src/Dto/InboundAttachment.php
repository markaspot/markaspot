<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Dto;

/**
 * Value object for a single attachment of an inbound email.
 *
 * The declared MIME type comes from attacker-controlled headers and must
 * never be trusted for security decisions. MailIngestOrchestrator re-verifies
 * the content with a finfo buffer sniff before any file is persisted.
 */
final class InboundAttachment {

  /**
   * Constructs an InboundAttachment.
   *
   * @param string $filename
   *   The declared file name (untrusted, never used as storage target).
   * @param string $mimeType
   *   The declared MIME type (untrusted).
   * @param string $content
   *   The raw binary content.
   * @param int $size
   *   The content size in bytes.
   */
  public function __construct(
    public readonly string $filename,
    public readonly string $mimeType,
    public readonly string $content,
    public readonly int $size,
  ) {
  }

}
