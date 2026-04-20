<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Mail\SplitParagraphsTrait;
use Drupal\markaspot_mail\Service\AttachmentResolver;
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

  use ResolveJurisdictionFromNodeTrait;
  use SplitParagraphsTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly AttachmentResolver $attachmentResolver,
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

    // Recipient is the target jurisdiction's staff — attach the citizen
    // uploads so the handler has the visual context without having to
    // click into the dashboard. field_request_image is stored under
    // uri_scheme: private, so we must opt in to private-scheme resolution.
    // The staff recipient is the legitimate audience for this data.
    $node = $ctx->params['node'] ?? NULL;
    $attachments = $node instanceof NodeInterface
      ? $this->attachmentResolver->resolve(
        $node,
        ['field_request_image', 'field_attachment'],
        includePrivate: TRUE,
      )
      : [];

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
      attachments: $attachments,
    );
  }

  /**
   * Resolves (mode, jurisdictionId) from params.
   *
   * Prefers params['jurisdiction'] (set by EscalationService to the target
   * jur group). Falls back to params['node']->field_jurisdiction via the
   * shared ResolveJurisdictionFromNodeTrait.
   *
   * @return array{0: string, 1: int|null}
   *   Two-element array: [mode, jurisdictionId].
   */
  private function resolveJurisdiction(array $params): array {
    $jur = $params['jurisdiction'] ?? NULL;
    if ($jur instanceof ContentEntityInterface && $jur->getEntityTypeId() === 'group' && $jur->bundle() === 'jur') {
      return ['jurisdiction', (int) $jur->id()];
    }
    $node = $params['node'] ?? NULL;
    return $this->resolveJurisdictionFromNode($node instanceof NodeInterface ? $node : NULL);
  }

}
