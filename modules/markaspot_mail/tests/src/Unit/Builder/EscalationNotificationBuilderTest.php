<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\markaspot_mail\Mail\MailAttachment;
use Drupal\node\NodeInterface;
use Drupal\markaspot_mail\Service\AttachmentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\EscalationNotificationBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 *
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\EscalationNotificationBuilder::class)]
#[Group('markaspot_mail')]
final class EscalationNotificationBuilderTest extends UnitTestCase {

  /**
   *
   */
  public function testGetTypeReturnsEcaEscalation(): void {
    $this->assertSame(MailType::ECA_ESCALATION, $this->buildBuilder()->getType());
  }

  /**
   *
   */
  public function testSupportsEscalationAndDelegationKeys(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_escalation', 'escalation_notification'));
    $this->assertTrue($builder->supports('markaspot_escalation', 'delegation_notification'));
    $this->assertFalse($builder->supports('markaspot_escalation', 'other_key'));
    $this->assertFalse($builder->supports('other_module', 'escalation_notification'));
  }

  /**
   *
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
   *
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
   *
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
   * Escalation recipients are always target-jurisdiction staff, so the
   * builder resolves attachments unconditionally and opts into
   * private:// streams (field_request_image ships there by default).
   */
  public function testBuildCallsResolverWithIncludePrivateOnWhenNodePresent(): void {
    $node = $this->createMock(NodeInterface::class);
    $attachment = new MailAttachment(
      filename: 'photo.jpg',
      filemime: 'image/jpeg',
      filepath: 'private://reports/photo.jpg',
    );

    $resolver = $this->createMock(AttachmentResolver::class);
    $resolver->expects($this->once())
      ->method('resolve')
      ->with(
        $this->identicalTo($node),
        ['field_request_image', 'field_attachment'],
        includePrivate: TRUE,
      )
      ->willReturn([$attachment]);

    $builder = $this->buildBuilder(attachmentResolver: $resolver);
    $ctx = $this->buildContext([
      'subject' => 'Request escalated',
      'body' => 'Please review.',
      'node' => $node,
    ]);
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertCount(1, $msg->attachments);
    $this->assertSame('photo.jpg', $msg->attachments[0]->filename);
  }

  /**
   *
   */
  public function testBuildSkipsResolverWhenNodeIsMissing(): void {
    $resolver = $this->createMock(AttachmentResolver::class);
    $resolver->expects($this->never())->method('resolve');

    $builder = $this->buildBuilder(attachmentResolver: $resolver);
    $ctx = $this->buildContext([
      'subject' => 'Delegation notice',
      'body' => 'No node attached to this escalation.',
    ]);
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertSame([], $msg->attachments);
  }

  /**
   * Builds a MailContext with sensible test defaults.
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
   * Builds the subject with mocked deps. AttachmentResolver defaults to
   * a mock that returns an empty list, matching pre-attachment-era
   * expectations; tests asserting attachment flow pass a configured mock.
   */
  private function buildBuilder(
    ?LoggerInterface $logger = NULL,
    ?AttachmentResolver $attachmentResolver = NULL,
  ): EscalationNotificationBuilder {
    // FQN on purpose: the autoformat hook strips short `use` imports
    // that only appear in ::class references, and we need this mock to
    // resolve at runtime.
    $resolver = $attachmentResolver
      ?? $this->createMock(AttachmentResolver::class);
    if ($attachmentResolver === NULL) {
      $resolver->method('resolve')->willReturn([]);
    }
    return new EscalationNotificationBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
      $resolver,
    );
  }

}
