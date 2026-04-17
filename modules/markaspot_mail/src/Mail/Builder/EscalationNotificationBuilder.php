<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_escalation:(escalation|delegation)_notification mails.
 *
 * Sent by EscalationService when a service request is escalated or
 * delegated to a different jurisdiction. Params contain the pre-assembled
 * subject + body (already token-replaced by the caller), plus the
 * target 'jurisdiction' group and the 'node' being escalated.
 *
 * Jurisdiction mode targets the TARGET jurisdiction (the recipient's
 * tenant), not the source — because the recipient is the one reading
 * the mail and expects familiar branding. Falls through to the node's
 * field_jurisdiction if the jurisdiction param is missing, and to
 * platform mode when neither resolves.
 */
final class EscalationNotificationBuilder implements MailBuilderInterface {

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_ESCALATION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_escalation'
      && ($key === 'escalation_notification' || $key === 'delegation_notification');
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
   * Resolves (mode, jurisdictionId) from params.
   *
   * Prefers params['jurisdiction'] (set by EscalationService to the target
   * jur group). Falls back to params['node']->field_jurisdiction if that
   * resolves to a jur group. Returns platform mode otherwise.
   *
   * @return array{0: string, 1: int|null}
   */
  private function resolveJurisdiction(array $params): array {
    $jur = $params['jurisdiction'] ?? NULL;
    if ($jur instanceof ContentEntityInterface && $jur->getEntityTypeId() === 'group' && $jur->bundle() === 'jur') {
      return ['jurisdiction', (int) $jur->id()];
    }

    $node = $params['node'] ?? NULL;
    if (!$node instanceof NodeInterface || !$node->hasField('field_jurisdiction')) {
      return ['platform', NULL];
    }
    $field = $node->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return ['platform', NULL];
    }
    $target = $field->referencedEntities()[0] ?? NULL;
    if ($target instanceof EntityInterface
      && $target instanceof ContentEntityInterface
      && $target->getEntityTypeId() === 'group'
      && $target->bundle() === 'jur') {
      return ['jurisdiction', (int) $target->id()];
    }
    return ['platform', NULL];
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
