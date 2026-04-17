<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\SplitParagraphsTrait;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_group:org_notification mails.
 *
 * Generic admin-to-organisation notification fired by the group module
 * when a service request is routed to one or more head organisations.
 * The caller builds the subject + message strings itself (typically with
 * context-specific placeholders already substituted), so this builder is
 * a pass-through wrapper that applies branding chrome without touching
 * the wording.
 *
 * Required params:
 *   - subject (string)
 *   - message (string, the body — legacy key name matches the hook)
 *
 * Always platform mode: org_notification targets head organisations
 * that may span multiple jurisdictions, and the caller doesn't pass a
 * single canonical jur context.
 */
final class GroupOrgNotificationBuilder implements MailBuilderInterface {

  use SplitParagraphsTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_GROUP;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_group' && $key === 'org_notification';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $subject = trim((string) ($ctx->params['subject'] ?? ''));
    $message = trim((string) ($ctx->params['message'] ?? ''));
    if ($subject === '' || $message === '') {
      $this->logger->warning('org_notification: missing subject or message param, skipping branded render.');
      return NULL;
    }

    $paragraphs = $this->splitParagraphs($message);
    $intro = array_shift($paragraphs) ?? '';

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => mb_strimwidth(strip_tags($message), 0, 100, '…'),
        'headline' => $subject,
        'intro' => $intro,
        'body_blocks' => $paragraphs,
      ],
      mode: 'platform',
    );
  }

}
