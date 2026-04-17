<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\EcaActionEmailBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\Builder\EcaActionEmailBuilder
 * @group markaspot_mail
 */
final class EcaActionEmailBuilderTest extends UnitTestCase {

  /**
   * @covers ::getType
   */
  public function testGetTypeReturnsEcaAction(): void {
    $this->assertSame(MailType::ECA_ACTION, $this->buildBuilder()->getType());
  }

  /**
   * @covers ::supports
   */
  public function testSupportsOnlySystemActionSendEmail(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('system', 'action_send_email'));
    $this->assertFalse($builder->supports('system', 'mail'));
    $this->assertFalse($builder->supports('system', 'password_reset'));
    $this->assertFalse($builder->supports('markaspot_feedback', 'feedback_request'));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenContextIsMissing(): void {
    $builder = $this->buildBuilder();
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: [],
      to: 'user@example.com',
      subject: 'Some subject',
      body: ['Some body'],
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenContextSubjectIsEmpty(): void {
    $builder = $this->buildBuilder();
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => ['subject' => '', 'message' => 'hi']],
      to: 'user@example.com',
      subject: 'Some subject',
      body: ['Some body'],
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsNullWhenFinalSubjectIsEmpty(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('notice');
    $builder = $this->buildBuilder($logger);
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => ['subject' => 'template', 'message' => 'hi']],
      to: 'user@example.com',
      subject: '',
      body: ['body'],
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * @covers ::build
   */
  public function testBuildPreservesSubjectAndSplitsBodyIntoParagraphs(): void {
    $builder = $this->buildBuilder();
    $body = "Dear citizen,\n\nThank you for your report. We will review it shortly.\n\nBest regards";

    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => ['subject' => 'tokenized-template', 'message' => 'tokenized-template']],
      to: 'citizen@example.com',
      subject: 'Your report was received',
      body: [$body],
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('Your report was received', $msg->subject);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertSame('Dear citizen,', $msg->content['intro']);
    $this->assertCount(2, $msg->content['body_blocks']);
    $this->assertStringStartsWith('Thank you', $msg->content['body_blocks'][0]);
    $this->assertSame('Best regards', $msg->content['body_blocks'][1]);
    $this->assertArrayHasKey('preheader', $msg->content);
  }

  /**
   * @covers ::build
   */
  public function testBuildResolvesJurisdictionModeFromNodeContext(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn(5);

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->willReturn($jurisdictionField);

    $builder = $this->buildBuilder();
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => [
        'subject' => 'tok',
        'message' => 'tok',
        'node' => $node,
      ]],
      to: 'citizen@example.com',
      subject: 'Status updated',
      body: ['Your report status changed.'],
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(5, $msg->jurisdictionId);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToPlatformWhenNodeHasNoJurisdiction(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $builder = $this->buildBuilder();
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => [
        'subject' => 'tok',
        'message' => 'tok',
        'node' => $node,
      ]],
      to: 'citizen@example.com',
      subject: 'Notice',
      body: ['Some notice body.'],
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
  }

  /**
   * Builds the subject with mocked deps.
   */
  private function buildBuilder(?LoggerInterface $logger = NULL): EcaActionEmailBuilder {
    return new EcaActionEmailBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
