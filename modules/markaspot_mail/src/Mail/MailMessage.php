<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

/**
 * The abstract, branding-independent result of a builder.
 *
 * A builder turns a MailContext (raw Drupal $message slice) into this DTO.
 * The hook then hands mode + jurisdictionId off to MailBrandingService,
 * and variant + content off to MailHtmlRenderer.
 *
 * $plainText is an optional explicit override. When NULL, the renderer
 * derives plain text deterministically from $content (same mapping the
 * existing MailHtmlRenderer::htmlToPlain() uses). Builders that need full
 * control over line breaks or wording in the plain-text alternative pass
 * an explicit string and bypass the default derivation entirely.
 */
final readonly class MailMessage {

  /**
   * @param string $subject
   *   Final Subject: header. Replaces $message['subject']. The hook runs
   *   defense-in-depth CR/LF/NUL stripping at the assignment site (mail
   *   header injection, CWE-93), but builders SHOULD still treat this as
   *   a header-bound value: don't interpolate raw user input here without
   *   prior sanitization, the hook sink is the last line of defense.
   * @param string $variant
   *   Either "hero_code" or "card_transactional". Unknown variants fall
   *   back to card_transactional at render time.
   * @param array $content
   *   Variant-specific slot payload (see mail-hero-code.html.twig and
   *   mail-card-transactional.html.twig for expected keys).
   * @param string $mode
   *   "platform" or "jurisdiction": controls footer zones and branding
   *   resolution.
   * @param int|null $jurisdictionId
   *   Group entity ID for jurisdiction mode; NULL in platform mode.
   * @param string|null $plainText
   *   Explicit plain-text override. NULL defers to the renderer default.
   *   Ends up as MIME text body content, not a header value. The hook
   *   normalizes \r\n to \n before stashing it in $message['params'], but
   *   builders with MIME-building plugins downstream should assume the
   *   string flows to a text/plain MIME part without further escaping.
   * @param list<\Drupal\markaspot_mail\Mail\MailAttachment> $attachments
   *   File attachments to ship alongside the rendered body. Empty by
   *   default — builders opt in per mail type (staff-facing mails like
   *   escalation/moderation attach; citizen-facing mails and OTP never).
   *   The hook converts each entry via MailAttachment::toParam() and
   *   merges the result into $message['params']['attachments'], where
   *   phpmailer_smtp::addAttachments() picks it up without further code.
   */
  public function __construct(
    public string $subject,
    public string $variant,
    public array $content,
    public string $mode = 'platform',
    public ?int $jurisdictionId = NULL,
    public ?string $plainText = NULL,
    public array $attachments = [],
  ) {}

}
