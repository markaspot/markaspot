<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\EscalationNotificationBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\Builder\EscalationNotificationBuilder
 * @group markaspot_mail
 */
final class EscalationNotificationBuilderTest extends UnitTestCase {

  /**
   * @covers ::getType
   */
  public function testGetTypeReturnsEcaEscalation(): void {
    $this->assertSame(MailType::ECA_ESCALATION, $this->buildBuilder()->getType());
  }

  /**
   * @covers ::supports
   */
  public function testSupportsEscalationAndDelegationKeys(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_escalation', 'escalation_notification'));
    $this->assertTrue($builder->supports('markaspot_escalation', 'delegation_notification'));
    $this->assertFalse($builder->supports('markaspot_escalation', 'other_key'));
    $this->assertFalse($builder->supports('other_module', 'escalation_notification'));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullOnMissingSubjectOrBody(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))->method('warning');
    $builder = $this->buildBuilder($logger);

    $ctx = $this->buildContext(['body' => 'x']);
    $this->assertNull($builder->build($ctx));
    $ctx = $this->buildContext(['subject' => 'x']);
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildResolvesJurisdictionFromTargetJurisdictionParam(): void {
    $jur = $this->createMock(GroupInterface::class);
    $jur->method('getEntityTypeId')->willReturn('group');
    $jur->method('bundle')->willReturn('jur');
    $jur->method('id')->willReturn(42);

    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'subject' => 'Request #12 escalated',
      'body' => "Intro paragraph.\n\nBody paragraph.",
      'jurisdiction' => $jur,
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(42, $msg->jurisdictionId);
    $this->assertSame('Request #12 escalated', $msg->subject);
    $this->assertSame('Request #12 escalated', $msg->content['headline']);
    $this->assertSame('Intro paragraph.', $msg->content['intro']);
    $this->assertSame(['Body paragraph.'], $msg->content['body_blocks']);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToPlatformModeWhenNoJurisdictionResolves(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'subject' => 'Delegation notice',
      'body' => 'Single paragraph.',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
  }

  /**
   *
   */
  private function buildContext(array $params): MailContext {
    return new MailContext(
      module: 'markaspot_escalation',
      key: 'escalation_notification',
      langcode: 'en',
      params: $params,
      to: 'admin@example.com',
    );
  }

  /**
   *
   */
  private function buildBuilder(?LoggerInterface $logger = NULL): EscalationNotificationBuilder {
    return new EscalationNotificationBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
