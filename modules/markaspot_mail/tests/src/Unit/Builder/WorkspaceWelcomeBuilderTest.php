<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\WorkspaceWelcomeBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
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
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $override = $this->createMock(LanguageConfigOverride::class);
    $override->method('get')->willReturn(NULL);
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->method('getLanguageConfigOverride')->willReturn($override);

    $builder = $this->buildBuilder(
      configFactory: $configFactory,
      languageManager: $languageManager,
    );
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
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn (string $key): ?array => $key === 'workspace_welcome'
        ? ['subject' => 'CUSTOM @site: @workspace_name is live']
        : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $override = $this->createMock(LanguageConfigOverride::class);
    $override->method('get')->willReturn(NULL);
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->method('getLanguageConfigOverride')->willReturn($override);

    $builder = $this->buildBuilder(
      configFactory: $configFactory,
      languageManager: $languageManager,
    );
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
  private function buildContext(array $params): MailContext {
    return new MailContext(
      module: 'markaspot_fastmap',
      key: 'workspace_welcome',
      langcode: 'en',
      params: $params,
      to: 'creator@example.com',
    );
  }

  /**
   * Builds the subject with default or injected mocks.
   */
  private function buildBuilder(
    ?ConfigFactoryInterface $configFactory = NULL,
    ?ConfigurableLanguageManagerInterface $languageManager = NULL,
    ?LoggerInterface $logger = NULL,
  ): WorkspaceWelcomeBuilder {
    if ($configFactory === NULL) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturn(NULL);
      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('get')->willReturn($config);
    }
    if ($languageManager === NULL) {
      $override = $this->createMock(LanguageConfigOverride::class);
      $override->method('get')->willReturn(NULL);
      $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
      $languageManager->method('getLanguageConfigOverride')->willReturn($override);
    }
    $builder = new WorkspaceWelcomeBuilder(
      $configFactory,
      $languageManager,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

}
