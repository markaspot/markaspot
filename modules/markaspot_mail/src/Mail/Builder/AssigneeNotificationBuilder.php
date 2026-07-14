<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_group:assignee_notification mails.
 */
final class AssigneeNotificationBuilder implements MailBuilderInterface {

  use ResolveJurisdictionFromNodeTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly MailBrandingService $branding,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_ASSIGNEE_NOTIFICATION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_group' && $key === 'assignee_notification';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $node = $ctx->params['node'] ?? NULL;
    if (!$node instanceof NodeInterface) {
      $this->logger->warning('assignee_notification: missing node param, skipping branded render.');
      return NULL;
    }

    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromNode($node);
    $branding = $this->branding->getBranding($jurisdictionId, $mode, $ctx->langcode);
    $requestId = $this->resolveRequestId($node);
    $category = $this->resolveCategoryLabel($node);
    $address = $this->resolveFieldString($node, 'field_address');
    $description = $this->resolveBodyText($node);
    $requestUrl = $this->resolveRequestUrl($requestId, $branding);
    $subject = (string) $this->t('Request #@request_id assigned to you', [
      '@request_id' => $requestId,
    ], ['langcode' => $ctx->langcode]);

    $bodyBlocks = [
      (string) $this->t('This request has been assigned to you for processing.', [], ['langcode' => $ctx->langcode]),
    ];
    if ($description !== '') {
      $bodyBlocks[] = (string) $this->t('Description: @description', [
        '@description' => $description,
      ], ['langcode' => $ctx->langcode]);
    }

    $features = [
      [(string) $this->t('Request', [], ['langcode' => $ctx->langcode]) => '#' . $requestId],
    ];
    if ($category !== '') {
      $features[] = [(string) $this->t('Category', [], ['langcode' => $ctx->langcode]) => $category];
    }
    if ($address !== '') {
      $features[] = [(string) $this->t('Location', [], ['langcode' => $ctx->langcode]) => $address];
    }

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => (string) $this->t('Request #@request_id was assigned to you', [
          '@request_id' => $requestId,
        ], ['langcode' => $ctx->langcode]),
        'headline' => $subject,
        'intro' => (string) $this->t('A citizen request is ready for review.', [], ['langcode' => $ctx->langcode]),
        'body_blocks' => $bodyBlocks,
        'features_block' => $features,
        'cta_label' => $requestUrl !== '' ? (string) $this->t('Open request', [], ['langcode' => $ctx->langcode]) : '',
        'cta_url' => $requestUrl,
      ],
      mode: $mode,
      jurisdictionId: $jurisdictionId,
    );
  }

  /**
   * Resolves the human-facing request identifier.
   */
  private function resolveRequestId(NodeInterface $node): string {
    $requestId = '';
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      $requestId = trim($node->get('request_id')->getString());
    }
    return $requestId !== '' ? $requestId : (string) $node->id();
  }

  /**
   * Resolves a taxonomy-like category label.
   */
  private function resolveCategoryLabel(NodeInterface $node): string {
    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return '';
    }
    $field = $node->get('field_category');
    if (!$field instanceof EntityReferenceFieldItemListInterface) {
      return '';
    }
    $category = $field->referencedEntities()[0] ?? NULL;
    return is_object($category) && method_exists($category, 'label')
      ? trim((string) $category->label())
      : '';
  }

  /**
   * Resolves a scalar node field value.
   */
  private function resolveFieldString(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    return trim($node->get($fieldName)->getString());
  }

  /**
   * Resolves and strips markup from the citizen-submitted description.
   */
  private function resolveBodyText(NodeInterface $node): string {
    return trim(strip_tags($this->resolveFieldString($node, 'body')));
  }

  /**
   * Builds the dashboard URL from resolved mail branding.
   */
  private function resolveRequestUrl(string $requestId, array $branding): string {
    $frontendBase = rtrim((string) ($branding['frontend_base_url'] ?? ''), '/');
    if ($frontendBase === '' || $requestId === '') {
      return '';
    }
    $slug = trim((string) ($branding['jurisdiction_slug'] ?? ''));
    $useJurisdictionPath = (bool) ($branding['frontend_uses_jurisdiction_path'] ?? ($slug !== ''));
    $prefix = $useJurisdictionPath && $slug !== '' ? '/' . rawurlencode($slug) : '';
    return $frontendBase . $prefix . '/dashboard/requests/' . rawurlencode($requestId);
  }

}
