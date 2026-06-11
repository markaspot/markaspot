<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\markaspot_mail_inbound\Util\MailTextUtils;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pure text helpers of the inbound mail pipeline.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Util\MailTextUtils
 */
class MailTextUtilsTest extends UnitTestCase {

  /**
   * @covers ::decodeMimeHeader
   */
  public function testDecodeMimeHeader(): void {
    $this->assertSame(
      'Straßenschaden in der Müllerstraße',
      MailTextUtils::decodeMimeHeader('=?utf-8?q?Stra=C3=9Fenschaden_in_der_M=C3=BCllerstra=C3=9Fe?=')
    );
    $this->assertSame(
      'Jürgen Müller',
      MailTextUtils::decodeMimeHeader('=?utf-8?q?J=C3=BCrgen_M=C3=BCller?=')
    );
    // Base64 encoded word.
    $this->assertSame(
      'Grüße',
      MailTextUtils::decodeMimeHeader('=?utf-8?B?R3LDvMOfZQ==?=')
    );
    // Plain values pass through untouched.
    $this->assertSame('Hello world', MailTextUtils::decodeMimeHeader('Hello world'));
    $this->assertSame('', MailTextUtils::decodeMimeHeader(''));
  }

  /**
   * @covers ::htmlToText
   */
  public function testHtmlToTextStripsAllMarkup(): void {
    $html = '<html><head><title>ignore</title><style>p{color:red}</style></head>'
      . '<body><p>The litter bin at <b>Park Lane 3</b> is overflowing.</p>'
      . "<script>alert('xss')</script>"
      . '<p>It smells &amp; attracts rats.</p></body></html>';
    $text = MailTextUtils::htmlToText($html);

    $this->assertStringContainsString('The litter bin at Park Lane 3 is overflowing.', $text);
    $this->assertStringContainsString('It smells & attracts rats.', $text);
    $this->assertStringNotContainsString('<', $text);
    $this->assertStringNotContainsString('alert', $text);
    $this->assertStringNotContainsString('ignore', $text);
    $this->assertStringNotContainsString('color:red', $text);
  }

  /**
   * @covers ::htmlToText
   */
  public function testHtmlToTextPreservesLineBreaks(): void {
    $text = MailTextUtils::htmlToText('<p>Line one</p><p>Line two</p><br>Line three');
    // The closing </p> and the <br> each contribute a newline; runs of three
    // or more newlines collapse to a single blank line.
    $this->assertSame("Line one\nLine two\n\nLine three", $text);
    $this->assertSame('', MailTextUtils::htmlToText(''));
  }

  /**
   * @covers ::sanitizeSubject
   */
  public function testSanitizeSubject(): void {
    // Control and format characters are stripped, whitespace collapsed.
    $this->assertSame(
      'Broken light',
      MailTextUtils::sanitizeSubject("Broken\x00\x07 \u{202E}light\r\n")
    );
    // Truncation respects UTF-8 and the maximum length.
    $long = str_repeat('ä', 300);
    $sanitized = MailTextUtils::sanitizeSubject($long, 255);
    $this->assertLessThanOrEqual(255, mb_strlen($sanitized));
    $this->assertSame('', MailTextUtils::sanitizeSubject("\x01\x02"));
  }

  /**
   * @covers ::normalizeMessageId
   */
  public function testNormalizeMessageId(): void {
    $this->assertSame('abc@example.com', MailTextUtils::normalizeMessageId('<abc@example.com>'));
    $this->assertSame('abc@example.com', MailTextUtils::normalizeMessageId("  <abc@example.com> \r\n"));
    $this->assertSame('', MailTextUtils::normalizeMessageId(''));
  }

  /**
   * @covers ::matchesAddressList
   */
  public function testMatchesAddressList(): void {
    // Exact address match, case-insensitive.
    $this->assertTrue(MailTextUtils::matchesAddressList('User@Example.com', ['user@example.com']));
    // Domain entries in all supported notations.
    $this->assertTrue(MailTextUtils::matchesAddressList('user@spam.example', ['@spam.example']));
    $this->assertTrue(MailTextUtils::matchesAddressList('user@spam.example', ['*@spam.example']));
    $this->assertTrue(MailTextUtils::matchesAddressList('user@spam.example', ['spam.example']));
    // Non-matches.
    $this->assertFalse(MailTextUtils::matchesAddressList('user@example.com', ['other@example.com']));
    $this->assertFalse(MailTextUtils::matchesAddressList('user@notspam.example', ['@spam.example']));
    $this->assertFalse(MailTextUtils::matchesAddressList('', ['@spam.example']));
    $this->assertFalse(MailTextUtils::matchesAddressList('user@example.com', []));
  }

  /**
   * @covers ::normalizeWhitespace
   */
  public function testNormalizeWhitespace(): void {
    $this->assertSame(
      "a b\n\nc",
      MailTextUtils::normalizeWhitespace("a \t b\r\n\r\n\r\n\r\nc\x00")
    );
  }

}
