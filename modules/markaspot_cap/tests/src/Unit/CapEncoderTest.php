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
    $document = new \DOMDocument();
    $this->assertTrue($document->loadXML($xml));
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('atom', CapEncoder::ATOM_NAMESPACE);
    // Only the feed identity uses the fallback. Entry identities are allowed
    // to extend the same URN namespace with their alert identifiers.
    $this->assertSame(
      'urn:markaspot:cap:feed',
      $xpath->evaluate('string(/atom:feed/atom:id)'),
    );
  }

  /**
   * An empty result remains a valid Atom feed, not an incomplete CAP alert.
   *
   * @covers ::encode
   */
  public function testEncodeEmptyListAsAtomFeed(): void {
    $encoder = new CapEncoder();
    $xml = $encoder->encode([], 'cap', [
      'cap_feed_id' => 'urn:markaspot:cap:feed:test',
    ]);
    $document = new \DOMDocument();
    $this->assertTrue($document->loadXML($xml));

    $this->assertSame('feed', $document->documentElement?->localName);
    $this->assertSame(
      'http://www.w3.org/2005/Atom',
      $document->documentElement?->namespaceURI,
    );
    $this->assertStringContainsString('urn:markaspot:cap:feed:test', $xml);
    $this->assertStringNotContainsString('<alert', $xml);
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('atom', CapEncoder::ATOM_NAMESPACE);
    $this->assertSame(1, $xpath->query('/atom:feed/atom:author/atom:name')->length);
    $this->assertSame('1970-01-01T00:00:00Z', $xpath->evaluate('string(/atom:feed/atom:updated)'));
  }

  /**
   * Atom and embedded CAP nodes retain their respective namespace URIs.
   *
   * @covers ::encode
   */
  public function testAtomFeedEmbedsFullyNamespacedCapAlert(): void {
    $encoder = new CapEncoder();
    $xml = $encoder->encode([
      [
        'identifier' => 'test-namespaces',
        'sender' => 'test@example.com',
        'sent' => '2026-07-10T10:00:00Z',
        '_atom_updated' => '2026-07-11T12:00:00Z',
        'status' => 'Actual',
        'msgType' => 'Alert',
        'scope' => 'Public',
        'info' => [
          'category' => 'Other',
          'event' => 'Flood',
          'urgency' => 'Immediate',
          'severity' => 'Severe',
          'certainty' => 'Observed',
          'area' => [
            'areaDesc' => 'Test area',
          ],
        ],
      ],
    ], 'cap');

    $document = new \DOMDocument();
    $this->assertTrue($document->loadXML($xml));
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('atom', CapEncoder::ATOM_NAMESPACE);
    $xpath->registerNamespace('cap', CapEncoder::CAP_NAMESPACE);

    $this->assertSame(
      1,
      $xpath->query('/atom:feed/atom:entry/atom:content/cap:alert/cap:identifier')->length,
    );
    $this->assertSame(
      1,
      $xpath->query('/atom:feed/atom:entry/atom:content/cap:alert/cap:info/cap:area/cap:areaDesc')->length,
    );
    $this->assertSame('Mark-a-Spot', $xpath->evaluate('string(/atom:feed/atom:author/atom:name)'));
    $this->assertSame('2026-07-11T12:00:00Z', $xpath->evaluate('string(/atom:feed/atom:updated)'));
    $this->assertSame('2026-07-11T12:00:00Z', $xpath->evaluate('string(/atom:feed/atom:entry/atom:updated)'));
    foreach ($xpath->query('/atom:feed/atom:entry/atom:content/cap:alert//*') as $node) {
      $this->assertSame(CapEncoder::CAP_NAMESPACE, $node->namespaceURI);
    }
  }

  /**
   * Entry IDs are globally scoped by their feed identity.
   *
   * @covers ::encode
   */
  public function testAtomEntryIdIncludesFeedIdentity(): void {
    $alert = [[
      'identifier' => 'REQ 42',
      'sender' => 'test@example.com',
      'sent' => '2026-07-10T10:00:00Z',
      'status' => 'Actual',
      'msgType' => 'Alert',
      'scope' => 'Public',
      'info' => [
        'category' => 'Other',
        'event' => 'Flood',
        'urgency' => 'Immediate',
        'severity' => 'Severe',
        'certainty' => 'Observed',
      ],
    ]];
    $encoder = new CapEncoder();

    $first = $encoder->encode($alert, 'cap', [
      'cap_feed_id' => 'urn:markaspot:cap:feed:site-a:root-7:scope-9',
    ]);
    $second = $encoder->encode($alert, 'cap', [
      'cap_feed_id' => 'urn:markaspot:cap:feed:site-b:root-7:scope-9',
    ]);

    $this->assertStringContainsString(
      'urn:markaspot:cap:feed:site-a:root-7:scope-9:alert:REQ%2042',
      $first,
    );
    $this->assertStringContainsString(
      'urn:markaspot:cap:feed:site-b:root-7:scope-9:alert:REQ%2042',
      $second,
    );
    $this->assertNotSame($first, $second);
  }

}
