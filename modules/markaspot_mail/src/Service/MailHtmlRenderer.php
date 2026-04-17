<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Renders branded HTML mail bodies plus a plaintext fallback.
 *
 * Two variants are supported:
 *   - hero_code         OTP-style hero box with a large monospace code. Used
 *                       for passwordless login / verification mails.
 *   - card_transactional White card with optional CTA button, body
 *                       paragraphs, key/value features table and a contact
 *                       block. Used for ECA notifications, workspace
 *                       verifications and demo expiry mails.
 *
 * The renderer never emits external CSS or a <style> in <head> beyond a
 * minimal progressive-enhancement block; all critical styling is inline so
 * it survives Gmail, Outlook and the broader MUA zoo.
 */
final class MailHtmlRenderer {

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly MailBrandingService $branding,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Renders a mail body.
   *
   * @param string $variant
   *   Either "hero_code" or "card_transactional".
   * @param array $branding
   *   Branding package from MailBrandingService::getBranding().
   * @param array $content
   *   Variant-specific content. See module README / task spec.
   * @param string $langcode
   *   Langcode for the rendered mail.
   *
   * @return array
   *   ['html' => string, 'plain' => string]
   */
  public function render(string $variant, array $branding, array $content, string $langcode): array {
    $variant = in_array($variant, ['hero_code', 'card_transactional'], TRUE)
      ? $variant
      : 'card_transactional';

    // Validate CTA URL if present; if it doesn't pass, degrade to plaintext.
    if ($variant === 'card_transactional' && !empty($content['cta_url'])) {
      if (!$this->isSafeUrl((string) $content['cta_url'])) {
        $this->logger->warning('Unsafe CTA URL in transactional mail, rendering as plain text: @url', [
          '@url' => $content['cta_url'],
        ]);
        $content['cta_url_is_safe'] = FALSE;
      }
      else {
        $content['cta_url_is_safe'] = TRUE;
      }
    }

    // SVG logos do not render reliably in mobile mail clients. We don't
    // rasterize here (out of scope for Stage 1, follow-up when all stages
    // are live). Emit a debug note so operators see the mismatch without
    // cluttering watchdog with warnings.
    $logoUrl = (string) ($branding['logo_url'] ?? '');
    if ($logoUrl !== '' && preg_match('/\.svg(?:$|[?#])/i', $logoUrl) === 1) {
      $this->logger->debug('Jurisdiction logo is SVG; mobile mail clients may not render it: @url', [
        '@url' => $logoUrl,
      ]);
    }

    $build = [
      '#theme' => 'markaspot_mail_layout',
      '#variant' => $variant,
      '#branding' => $branding,
      '#content' => $content,
      '#langcode' => $langcode,
    ];

    $html = (string) $this->renderer->renderInIsolation($build);
    $plain = $this->htmlToPlain($variant, $branding, $content);

    return [
      'html' => $html,
      'plain' => $plain,
    ];
  }

  /**
   * Converts the rendered mail to a plaintext fallback.
   *
   * We don't re-parse the HTML; we re-derive plain text from $content and
   * $branding so we keep full control over line breaks, spacing and the
   * spaced-out OTP code. The result is deterministic and test-friendly.
   */
  private function htmlToPlain(string $variant, array $branding, array $content): string {
    $lines = [];
    $platformName = (string) ($branding['platform_name'] ?? 'Mark-a-Spot');

    if ($variant === 'hero_code') {
      $headline = trim((string) ($content['headline'] ?? ''));
      if ($headline !== '') {
        $lines[] = $headline;
        $lines[] = '';
      }
      $code = trim((string) ($content['code'] ?? ''));
      if ($code !== '') {
        $spaced = trim(implode(' ', str_split($code)));
        $lines[] = $spaced;
        $lines[] = '';
      }
      $subtext = trim((string) ($content['subtext'] ?? ''));
      if ($subtext !== '') {
        $lines[] = $subtext;
        $lines[] = '';
      }
    }
    else {
      $headline = trim((string) ($content['headline'] ?? ''));
      if ($headline !== '') {
        $lines[] = $headline;
        $lines[] = '';
      }
      $intro = trim((string) ($content['intro'] ?? ''));
      if ($intro !== '') {
        $lines[] = $intro;
        $lines[] = '';
      }
      foreach ((array) ($content['body_blocks'] ?? []) as $block) {
        $block = trim((string) $block);
        if ($block !== '') {
          $lines[] = $block;
          $lines[] = '';
        }
      }
      $ctaUrl = trim((string) ($content['cta_url'] ?? ''));
      $ctaLabel = trim((string) ($content['cta_label'] ?? ''));
      if ($ctaUrl !== '' && !empty($content['cta_url_is_safe'])) {
        if ($ctaLabel !== '') {
          $lines[] = $ctaLabel . ': ' . $ctaUrl;
        }
        else {
          $lines[] = $ctaUrl;
        }
        $lines[] = '';
      }
      foreach ((array) ($content['features_block'] ?? []) as $pair) {
        if (!is_array($pair)) {
          continue;
        }
        foreach ($pair as $label => $value) {
          $lines[] = $label . ': ' . $value;
        }
      }
      if (!empty($content['features_block'])) {
        $lines[] = '';
      }
      foreach ((array) ($content['contact_block'] ?? []) as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
          $lines[] = $line;
        }
      }
      if (!empty($content['contact_block'])) {
        $lines[] = '';
      }
    }

    $lines[] = '--';
    $lines[] = $platformName;
    if (!empty($branding['support_email'])) {
      $lines[] = $branding['support_email'];
    }
    if (!empty($branding['legal_notice_url'])) {
      $lines[] = 'Impressum: ' . $branding['legal_notice_url'];
    }
    if (!empty($branding['privacy_url'])) {
      $lines[] = 'Privacy: ' . $branding['privacy_url'];
    }

    $text = implode("\n", $lines);
    // Collapse 3+ consecutive newlines to two.
    $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text) . "\n";
  }

  /**
   * Validates an external URL for use as CTA href.
   *
   * Only http / https targets are accepted; anything else (javascript:,
   * data:, mailto: etc. when used as a button target) returns FALSE.
   */
  private function isSafeUrl(string $url): bool {
    if (preg_match('#^https?://#i', $url) !== 1) {
      return FALSE;
    }
    try {
      Url::fromUri($url);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
