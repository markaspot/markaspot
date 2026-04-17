<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_fastmap:workspace_verification mails.
 *
 * Sent to a workspace creator after sign-up on civicspot.io so they can
 * click through to activate the workspace within the configured cleanup
 * window (default 7 days). The legacy markaspot_fastmap_mail() hook
 * renders a plain-text body via @-placeholder strtr(); this builder
 * preserves the admin-editable copy in the config but wraps the result
 * in a branded card_transactional with a pill-shaped "Verify email" CTA.
 *
 * Required params:
 *   - workspace_name (string)
 *   - verify_url (string, absolute URL with verification token)
 *
 * Optional params:
 *   - site_name (string, defaults to "CivicSpot")
 *   - cleanup_days (int|string, defaults to 7)
 *
 * Always platform mode: the workspace doesn't have a jurisdiction yet
 * (that's precisely what the verification step unlocks), so there is no
 * jur group to attach Zone-1 branding to.
 *
 * Admin-facing subject + body template live in markaspot_fastmap.mail
 * .workspace_verification config with per-locale overrides. When the
 * config is missing we fall back to hardcoded t() copy so fresh test
 * environments still produce sensible mail.
 */
final class WorkspaceVerificationBuilder implements MailBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConfigurableLanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::FASTMAP_WORKSPACE_VERIFICATION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_fastmap' && $key === 'workspace_verification';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $workspaceName = trim((string) ($ctx->params['workspace_name'] ?? ''));
    $verifyUrl = trim((string) ($ctx->params['verify_url'] ?? ''));
    if ($workspaceName === '' || $verifyUrl === '') {
      $this->logger->warning('workspace_verification: missing workspace_name or verify_url, skipping branded render.');
      return NULL;
    }

    $siteName = trim((string) ($ctx->params['site_name'] ?? ''));
    if ($siteName === '') {
      $siteName = 'CivicSpot';
    }
    $cleanupDays = (string) ($ctx->params['cleanup_days'] ?? '7');
    $langcode = $ctx->langcode;

    $replacements = [
      '@site_name' => $siteName,
      '@workspace_name' => $workspaceName,
      '@verify_url' => $verifyUrl,
      '@cleanup_days' => $cleanupDays,
    ];

    $subject = $this->resolveFromConfig('subject', $replacements, $langcode)
      ?: (string) $this->t('Activate your @site workspace "@name"', [
        '@site' => $siteName,
        '@name' => $workspaceName,
      ], ['langcode' => $langcode]);

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => (string) $this->t('Click to activate your @name workspace', [
          '@name' => $workspaceName,
        ], ['langcode' => $langcode]),
        'headline' => (string) $this->t('Activate your workspace', [], ['langcode' => $langcode]),
        'intro' => (string) $this->t('Welcome to @site! Your workspace "@name" is ready to activate.', [
          '@site' => $siteName,
          '@name' => $workspaceName,
        ], ['langcode' => $langcode]),
        'body_blocks' => [
          (string) $this->t('Click the button below to verify your email and unlock the workspace.', [], ['langcode' => $langcode]),
          (string) $this->t('Unverified workspaces are automatically removed after @days days.', [
            '@days' => $cleanupDays,
          ], ['langcode' => $langcode]),
        ],
        'cta_label' => (string) $this->t('Verify email', [], ['langcode' => $langcode]),
        'cta_url' => $verifyUrl,
      ],
      mode: 'platform',
    );
  }

  /**
   * Reads a config template for subject/body and substitutes @placeholders.
   *
   * Tries language-override first (per-locale editable subject), then the
   * default config, then returns an empty string so the caller can apply
   * its t()-based fallback.
   */
  private function resolveFromConfig(string $key, array $replacements, string $langcode): string {
    $config = $this->languageManager
      ->getLanguageConfigOverride($langcode, 'markaspot_fastmap.mail')
      ->get('workspace_verification');
    if (!is_array($config) || empty($config[$key])) {
      $config = $this->configFactory
        ->get('markaspot_fastmap.mail')
        ->get('workspace_verification');
    }
    if (!is_array($config) || empty($config[$key])) {
      return '';
    }
    return (string) strtr((string) $config[$key], $replacements);
  }

}
