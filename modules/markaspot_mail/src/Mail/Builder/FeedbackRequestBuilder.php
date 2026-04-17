<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_feedback:feedback_request mails.
 *
 * This is the "please rate the resolution" mail that markaspot_feedback
 * triggers via its cron worker once a service request sits in a status
 * flagged as feedback-eligible. The legacy implementation reads a token-
 * based subject + body from markaspot_feedback.mail.feedback_request and
 * renders it as flat text; here we discard the legacy body entirely and
 * rebuild the message as structured card_transactional content so the
 * mail picks up the jurisdiction's brand, primary color and Zone-2
 * attribution.
 *
 * Required param:
 *   - node: NodeInterface (service_request)
 *
 * Optional param:
 *   - request_id: pre-computed request identifier (falls back to
 *     field_request_id or $node->id()).
 *
 * Jurisdiction mode when the node's field_jurisdiction resolves to a
 * loadable jur group; otherwise platform mode (the rare orphan case).
 */
final class FeedbackRequestBuilder implements MailBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly MailBrandingService $branding,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_FEEDBACK;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_feedback' && $key === 'feedback_request';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $node = $ctx->params['node'] ?? NULL;
    if (!$node instanceof NodeInterface) {
      $this->logger->warning('feedback_request: missing or invalid "node" param, skipping branded render.');
      return NULL;
    }

    [$mode, $jurisdictionId] = $this->resolveJurisdiction($node);

    $requestId = $this->resolveRequestId($ctx, $node);
    $nodeTitle = (string) $node->label();
    $uuid = (string) $node->uuid();

    // Pull the jurisdiction-aware frontend base via the branding service
    // so the CTA lands on the tenant workspace, not on the platform site.
    $brandingPackage = $this->branding->getBranding($jurisdictionId, $mode, $ctx->langcode);
    $frontendBase = rtrim((string) ($brandingPackage['frontend_base_url'] ?? ''), '/');
    $slug = (string) ($brandingPackage['jurisdiction_slug'] ?? '');
    $ctaPath = ($slug !== '' ? '/' . $slug : '') . '/feedback/' . $uuid;
    $ctaUrl = $frontendBase . $ctaPath;

    $headline = (string) $this->t('Your report was resolved', [], ['langcode' => $ctx->langcode]);
    $intro = (string) $this->t('Thank you for using the issue tracker. Your report "@title" has been processed and is now marked as completed.', [
      '@title' => $nodeTitle,
    ], ['langcode' => $ctx->langcode]);
    $bodyFeedback = (string) $this->t('Are you satisfied with the result, or is there still action needed from your perspective? We would greatly appreciate your feedback.', [], ['langcode' => $ctx->langcode]);
    $bodyReopen = (string) $this->t('If you believe your concern has not been fully addressed, you can also reopen the case through this link.', [], ['langcode' => $ctx->langcode]);

    $content = [
      'preheader' => (string) $this->t('Feedback welcome on your report @id', ['@id' => $requestId], ['langcode' => $ctx->langcode]),
      'headline' => $headline,
      'intro' => $intro,
      'body_blocks' => [$bodyFeedback, $bodyReopen],
      'cta_label' => (string) $this->t('Give feedback', [], ['langcode' => $ctx->langcode]),
      'cta_url' => $ctaUrl,
      'features_block' => [
        [(string) $this->t('Report', [], ['langcode' => $ctx->langcode]) => '#' . $requestId],
      ],
    ];

    $subject = (string) $this->t('Your report @id — feedback welcome', [
      '@id' => $requestId,
    ], ['langcode' => $ctx->langcode]);

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: $content,
      mode: $mode,
      jurisdictionId: $jurisdictionId,
    );
  }

  /**
   * Resolves (mode, jurisdictionId) from the node's field_jurisdiction.
   *
   * Uses the typed referencedEntities() accessor rather than the magic
   * ->entity property so the resolution is mockable in unit tests and
   * doesn't trip PHP 8.2+ dynamic-property deprecations.
   *
   * @return array{0: string, 1: int|null}
   *   Two-element array: [mode, jurisdictionId]. mode is 'jurisdiction' when
   *   the field holds a jur group target, 'platform' otherwise.
   */
  private function resolveJurisdiction(NodeInterface $node): array {
    if (!$node->hasField('field_jurisdiction')) {
      return ['platform', NULL];
    }
    $field = $node->get('field_jurisdiction');
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

  /**
   * Resolves the human-facing request identifier.
   *
   * Uses getString() instead of the magic ->value accessor on the field
   * item list so the mockable surface stays explicit.
   */
  private function resolveRequestId(MailContext $ctx, NodeInterface $node): string {
    if (!empty($ctx->params['request_id'])) {
      return (string) $ctx->params['request_id'];
    }
    if ($node->hasField('field_request_id') && !$node->get('field_request_id')->isEmpty()) {
      return trim($node->get('field_request_id')->getString());
    }
    return (string) $node->id();
  }

}
