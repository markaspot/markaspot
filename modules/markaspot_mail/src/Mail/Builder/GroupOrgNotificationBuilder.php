<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_group:org_notification mails.
 *
 * Generic admin-to-organisation notification fired by the group module
 * when a service request is routed to one or more head organisations.
 * The group module owns trigger timing and recipient resolution; this builder
 * owns the wording, translation and jurisdiction branding.
 *
 * Required params:
 *   - node (NodeInterface)
 *   - organisation (GroupInterface)
 */
final class GroupOrgNotificationBuilder implements MailBuilderInterface {

  use ResolveJurisdictionFromNodeTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly MailBrandingService $branding,
    private readonly MailTextResolver $textResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_GROUP_ORG_NOTIFICATION;
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
    $node = $ctx->params['node'] ?? NULL;
    $organisation = $ctx->params['organisation'] ?? NULL;
    if (!$node instanceof NodeInterface || !$organisation instanceof GroupInterface) {
      $this->logger->warning('org_notification: missing node or organisation param, skipping branded render.');
      return NULL;
    }

    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromNode($node);
    $branding = $this->branding->getBranding($jurisdictionId, $mode, $ctx->langcode);
    $requestId = $this->resolveRequestId($node);
    $organisationName = trim((string) $organisation->label());
    $organisationLabel = $organisationName !== ''
      ? $organisationName
      : (string) $this->t('your organisation', [], ['langcode' => $ctx->langcode]);
    $category = $this->resolveCategoryLabel($node);
    $address = $this->resolveFieldString($node, 'field_address');
    $description = $this->resolveBodyText($node);
    $requestUrl = $this->resolveRequestUrl($requestId, $branding);

    $defaultSubject = (string) $this->t('Request #@request_id assigned to @organisation', [
      '@request_id' => $requestId,
      '@organisation' => $organisationLabel,
    ], ['langcode' => $ctx->langcode]);
    $defaultPreheader = (string) $this->t('Request #@request_id was assigned to @organisation', [
      '@request_id' => $requestId,
      '@organisation' => $organisationLabel,
    ], ['langcode' => $ctx->langcode]);
    $defaultIntro = (string) $this->t('A citizen request is ready for review.', [], ['langcode' => $ctx->langcode]);
    $defaultLead = (string) $this->t(
      'This request has been assigned to your organisation for processing.',
      [],
      ['langcode' => $ctx->langcode],
    );
    $defaultCtaLabel = (string) $this->t('Open request', [], ['langcode' => $ctx->langcode]);

    $slots = $this->resolveTextSlots('group_assignment', $node, $ctx->langcode);
    $slots = $this->replaceContextPlaceholders($slots, [
      '{{ organisation }}' => $organisationLabel,
      '{{ request_id }}' => $requestId,
    ]);

    $subject = $slots['subject'] !== '' ? $slots['subject'] : $defaultSubject;
    $headline = $slots['headline'] !== '' ? $slots['headline'] : $defaultSubject;
    $intro = $slots['intro'] !== '' ? $slots['intro'] : $defaultIntro;
    $preheader = $slots['preheader'] !== '' ? $slots['preheader'] : $defaultPreheader;
    $ctaLabel = $slots['cta_label'] !== '' ? $slots['cta_label'] : $defaultCtaLabel;

    $bodyBlocks = $slots['body_blocks'] !== [] ? $slots['body_blocks'] : [$defaultLead];
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
    if ($organisationName !== '') {
      $features[] = [(string) $this->t('Organisation', [], ['langcode' => $ctx->langcode]) => $organisationName];
    }

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => $preheader,
        'headline' => $headline,
        'intro' => $intro,
        'body_blocks' => $bodyBlocks,
        'features_block' => $features,
        'cta_label' => $requestUrl !== '' ? $ctaLabel : '',
        'cta_url' => $requestUrl,
      ],
      mode: $mode,
      jurisdictionId: $jurisdictionId,
    );
  }

  /**
   * Resolves and token-replaces the six editable text slots.
   */
  private function resolveTextSlots(string $key, NodeInterface $node, string $langcode): array {
    $resolved = $this->textResolver->resolve('markaspot_mail.texts', $key, $langcode);
    $slots = [
      'subject' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'subject', $langcode),
      'headline' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'headline', $langcode),
      'intro' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'intro', $langcode),
      'body_blocks' => $resolved['body_blocks'],
      'cta_label' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'cta_label', $langcode),
      'preheader' => $this->textResolver->resolveField('markaspot_mail.texts', $key, 'preheader', $langcode),
    ];
    return $this->textResolver->replaceTokens($slots, ['node' => $node], $langcode);
  }

  /**
   * Replaces assignment-only placeholders after generic token replacement.
   */
  private function replaceContextPlaceholders(array $slots, array $replacements): array {
    foreach (['subject', 'headline', 'cta_label', 'preheader'] as $slot) {
      $slots[$slot] = strtr($slots[$slot], $replacements);
    }
    $htmlReplacements = array_map(
      static fn(string $replacement): string => Html::escape($replacement),
      $replacements,
    );
    $slots['intro'] = Xss::filter(
      strtr($slots['intro'], $htmlReplacements),
      MailTextResolver::MAIL_ALLOWED_TAGS,
    );
    $slots['body_blocks'] = array_map(
      static fn(string $block): string => Xss::filter(
        strtr($block, $htmlReplacements),
        MailTextResolver::MAIL_ALLOWED_TAGS,
      ),
      $slots['body_blocks'],
    );
    return $slots;
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
   * Resolves and escapes the citizen-submitted description.
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
