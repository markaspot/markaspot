<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Mail\SplitParagraphsTrait;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_moderation:(flag_threshold|flag_immediate) mails.
 *
 * Sent by the moderation service when a service request gets flagged
 * (threshold reached or an immediate-action flag). Follows the DSA
 * Article 16 notice-and-action contract, so the caller pre-assembles
 * the subject + body. We preserve that copy verbatim and wrap it in
 * branded card_transactional chrome.
 *
 * Required params:
 *   - subject (string)
 *   - body (string)
 *
 * Optional param:
 *   - node (NodeInterface) — when present, jurisdiction resolution via
 *     field_jurisdiction; otherwise platform mode.
 */
final class ModerationNoticeBuilder implements MailBuilderInterface {

  use ResolveJurisdictionFromNodeTrait;
  use SplitParagraphsTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_MODERATION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_moderation'
      && ($key === 'flag_threshold' || $key === 'flag_immediate');
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

    $node = $ctx->params['node'] ?? NULL;
    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromNode(
      $node instanceof NodeInterface ? $node : NULL
    );

    $paragraphs = $this->splitParagraphs($body);
    $intro = array_shift($paragraphs) ?? '';

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => mb_strimwidth(strip_tags($body), 0, 100, '…'),
        'headline' => $subject,
        'intro' => $intro,
        'body_blocks' => $paragraphs,
      ],
      mode: $mode,
      jurisdictionId: $jurisdictionId,
    );
  }

}
