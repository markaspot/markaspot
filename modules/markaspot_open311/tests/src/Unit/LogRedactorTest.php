<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\markaspot_open311\Logger\LogRedactor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 3) . '/src/Logger/LogRedactor.php';

/**
 * Tests credential redaction from URLs and message fragments.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Logger\LogRedactor
 */
final class LogRedactorTest extends UnitTestCase {

  /**
   * Tests URL query parameter redaction.
   *
   * @covers ::redactUrl
   */
  #[DataProvider('urlProvider')]
  public function testRedactUrl(string $url, string $expected): void {
    $this->assertSame($expected, (new LogRedactor())->redactUrl($url));
  }

  /**
   * Provides URL redaction cases.
   *
   * @return array<string, array{string, string}>
   *   URL and expected redacted URL pairs.
   */
  public static function urlProvider(): array {
    return [
      'first parameter' => [
        'https://example.test/path?api_key=secret123&foo=bar',
        'https://example.test/path?api_key=[REDACTED]&foo=bar',
      ],
      'subsequent parameter and case' => [
        'https://example.test/path?foo=bar&API-KEY=Secret123#fragment',
        'https://example.test/path?foo=bar&API-KEY=[REDACTED]#fragment',
      ],
      'compact api key' => [
        'https://example.test/?apikey=secret123',
        'https://example.test/?apikey=[REDACTED]',
      ],
      'token' => [
        'https://example.test/?token=secret123',
        'https://example.test/?token=[REDACTED]',
      ],
      'access token underscore' => [
        'https://example.test/?access_token=secret123',
        'https://example.test/?access_token=[REDACTED]',
      ],
      'access token hyphen' => [
        'https://example.test/?access-token=secret123',
        'https://example.test/?access-token=[REDACTED]',
      ],
      'generic key' => [
        'https://example.test/?key=secret123',
        'https://example.test/?key=[REDACTED]',
      ],
      'encoded delimiters' => [
        'https://example.test/?api_key%3Dsecret123%26foo%3Dbar',
        'https://example.test/?api_key%3D[REDACTED]%26foo%3Dbar',
      ],
      'multiple encoded credentials' => [
        'https://example.test/?api_key%3Done%26TOKEN%3Dtwo',
        'https://example.test/?api_key%3D[REDACTED]%26TOKEN%3D[REDACTED]',
      ],
      'encoded ampersand inside value' => [
        'https://example.test/?api_key=one%26two',
        'https://example.test/?api_key=[REDACTED]',
      ],
      'query-only malformed input' => [
        'api_key=secret123&foo=bar',
        'api_key=[REDACTED]&foo=bar',
      ],
      'malformed URL fallback' => [
        'https://[broken api_key=secret123',
        'https://[broken api_key=[REDACTED]',
      ],
      'unrelated parameter' => [
        'https://example.test/?monkey=banana&foo=bar',
        'https://example.test/?monkey=banana&foo=bar',
      ],
    ];
  }

  /**
   * Tests arbitrary log message redaction.
   *
   * @covers ::redactMessage
   */
  #[DataProvider('messageProvider')]
  public function testRedactMessage(string $message, string $expected): void {
    $this->assertSame($expected, (new LogRedactor())->redactMessage($message));
  }

  /**
   * Provides message redaction cases.
   *
   * @return array<string, array{string, string}>
   *   Message and expected redacted message pairs.
   */
  public static function messageProvider(): array {
    return [
      'query fragment' => [
        'Request failed: api_key=secret123, retry disabled.',
        'Request failed: api_key=[REDACTED] retry disabled.',
      ],
      'punctuation inside credential' => [
        'Request failed: api_key=abc,def;ghi retry disabled.',
        'Request failed: api_key=[REDACTED] retry disabled.',
      ],
      'bearer authorization' => [
        'Authorization: Bearer ey.secret.token request failed',
        'Authorization: Bearer [REDACTED] request failed',
      ],
      'cookie header' => [
        'Cookie: session=secret123; theme=dark',
        'Cookie: [REDACTED]',
      ],
      'case-insensitive fragments' => [
        "APIKEY=one\nAUTHORIZATION: bearer two\nCOOKIE: sid=three",
        "APIKEY=[REDACTED]\nAUTHORIZATION: bearer [REDACTED]\nCOOKIE: [REDACTED]",
      ],
      'unrelated message' => [
        'The monkey=banana value is not a credential parameter.',
        'The monkey=banana value is not a credential parameter.',
      ],
    ];
  }

}
