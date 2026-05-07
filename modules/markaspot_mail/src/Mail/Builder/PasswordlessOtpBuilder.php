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
      '@minutes' => $expiresIn,
      '@platform_name' => $platformName,
    ];

    // The subject and preheader templates explicitly drop @code. Admins who
    // customize config could otherwise leak the OTP into headers, previews,
    // notifications, MTA logs, and indexed provider metadata. Body +
    // plainText keep @code; header/preview-bound slots do not.
    $subjectReplacements = array_diff_key($replacements, ['@code' => TRUE]);
    $subject = $this->resolveFromConfig('subject', $subjectReplacements, $langcode)
      ?: (string) $this->t('@platform_name: Your verification code', [
        '@platform_name' => $platformName,
      ], ['langcode' => $langcode]);

    $preheader = $this->resolvePreheaderFromConfig($subjectReplacements, $langcode)
      ?: $this->deriveLegacyBodyPreview($replacements, $langcode)
      ?: $this->resolvePreheaderFromBaseConfig($subjectReplacements)
      ?: (string) $this->t('Your verification code expires in @minutes minutes.', [
        '@minutes' => $expiresIn,
      ], ['langcode' => $langcode]);
    $headline = $this->resolveFromConfig('headline', $replacements, $langcode, FALSE)
      ?: $this->deriveHeadlineFromSubject($subjectReplacements, $langcode)
      ?: $this->resolveFromConfig('headline', $replacements, $langcode)
      ?: (string) $this->t('Verify your account', [], ['langcode' => $langcode]);
    $subtext = $this->resolveFromConfig('subtext', $replacements, $langcode, FALSE)
      ?: $this->deriveLegacyBodySubtext($replacements, $langcode)
      ?: $this->resolveFromConfig('subtext', $replacements, $langcode)
      ?: (string) $this->t('Enter this code in the next @minutes minutes. If you did not request this, you can ignore this email.', [
        '@minutes' => $expiresIn,
      ], ['langcode' => $langcode]);
    $plainText = $this->resolveFromConfig('plain_text', $replacements, $langcode, FALSE)
      ?: $this->resolveFromConfig('body', $replacements, $langcode, FALSE)
      ?: $this->resolveFromConfig('plain_text', $replacements, $langcode)
      ?: (string) $this->t("Your verification code: @code\n\nIt expires in @minutes minutes.", [
        '@code' => $code,
        '@minutes' => $expiresIn,
      ], ['langcode' => $langcode]);

    return new MailMessage(
      subject: $subject,
      variant: 'hero_code',
      content: [
        'preheader' => $preheader,
        'headline' => $headline,
        'code' => $this->formatCodeForDisplay($code),
        'subtext' => $subtext,
      ],
      mode: $mode,
      jurisdictionId: $jurisdictionId,
      // Plain-text override: the hero_code variant's auto-derived plain
      // text works, but the space-separated code that looks great in the
      // monospaced hero reads awkwardly at the inbox preview / SMS
      // fallback level. Keep the plain body compact.
      plainText: $plainText,
    );
  }

  /**
   * Formats the OTP code for display in the hero block.
   */
  private function formatCodeForDisplay(string $code): string {
    return $code;
  }

  /**
   * Reads a config template and substitutes @placeholders.
   */
  private function resolveFromConfig(string $key, array $replacements, string $langcode, bool $fallbackToBase = TRUE): string {
    $template = $this->readConfigTemplate($key, $langcode, $fallbackToBase);
    return $template !== '' ? (string) strtr($template, $replacements) : '';
  }

  /**
   * Reads a raw config template.
   */
  private function readConfigTemplate(string $key, string $langcode, bool $fallbackToBase = TRUE): string {
    $config = $this->languageManager
      ->getLanguageConfigOverride($langcode, 'markaspot_passwordless.mail')
      ->get('verification_code');
    if (is_array($config) && !empty($config[$key])) {
      return (string) $config[$key];
    }
    if (!$fallbackToBase) {
      return '';
    }

    $config = $this->configFactory
      ->get('markaspot_passwordless.mail')
      ->get('verification_code');
    return is_array($config) && !empty($config[$key]) ? (string) $config[$key] : '';
  }

  /**
   * Reads preheader config without allowing @code to leak into previews.
   */
  private function resolvePreheaderFromConfig(array $replacements, string $langcode): string {
    $template = $this->readConfigTemplate('preheader', $langcode, FALSE);
    if ($template === '') {
      return '';
    }
    return (string) strtr($this->stripCodePlaceholder($template), $replacements);
  }

  /**
   * Reads the base preheader without allowing @code to leak into previews.
   */
  private function resolvePreheaderFromBaseConfig(array $replacements): string {
    $config = $this->configFactory
      ->get('markaspot_passwordless.mail')
      ->get('verification_code');
    if (!is_array($config) || empty($config['preheader'])) {
      return '';
    }
    return (string) strtr($this->stripCodePlaceholder((string) $config['preheader']), $replacements);
  }

  /**
   * Derives a localized headline from legacy subject config.
   */
  private function deriveHeadlineFromSubject(array $replacements, string $langcode): string {
    $template = $this->readConfigTemplate('subject', $langcode, FALSE);
    if ($template === '') {
      return '';
    }
    $subject = trim((string) strtr($template, $replacements));
    $platformName = (string) ($replacements['@platform_name'] ?? '');
    if ($platformName !== '' && str_starts_with($subject, $platformName . ':')) {
      return trim(substr($subject, strlen($platformName) + 1));
    }
    if (str_contains($subject, ':')) {
      return trim((string) preg_replace('/^.*?:\s*/', '', $subject, 1));
    }
    return $subject;
  }

  /**
   * Derives a localized inbox preview from legacy body config.
   */
  private function deriveLegacyBodyPreview(array $replacements, string $langcode): string {
    $line = $this->firstLegacyBodyLineWithoutCode($langcode);
    return $line !== '' ? (string) strtr($line, $replacements) : '';
  }

  /**
   * Derives localized hero support text from legacy body config.
   */
  private function deriveLegacyBodySubtext(array $replacements, string $langcode): string {
    $lines = $this->legacyBodyLinesWithoutCode($langcode);
    return $lines !== [] ? (string) strtr(implode(' ', $lines), $replacements) : '';
  }

  /**
   * Returns the first non-code line from legacy body config.
   */
  private function firstLegacyBodyLineWithoutCode(string $langcode): string {
    $lines = $this->legacyBodyLinesWithoutCode($langcode);
    return $lines[0] ?? '';
  }

  /**
   * Returns non-empty legacy body lines that do not contain the OTP code.
   *
   * Older locale overrides only shipped subject/body. Reusing their non-code
   * body lines keeps localized mails localized until a site adds the newer
   * headline, preheader, subtext and plain_text keys.
   *
   * @return string[]
   *   Body lines without @code.
   */
  private function legacyBodyLinesWithoutCode(string $langcode): array {
    $template = $this->readConfigTemplate('body', $langcode, FALSE);
    if ($template === '') {
      return [];
    }
    $lines = preg_split('/\R+/', $template) ?: [];
    $filtered = [];
    foreach ($lines as $line) {
      $line = trim((string) $line);
      if ($line === '' || str_contains($line, '@code')) {
        continue;
      }
      $filtered[] = $line;
    }
    return $filtered;
  }

  /**
   * Removes the OTP placeholder from preview-bound templates.
   */
  private function stripCodePlaceholder(string $template): string {
    $stripped = (string) preg_replace('/\s*[:-]?\s*@code\b/', '', $template);
    return trim($stripped);
  }

}
