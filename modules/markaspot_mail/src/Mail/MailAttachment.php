<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

/**
 * A single file to attach to an outgoing mail.
 *
 * Builders produce these and hand them to MailMessage::$attachments; the
 * hook dispatcher then converts each one to the array shape that
 * phpmailer_smtp's addAttachments() consumes
 * (see \Drupal\phpmailer_smtp\Plugin\Mail\PhpMailerSmtp::addAttachments).
 *
 * Payload is either a stream URI / absolute path ($filepath) or the raw
 * bytes ($filecontent) — never both missing. A file with neither would
 * be silently dropped downstream, so we fail fast at construction instead.
 *
 * $filesize is optional metadata used by AttachmentResolver to enforce
 * per-file and total-size budgets. When NULL the resolver treats the
 * attachment as "unknown size" and accounts for it conservatively.
 *
 * Pattern note: MailAttachment fails fast at construction, while
 * MailMessage — which is also a readonly DTO in this package — does
 * not. The asymmetry is deliberate: MailMessage is strictly intra-module
 * (only Builders produce it, MailAlterHook consumes it), whereas
 * MailAttachment is an intended extension point for future generators
 * (PDF receipts, CSV exports) and third-party builders. Fail-fast here
 * turns "invalid attachment silently dropped by phpmailer_smtp" into a
 * visible construction error.
 *
 * $filename is sanitized at construction: CR/LF/NUL stripped (CWE-93
 * defense-in-depth against Content-Disposition header injection, mirroring
 * MailAlterHook::sanitizeHeaderValue) and basename-reduced to block
 * ../ path-traversal or directory separators that some mail clients
 * render as part of the visible filename.
 */
final readonly class MailAttachment {

  public string $filename;

  /**
   * @param string $filename
   *   User-visible filename in the mail client. Sanitized to a flat
   *   basename with CR/LF/NUL stripped before storage.
   * @param string $filemime
   *   Full MIME type (e.g. "image/jpeg"). AttachmentResolver gates this
   *   against markaspot_mail.settings:attachments.allowed_mime; downstream
   *   phpmailer_smtp will auto-detect if empty, but we prefer the explicit
   *   gate to live upstream.
   * @param string|null $filepath
   *   Stream URI ("public://…") or absolute filesystem path.
   *   phpmailer_smtp resolves public:// via the file_system service.
   *   private:// is blocked by the resolver before construction.
   * @param string|null $filecontent
   *   In-memory bytes, for dynamically-generated attachments (PDF
   *   receipts, CSV exports). Either this or $filepath must be set.
   * @param int|null $filesize
   *   Size in bytes. NULL = unknown (resolver accounts conservatively).
   */
  public function __construct(
    string $filename,
    public string $filemime,
    public ?string $filepath = NULL,
    public ?string $filecontent = NULL,
    public ?int $filesize = NULL,
  ) {
    if ($filepath === NULL && $filecontent === NULL) {
      throw new \InvalidArgumentException(
        'MailAttachment requires either $filepath or $filecontent.'
      );
    }
    $sanitized = basename(str_replace(["\r", "\n", "\0"], '', $filename));
    if ($sanitized === '' || $sanitized === '.' || $sanitized === '..') {
      throw new \InvalidArgumentException(
        'MailAttachment requires a non-empty, path-traversal-safe $filename.'
      );
    }
    $this->filename = $sanitized;
  }

  /**
   * Converts to the array shape phpmailer_smtp expects in params.attachments.
   *
   * Keys match PhpMailerSmtp::addAttachments(): filepath, filename,
   * filemime, filecontent. Empty / NULL values are preserved (phpmailer
   * treats missing-or-empty mime as "auto-detect").
   *
   * @return array{
   *   filename: string,
   *   filemime: string,
   *   filepath?: string,
   *   filecontent?: string,
   *   }
   */
  public function toParam(): array {
    $out = [
      'filename' => $this->filename,
      'filemime' => $this->filemime,
    ];
    if ($this->filepath !== NULL) {
      $out['filepath'] = $this->filepath;
    }
    if ($this->filecontent !== NULL) {
      $out['filecontent'] = $this->filecontent;
    }
    return $out;
  }

}
