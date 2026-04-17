<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\GroupOrgNotificationBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\Builder\GroupOrgNotificationBuilder
 * @group markaspot_mail
 */
final class GroupOrgNotificationBuilderTest extends UnitTestCase {

  /**
   * @covers ::getType
   */
  public function testGetTypeReturnsEcaGroup(): void {
    $this->assertSame(MailType::ECA_GROUP, $this->buildBuilder()->getType());
  }

  /**
   * @covers ::supports
   */
  public function testSupportsOnlyOrgNotification(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_group', 'org_notification'));
    $this->assertFalse($builder->supports('markaspot_group', 'member_invitation'));
    $this->assertFalse($builder->supports('other', 'org_notification'));
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
  public function testBuildProducesPlatformCardFromSubjectAndMessage(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'subject' => 'New report routed to Dept A',
      'message' => "Intro line.\n\nSecond paragraph.",
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertSame('New report routed to Dept A', $msg->subject);
    $this->assertSame('Intro line.', $msg->content['intro']);
    $this->assertSame(['Second paragraph.'], $msg->content['body_blocks']);
  }

  /**
   *
   */
  private function buildContext(array $params): MailContext {
    return new MailContext(
      module: 'markaspot_group',
      key: 'org_notification',
      langcode: 'en',
      params: $params,
      to: 'head@org.example',
    );
  }

  /**
   *
   */
  private function buildBuilder(?LoggerInterface $logger = NULL): GroupOrgNotificationBuilder {
    return new GroupOrgNotificationBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
