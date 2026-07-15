<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\WorkspaceWelcomeBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WorkspaceWelcomeBuilder.
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\WorkspaceWelcomeBuilder::class)]
#[Group('markaspot_mail')]
final class WorkspaceWelcomeBuilderTest extends UnitTestCase {

  /**
   * Tests that getType() returns the FastMap workspace welcome type.
   */
  public function testGetTypeReturnsFastmapWorkspaceWelcome(): void {
    $this->assertSame(
      MailType::FASTMAP_WORKSPACE_WELCOME,
      $this->buildBuilder()->getType()
    );
  }

  /**
   * Tests that supports() only claims the workspace_welcome mail key.
   */
  public function testSupportsOnlyWorkspaceWelcomeKey(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_fastmap', 'workspace_welcome'));
    $this->assertFalse($builder->supports('markaspot_fastmap', 'workspace_verification'));
    $this->assertFalse($builder->supports('markaspot_fastmap', 'other'));
    $this->assertFalse($builder->supports('other', 'workspace_welcome'));
  }

  /**
   * Tests that build() bails out and logs when workspace_name is missing.
   */
  public function testBuildReturnsNullWhenWorkspaceNameMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = $this->buildContext([
      'workspace_url' => 'https://amsterdam-demo.civicspot.io',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Tests that build() bails out and logs when workspace_url is missing.
   */
  public function testBuildReturnsNullWhenWorkspaceUrlMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = $this->buildContext([
      'workspace_name' => 'Amsterdam Demo',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Rejects non-web workspace URL schemes before they reach mail links.
   */
  public function testBuildReturnsNullWhenWorkspaceUrlIsUnsafe(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(3))->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'ftp://example.test/workspace'] as $workspaceUrl) {
      $ctx = $this->buildContext([
        'workspace_name' => 'Unsafe workspace',
        'workspace_url' => $workspaceUrl,
      ]);
      $this->assertNull($builder->build($ctx));
    }
  }

  /**
   * Tests the happy path: CTA plus dashboard/login URLs in the body.
   */
  public function testBuildProducesPlatformMailWithCtaAndDashboardLoginLinks(): void {
    $builder = $this->buildBuilder();

    $ctx = $this->buildContext([
      'workspace_name' => 'Amsterdam Demo',
      'site_name' => 'CivicSpot',
      'workspace_url' => 'https://amsterdam-demo.civicspot.io',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertStringContainsString('Amsterdam Demo', $msg->subject);
    $this->assertStringContainsString('CivicSpot', $msg->subject);
    $this->assertStringContainsString('Amsterdam Demo', $msg->content['intro']);
    $this->assertSame('Open your workspace', $msg->content['cta_label']);
    $this->assertSame('https://amsterdam-demo.civicspot.io', $msg->content['cta_url']);
    // Dashboard and login URLs must be visible in the body text (not just
    // the single CTA), matching the plaintext template this replaces.
    $this->assertTrue(array_any(
      $msg->content['body_blocks'],
      fn($b) => str_contains($b, 'https://amsterdam-demo.civicspot.io/dashboard')
    ));
    $this->assertTrue(array_any(
      $msg->content['body_blocks'],
      fn($b) => str_contains($b, 'https://amsterdam-demo.civicspot.io/auth/login')
    ));
  }

  /**
   * Escapes visible dashboard and login URLs before rendering raw mail HTML.
   */
  public function testBuildEscapesVisibleDashboardAndLoginLinks(): void {
    $msg = $this->buildBuilder()->build($this->buildContext([
      'workspace_name' => 'Escaped links',
      'workspace_url' => 'https://example.test/workspace&tenant=1',
    ]));

    $this->assertNotNull($msg);
    $this->assertStringNotContainsString('workspace&tenant=1', $msg->content['body_blocks'][2]);
    $this->assertStringNotContainsString('workspace&tenant=1', $msg->content['body_blocks'][3]);
    $this->assertStringContainsString(
      'https://example.test/workspace&amp;tenant=1/dashboard',
      $msg->content['body_blocks'][2],
    );
    $this->assertStringContainsString(
      'https://example.test/workspace&amp;tenant=1/auth/login',
      $msg->content['body_blocks'][3],
    );
  }

  /**
   * Keeps the legacy fallback body copy when no wording terms are supplied.
   */
  public function testBuildKeepsDefaultFallbackWordingWithoutOptionalTerms(): void {
    $msg = $this->buildBuilder()->build($this->buildContext([
      'workspace_name' => 'Default wording',
      'workspace_url' => 'https://default-wording.civicspot.io',
    ]));

    $this->assertNotNull($msg);
    $this->assertSame([
      'Try it out: create your first test report directly on the map.',
      'A few demo reports are already in place. Edit or delete them anytime.',
      'Manage incoming reports in your dashboard: <a href="https://default-wording.civicspot.io/dashboard" style="color:#2563eb; text-decoration:underline;">https://default-wording.civicspot.io/dashboard</a>',
      'Log in anytime: <a href="https://default-wording.civicspot.io/auth/login" style="color:#2563eb; text-decoration:underline;">https://default-wording.civicspot.io/auth/login</a>',
    ], $msg->content['body_blocks']);
  }

  /**
   * Retains existing locale translations for the report wording preset.
   */
  public function testBuildRetainsLocalizedLegacyFallbackBlocksWithoutOptionalTerms(): void {
    $translatedSources = [];
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static function (TranslatableMarkup $markup) use (&$translatedSources): string {
        $source = $markup->getUntranslatedString();
        $translatedSources[] = $source;
        return match ($source) {
          'Try it out: create your first test report directly on the map.' => 'Erstelle deinen ersten Testbericht direkt auf der Karte.',
          'A few demo reports are already in place. Edit or delete them anytime.' => 'Ein paar Demo-Meldungen sind bereits vorhanden.',
          'Manage incoming reports in your dashboard:' => 'Verwalte eingehende Meldungen im Dashboard:',
          default => $source,
        };
      },
    );

    $msg = $this->buildBuilder(translation: $translation)->build($this->buildContext([
      'workspace_name' => 'Lokalisierte Begriffe',
      'workspace_url' => 'https://localized-wording.civicspot.io',
      'wording_preset' => 'report',
      'wording_singular' => 'suggestion',
      'wording_plural' => 'suggestions',
    ], 'de'));

    $this->assertNotNull($msg);
    $this->assertSame([
      'Erstelle deinen ersten Testbericht direkt auf der Karte.',
      'Ein paar Demo-Meldungen sind bereits vorhanden.',
      'Verwalte eingehende Meldungen im Dashboard: <a href="https://localized-wording.civicspot.io/dashboard" style="color:#2563eb; text-decoration:underline;">https://localized-wording.civicspot.io/dashboard</a>',
    ], array_slice($msg->content['body_blocks'], 0, 3));
    $this->assertContains('Try it out: create your first test report directly on the map.', $translatedSources);
    $this->assertContains('A few demo reports are already in place. Edit or delete them anytime.', $translatedSources);
    $this->assertContains('Manage incoming reports in your dashboard:', $translatedSources);
    $this->assertNotContains('Try it out: create your first test @wording_singular directly on the map.', $translatedSources);
    $this->assertNotContains('A few demo @wording_plural are already in place. Edit or delete them anytime.', $translatedSources);
    $this->assertNotContains('Manage incoming @wording_plural in your dashboard:', $translatedSources);
  }

  /**
   * Uses the legacy translation sources for an unknown wording preset.
   */
  public function testBuildUsesLegacySourcesForUnknownWordingPreset(): void {
    $translatedSources = [];
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static function (TranslatableMarkup $markup) use (&$translatedSources): string {
        $source = $markup->getUntranslatedString();
        $translatedSources[] = $source;
        return $source;
      },
    );

    $msg = $this->buildBuilder(translation: $translation)->build($this->buildContext([
      'workspace_name' => 'Unknown preset',
      'workspace_url' => 'https://unknown-preset.civicspot.io',
      'wording_preset' => 'unknown',
      'wording_singular' => 'suggestion',
      'wording_plural' => 'suggestions',
    ]));

    $this->assertNotNull($msg);
    $this->assertSame('Try it out: create your first test report directly on the map.', $msg->content['body_blocks'][0]);
    $this->assertSame('A few demo reports are already in place. Edit or delete them anytime.', $msg->content['body_blocks'][1]);
    $this->assertContains('Manage incoming reports in your dashboard:', $translatedSources);
    $this->assertNotContains('Manage incoming @wording_plural in your dashboard:', $translatedSources);
  }

  /**
   * Uses the supplied citizen-facing terms in every relevant fallback block.
   */
  public function testBuildUsesOptionalWordingTermsInFallbackBodyBlocks(): void {
    $msg = $this->buildBuilder()->build($this->buildContext([
      'workspace_name' => 'Suggestion workspace',
      'workspace_url' => 'https://suggestions.civicspot.io',
      'wording_preset' => 'suggestion',
      'wording_singular' => 'suggestion',
      'wording_plural' => 'suggestions',
    ]));

    $this->assertNotNull($msg);
    $this->assertSame('Try it out: create your first test suggestion directly on the map.', $msg->content['body_blocks'][0]);
    $this->assertSame('A few demo suggestions are already in place. Edit or delete them anytime.', $msg->content['body_blocks'][1]);
    $this->assertStringContainsString('Manage incoming suggestions in your dashboard:', $msg->content['body_blocks'][2]);
    $this->assertStringNotContainsString('reports', $msg->content['body_blocks'][2]);
  }

  /**
   * Falls back independently when one optional wording term is blank.
   */
  public function testBuildFallsBackForBlankIndividualWordingTerms(): void {
    $msg = $this->buildBuilder()->build($this->buildContext([
      'workspace_name' => 'Partial wording',
      'workspace_url' => 'https://partial-wording.civicspot.io',
      'wording_preset' => 'entry',
      'wording_singular' => 'entry',
      'wording_plural' => '   ',
    ]));

    $this->assertNotNull($msg);
    $this->assertSame('Try it out: create your first test entry directly on the map.', $msg->content['body_blocks'][0]);
    $this->assertSame('A few demo entries are already in place. Edit or delete them anytime.', $msg->content['body_blocks'][1]);
    $this->assertStringContainsString('Manage incoming entries in your dashboard:', $msg->content['body_blocks'][2]);
  }

  /**
   * Escapes supplied wording terms before the raw HTML mail renderer sees them.
   */
  public function testBuildEscapesOptionalWordingTermsInFallbackBodyBlocks(): void {
    $msg = $this->buildBuilder()->build($this->buildContext([
      'workspace_name' => 'Escaped wording',
      'workspace_url' => 'https://escaped-wording.civicspot.io',
      'wording_preset' => 'entry',
      'wording_singular' => '<strong>entry</strong>',
      'wording_plural' => '<em>entries</em>',
    ]));

    $this->assertNotNull($msg);
    $this->assertStringContainsString('&lt;strong&gt;entry&lt;/strong&gt;', $msg->content['body_blocks'][0]);
    $this->assertStringContainsString('&lt;em&gt;entries&lt;/em&gt;', $msg->content['body_blocks'][1]);
    $this->assertStringNotContainsString('<strong>entry</strong>', $msg->content['body_blocks'][0]);
    $this->assertStringNotContainsString('<em>entries</em>', $msg->content['body_blocks'][2]);
  }

  /**
   * Tests that a missing site_name param falls back to "CivicSpot".
   */
  public function testBuildUsesDefaultSiteNameWhenMissing(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'workspace_name' => 'Demo',
      'workspace_url' => 'https://demo.civicspot.io',
    ]);
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertStringContainsString('CivicSpot', $msg->subject);
  }

  /**
   * Tests that a missing config falls back to the hardcoded t() subject.
   */
  public function testBuildFallsBackToTranslationWhenConfigMissing(): void {
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolveField')->willReturn('');

    $builder = $this->buildBuilder(textResolver: $textResolver);
    $ctx = $this->buildContext([
      'workspace_name' => 'MyDemo',
      'site_name' => 'FastMap',
      'workspace_url' => 'https://mydemo.civicspot.io',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('Your FastMap workspace "MyDemo" is ready', $msg->subject);
  }

  /**
   * Tests that a configured subject template resolves @placeholders.
   */
  public function testBuildAppliesConfigSubjectTemplateViaPlaceholders(): void {
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolveField')->willReturnCallback(
      fn (string $configName, string $key, string $field, string $langcode): string => $field === 'subject'
        ? 'CUSTOM @site: @workspace_name is live'
        : '',
    );

    $builder = $this->buildBuilder(textResolver: $textResolver);
    $ctx = $this->buildContext([
      'workspace_name' => 'MyDemo',
      'site_name' => 'FastMap',
      'workspace_url' => 'https://mydemo.civicspot.io',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('CUSTOM @site: MyDemo is live', $msg->subject);
  }

  /**
   * Builds a MailContext with sensible test defaults.
   */
  private function buildContext(array $params, string $langcode = 'en'): MailContext {
    return new MailContext(
      module: 'markaspot_fastmap',
      key: 'workspace_welcome',
      langcode: $langcode,
      params: $params,
      to: 'creator@example.com',
    );
  }

  /**
   * Builds the subject with default or injected mocks.
   */
  private function buildBuilder(
    ?MailTextResolver $textResolver = NULL,
    ?LoggerInterface $logger = NULL,
    ?TranslationInterface $translation = NULL,
  ): WorkspaceWelcomeBuilder {
    if ($textResolver === NULL) {
      $textResolver = $this->createMock(MailTextResolver::class);
      $textResolver->method('resolveField')->willReturn('');
    }
    $builder = new WorkspaceWelcomeBuilder(
      $textResolver,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($translation ?? $this->getStringTranslationStub());
    return $builder;
  }

}
