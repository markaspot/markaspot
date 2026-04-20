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
use Drupal\markaspot_mail\Mail\Builder\WorkspaceVerificationBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\WorkspaceVerificationBuilder::class)]
#[Group('markaspot_mail')]
final class WorkspaceVerificationBuilderTest extends UnitTestCase {

  public function testGetTypeReturnsFastmapWorkspaceVerification(): void {
    $this->assertSame(
      MailType::FASTMAP_WORKSPACE_VERIFICATION,
      $this->buildBuilder()->getType()
    );
  }

  public function testSupportsOnlyWorkspaceVerificationKey(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_fastmap', 'workspace_verification'));
    $this->assertFalse($builder->supports('markaspot_fastmap', 'demo_expiry_reminder'));
    $this->assertFalse($builder->supports('markaspot_fastmap', 'other'));
    $this->assertFalse($builder->supports('other', 'workspace_verification'));
  }

  public function testBuildReturnsNullWhenWorkspaceNameMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = $this->buildContext([
      'verify_url' => 'https://civicspot.io/verify?token=abc',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  public function testBuildReturnsNullWhenVerifyUrlMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = $this->buildContext([
      'workspace_name' => 'Amsterdam Demo',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  public function testBuildProducesPlatformMailWithCtaAndCleanupReference(): void {
    $builder = $this->buildBuilder();

    $ctx = $this->buildContext([
      'workspace_name' => 'Amsterdam Demo',
      'site_name' => 'CivicSpot',
      'verify_url' => 'https://civicspot.io/verify?token=xyz789',
      'cleanup_days' => '14',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertStringContainsString('Amsterdam Demo', $msg->subject);
    $this->assertStringContainsString('CivicSpot', $msg->subject);
    $this->assertStringContainsString('CivicSpot', $msg->content['intro']);
    $this->assertStringContainsString('Amsterdam Demo', $msg->content['intro']);
    $this->assertSame('Verify email', $msg->content['cta_label']);
    $this->assertSame('https://civicspot.io/verify?token=xyz789', $msg->content['cta_url']);
    // Cleanup window must appear in the body so recipients know the
    // deadline without hunting through the full T&C.
    $this->assertTrue(array_any($msg->content['body_blocks'], fn($b) => str_contains($b, '14')));
  }

  public function testBuildUsesDefaultSiteNameWhenMissing(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'workspace_name' => 'Demo',
      'verify_url' => 'https://civicspot.io/verify?t=1',
    ]);
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertStringContainsString('CivicSpot', $msg->subject);
  }

  public function testBuildAppliesConfigSubjectTemplateViaPlaceholders(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn (string $key): ?array => $key === 'workspace_verification'
        ? ['subject' => 'CUSTOM @site: activate @workspace_name now']
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
      'verify_url' => 'https://civicspot.io/verify?t=1',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('CUSTOM @site: activate MyDemo now', $msg->subject);
  }

  /**
   * Builds a MailContext with sensible test defaults.
   */
  private function buildContext(array $params): MailContext {
    return new MailContext(
      module: 'markaspot_fastmap',
      key: 'workspace_verification',
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
  ): WorkspaceVerificationBuilder {
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
    $builder = new WorkspaceVerificationBuilder(
      $configFactory,
      $languageManager,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

}
