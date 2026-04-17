<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
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

    [$mode, $jurisdictionId] = $this->resolveJurisdiction($ctx->params);

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

  /**
   * Resolves (mode, jurisdictionId) from the optional node param.
   *
   * @return array{0: string, 1: int|null}
   */
  private function resolveJurisdiction(array $params): array {
    $node = $params['node'] ?? NULL;
    if (!$node instanceof NodeInterface || !$node->hasField('field_jurisdiction')) {
      return ['platform', NULL];
    }
    $field = $node->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return ['platform', NULL];
    }
    $target = $field->referencedEntities()[0] ?? NULL;
    if (!$target instanceof ContentEntityInterface
      || $target->getEntityTypeId() !== 'group'
      || $target->bundle() !== 'jur') {
      return ['platform', NULL];
    }
    return ['jurisdiction', (int) $target->id()];
  }

  /**
   * Splits a body string into paragraph-delimited blocks.
   *
   * @return list<string>
   */
  private function splitParagraphs(string $body): array {
    $raw = preg_split("/\n\s*\n/", $body) ?: [$body];
    $out = [];
    foreach ($raw as $paragraph) {
      $paragraph = trim($paragraph);
      if ($paragraph !== '') {
        $out[] = $paragraph;
      }
    }
    return $out;
  }

}
