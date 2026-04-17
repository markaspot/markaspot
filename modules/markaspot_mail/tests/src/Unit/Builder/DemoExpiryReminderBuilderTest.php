<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\DemoExpiryReminderBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\Builder\DemoExpiryReminderBuilder
 * @group markaspot_mail
 */
final class DemoExpiryReminderBuilderTest extends UnitTestCase {

  /**
   * @covers ::getType
   */
  public function testGetTypeReturnsFastmapDemoExpiry(): void {
    $this->assertSame(
      MailType::FASTMAP_DEMO_EXPIRY,
      $this->buildBuilder()->getType()
    );
  }

  /**
   * @covers ::supports
   */
  public function testSupportsOnlyDemoExpiryReminderKey(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_fastmap', 'demo_expiry_reminder'));
    $this->assertFalse($builder->supports('markaspot_fastmap', 'workspace_verification'));
    $this->assertFalse($builder->supports('other', 'demo_expiry_reminder'));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenWorkspaceNameMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = $this->buildContext([
      'expiry_date' => '2026-05-01',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenExpiryDateMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = $this->buildContext([
      'workspace_name' => 'Demo',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToCivicspotUrlWhenSlugOrBaseMissing(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'workspace_name' => 'Demo',
      'expiry_date' => '2026-05-01',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('https://civicspot.io', $msg->content['cta_url']);
    $this->assertSame('Create a permanent workspace', $msg->content['cta_label']);
    // Features block always shows workspace + expiry so the user can
    // confirm which workspace the warning is about.
    $this->assertNotEmpty($msg->content['features_block']);
  }

  /**
   * @covers ::build
   */
  public function testBuildBuildsWorkspaceUrlFromPlainBaseAndSlug(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['workspace_base_url', 'https://civicspot.io/'],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $builder = $this->buildBuilder(configFactory: $configFactory);
    $ctx = $this->buildContext([
      'workspace_name' => 'Demo',
      'expiry_date' => '2026-05-01',
      'workspace_slug' => 'acme',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('https://civicspot.io/acme', $msg->content['cta_url']);
    $this->assertSame('Open my workspace', $msg->content['cta_label']);
  }

  /**
   * @covers ::build
   */
  public function testBuildBuildsWorkspaceUrlFromTemplateWithSlugPlaceholder(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['workspace_base_url', 'https://{slug}.civicspot.io'],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $builder = $this->buildBuilder(configFactory: $configFactory);
    $ctx = $this->buildContext([
      'workspace_name' => 'Demo',
      'expiry_date' => '2026-05-01',
      'workspace_slug' => 'acme',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('https://acme.civicspot.io', $msg->content['cta_url']);
  }

  /**
   * @covers ::build
   *
   * Path-traversal / fragment-smuggling regression guard. Slugs flow from
   * workflow code into the CTA URL; a crafted "../../admin" or
   * "acme?redir=attacker" must be stripped before URL assembly even though
   * the renderer's isSafeUrl() would accept them structurally.
   */
  public function testBuildSanitizesSlugBeforeUrlAssembly(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['workspace_base_url', 'https://civicspot.io/'],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $builder = $this->buildBuilder(configFactory: $configFactory);
    foreach ([
      '../../admin' => 'https://civicspot.io/admin',
      'acme?redir=attacker' => 'https://civicspot.io/acmerediraattacker',
      'ACME/foo' => 'https://civicspot.io/acmefoo',
      'fine-slug' => 'https://civicspot.io/fine-slug',
    ] as $input => $expected) {
      $ctx = $this->buildContext([
        'workspace_name' => 'Demo',
        'expiry_date' => '2026-05-01',
        'workspace_slug' => $input,
      ]);
      $msg = $builder->build($ctx);
      $this->assertNotNull($msg);
      $this->assertStringNotContainsString('..', $msg->content['cta_url']);
      $this->assertStringNotContainsString('?', $msg->content['cta_url']);
      $this->assertStringNotContainsString('#', $msg->content['cta_url']);
    }
  }

  /**
   * @covers ::build
   */
  public function testBuildSubjectAndHeadlineReferenceWorkspaceAndDate(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'workspace_name' => 'acme-demo',
      'expiry_date' => '2026-05-15',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertStringContainsString('acme-demo', $msg->subject);
    $this->assertStringContainsString('2026-05-15', $msg->content['headline']);
    $this->assertStringContainsString('acme-demo', $msg->content['intro']);
    $this->assertStringContainsString('2026-05-15', $msg->content['intro']);
  }

  /**
   * Builds a MailContext with sensible test defaults.
   */
  private function buildContext(array $params): MailContext {
    return new MailContext(
      module: 'markaspot_fastmap',
      key: 'demo_expiry_reminder',
      langcode: 'en',
      params: $params,
      to: 'owner@example.com',
    );
  }

  /**
   * Builds the subject with optional dep injection.
   */
  private function buildBuilder(
    ?ConfigFactoryInterface $configFactory = NULL,
    ?LoggerInterface $logger = NULL,
  ): DemoExpiryReminderBuilder {
    if ($configFactory === NULL) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturn(NULL);
      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('get')->willReturn($config);
    }
    $builder = new DemoExpiryReminderBuilder(
      $configFactory,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

}
