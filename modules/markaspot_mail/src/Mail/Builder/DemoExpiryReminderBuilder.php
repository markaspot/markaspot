<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_fastmap:demo_expiry_reminder mails.
 *
 * Sent to the creator of a demo workspace shortly before it hits its
 * auto-cleanup date, so they can either log in + upgrade to keep it or
 * let it expire. The legacy markaspot_fastmap_mail() hook renders three
 * separate t() paragraphs plus a hardcoded civicspot.io upsell line;
 * this builder reassembles the same information as structured
 * card_transactional content with a pill-shaped "Keep my workspace" CTA.
 *
 * Required params:
 *   - workspace_name (string)
 *   - expiry_date    (string, pre-formatted date ready for display)
 *
 * Optional params:
 *   - workspace_slug (string; when present + workspace_base_url is
 *     configured, the CTA lands on the workspace login screen rather
 *     than the civicspot.io homepage)
 *
 * Always platform mode: the recipient is a workspace owner on the
 * CivicSpot SaaS, not a citizen of any single jur group.
 */
final class DemoExpiryReminderBuilder implements MailBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::FASTMAP_DEMO_EXPIRY;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_fastmap' && $key === 'demo_expiry_reminder';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $workspaceName = trim((string) ($ctx->params['workspace_name'] ?? ''));
    $expiryDate = trim((string) ($ctx->params['expiry_date'] ?? ''));
    if ($workspaceName === '' || $expiryDate === '') {
      $this->logger->warning('demo_expiry_reminder: missing workspace_name or expiry_date, skipping branded render.');
      return NULL;
    }

    $slug = trim((string) ($ctx->params['workspace_slug'] ?? ''));
    $langcode = $ctx->langcode;
    $workspaceUrl = $this->resolveWorkspaceUrl($slug);
    $ctaUrl = $workspaceUrl !== '' ? $workspaceUrl : 'https://civicspot.io';

    $subject = (string) $this->t('Your demo workspace "@name" expires soon', [
      '@name' => $workspaceName,
    ], ['langcode' => $langcode]);

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => (string) $this->t('Demo workspace "@name" expires @date', [
          '@name' => $workspaceName,
          '@date' => $expiryDate,
        ], ['langcode' => $langcode]),
        'headline' => (string) $this->t('Your demo expires @date', [
          '@date' => $expiryDate,
        ], ['langcode' => $langcode]),
        'intro' => (string) $this->t('The demo workspace "@name" will be automatically removed on @date.', [
          '@name' => $workspaceName,
          '@date' => $expiryDate,
        ], ['langcode' => $langcode]),
        'body_blocks' => [
          (string) $this->t('Upgrade now to keep your workspace, history and settings, or ignore this email to let it expire.', [], ['langcode' => $langcode]),
        ],
        'cta_label' => $workspaceUrl !== ''
          ? (string) $this->t('Open my workspace', [], ['langcode' => $langcode])
          : (string) $this->t('Create a permanent workspace', [], ['langcode' => $langcode]),
        'cta_url' => $ctaUrl,
        'features_block' => [
          [(string) $this->t('Workspace', [], ['langcode' => $langcode]) => $workspaceName],
          [(string) $this->t('Expires', [], ['langcode' => $langcode]) => $expiryDate],
        ],
      ],
      mode: 'platform',
    );
  }

  /**
   * Resolves the workspace base URL from markaspot_fastmap.settings.
   *
   * Supports both template URLs ("https://example.com/{slug}") and plain
   * base URLs ("https://example.com") by appending the slug when no
   * placeholder is present, mirroring the legacy hook_mail's behavior.
   * Returns empty string if no slug or no configured base so the caller
   * can fall back to the civicspot.io upsell URL.
   */
  private function resolveWorkspaceUrl(string $slug): string {
    if ($slug === '') {
      return '';
    }
    $base = (string) $this->configFactory
      ->get('markaspot_fastmap.settings')
      ->get('workspace_base_url');
    if ($base === '') {
      return '';
    }
    if (str_contains($base, '{slug}')) {
      return (string) str_replace(['{slug}', '{id}'], [$slug, ''], $base);
    }
    return rtrim($base, '/') . '/' . $slug;
  }

}
