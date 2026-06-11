<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\markaspot_mail_inbound\Exception\MailParseException;
use Drupal\markaspot_mail_inbound\Service\MailParserService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests parsing raw .eml fixtures into InboundMessage DTOs.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\MailParserService
 */
class MailParserServiceTest extends UnitTestCase {

  /**
   * The parser under test.
   */
  protected MailParserService $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = new MailParserService();
  }

  /**
   * Loads a fixture file.
   */
  protected function fixture(string $name): string {
    $path = __DIR__ . '/../../fixtures/' . $name;
    $this->assertFileExists($path);
    return (string) file_get_contents($path);
  }

  /**
   * @covers ::parse
   */
  public function testPlainTextMessage(): void {
    $message = $this->parser->parse($this->fixture('plain-text.eml'));

    $this->assertSame('jan.devries@example.org', $message->fromAddress);
    $this->assertSame('Jan de Vries', $message->fromName);
    $this->assertSame('Broken streetlight at Main Square', $message->subject);
    $this->assertSame('plain-001@example.org', $message->messageId);
    $this->assertSame('', $message->inReplyTo);
    $this->assertSame([], $message->references);
    $this->assertContains('report@city.example', $message->toAddresses);
    $this->assertStringContainsString('flickering for a week', $message->textBody);
    $this->assertSame('', $message->htmlBody);
    $this->assertSame([], $message->attachments);
    $this->assertNotNull($message->date);
  }

  /**
   * @covers ::parse
   */
  public function testHtmlOnlyMessage(): void {
    $message = $this->parser->parse($this->fixture('html-only.eml'));

    $this->assertSame('erika@example.org', $message->fromAddress);
    $this->assertSame('Overflowing litter bin', $message->subject);
    $this->assertSame('', $message->textBody);
    $this->assertStringContainsString('Park Lane 3', $message->htmlBody);
  }

  /**
   * @covers ::parse
   */
  public function testAttachmentMessage(): void {
    $message = $this->parser->parse($this->fixture('with-attachment.eml'));

    $this->assertSame('pieter@example.org', $message->fromAddress);
    $this->assertStringContainsString('Deep pothole on Bridge Street', $message->textBody);
    $this->assertCount(1, $message->attachments);

    $attachment = $message->attachments[0];
    $this->assertSame('pothole.jpg', $attachment->filename);
    $this->assertSame('image/jpeg', $attachment->mimeType);
    $this->assertGreaterThan(0, $attachment->size);
    $this->assertSame($attachment->size, strlen($attachment->content));
    // The decoded content must be a real JPEG (magic bytes), so the
    // creator's finfo sniff will accept it.
    $this->assertSame("\xFF\xD8\xFF", substr($attachment->content, 0, 3));
  }

  /**
   * @covers ::parse
   */
  public function testReplyThreadingHeaders(): void {
    $message = $this->parser->parse($this->fixture('reply.eml'));

    $this->assertSame('reply-004@example.org', $message->messageId);
    $this->assertSame('plain-001@example.org', $message->inReplyTo);
    $this->assertSame(['plain-001@example.org'], $message->references);
    $this->assertSame(['plain-001@example.org'], $message->getThreadingIds());
    $this->assertStringContainsString('lamp post number is 4711', $message->textBody);
  }

  /**
   * @covers ::parse
   */
  public function testUtf8QuotedPrintableMessage(): void {
    $message = $this->parser->parse($this->fixture('utf8-quoted-printable.eml'));

    $this->assertSame('Straßenschaden in der Müllerstraße', $message->subject);
    $this->assertSame('Jürgen Müller', $message->fromName);
    $this->assertSame('juergen.mueller@example.org', $message->fromAddress);
    $this->assertStringContainsString('Großes Schlagloch vor der Bäckerei', $message->textBody);
    $this->assertStringContainsString('Müllerstraße 23', $message->textBody);
    // Cc recipients are part of the routing candidates.
    $this->assertContains('ordnungsamt@city.example', $message->toAddresses);
    $this->assertContains('report@city.example', $message->toAddresses);
  }

  /**
   * @covers ::parse
   *
   * H2: a malformed Date header must not kill the mail at parse time. The
   * parser passes webklex a soft_fail + fallback_date config (mirroring
   * MailboxFetcher), so an unparsable Date resolves to the fallback instead of
   * throwing InvalidMessageDateException. The rest of the message must still
   * parse normally.
   */
  public function testMalformedDateUsesFallbackInsteadOfThrowing(): void {
    $message = $this->parser->parse($this->fixture('malformed-date.eml'));

    // The mail parsed: subject, sender and body all survive.
    $this->assertSame('bad.date@example.org', $message->fromAddress);
    $this->assertSame('Pothole report with a broken Date header', $message->subject);
    $this->assertSame('malformed-date-001@example.org', $message->messageId);
    $this->assertStringContainsString('deep pothole on Market Street', $message->textBody);

    // The unparsable Date resolved to the fallback (01.01.1970), not NULL and
    // not an exception. The fallback timestamp is near the unix epoch.
    $this->assertNotNull($message->date);
    $this->assertLessThan(86400, $message->date);
  }

  /**
   * @covers ::parse
   */
  public function testEmptySourceThrows(): void {
    $this->expectException(MailParseException::class);
    $this->parser->parse('   ');
  }

}
