<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Mail;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\SplitParagraphsTrait;
use Psr\Log\LoggerInterface;

/**
 * Brands the inbound-mail return channel (#482).
 *
 * Claims markaspot_mail_inbound's triage_reply and
 * auto_reply_missing_location keys for markaspot_mail's builder registry, so
 * a staff or auto reply to an email citizen ships in the jurisdiction-
 * branded card chrome. Branding mode is keyed to the mail's jurisdiction
 * (params['jurisdiction_id'], set by InboundMailReplyService); platform mode
 * when the mail has none. The branding package's reply_to (the
 * jurisdiction's field_jurisdiction_e_mail) then wins in MailAlterHook —
 * the same address the reply service already set, by design.
 *
 * CROSS-MODULE WIRING: this class implements markaspot_mail's interface but
 * ships in markaspot_mail_inbound, which does NOT hard-depend on
 * markaspot_mail. That is safe: the service definition (tagged
 * markaspot_mail.builder) is inert when markaspot_mail is absent — nothing
 * consumes the tag, the class is never instantiated, and Symfony's container
 * does not autoload classes of un-instantiated services at compile time.
 * Without markaspot_mail the reply goes out as the plain-text hook_mail
 * body, the documented degradation.
 *
 * MailType: the enum lives in markaspot_mail and gains no new case here
 * (non-regression pillar: zero changes outside this module). ECA_ACTION is
 * reused as the closest generic classification; the type only feeds
 * logging/metrics and findByType() introspection (where the linear scan
 * returns markaspot_mail's own ECA builder first, so no lookup changes).
 */
final class TriageReplyBuilder implements MailBuilderInterface {

  use SplitParagraphsTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::INBOUND_TRIAGE_REPLY;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_mail_inbound'
      && ($key === 'triage_reply' || $key === 'auto_reply_missing_location');
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $subject = trim((string) ($ctx->params['subject'] ?? ''));
    $body = trim((string) ($ctx->params['body'] ?? ''));
    if ($subject === '' || $body === '') {
      $this->logger->warning('@key: missing subject or body param, skipping branded render.', [
        '@key' => $ctx->key,
      ]);
      return NULL;
    }

    $jurisdictionId = (int) ($ctx->params['jurisdiction_id'] ?? 0);

    $paragraphs = $this->splitParagraphs($body);
    $intro = array_shift($paragraphs) ?? '';

    // Citizen-facing: never attach files, never include foreign content.
    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => mb_strimwidth(strip_tags($body), 0, 100, '…'),
        'intro' => $intro,
        'body_blocks' => $paragraphs,
      ],
      mode: $jurisdictionId > 0 ? 'jurisdiction' : 'platform',
      jurisdictionId: $jurisdictionId > 0 ? $jurisdictionId : NULL,
    );
  }

}
