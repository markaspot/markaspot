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
use Psr\Log\LoggerInterface;

/**
 * Builder for Drupal-core EmailAction mails driven by ECA workflows.
 *
 * Markaspot_group ships four ECA processes (process_confirm_report,
 * process_apply_group, process_tunr6d6 status flow, process_ugsohtl
 * welcome flow) that all fire the core action_send_email_action plugin.
 * That plugin routes every mail through module=system, key=action_send_email,
 * with the admin-configured subject + message already token-replaced by
 * system_mail() before hook_mail_alter runs.
 *
 * This builder takes the already-finalized subject + body from the
 * MailContext, resolves the jurisdiction from the acted-upon entity
 * (shipped in $ctx->params['context']['node'] by EmailAction::execute()),
 * and wraps the verbatim ECA copy in a card_transactional body so the
 * recipient sees the same wording inside branded HTML. We do not attempt
 * to re-author or re-paragraphize the admin's copy: the ECA editor is
 * the source of truth for wording, we only own the chrome.
 *
 * No CTA extraction: ECA bodies rarely carry a canonical single CTA, and
 * guessing from URL patterns in the plaintext is fragile. If operators
 * want a button they can migrate the workflow to a concrete builder in
 * a later stage.
 *
 * The builder returns NULL when $params['context'] is missing or has no
 * subject, which keeps the dispatcher from turning accidental
 * system:action_send_email sends (non-ECA, no entity) into broken cards.
 */
final class EcaActionEmailBuilder implements MailBuilderInterface {

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_ACTION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'system' && $key === 'action_send_email';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $context = $ctx->params['context'] ?? NULL;
    if (!is_array($context) || empty($context['subject'])) {
      // Not an ECA-driven EmailAction — the mail arrived through another
      // code path that routes via system:action_send_email without the
      // $params['context'] envelope. Pass through unbranded.
      return NULL;
    }

    $subject = trim($ctx->subject);
    if ($subject === '') {
      $this->logger->notice('ECA action mail has empty subject after system_mail; skipping branded render.');
      return NULL;
    }

    $body = $this->extractBody($ctx);
    if ($body === '') {
      $this->logger->notice('ECA action mail has empty body after system_mail; skipping branded render.');
      return NULL;
    }

    $paragraphs = $this->splitParagraphs($body);
    $intro = array_shift($paragraphs) ?? '';
    $bodyBlocks = $paragraphs;

    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromContext($context);

    $content = [
      'preheader' => mb_strimwidth(strip_tags($body), 0, 100, '...'),
      'intro' => $intro,
      'body_blocks' => $bodyBlocks,
    ];

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: $content,
      mode: $mode,
      jurisdictionId: $jurisdictionId,
    );
  }

  /**
   * Flattens $ctx->body into a single plain-text string.
   *
   * System_mail() for action_send_email appends its body as a single
   * element via $message['body'][] = $body; we coerce every entry to
   * string and join on a double newline so upstream hooks that pushed
   * multiple entries land on paragraph boundaries.
   */
  private function extractBody(MailContext $ctx): string {
    $parts = [];
    foreach ($ctx->body as $entry) {
      $parts[] = (string) $entry;
    }
    return trim(implode("\n\n", $parts));
  }

  /**
   * Splits a body string into paragraph-delimited blocks.
   *
   * Blank-line separated paragraphs; leading/trailing whitespace trimmed;
   * empty paragraphs dropped.
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

  /**
   * Resolves (mode, jurisdictionId) from the ECA action's $configuration.
   *
   * EmailAction::execute() sets $configuration['node'] to the entity being
   * acted upon; it might also arrive via $configuration['entity'] depending
   * on the ECA model. When the entity has a field_jurisdiction reference
   * pointing at a jur group we switch to jurisdiction mode; otherwise we
   * stay platform.
   *
   * @return array{0: string, 1: int|null}
   */
  private function resolveJurisdictionFromContext(array $context): array {
    $entity = $context['node'] ?? $context['entity'] ?? NULL;
    if (!$entity instanceof EntityInterface) {
      return ['platform', NULL];
    }
    if (!$entity instanceof ContentEntityInterface) {
      return ['platform', NULL];
    }
    if (!$entity->hasField('field_jurisdiction')) {
      return ['platform', NULL];
    }
    $field = $entity->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return ['platform', NULL];
    }
    $referenced = $field->referencedEntities();
    $target = $referenced[0] ?? NULL;
    if (!$target instanceof ContentEntityInterface || $target->getEntityTypeId() !== 'group') {
      return ['platform', NULL];
    }
    if ($target->bundle() !== 'jur') {
      return ['platform', NULL];
    }
    return ['jurisdiction', (int) $target->id()];
  }

}
