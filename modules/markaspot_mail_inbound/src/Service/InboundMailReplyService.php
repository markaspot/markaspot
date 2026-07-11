<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Util\MailTextUtils;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sends the outbound return channel for inbound mails (#482, Phase 2).
 *
 * A staff (or auto) reply goes out through Drupal's mail manager under the
 * markaspot_mail_inbound module with the triage_reply (or
 * auto_reply_missing_location) key. When markaspot_mail is enabled, the
 * tagged TriageReplyBuilder picks the key up and wraps the text in the
 * jurisdiction-branded chrome; without it the plain hook_mail body is sent.
 *
 * FROM / REPLY-TO: the jurisdiction's field_jurisdiction_e_mail (CR/LF/NUL
 * sanitized like markaspot_mail's MailAlterHook does), falling back to the
 * site mail. When markaspot_mail brands the message, its MailAlterHook
 * REPLACES Reply-To with the branding package's reply_to — which for
 * jurisdiction mode is the same field_jurisdiction_e_mail, so the
 * jurisdiction address wins on both paths.
 *
 * THREADING: every outbound reply gets its own RFC 5322 Message-ID
 * (<uuid@host>) plus In-Reply-To (the newest message in the conversation)
 * and References (the whole chain). The outbound id is recorded on the
 * mail's thread_message_ids so a citizen replying to OUR reply threads back
 * onto the same staged mail (MailIngestOrchestrator matches any recorded
 * id). The SMTP transport honors the preset header: phpmailer_smtp maps a
 * "message-id" header onto PHPMailer's $MessageID property verbatim
 * (PhpMailerSmtp::mail(), the 'message-id' => 'MessageID' property map), so
 * the generated id is what actually leaves the server. Transports that
 * ignore it still thread correctly via the citizen's own ids in
 * In-Reply-To / References.
 *
 * RECORDING: the reply text is appended to the mail's body conversation log
 * (shared format, see MailTextUtils::appendConversationEntry()) and the save
 * touches "changed", re-surfacing the mail. When outbound is unavailable the
 * reply is recorded with a "(not sent: ...)" marker and sent=FALSE — the
 * "in-system notes only, no send" degradation row.
 *
 * SECURITY: the recipient is always and only the mail's verified
 * from_address. No CC/BCC, no content from other mails or nodes (the body is
 * staff-typed or a fixed template). Logs carry metadata only (mail id, key,
 * sent flag) — never the body or the address (Phase 1 PII convention).
 */
class InboundMailReplyService {

  use StringTranslationTrait;

  /**
   * The mail key for a staff-typed triage reply.
   */
  public const KEY_TRIAGE_REPLY = 'triage_reply';

  /**
   * The mail key for the automatic missing-location reply.
   */
  public const KEY_AUTO_REPLY_MISSING_LOCATION = 'auto_reply_missing_location';

  /**
   * Constructs the reply service.
   */
  public function __construct(
    protected MailManagerInterface $mailManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
    protected ConfigFactoryInterface $configFactory,
    protected UuidInterface $uuid,
    protected RequestStack $requestStack,
    protected MailboxResolver $mailboxResolver,
    protected MailIntakeFidelity $fidelity,
    protected LoggerChannelInterface $logger,
    protected ?CitizenWordingResolver $citizenWordingResolver = NULL,
  ) {
  }

  /**
   * Sends (or records) a reply to the mail's verified sender.
   *
   * @param \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail
   *   The inbound mail (the conversation anchor; staged or promoted).
   * @param string $body
   *   The reply text (plain text, staff-typed or a fixed template).
   * @param string $key
   *   The mail key (one of the KEY_* constants).
   * @param string $recordLabel
   *   The conversation-log label, e.g. "Staff reply" or "Auto-reply".
   *
   * @return bool
   *   TRUE when the mail was handed to the transport successfully. FALSE
   *   when outbound is unavailable or sending failed — the reply is then
   *   recorded on the mail as a note only.
   */
  public function sendReply(InboundMail $mail, string $body, string $key = self::KEY_TRIAGE_REPLY, string $recordLabel = 'Staff reply'): bool {
    $body = trim($body);
    $to = trim($mail->getFromAddress());
    if ($body === '' || $to === '') {
      $this->logger->warning('Reply to inbound mail @id skipped: empty body or missing recipient.', ['@id' => $mail->id()]);
      return FALSE;
    }

    $sent = FALSE;
    $outboundMessageId = '';
    if ($this->fidelity->outboundAvailable()) {
      $outboundMessageId = $this->generateMessageId();
      $params = $this->buildMailParams($mail, $body, $outboundMessageId, $key);
      // A failing SMTP transport (PHPMailer with debug output) ECHOES its
      // error straight into the output stream, which corrupts the JSON
      // response of the API controller (smoke-found: HTTP 200 text/html
      // "SMTP error: ..." instead of {"sent":false,...}). Buffer and discard
      // anything the transport prints; the boolean result is the contract.
      // Anchor to the current buffer level so a hook/transport that opens
      // (or over-pops) buffers of its own cannot make the finally block
      // drain the wrong one.
      $bufferLevel = ob_get_level();
      ob_start();
      try {
        $result = $this->mailManager->mail(
          'markaspot_mail_inbound',
          $key,
          $to,
          $this->languageManager->getDefaultLanguage()->getId(),
          $params
        );
        $sent = (bool) ($result['result'] ?? FALSE);
      }
      catch (\Throwable $e) {
        // Metadata-only logging: the exception type is enough for an
        // operator; the message could echo the recipient address.
        $this->logger->error('Sending a @key reply for inbound mail @id failed (@type).', [
          '@key' => $key,
          '@id' => $mail->id(),
          '@type' => get_class($e),
        ]);
      }
      finally {
        $leaked = '';
        while (ob_get_level() > $bufferLevel) {
          $leaked .= (string) ob_get_clean();
        }
        if ($leaked !== '') {
          // Metadata only: transport error text can contain host/credential
          // fragments, so log the length, never the content.
          $this->logger->warning('Discarded @bytes bytes of transport output while sending a @key reply for inbound mail @id.', [
            '@bytes' => strlen($leaked),
            '@key' => $key,
            '@id' => $mail->id(),
          ]);
        }
      }
    }

    // Record OUR outbound Message-ID only when the mail actually went out:
    // a citizen can only ever reply to a message that exists.
    if ($sent && $outboundMessageId !== '') {
      $this->appendThreadMessageId($mail, $outboundMessageId);
    }

    // Always record the reply on the conversation log (audit trail). An
    // unsent reply is marked so the log never claims a send that did not
    // happen. Saving touches "changed".
    $settings = $this->mailboxResolver->getGlobalSettings();
    $label = $recordLabel . ' (' . gmdate('Y-m-d H:i') . ' UTC)';
    if (!$sent) {
      $label .= ' (not sent: outbound mail unavailable)';
    }
    $mail->set('body', [
      'value' => MailTextUtils::appendConversationEntry($mail->getBody(), $label, $body, (int) $settings['max_body_length']),
      'format' => 'plain_text',
    ]);
    $mail->save();

    $this->logger->notice('Reply @key for inbound mail @id recorded (sent: @sent).', [
      '@key' => $key,
      '@id' => $mail->id(),
      '@sent' => $sent ? 'yes' : 'no',
    ]);

    return $sent;
  }

  /**
   * Builds the hook_mail params: subject, body, threading + sender headers.
   *
   * @param \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail
   *   The conversation anchor.
   * @param string $body
   *   The reply text.
   * @param string $outboundMessageId
   *   The generated Message-ID (normalized, without angle brackets).
   * @param string $key
   *   The mail key (one of the KEY_* constants).
   *
   * @return array<string, mixed>
   *   The mail params consumed by markaspot_mail_inbound_mail() and, when
   *   markaspot_mail is enabled, by TriageReplyBuilder.
   */
  protected function buildMailParams(InboundMail $mail, string $body, string $outboundMessageId, string $key = self::KEY_TRIAGE_REPLY): array {
    $subject = $mail->getSubject() !== ''
      ? 'Re: ' . $mail->getSubject()
      : (string) $this->t('Your @term', [
        '@term' => $this->resolveCitizenTerm($mail),
      ]);

    $headers = [
      'Message-ID' => '<' . $outboundMessageId . '>',
    ];

    // RFC 3834 mail-loop protection: both keys declare themselves as
    // machine-generated so vacation responders, ticket systems and other
    // auto-repliers do not answer OUR mail and ping-pong with the intake
    // mailbox (the SpamHeuristicFilter honors the same headers inbound).
    if ($key === self::KEY_AUTO_REPLY_MISSING_LOCATION) {
      // A fully automatic response to an inbound mail.
      $headers['Auto-Submitted'] = 'auto-replied';
      $headers['X-Auto-Response-Suppress'] = 'All';
      $headers['Precedence'] = 'bulk';
    }
    else {
      // A staff-typed reply sent through an automated channel: flag it as
      // auto-generated for responder suppression, but WITHOUT Precedence:
      // bulk — it is an individual human answer, not bulk mail.
      $headers['Auto-Submitted'] = 'auto-generated';
      $headers['X-Auto-Response-Suppress'] = 'All';
    }
    $threadIds = $mail->getThreadMessageIds();
    if ($threadIds !== []) {
      // In-Reply-To: the newest message in the conversation (RFC 5322
      // threading replies to the most recent message, whichever side sent
      // it); References: the whole chain, oldest first.
      $headers['In-Reply-To'] = '<' . end($threadIds) . '>';
      $headers['References'] = implode(' ', array_map(
        static fn(string $id): string => '<' . $id . '>',
        $threadIds
      ));
    }

    $fromAddress = $this->resolveFromAddress($mail->getJurisdictionId());
    if ($fromAddress !== '') {
      $headers['From'] = $fromAddress;
      $headers['Reply-To'] = $fromAddress;
    }

    return [
      'subject' => MailTextUtils::sanitizeSubject($subject, 989),
      'body' => $body,
      'headers' => $headers,
      'from' => $fromAddress,
      'jurisdiction_id' => $mail->getJurisdictionId(),
    ];
  }

  /**
   * Resolves the selected citizen term for a conversation's jurisdiction.
   *
   * The return-channel uses the site's default language, matching the
   * langcode passed to MailManager in sendReply().
   */
  protected function resolveCitizenTerm(InboundMail $mail): string {
    if ($this->citizenWordingResolver === NULL || $mail->getJurisdictionId() <= 0) {
      return 'report';
    }

    try {
      $group = $this->entityTypeManager
        ->getStorage('group')
        ->load($mail->getJurisdictionId());
    }
    catch (\Throwable) {
      return 'report';
    }

    if (!$group instanceof GroupInterface) {
      return 'report';
    }

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $wording = $this->citizenWordingResolver->resolve($group, $langcode);
    return $wording['singular'];
  }

  /**
   * Resolves the sender address for a jurisdiction.
   *
   * The jurisdiction's field_jurisdiction_e_mail when set, otherwise the
   * site mail. Header-sanitized (CR/LF/NUL, the MailAlterHook convention).
   */
  protected function resolveFromAddress(int $jurisdictionGid): string {
    $address = '';
    if ($jurisdictionGid > 0) {
      try {
        $group = $this->entityTypeManager->getStorage('group')->load($jurisdictionGid);
        if ($group !== NULL && $group->hasField('field_jurisdiction_e_mail') && !$group->get('field_jurisdiction_e_mail')->isEmpty()) {
          $address = trim((string) $group->get('field_jurisdiction_e_mail')->value);
        }
      }
      catch (\Throwable) {
        // Group entity type unavailable; fall through to the site mail.
      }
    }
    if ($address === '') {
      $address = trim((string) $this->configFactory->get('system.site')->get('mail'));
    }
    return str_replace(["\r", "\n", "\0"], '', $address);
  }

  /**
   * Generates a normalized RFC 5322 Message-ID (without angle brackets).
   */
  protected function generateMessageId(): string {
    $host = $this->requestStack->getCurrentRequest()?->getHost()
      ?: (gethostname() ?: 'localhost');
    // Hosts come from trusted-host-validated requests; strip anything that
    // could break the header anyway.
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
    return $this->uuid->generate() . '@' . $host;
  }

  /**
   * Records an outbound Message-ID on the mail's thread chain.
   *
   * Idempotent and length-capped like the orchestrator's appends, so the
   * orchestrator matches a citizen reply to OUR reply back onto this mail.
   */
  protected function appendThreadMessageId(InboundMail $mail, string $messageId): void {
    $messageId = MailTextUtils::normalizeMessageId($messageId);
    if ($messageId === '') {
      return;
    }
    $ids = $mail->getThreadMessageIds();
    if (in_array($messageId, $ids, TRUE)) {
      return;
    }
    $ids[] = mb_substr($messageId, 0, 998);
    $mail->set('thread_message_ids', $ids);
  }

}
