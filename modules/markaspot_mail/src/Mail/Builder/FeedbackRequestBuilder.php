<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\language\ConfigurableLanguageManagerInterface;
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
 *   - request_id: pre-computed request identifier. Falls back to the
 *     node's request_id BASE field (service_request bundle), then to
 *     the node id.
 *
 * Subject template:
 *   Pulled from markaspot_feedback.mail.feedback_request.subject via the
 *   language manager's config override, so site admins can customize the
 *   wording per locale without touching PHP. Drupal token replacement
 *   runs against the template with ['node' => $node, 'langcode' => ...]
 *   so tokens like [node:request_id], [node:title], [node:uuid] and
 *   anything the markaspot_token module exposes will resolve. Falls back
 *   to a hardcoded string only when the config has no subject entry for
 *   the active langcode and no fallback default.
 *
 * Jurisdiction mode when the node's field_jurisdiction resolves to a
 * loadable jur group; otherwise platform mode (the rare orphan case).
 */
final class FeedbackRequestBuilder implements MailBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly MailBrandingService $branding,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConfigurableLanguageManagerInterface $languageManager,
    private readonly Token $token,
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

    $subject = $this->resolveSubject($node, $requestId, $ctx->langcode);

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
   * Resolves the subject via the legacy config template + token replacement.
   *
   * Loads markaspot_feedback.mail.feedback_request.subject with the
   * language manager's config override so per-locale overrides work. Runs
   * Drupal token replacement against ['node' => $node]. Falls back to a
   * t()-based default only when the config has no usable subject entry.
   */
  private function resolveSubject(NodeInterface $node, string $requestId, string $langcode): string {
    $template = '';
    $override = $this->languageManager
      ->getLanguageConfigOverride($langcode, 'markaspot_feedback.mail')
      ->get('feedback_request');
    if (is_array($override) && !empty($override['subject'])) {
      $template = (string) $override['subject'];
    }
    else {
      $config = $this->configFactory->get('markaspot_feedback.mail')->get('feedback_request');
      if (is_array($config) && !empty($config['subject'])) {
        $template = (string) $config['subject'];
      }
    }
    if ($template === '') {
      return (string) $this->t('Your report @id: feedback welcome', [
        '@id' => $requestId,
      ], ['langcode' => $langcode]);
    }
    return (string) $this->token->replace($template, ['node' => $node], [
      'langcode' => $langcode,
      'clear' => TRUE,
    ]);
  }

  /**
   * Resolves the human-facing request identifier.
   *
   * Request_id is a base field on the service_request bundle (no field_
   * prefix, no node__field_request_id table). It stores the Mark-a-Spot
   * counter format "<counter>-<year>" (e.g. "51-2026") and is also exposed
   * via the [node:request_id] token in markaspot_token.module.
   *
   * Uses getString() instead of the magic ->value accessor so the resolver
   * stays mockable under PHP 8.2+ dynamic-property deprecations.
   */
  private function resolveRequestId(MailContext $ctx, NodeInterface $node): string {
    if (!empty($ctx->params['request_id'])) {
      return (string) $ctx->params['request_id'];
    }
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      return trim($node->get('request_id')->getString());
    }
    return (string) $node->id();
  }

}
