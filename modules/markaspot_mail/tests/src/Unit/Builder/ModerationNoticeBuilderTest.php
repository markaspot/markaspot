<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\ModerationNoticeBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\Builder\ModerationNoticeBuilder
 * @group markaspot_mail
 */
final class ModerationNoticeBuilderTest extends UnitTestCase {

  /**
   * @covers ::getType
   */
  public function testGetTypeReturnsEcaModeration(): void {
    $this->assertSame(MailType::ECA_MODERATION, $this->buildBuilder()->getType());
  }

  /**
   * @covers ::supports
   */
  public function testSupportsFlagThresholdAndFlagImmediate(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_moderation', 'flag_threshold'));
    $this->assertTrue($builder->supports('markaspot_moderation', 'flag_immediate'));
    $this->assertFalse($builder->supports('markaspot_moderation', 'other'));
    $this->assertFalse($builder->supports('other', 'flag_threshold'));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullOnMissingParams(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder($logger);
    $this->assertNull($builder->build($this->buildContext(['subject' => 'x'])));
  }

  /**
   * @covers ::build
   */
  public function testBuildProducesPlatformCardFromSubjectAndBody(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'subject' => 'Report flagged',
      'body' => "Under DSA Article 16, your report was flagged.\n\nReason: policy violation.",
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('platform', $msg->mode);
    $this->assertSame('Report flagged', $msg->subject);
    $this->assertSame('Report flagged', $msg->content['headline']);
    $this->assertStringStartsWith('Under DSA', $msg->content['intro']);
    $this->assertCount(1, $msg->content['body_blocks']);
  }

  /**
   *
   */
  private function buildContext(array $params): MailContext {
    return new MailContext(
      module: 'markaspot_moderation',
      key: 'flag_threshold',
      langcode: 'en',
      params: $params,
      to: 'admin@example.com',
    );
  }

  /**
   *
   */
  private function buildBuilder(?LoggerInterface $logger = NULL): ModerationNoticeBuilder {
    return new ModerationNoticeBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
