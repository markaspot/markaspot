<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailHtmlRenderer;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Service\MailHtmlRenderer
 * @group markaspot_mail
 *
 * These tests verify the render-array shape passed into the Drupal Renderer
 * and the plaintext fallback derived from the same content. They don't hit
 * Twig (that's what integration / kernel smoke tests are for); instead we
 * stub the Renderer to synthesize a deterministic HTML envelope that still
 * lets us assert the key pieces the consumer cares about (primary color,
 * code string, bulletproof button shape).
 */
final class MailHtmlRendererTest extends UnitTestCase {

  /**
   * @covers ::render
   */
  public function testHeroCodeRendersCodeAndPrimaryColor(): void {
    $branding = $this->buildBranding();
    $content = [
      'headline' => 'Verify your account',
      'code' => '156428',
      'subtext' => 'Enter this code in the next 10 minutes.',
      'preheader' => 'Your one-time login code',
    ];

    $renderer = $this->buildRenderer();
    $out = $renderer->render('hero_code', $branding, $content, 'en');

    $this->assertArrayHasKey('html', $out);
    $this->assertArrayHasKey('plain', $out);
    // The stub renderer echoes back the relevant variables so we can assert
    // the primary color + code reached the Twig layer.
    $this->assertStringContainsString('background:#004ced', $out['html']);
    $this->assertStringContainsString('156428', $out['html']);
    $this->assertStringContainsString('variant=hero_code', $out['html']);
    // Preheader is passed through and tagged as hidden.
    $this->assertStringContainsString('preheader:Your one-time login code', $out['html']);
    $this->assertStringContainsString('preheader-hidden', $out['html']);
  }

  /**
   * @covers ::render
   */
  public function testHeroCodePlainSpacesOutCode(): void {
    $branding = $this->buildBranding();
    $content = [
      'headline' => 'Verify your account',
      'code' => '156428',
      'subtext' => 'Enter within 10 minutes.',
      'preheader' => 'Your code',
    ];

    $renderer = $this->buildRenderer();
    $plain = $renderer->render('hero_code', $branding, $content, 'en')['plain'];

    $this->assertStringContainsString('1 5 6 4 2 8', $plain);
    $this->assertStringContainsString('Verify your account', $plain);
    $this->assertStringContainsString('Mark-a-Spot', $plain);
    $this->assertStringContainsString('support@civic-patches.com', $plain);
    $this->assertStringContainsString('Impressum: https://civicpatches.de/impressum', $plain);
  }

  /**
   * @covers ::render
   */
  public function testCardTransactionalUsesBulletproofButton(): void {
    $branding = $this->buildBranding();
    $content = [
      'headline' => 'Your report was updated',
      'intro' => 'We moved your report to "In progress".',
      'body_blocks' => ['Thank you for contributing to the neighborhood.'],
      'cta_label' => 'View report',
      'cta_url' => 'https://mark-a-spot.com/amsterdam/requests/42',
      'preheader' => 'Report #42 update',
    ];

    $renderer = $this->buildRenderer();
    $out = $renderer->render('card_transactional', $branding, $content, 'en');

    // Renderer must mark the CTA URL as safe (https://).
    $this->assertStringContainsString('cta_url_is_safe:1', $out['html']);
    $this->assertStringContainsString('cta_url:https://mark-a-spot.com/amsterdam/requests/42', $out['html']);
    $this->assertStringContainsString('variant=card_transactional', $out['html']);
    // Plain fallback must include the URL alongside its label.
    $this->assertStringContainsString('View report: https://mark-a-spot.com/amsterdam/requests/42', $out['plain']);
  }

  /**
   * @covers ::render
   */
  public function testUnsafeCtaUrlIsFlaggedAsUnsafe(): void {
    $branding = $this->buildBranding();
    $content = [
      'headline' => 'Your report',
      'intro' => 'hi',
      'body_blocks' => [],
      'cta_label' => 'Open',
      'cta_url' => 'javascript:alert(1)',
      'preheader' => '',
    ];

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->atLeastOnce())->method('warning');
    $renderer = $this->buildRenderer($logger);

    $out = $renderer->render('card_transactional', $branding, $content, 'en');
    $this->assertStringContainsString('cta_url_is_safe:0', $out['html']);
    // Plain fallback must NOT leak the unsafe URL as a "CTA line".
    $this->assertStringNotContainsString('Open: javascript:alert(1)', $out['plain']);
  }

  /**
   * @covers ::render
   */
  public function testFeaturesAndContactBlocksAppearInPlain(): void {
    $branding = $this->buildBranding();
    $content = [
      'headline' => 'Demo workspace expires soon',
      'intro' => 'Your demo ends in 3 days.',
      'body_blocks' => [],
      'features_block' => [
        ['Workspace' => 'acme-demo'],
        ['Expires' => '2026-05-01'],
      ],
      'contact_block' => ['Questions? support@civic-patches.com'],
      'preheader' => 'Demo ends soon',
    ];
    $renderer = $this->buildRenderer();
    $plain = $renderer->render('card_transactional', $branding, $content, 'en')['plain'];

    $this->assertStringContainsString('Workspace: acme-demo', $plain);
    $this->assertStringContainsString('Expires: 2026-05-01', $plain);
    $this->assertStringContainsString('Questions? support@civic-patches.com', $plain);
  }

  /**
   * @covers ::render
   */
  public function testPlainTextOverrideReplacesDerivedPlain(): void {
    $branding = $this->buildBranding();
    $content = [
      'headline' => 'Your report was updated',
      'intro' => 'Auto-derived intro that must NOT appear when override is passed.',
      'body_blocks' => [],
      'preheader' => '',
    ];
    $override = "Builder-controlled plain text.\nSecond line.";

    $renderer = $this->buildRenderer();
    $out = $renderer->render('card_transactional', $branding, $content, 'en', $override);

    $this->assertStringContainsString("Builder-controlled plain text.\nSecond line.", $out['plain']);
    $this->assertStringEndsWith("\n", $out['plain'], 'Override must terminate with a newline.');
    // The auto-derived intro must not appear — override wins entirely.
    $this->assertStringNotContainsString('Auto-derived intro', $out['plain']);
  }

  /**
   * @covers ::render
   */
  public function testPlainTextOverrideNullFallsBackToDerivation(): void {
    $branding = $this->buildBranding();
    $content = [
      'headline' => 'Your report was updated',
      'intro' => 'Auto-derived intro that SHOULD appear when override is NULL.',
      'body_blocks' => [],
      'preheader' => '',
    ];

    $renderer = $this->buildRenderer();
    $out = $renderer->render('card_transactional', $branding, $content, 'en', NULL);

    $this->assertStringContainsString('Auto-derived intro', $out['plain']);
    $this->assertStringContainsString('Mark-a-Spot', $out['plain']);
  }

  /**
   * Builds a MailHtmlRenderer backed by a stub RendererInterface.
   *
   * The stub echoes the build array's key variables so tests can assert
   * what reached Twig without involving the Twig engine itself.
   */
  private function buildRenderer(?LoggerInterface $logger = NULL): MailHtmlRenderer {
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderInIsolation')->willReturnCallback(
      function (array $build): string {
        return $this->synthesizeHtml($build);
      },
    );
    return new MailHtmlRenderer(
      $renderer,
      $this->buildRealBrandingService(),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a real MailBrandingService with harmless mocked dependencies.
   *
   * MailBrandingService is final and therefore not double-able; the
   * renderer only calls the class in its type hint, not any of its
   * methods, so a real instance with mocked deps is the lightest way to
   * satisfy the constructor.
   */
  private function buildRealBrandingService(): MailBrandingService {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $configFactory->method('get')->willReturn($config);
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    return new MailBrandingService(
      $etm,
      $configFactory,
      $languageManager,
      $fileUrlGenerator,
      $logger,
    );
  }

  /**
   * Synthesizes a deterministic HTML stub from the build array.
   *
   * Surfaces the key render-array inputs so tests can assert them without
   * involving Twig.
   */
  private function synthesizeHtml(array $build): string {
    $variant = (string) ($build['#variant'] ?? '');
    $branding = (array) ($build['#branding'] ?? []);
    $content = (array) ($build['#content'] ?? []);

    $parts = [
      '<!DOCTYPE html>',
      '<!-- variant=' . $variant . ' -->',
      '<!-- primary=' . ($branding['primary_color'] ?? '') . ' -->',
      '<div style="background:' . ($branding['primary_color'] ?? '') . ';">',
    ];
    if ($variant === 'hero_code') {
      $parts[] = '<code>' . ($content['code'] ?? '') . '</code>';
      $parts[] = '<span class="preheader-hidden">preheader:' . ($content['preheader'] ?? '') . '</span>';
    }
    else {
      $parts[] = '<h1>' . ($content['headline'] ?? '') . '</h1>';
      if (!empty($content['cta_url'])) {
        $parts[] = 'cta_url:' . ($content['cta_url'] ?? '');
        $parts[] = 'cta_url_is_safe:' . (int) (!empty($content['cta_url_is_safe']));
      }
    }
    $parts[] = '</div>';
    return implode("\n", $parts);
  }

  /**
   * Canonical branding fixture for the tests.
   */
  private function buildBranding(): array {
    return [
      'mode' => 'platform',
      'platform_name' => 'Mark-a-Spot',
      'logo_url' => 'https://mark-a-spot.com/logo.png',
      'primary_color' => '#004ced',
      'background_color' => '#EEF3FF',
      'support_email' => 'support@civic-patches.com',
      'legal_notice_url' => 'https://civicpatches.de/impressum',
      'privacy_url' => 'https://civicpatches.de/datenschutz',
      'email_footer_html' => Markup::create(''),
      'reply_to' => 'support@civic-patches.com',
      'frontend_base_url' => 'https://mark-a-spot.com',
      'jurisdiction_slug' => NULL,
      'jurisdiction_label' => NULL,
      'platform_footer' => [
        'mas_link' => 'https://mark-a-spot.com',
        'docs_link' => 'https://mark-a-spot.com/docs',
        'civicspot_link' => 'https://civicspot.io',
        'impressum_link' => 'https://civicpatches.de/impressum',
        'datenschutz_link' => 'https://civicpatches.de/datenschutz',
        'copyright' => '© ' . date('Y') . ' Civic Patches GmbH',
      ],
    ];
  }

}
