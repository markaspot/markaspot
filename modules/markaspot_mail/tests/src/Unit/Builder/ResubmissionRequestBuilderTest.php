<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Utility\Token;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\ResubmissionRequestBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\Builder\ResubmissionRequestBuilder
 * @group markaspot_mail
 */
final class ResubmissionRequestBuilderTest extends UnitTestCase {

  /**
   * @covers ::getType
   */
  public function testGetTypeReturnsEcaResubmission(): void {
    $this->assertSame(MailType::ECA_RESUBMISSION, $this->buildBuilder()->getType());
  }

  /**
   * @covers ::supports
   */
  public function testSupportsOnlyResubmitRequest(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_resubmission', 'resubmit_request'));
    $this->assertFalse($builder->supports('markaspot_resubmission', 'other'));
    $this->assertFalse($builder->supports('other', 'resubmit_request'));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenNodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: [],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToHardcodedCopyWhenConfigMissing(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $builder = $this->buildBuilder();
    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('platform', $msg->mode);
    $this->assertStringContainsString('needs more information', $msg->subject);
    $this->assertSame('Please clarify your report', $msg->content['headline']);
  }

  /**
   * @covers ::build
   */
  public function testBuildAppliesConfigTemplateViaTokenReplace(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn (string $key): ?array => $key === 'resubmit_request'
        ? [
          'subject' => 'Please clarify [node:title]',
          'body' => "Hello,\n\nPlease clarify the report [node:request_id].",
        ]
        : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $override = $this->createMock(LanguageConfigOverride::class);
    $override->method('get')->willReturn(NULL);
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->method('getLanguageConfigOverride')->willReturn($override);

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(
      fn (string $tmpl): string => str_replace(
        ['[node:title]', '[node:request_id]'],
        ['Pothole on Main St', '42-2026'],
        $tmpl,
      ),
    );

    $builder = $this->buildBuilder(
      configFactory: $configFactory,
      languageManager: $languageManager,
      token: $token,
    );
    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('Please clarify Pothole on Main St', $msg->subject);
    $this->assertSame('Hello,', $msg->content['intro']);
    $this->assertSame(['Please clarify the report 42-2026.'], $msg->content['body_blocks']);
  }

  /**
   * Builds the subject with optional dep injection.
   */
  private function buildBuilder(
    ?ConfigFactoryInterface $configFactory = NULL,
    ?ConfigurableLanguageManagerInterface $languageManager = NULL,
    ?Token $token = NULL,
    ?LoggerInterface $logger = NULL,
  ): ResubmissionRequestBuilder {
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
    if ($token === NULL) {
      $token = $this->createMock(Token::class);
      $token->method('replace')->willReturnCallback(fn (string $t): string => $t);
    }
    $builder = new ResubmissionRequestBuilder(
      $configFactory,
      $languageManager,
      $token,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

}
