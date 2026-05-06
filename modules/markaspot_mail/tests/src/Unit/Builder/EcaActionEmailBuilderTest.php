<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\MockObject\MockObject;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\markaspot_mail\Mail\MailAttachment;
use Drupal\Core\Field\FieldItemListInterface;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use Drupal\markaspot_mail\Service\AttachmentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\EcaActionEmailBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 *
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\EcaActionEmailBuilder::class)]
#[Group('markaspot_mail')]
final class EcaActionEmailBuilderTest extends UnitTestCase {

  /**
   *
   */
  public function testGetTypeReturnsEcaAction(): void {
    $this->assertSame(MailType::ECA_ACTION, $this->buildBuilder()->getType());
  }

  /**
   *
   */
  public function testSupportsOnlySystemActionSendEmail(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('system', 'action_send_email'));
    $this->assertFalse($builder->supports('system', 'mail'));
    $this->assertFalse($builder->supports('system', 'password_reset'));
    $this->assertFalse($builder->supports('markaspot_feedback', 'feedback_request'));
  }

  /**
   *
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
   *
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
   *
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
   *
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
    $this->assertSame('Dear citizen,', (string) $msg->content['intro']);
    $this->assertCount(2, $msg->content['body_blocks']);
    $this->assertStringStartsWith('Thank you', (string) $msg->content['body_blocks'][0]);
    $this->assertSame('Best regards', (string) $msg->content['body_blocks'][1]);
    $this->assertArrayHasKey('preheader', $msg->content);
  }

  /**
   *
   */
  public function testBuildPreservesSingleLineBreaksInsideParagraphs(): void {
    $builder = $this->buildBuilder();
    $body = "Address:\nOstwall 175\n47798 Krefeld\n\nFooter:\nLine one\nLine two";

    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => ['subject' => 'tokenized-template', 'message' => 'tokenized-template']],
      to: 'staff@example.com',
      subject: 'Forwarded report',
      body: [$body],
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('Address:<br>Ostwall 175<br>47798 Krefeld', (string) $msg->content['intro']);
    $this->assertSame('Footer:<br>Line one<br>Line two', (string) $msg->content['body_blocks'][0]);
  }

  /**
   *
   */
  public function testBuildLeavesStructuredHtmlBlocksUntouched(): void {
    $builder = $this->buildBuilder();
    $body = "Intro\nline\n\n<ul>\n<li>One</li>\n<li>Two</li>\n</ul>\n\nLine one<br>\nLine two";

    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => ['subject' => 'tokenized-template', 'message' => 'tokenized-template']],
      to: 'staff@example.com',
      subject: 'Forwarded report',
      body: [$body],
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('Intro<br>line', (string) $msg->content['intro']);
    $this->assertStringContainsString("<ul>\n<li>One</li>\n<li>Two</li>\n</ul>", (string) $msg->content['body_blocks'][0]);
    $this->assertStringNotContainsString('<ul><br>', (string) $msg->content['body_blocks'][0]);
    $this->assertSame("Line one<br>\nLine two", (string) $msg->content['body_blocks'][1]);
    $this->assertStringNotContainsString('<br><br>', (string) $msg->content['body_blocks'][1]);
  }

  /**
   *
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
   *
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
   * Adversarial recipient cases from the round-3 security review.
   *
   * The reporter-gate decides whether citizen uploads get attached to
   * an ECA-driven mail. A naive first-match regex on angle-brackets
   * is bypassable via `"Max <spoof@evil.com>" <real@example.com>` —
   * the real address is always the trailing angle-addr, and the display
   * name can contain attacker-controlled content. These cases pin each
   * of the six patterns from the review against the current regex so
   * a future tightening/loosening can't silently kill the gate.
   */
  public function testRecipientReporterCanonicalDisplayName(): void {
    // Case 1: "Max Mustermann" <reporter@example.com> — match.
    $this->assertResolverCalled(
      $this->never(),
      to: '"Max Mustermann" <reporter@example.com>',
      reporter: 'reporter@example.com',
    );
  }

  /**
   *
   */
  public function testRecipientSpoofedAngleInDisplayNameDoesNotMatchSpoof(): void {
    // Case 2: attacker-injected angle-addr in display-name, legit
    // reporter at the end. Greedy first-match regex would have grabbed
    // spoof@evil.com and compared against reporter — which happens to
    // NOT match, so attachments would go out. After the fix the
    // anchored regex grabs reporter@example.com correctly → match.
    $this->assertResolverCalled(
      $this->never(),
      to: '"Max <spoof@evil.com>" <reporter@example.com>',
      reporter: 'reporter@example.com',
    );
  }

  /**
   *
   */
  public function testRecipientReporterSpoofedInDisplayNameDoesNotFalseMatch(): void {
    // Case 3: reporter-lookalike in display-name, actual recipient is
    // attacker. Greedy regex would grab reporter@example.com from the
    // display-name and wrongly classify this as "reporter" → skip
    // attachments → attacker loses the data, but the attacker is
    // actually staff and has a right to them. After fix: anchored
    // regex grabs attacker@evil.com → no match → resolve is called.
    $this->assertResolverCalled(
      $this->once(),
      to: '"Evil <reporter@example.com>" <attacker@evil.com>',
      reporter: 'reporter@example.com',
    );
  }

  /**
   *
   */
  public function testRecipientAngleAddressWithoutDisplayName(): void {
    // Case 4: <reporter@example.com>.
    $this->assertResolverCalled(
      $this->never(),
      to: '<reporter@example.com>',
      reporter: 'reporter@example.com',
    );
  }

  /**
   *
   */
  public function testRecipientCommaSeparatedListContainingReporter(): void {
    // Case 5: comma-separated, reporter in the list.
    $this->assertResolverCalled(
      $this->never(),
      to: 'reporter@example.com,attacker@evil.com',
      reporter: 'reporter@example.com',
    );
  }

  /**
   *
   */
  public function testRecipientNestedAngleBracketsInQuotedString(): void {
    // Case 6: nested angle-brackets in quoted string. The character
    // class [^<>]+ refuses to span inner angle brackets, so the
    // trailing angle-addr wins.
    $this->assertResolverCalled(
      $this->never(),
      to: '"Weird <nested>" <reporter@example.com>',
      reporter: 'reporter@example.com',
    );
  }

  /**
   *
   */
  public function testStaffRecipientTriggersAttachmentResolve(): void {
    // Positive control: a plain staff address (no reporter match)
    // triggers resolve() with includePrivate: true.
    $this->assertResolverCalled(
      $this->once(),
      to: 'staff@agency.gov',
      reporter: 'reporter@example.com',
    );
  }

  /**
   *
   */
  public function testNonServiceRequestBundleDoesNotTriggerResolve(): void {
    // Bundle-gate: ECA workflows against other bundles (article,
    // custom content types) must not trigger attachment resolution
    // even if the recipient is a staff address, because the field
    // names (field_request_image / field_attachment) are
    // service_request-specific and would silently miss.
    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('bundle')->willReturn('article');
    $node->method('hasField')->willReturn(FALSE);

    $resolver = $this->createMock(AttachmentResolver::class);
    $resolver->expects($this->never())->method('resolve');

    $builder = $this->buildBuilder(attachmentResolver: $resolver);
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => [
        'subject' => 'x',
        'message' => 'y',
        'node' => $node,
      ]],
      to: 'staff@agency.gov',
      subject: 'Notification',
      body: ['Please review.'],
    );
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertSame([], $msg->attachments);
  }

  /**
   *
   */
  public function testEmptyFieldEmailFallsThroughToAttach(): void {
    // field_e_mail empty (anonymous report): check returns FALSE, so
    // the caller treats the recipient as staff and attaches. This is
    // the defined semantic — anonymous citizens can't receive mail,
    // so any mail in this path is going to staff anyway.
    $emailField = $this->createMock(FieldItemListInterface::class);
    $emailField->method('isEmpty')->willReturn(TRUE);

    $jurField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurField->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')->willReturnCallback(
      static fn(string $name): bool => in_array($name, ['field_e_mail', 'field_jurisdiction'], TRUE),
    );
    $node->method('get')->willReturnCallback(function (string $name) use ($emailField, $jurField) {
      return match ($name) {
        'field_e_mail' => $emailField,
        'field_jurisdiction' => $jurField,
      };
    });

    $resolver = $this->createMock(AttachmentResolver::class);
    $resolver->expects($this->once())
      ->method('resolve')
      ->willReturn([]);

    $builder = $this->buildBuilder(attachmentResolver: $resolver);
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => [
        'subject' => 'x',
        'message' => 'y',
        'node' => $node,
      ]],
      to: 'staff@agency.gov',
      subject: 'Notification',
      body: ['Please review.'],
    );
    $this->assertNotNull($builder->build($ctx));
  }

  /**
   *
   */
  public function testAttachmentsFlowFromResolverToMailMessage(): void {
    // End-to-end check: if resolver produces attachments, they arrive
    // on MailMessage in the same order.
    $attachment = new MailAttachment(
      filename: 'evidence.jpg',
      filemime: 'image/jpeg',
      filepath: 'private://reports/evidence.jpg',
    );
    $resolver = $this->createMock(AttachmentResolver::class);
    $resolver->expects($this->once())
      ->method('resolve')
      ->with(
        $this->isInstanceOf(ContentEntityInterface::class),
        ['field_request_image', 'field_request_media', 'field_attachment'],
        includePrivate: TRUE,
      )
      ->willReturn([$attachment]);

    $node = $this->buildServiceRequestNode('reporter@example.com');
    $builder = $this->buildBuilder(attachmentResolver: $resolver);
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => [
        'subject' => 'x',
        'message' => 'y',
        'node' => $node,
      ]],
      to: 'staff@agency.gov',
      subject: 'Escalation',
      body: ['See attached.'],
    );
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertCount(1, $msg->attachments);
    $this->assertSame('evidence.jpg', $msg->attachments[0]->filename);
  }

  /**
   * Shared assertion for the six adversarial recipient cases.
   *
   * Runs build() against a minimal service_request-bundled node with
   * a configured reporter email, and asserts the resolver was called
   * (or not) according to the passed invocation matcher. Using the
   * mock's expects() to drive the assertion avoids introspecting the
   * MailMessage::$attachments, which would be identical ([]) in both
   * the "reporter matched, skipped" and "resolver returned []" cases.
   */
  private function assertResolverCalled(
    InvocationOrder $expected,
    string $to,
    string $reporter,
  ): void {
    $resolver = $this->createMock(AttachmentResolver::class);
    $resolver->expects($expected)
      ->method('resolve')
      ->willReturn([]);

    $node = $this->buildServiceRequestNode($reporter);
    $builder = $this->buildBuilder(attachmentResolver: $resolver);
    $ctx = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => [
        'subject' => 'x',
        'message' => 'y',
        'node' => $node,
      ]],
      to: $to,
      subject: 'Some subject',
      body: ['Some body.'],
    );
    $builder->build($ctx);
  }

  /**
   * Helper: minimal service_request node with field_e_mail and an empty
   * field_jurisdiction, bundle-gated so EcaActionEmailBuilder's
   * bundle-check passes.
   */
  private function buildServiceRequestNode(string $reporterEmail): NodeInterface&MockObject {
    $emailField = $this->createMock(FieldItemListInterface::class);
    $emailField->method('isEmpty')->willReturn($reporterEmail === '');
    $emailField->method('getString')->willReturn($reporterEmail);

    $jurField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurField->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')->willReturnCallback(
      static fn(string $name): bool => in_array($name, ['field_e_mail', 'field_jurisdiction'], TRUE),
    );
    $node->method('get')->willReturnCallback(function (string $name) use ($emailField, $jurField) {
      return match ($name) {
        'field_e_mail' => $emailField,
        'field_jurisdiction' => $jurField,
      };
    });
    return $node;
  }

  /**
   * Builds the subject with mocked deps. AttachmentResolver defaults to
   * a mock that returns an empty list, matching pre-attachment-era
   * expectations; tests asserting attachment flow pass a configured mock.
   */
  private function buildBuilder(
    ?LoggerInterface $logger = NULL,
    ?AttachmentResolver $attachmentResolver = NULL,
  ): EcaActionEmailBuilder {
    // FQN on purpose: the autoformat hook strips short `use` imports
    // that only appear in ::class references, and we need this mock to
    // resolve at runtime.
    $resolver = $attachmentResolver
      ?? $this->createMock(AttachmentResolver::class);
    if ($attachmentResolver === NULL) {
      $resolver->method('resolve')->willReturn([]);
    }
    return new EcaActionEmailBuilder(
      $logger ?? $this->createMock(LoggerInterface::class),
      $resolver,
    );
  }

}
