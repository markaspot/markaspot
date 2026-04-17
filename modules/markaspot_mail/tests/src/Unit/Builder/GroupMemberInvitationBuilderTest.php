<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\GroupMemberInvitationBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\Builder\GroupMemberInvitationBuilder
 * @group markaspot_mail
 */
final class GroupMemberInvitationBuilderTest extends UnitTestCase {

  /**
   * @covers ::getType
   */
  public function testGetTypeReturnsEcaGroup(): void {
    $this->assertSame(MailType::ECA_GROUP, $this->buildBuilder()->getType());
  }

  /**
   * @covers ::supports
   */
  public function testSupportsOnlyMemberInvitationKey(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_group', 'member_invitation'));
    $this->assertFalse($builder->supports('markaspot_group', 'org_notification'));
    $this->assertFalse($builder->supports('markaspot_group', 'other_key'));
    $this->assertFalse($builder->supports('other_module', 'member_invitation'));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenGroupNameMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder($logger);

    $ctx = $this->buildContext([
      'claim_url' => 'https://example.com/auth/invite?token=abc',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenClaimUrlMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder($logger);

    $ctx = $this->buildContext([
      'group_name' => 'Stadt Amsterdam',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenClaimUrlIsOnlyWhitespace(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder($logger);

    $ctx = $this->buildContext([
      'group_name' => 'Stadt Amsterdam',
      'claim_url' => '   ',
    ]);
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildProducesPlatformMailMessageWithCta(): void {
    $builder = $this->buildBuilder();

    $ctx = $this->buildContext([
      'group_name' => 'Stadt Amsterdam',
      'claim_url' => 'https://example.com/auth/invite?token=xyz789',
      'site_name' => 'CivicSpot',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertStringContainsString('Stadt Amsterdam', $msg->subject);
    $this->assertStringContainsString('Stadt Amsterdam', $msg->content['intro']);
    $this->assertStringContainsString('CivicSpot', $msg->content['intro']);
    $this->assertSame('Accept invitation', $msg->content['cta_label']);
    $this->assertSame('https://example.com/auth/invite?token=xyz789', $msg->content['cta_url']);
    $this->assertCount(2, $msg->content['body_blocks']);
  }

  /**
   * @covers ::build
   */
  public function testBuildOmitsSiteNameFromIntroWhenMissing(): void {
    $builder = $this->buildBuilder();

    $ctx = $this->buildContext([
      'group_name' => 'Stadt Amsterdam',
      'claim_url' => 'https://example.com/auth/invite?token=abc',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    // Intro copy should still reference the group when site_name is absent.
    $this->assertStringContainsString('Stadt Amsterdam', $msg->content['intro']);
    // Without site_name the intro reads "invited to join @group." only.
    $this->assertStringNotContainsString(' on ', $msg->content['intro']);
  }

  /**
   * Builds a MailContext pre-populated with sensible test defaults.
   */
  private function buildContext(array $params): MailContext {
    return new MailContext(
      module: 'markaspot_group',
      key: 'member_invitation',
      langcode: 'en',
      params: $params,
      to: 'invitee@example.com',
    );
  }

  /**
   * Builds the subject with an optional custom logger.
   */
  private function buildBuilder(?LoggerInterface $logger = NULL): GroupMemberInvitationBuilder {
    $builder = new GroupMemberInvitationBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

}
