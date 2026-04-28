<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\markaspot_mail\Mail\MailAttachment;
use Drupal\node\NodeInterface;
use Drupal\markaspot_mail\Service\AttachmentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\ModerationNoticeBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 *
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\ModerationNoticeBuilder::class)]
#[Group('markaspot_mail')]
final class ModerationNoticeBuilderTest extends UnitTestCase {

  /**
   *
   */
  public function testGetTypeReturnsEcaModeration(): void {
    $this->assertSame(MailType::ECA_MODERATION, $this->buildBuilder()->getType());
  }

  /**
   *
   */
  public function testSupportsFlagThresholdAndFlagImmediate(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_moderation', 'flag_threshold'));
    $this->assertTrue($builder->supports('markaspot_moderation', 'flag_immediate'));
    $this->assertFalse($builder->supports('markaspot_moderation', 'other'));
    $this->assertFalse($builder->supports('other', 'flag_threshold'));
  }

  /**
   *
   */
  public function testBuildReturnsNullOnMissingParams(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder($logger);
    $this->assertNull($builder->build($this->buildContext(['subject' => 'x'])));
  }

  /**
   *
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
    $this->assertStringStartsWith('Under DSA', (string) $msg->content['intro']);
    $this->assertCount(1, $msg->content['body_blocks']);
  }

  /**
   * Builds a MailContext with sensible test defaults.
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
   * Moderation recipients are moderator-role users with dashboard
   * access — the builder attaches citizen uploads unconditionally
   * with includePrivate: TRUE so field_request_image (private://) is
   * reachable as a mail attachment.
   */
  public function testBuildCallsResolverWithIncludePrivateOnWhenNodePresent(): void {
    $node = $this->createMock(NodeInterface::class);
    $attachment = new MailAttachment(
      filename: 'flag-evidence.jpg',
      filemime: 'image/jpeg',
      filepath: 'private://reports/flag-evidence.jpg',
    );

    $resolver = $this->createMock(AttachmentResolver::class);
    $resolver->expects($this->once())
      ->method('resolve')
      ->with(
        $this->identicalTo($node),
        ['field_request_image', 'field_request_media', 'field_attachment'],
        includePrivate: TRUE,
      )
      ->willReturn([$attachment]);

    $builder = $this->buildBuilder(attachmentResolver: $resolver);
    $ctx = $this->buildContext([
      'subject' => 'Flag threshold reached',
      'body' => 'Review required.',
      'node' => $node,
    ]);
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertCount(1, $msg->attachments);
  }

  /**
   *
   */
  public function testBuildSkipsResolverWhenNodeIsMissing(): void {
    $resolver = $this->createMock(AttachmentResolver::class);
    $resolver->expects($this->never())->method('resolve');

    $builder = $this->buildBuilder(attachmentResolver: $resolver);
    $ctx = $this->buildContext([
      'subject' => 'Moderation action',
      'body' => 'No node associated.',
    ]);
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertSame([], $msg->attachments);
  }

  /**
   * Builds the subject with mocked deps. AttachmentResolver defaults to
   * a mock that returns an empty list, matching pre-attachment-era
   * expectations; tests asserting attachment flow pass a configured mock.
   */
  private function buildBuilder(
    ?LoggerInterface $logger = NULL,
    ?AttachmentResolver $attachmentResolver = NULL,
  ): ModerationNoticeBuilder {
    // FQN on purpose: the autoformat hook strips short `use` imports
    // that only appear in ::class references, and we need this mock to
    // resolve at runtime.
    $resolver = $attachmentResolver
      ?? $this->createMock(AttachmentResolver::class);
    if ($attachmentResolver === NULL) {
      $resolver->method('resolve')->willReturn([]);
    }
    return new ModerationNoticeBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
      $resolver,
    );
  }

}
