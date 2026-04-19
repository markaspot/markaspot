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
 * Builder for markaspot_passwordless:verification_code mails.
 *
 * Produces the sign-in OTP mail in the hero_code variant: a colored hero
 * card with the code rendered in monospace with letter-spacing for visual
 * digit separation, framed by the recipient-facing subject + subtext.
 *
 * Required param:
 *   - code (string): the OTP value (numeric, but we never narrow the type
 *     in case operators ever extend it to alpha codes).
 *
 * Optional params:
 *   - expires_in (int|string): minutes until the code expires, default 10.
 *   - platform_name (string): recipient-facing brand name, used as the
 *     subject-line prefix and as a fallback platform label. Defaults to
 *     "Mark-a-Spot" via the branding service.
 *   - jurisdiction_id (int|null): Group entity id of the jur group the
 *     OTP belongs to, if any. When set, the mail switches to jurisdiction
 *     mode so the Zone-1 footer renders with the tenant's email footer,
 *     legal notice and support email. When NULL, we stay in platform mode.
 *   - email_footer (string): legacy param from markaspot_passwordless's
 *     hook_mail; ignored here because jurisdiction mode already pulls the
 *     same value via MailBrandingService.
 *
 * Subject template:
 *   Read from markaspot_passwordless.mail.verification_code config with
 *   language override, @placeholder substitution via strtr. Falls back to
 *   hardcoded t() when config is missing.
 */
final class PasswordlessOtpBuilder implements MailBuilderInterface {

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
    return MailType::PASSWORDLESS_OTP;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_passwordless' && $key === 'verification_code';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $code = trim((string) ($ctx->params['code'] ?? ''));
    if ($code === '') {
      $this->logger->warning('verification_code: missing "code" param, skipping branded render.');
      return NULL;
    }

    $expiresIn = (string) ($ctx->params['expires_in'] ?? '10');
    $platformName = trim((string) ($ctx->params['platform_name'] ?? ''));
    if ($platformName === '') {
      $platformName = 'Mark-a-Spot';
    }
    $langcode = $ctx->langcode;

    $jurisdictionId = NULL;
    $mode = 'platform';
    $rawJid = $ctx->params['jurisdiction_id'] ?? NULL;
    if ($rawJid !== NULL && (int) $rawJid > 0) {
      $jurisdictionId = (int) $rawJid;
      $mode = 'jurisdiction';
    }

    $replacements = [
      '@code' => $code,
      '@expires_in' => $expiresIn,
      '@platform_name' => $platformName,
    ];

    // The subject template explicitly drops @code. Admins who customize
    // the subject config could otherwise write "@platform_name: @code"
    // and leak the OTP into the mail-subject header, which travels in
    // cleartext SMTP, appears in inbox-preview notifications, gets
    // logged at MTAs, and is often indexed by mail providers. Body +
    // plainText keep the @code placeholder; only the header-bound slot
    // drops it here.
    $subjectReplacements = array_diff_key($replacements, ['@code' => TRUE]);
    $subject = $this->resolveFromConfig('subject', $subjectReplacements, $langcode)
      ?: (string) $this->t('@platform_name: Your verification code', [
        '@platform_name' => $platformName,
      ], ['langcode' => $langcode]);

    return new MailMessage(
      subject: $subject,
      variant: 'hero_code',
      content: [
        'preheader' => (string) $this->t('Your verification code: @code', [
          '@code' => $code,
        ], ['langcode' => $langcode]),
        'headline' => (string) $this->t('Verify your account', [], ['langcode' => $langcode]),
        'code' => $this->formatCodeForDisplay($code),
        'subtext' => (string) $this->t('Enter this code in the next @minutes minutes. If you did not request this, you can ignore this email.', [
          '@minutes' => $expiresIn,
        ], ['langcode' => $langcode]),
      ],
      mode: $mode,
      jurisdictionId: $jurisdictionId,
      // Plain-text override: the hero_code variant's auto-derived plain
      // text works, but the space-separated code that looks great in the
      // monospaced hero reads awkwardly at the inbox preview / SMS
      // fallback level. Keep the plain body compact.
      plainText: (string) $this->t("Your verification code: @code\n\nIt expires in @minutes minutes.", [
        '@code' => $code,
        '@minutes' => $expiresIn,
      ], ['langcode' => $langcode]),
    );
  }

  /**
   *
   */
  private function formatCodeForDisplay(string $code): string {
    return $code;
  }

  /**
   * Reads a config template and substitutes @placeholders.
   */
  private function resolveFromConfig(string $key, array $replacements, string $langcode): string {
    $config = $this->languageManager
      ->getLanguageConfigOverride($langcode, 'markaspot_passwordless.mail')
      ->get('verification_code');
    if (!is_array($config) || empty($config[$key])) {
      $config = $this->configFactory
        ->get('markaspot_passwordless.mail')
        ->get('verification_code');
    }
    if (!is_array($config) || empty($config[$key])) {
      return '';
    }
    return (string) strtr((string) $config[$key], $replacements);
  }

}
