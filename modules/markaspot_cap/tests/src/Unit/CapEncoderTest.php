<?php

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\markaspot_cap\Encoder\CapEncoder;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the CapEncoder.
 *
 * @group markaspot_cap
 * @coversDefaultClass \Drupal\markaspot_cap\Encoder\CapEncoder
 */
class CapEncoderTest extends UnitTestCase {

  /**
   * @covers ::decode
   */
  public function testDecodeRejectsDoctypeDeclaration(): void {
    $encoder = new CapEncoder();
    $xml = '<?xml version="1.0"?><!DOCTYPE foo SYSTEM "file:///etc/passwd"><foo/>';

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('DTDs are not allowed in CAP documents.');

    $encoder->decode($xml, 'cap');
  }

  /**
   * @covers ::decode
   */
  public function testDecodeRejectsMalformedXml(): void {
    $encoder = new CapEncoder();
    $xml = '<unclosed>';

    $this->expectException(\UnexpectedValueException::class);

    $encoder->decode($xml, 'cap');
  }

  /**
   * @covers ::decode
   */
  public function testDecodeRejectsEmptyString(): void {
    $encoder = new CapEncoder();

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Invalid CAP XML data, it cannot be empty.');

    $encoder->decode('', 'cap');
  }

  /**
   * @covers ::encode
   * @covers ::supportsEncoding
   */
  public function testSupportsCapFormat(): void {
    $encoder = new CapEncoder();

    $this->assertTrue($encoder->supportsEncoding('cap'));
    $this->assertFalse($encoder->supportsEncoding('json'));
    $this->assertTrue($encoder->supportsDecoding('cap'));
    $this->assertFalse($encoder->supportsDecoding('xml'));
  }

  /**
   * @covers ::encode
   */
  public function testEncodeUsesCapFeedIdFromContext(): void {
    $encoder = new CapEncoder();
    $alerts = [
      [
        'identifier' => 'test-001',
        'sender' => 'test@example.com',
        'sent' => '2024-01-01T00:00:00Z',
        'status' => 'Actual',
        'msgType' => 'Alert',
        'scope' => 'Public',
        'info' => [
          'category' => 'Other',
          'event' => 'Test',
          'urgency' => 'Expected',
          'severity' => 'Minor',
          'certainty' => 'Observed',
        ],
      ],
    ];

    $uuid = 'abc123';
    $xml = $encoder->encode($alerts, 'cap', ['cap_feed_id' => 'urn:markaspot:cap:feed:' . $uuid]);

    $this->assertStringContainsString('urn:markaspot:cap:feed:' . $uuid, $xml);
  }

  /**
   * @covers ::encode
   */
  public function testEncodeUsesDefaultFeedIdWhenContextAbsent(): void {
    $encoder = new CapEncoder();
    $alerts = [
      [
        'identifier' => 'test-002',
        'sender' => 'test@example.com',
        'sent' => '2024-01-01T00:00:00Z',
        'status' => 'Actual',
        'msgType' => 'Alert',
        'scope' => 'Public',
        'info' => [
          'category' => 'Other',
          'event' => 'Test',
          'urgency' => 'Expected',
          'severity' => 'Minor',
          'certainty' => 'Observed',
        ],
      ],
    ];

    $xml = $encoder->encode($alerts, 'cap');

    $this->assertStringContainsString('urn:markaspot:cap:feed', $xml);
    // Must not contain a UUID suffix when context is absent.
    $this->assertStringNotContainsString('urn:markaspot:cap:feed:', $xml);
  }

}
