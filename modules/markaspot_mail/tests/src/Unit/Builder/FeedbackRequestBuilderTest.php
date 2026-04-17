<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\Core\Utility\Token;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Markup;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\FeedbackRequestBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\Builder\FeedbackRequestBuilder
 * @group markaspot_mail
 */
final class FeedbackRequestBuilderTest extends UnitTestCase {

  /**
   * @covers ::getType
   */
  public function testGetTypeReturnsEcaFeedback(): void {
    $builder = $this->buildBuilder();
    $this->assertSame(MailType::ECA_FEEDBACK, $builder->getType());
  }

  /**
   * @covers ::supports
   */
  public function testSupportsOnlyFeedbackRequestKey(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_feedback', 'feedback_request'));
    $this->assertFalse($builder->supports('markaspot_feedback', 'other_key'));
    $this->assertFalse($builder->supports('other_module', 'feedback_request'));
    $this->assertFalse($builder->supports('markaspot_escalation', 'escalation_notice'));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenNodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(NULL, $logger);

    $ctx = new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'en',
      params: [],
      to: 'user@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenParamIsNotNode(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(NULL, $logger);

    $ctx = new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'en',
      params: ['node' => 'not-a-node'],
      to: 'user@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildProducesJurisdictionScopedMailMessage(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn(7);

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
      ['request_id', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
      ['request_id', $this->singleValueField('2026-0042')],
    ]);
    $node->method('label')->willReturn('Broken streetlight');
    $node->method('uuid')->willReturn('abc-123-uuid');

    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'mode' => 'jurisdiction',
      'frontend_base_url' => 'https://utrecht.civicspot.io',
      'jurisdiction_slug' => 'utrecht',
      'email_footer_html' => Markup::create(''),
    ]);

    $builder = $this->buildBuilder($branding);

    $ctx = new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'user@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(7, $msg->jurisdictionId);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertStringContainsString('2026-0042', $msg->subject);
    $this->assertSame(
      'https://utrecht.civicspot.io/utrecht/feedback/abc-123-uuid',
      $msg->content['cta_url']
    );
    $this->assertStringContainsString('Broken streetlight', $msg->content['intro']);
    $this->assertSame('Give feedback', $msg->content['cta_label']);
    $this->assertArrayHasKey('features_block', $msg->content);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToPlatformModeWhenJurisdictionMissing(): void {
    $emptyField = $this->createMock(FieldItemListInterface::class);
    $emptyField->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
      ['request_id', FALSE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $emptyField],
    ]);
    $node->method('label')->willReturn('Pothole');
    $node->method('id')->willReturn(101);
    $node->method('uuid')->willReturn('xyz-999-uuid');

    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'mode' => 'platform',
      'frontend_base_url' => 'https://mark-a-spot.com',
      'jurisdiction_slug' => NULL,
      'email_footer_html' => Markup::create(''),
    ]);

    $builder = $this->buildBuilder($branding);

    $ctx = new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'user@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertStringContainsString('101', $msg->subject);
    $this->assertSame(
      'https://mark-a-spot.com/feedback/xyz-999-uuid',
      $msg->content['cta_url']
    );
  }

  /**
   * Builds the subject with mocked deps + string-translation stub.
   *
   * ConfigFactory + LanguageManager are stubbed to return empty config so
   * the builder falls back to the hardcoded t() subject template. Token
   * service is stubbed to a no-op replace() that returns its input. Tests
   * that want the config-driven path assert against that explicitly.
   */
  private function buildBuilder(?MailBrandingService $branding = NULL, ?LoggerInterface $logger = NULL): FeedbackRequestBuilder {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $immutableConfig = $this->createMock(ImmutableConfig::class);
    $immutableConfig->method('get')->willReturn(NULL);
    $configFactory->method('get')->willReturn($immutableConfig);

    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $override = $this->createMock(LanguageConfigOverride::class);
    $override->method('get')->willReturn(NULL);
    $languageManager->method('getLanguageConfigOverride')->willReturn($override);

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(
      fn (string $template): string => $template,
    );

    $builder = new FeedbackRequestBuilder(
      $branding ?? $this->createMock(MailBrandingService::class),
      $configFactory,
      $languageManager,
      $token,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

  /**
   * Builds a single-value FieldItemListInterface stub via getString().
   *
   * We intentionally avoid the magic ->value accessor; the builder uses
   * getString() so the mock stays typed and works under PHP 8.2+
   * dynamic-property deprecations.
   */
  private function singleValueField(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('getString')->willReturn($value);
    return $field;
  }

}
