<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\markaspot_open311\Service\RemoteImageException;
use Drupal\markaspot_open311\Service\RemoteImageFetcher;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the SSRF and payload guards of the Open311 media_url downloader.
 */
#[CoversClass(RemoteImageFetcher::class)]
#[Group('markaspot_open311')]
final class RemoteImageFetcherTest extends UnitTestCase {

  private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
  private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
  private const EXTENSIONS = ['png', 'gif', 'jpg', 'jpeg'];

  /**
   * Requests the mock client received, with their options.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface, options: array}>
   */
  private array $history = [];

  /**
   * Builds a fetcher on a mock client with a fixed DNS table.
   *
   * @param \Psr\Http\Message\ResponseInterface[]|\Throwable[] $responses
   *   Queued responses; an empty queue fails any unexpected request.
   * @param array<string, string[]> $dns
   *   Host name to resolved addresses.
   */
  private function fetcher(array $responses = [], array $dns = []): RemoteImageFetcher {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $client = new Client(['handler' => $stack]);

    return new class($client, $dns) extends RemoteImageFetcher {

      public function __construct(Client $client, private array $dns) {
        parent::__construct($client);
      }

      /**
       * {@inheritdoc}
       */
      protected function resolveHost(string $host): array {
        return $this->dns[$host] ?? [];
      }

    };
  }

  /**
   * Asserts that fetching fails with the given reason.
   */
  private function assertRejected(string $reason, RemoteImageFetcher $fetcher, string $url, int $maxBytes = 1048576): void {
    try {
      $fetcher->fetch($url, $maxBytes, self::EXTENSIONS);
      $this->fail("Expected the media URL to be rejected with '$reason'.");
    }
    catch (RemoteImageException $e) {
      $this->assertSame($reason, $e->getReason());
    }
  }

  /**
   * Internal targets are rejected before any request is sent.
   */
  #[DataProvider('blockedAddressProvider')]
  public function testNonPublicAddressesAreBlocked(string $url, array $dns): void {
    $this->assertRejected('blocked_address', $this->fetcher([], $dns), $url);
    $this->assertSame([], $this->history);
  }

  /**
   * URLs that point at internal or reserved addresses.
   */
  public static function blockedAddressProvider(): array {
    return [
      'loopback literal' => ['http://127.0.0.1/a.png', []],
      'metadata service' => ['http://169.254.169.254/latest/meta-data', []],
      'private ten' => ['http://10.1.2.3/a.png', []],
      'docker bridge' => ['http://172.19.0.5/a.png', []],
      'cgnat' => ['http://100.64.0.1/a.png', []],
      'unspecified' => ['http://0.0.0.0/a.png', []],
      'multicast' => ['http://224.0.0.1/a.png', []],
      'ipv6 loopback' => ['http://[::1]/a.png', []],
      'ipv4 mapped ipv6' => ['http://[::ffff:127.0.0.1]/a.png', []],
      'nat64 to loopback' => ['http://[64:ff9b::7f00:1]/a.png', []],
      'ipv6 unique local' => ['http://[fd00::1]/a.png', []],
      'ipv4 compatible ipv6' => ['http://[::7f00:1]/a.png', []],
      'name resolving to private' => ['https://minio/bucket/a.png', ['minio' => ['172.19.0.4']]],
      'name with one private record' => [
        'https://cdn.example.com/a.png',
        ['cdn.example.com' => ['93.184.216.34', '10.0.0.7']],
      ],
    ];
  }

  /**
   * Scheme, port, credential and host problems are rejected up front.
   */
  #[DataProvider('invalidUrlProvider')]
  public function testInvalidUrlsAreRejected(string $url, string $reason): void {
    $this->assertRejected($reason, $this->fetcher(), $url);
    $this->assertSame([], $this->history);
  }

  /**
   * URLs that never reach the network.
   */
  public static function invalidUrlProvider(): array {
    return [
      'relative' => ['/sites/default/files/a.png', 'invalid_url'],
      'empty' => ['  ', 'invalid_url'],
      'file scheme' => ['file:///etc/passwd', 'invalid_url'],
      'gopher' => ['gopher://example.com/_', 'scheme'],
      'ftp' => ['ftp://example.com/a.png', 'scheme'],
      'custom port' => ['http://example.com:8080/a.png', 'port'],
      'https on 80' => ['https://example.com:80/a.png', 'port'],
      'credentials' => ['https://user:secret@example.com/a.png', 'credentials'],
      'unresolvable' => ['https://does-not-exist.example/a.png', 'unresolvable'],
    ];
  }

  /**
   * A valid PNG is returned with the extension detected from its bytes.
   */
  public function testValidImageIsReturnedWithDetectedType(): void {
    $fetcher = $this->fetcher(
      [new Response(200, ['Content-Type' => 'text/plain'], base64_decode(self::PNG))],
      ['images.example.com' => ['93.184.216.34']],
    );

    $image = $fetcher->fetch('https://images.example.com/upload/report.php?id=1', 1048576, self::EXTENSIONS);

    $this->assertSame('png', $image['extension']);
    $this->assertSame('image/png', $image['mime']);
    $this->assertSame(base64_decode(self::PNG), $image['data']);

    $options = $this->history[0]['options'];
    $this->assertFalse($options['allow_redirects']);
    $this->assertSame(['images.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    $this->assertSame(1048576, $options['curl'][CURLOPT_MAXFILESIZE_LARGE]);
    $this->assertArrayHasKey('timeout', $options);
    $this->assertArrayHasKey('connect_timeout', $options);
  }

  /**
   * IPv6 targets are pinned in the bracketed form curl expects.
   */
  public function testIpv6AddressIsPinnedWithBrackets(): void {
    $fetcher = $this->fetcher(
      [new Response(200, [], base64_decode(self::GIF))],
      ['v6.example.com' => ['2606:4700::1111']],
    );

    $image = $fetcher->fetch('http://v6.example.com/a.gif', 1048576, self::EXTENSIONS);

    $this->assertSame('gif', $image['extension']);
    $this->assertSame(['v6.example.com:80:[2606:4700::1111]'], $this->history[0]['options']['curl'][CURLOPT_RESOLVE]);
  }

  /**
   * IP literals need no pinning; a proxy is never used.
   */
  public function testIpLiteralIsFetchedWithoutResolveEntryAndProxy(): void {
    $fetcher = $this->fetcher([new Response(200, [], base64_decode(self::PNG))]);

    $fetcher->fetch('http://93.184.216.34/a.png', 1048576, self::EXTENSIONS);

    $options = $this->history[0]['options'];
    $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl']);
    $this->assertSame('', $options['proxy']);
    $this->assertSame('*', $options['curl'][CURLOPT_NOPROXY]);
  }

  /**
   * Broken or ambiguous redirect targets are rejected as redirects.
   */
  public function testMalformedOrMultipleLocationsAreRejected(): void {
    $fetcher = $this->fetcher(
      [
        new Response(302, ['Location' => 'http://[::1']),
        new Response(302, ['Location' => ['https://a.example.net/1.png', 'https://b.example.net/2.png']]),
      ],
      ['images.example.com' => ['93.184.216.34']],
    );

    $this->assertRejected('redirect', $fetcher, 'https://images.example.com/a.png');
    $this->assertRejected('redirect', $fetcher, 'https://images.example.com/b.png');
  }

  /**
   * Without the curl handler the pinning cannot be enforced: refuse.
   */
  public function testMissingCurlTransportIsRejected(): void {
    $fetcher = new class(new Client(['handler' => HandlerStack::create(new MockHandler([]))])) extends RemoteImageFetcher {

      /**
       * {@inheritdoc}
       */
      protected function transportIsSupported(): bool {
        return FALSE;
      }

    };

    $this->assertRejected('transport', $fetcher, 'https://images.example.com/a.png');
  }

  /**
   * A redirect to an internal address is checked and refused.
   */
  public function testRedirectToPrivateAddressIsBlocked(): void {
    $fetcher = $this->fetcher(
      [new Response(302, ['Location' => 'http://169.254.169.254/latest/user-data'])],
      ['images.example.com' => ['93.184.216.34']],
    );

    $this->assertRejected('blocked_address', $fetcher, 'https://images.example.com/a.png');
    $this->assertCount(1, $this->history);
  }

  /**
   * A redirect to a public host is followed and pinned again.
   */
  public function testRedirectToPublicHostIsFollowed(): void {
    $fetcher = $this->fetcher(
      [
        new Response(301, ['Location' => 'https://cdn.example.net/a.png']),
        new Response(200, [], base64_decode(self::PNG)),
      ],
      [
        'images.example.com' => ['93.184.216.34'],
        'cdn.example.net' => ['151.101.1.1'],
      ],
    );

    $image = $fetcher->fetch('http://images.example.com/a.png', 1048576, self::EXTENSIONS);

    $this->assertSame('png', $image['extension']);
    $this->assertCount(2, $this->history);
    $this->assertSame(['cdn.example.net:443:151.101.1.1'], $this->history[1]['options']['curl'][CURLOPT_RESOLVE]);
  }

  /**
   * Redirect chains are cut off.
   */
  public function testTooManyRedirectsAreRejected(): void {
    $redirect = fn () => new Response(302, ['Location' => '/next']);
    $fetcher = $this->fetcher(
      [$redirect(), $redirect(), $redirect(), $redirect()],
      ['images.example.com' => ['93.184.216.34']],
    );

    $this->assertRejected('redirect', $fetcher, 'https://images.example.com/a.png');
    $this->assertCount(4, $this->history);
  }

  /**
   * Content that is not a PNG, GIF or JPEG is rejected.
   */
  #[DataProvider('nonImageProvider')]
  public function testNonImagePayloadIsRejected(string $body): void {
    $fetcher = $this->fetcher(
      [new Response(200, ['Content-Type' => 'image/png'], $body)],
      ['images.example.com' => ['93.184.216.34']],
    );

    $this->assertRejected('not_an_image', $fetcher, 'https://images.example.com/a.png');
  }

  /**
   * Payloads that must not be stored as request images.
   */
  public static function nonImageProvider(): array {
    return [
      'html' => ['<html><body>admin panel</body></html>'],
      'php' => ['<?php system($_GET["c"]);'],
      'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
      'empty' => [''],
    ];
  }

  /**
   * A detected type outside the field allowlist is rejected.
   */
  public function testTypeOutsideFieldAllowlistIsRejected(): void {
    $fetcher = $this->fetcher(
      [new Response(200, [], base64_decode(self::GIF))],
      ['images.example.com' => ['93.184.216.34']],
    );

    try {
      $fetcher->fetch('https://images.example.com/a.png', 1048576, ['png', 'jpg']);
      $this->fail('A GIF must not pass a png/jpg allowlist.');
    }
    catch (RemoteImageException $e) {
      $this->assertSame('extension', $e->getReason());
    }
  }

  /**
   * Bodies over the limit are rejected, with or without Content-Length.
   */
  public function testOversizedPayloadIsRejected(): void {
    $png = base64_decode(self::PNG);
    $fetcher = $this->fetcher(
      [
        new Response(200, ['Content-Length' => '999999'], $png),
        new Response(200, [], $png . str_repeat("\0", 200)),
      ],
      ['images.example.com' => ['93.184.216.34']],
    );

    $this->assertRejected('too_large', $fetcher, 'https://images.example.com/a.png', 100);
    $this->assertRejected('too_large', $fetcher, 'https://images.example.com/b.png', 100);
  }

  /**
   * The curl size cap (error 63) is reported as too large.
   */
  public function testCurlMaxFileSizeErrorIsReportedAsTooLarge(): void {
    $request = new Request('GET', 'https://images.example.com/a.jpg');
    $fetcher = $this->fetcher(
      [new RequestException('cURL error 63: Maximum file size exceeded', $request, NULL, NULL, ['errno' => 63])],
      ['images.example.com' => ['93.184.216.34']],
    );

    $this->assertRejected('too_large', $fetcher, 'https://images.example.com/a.jpg');
  }

  /**
   * HTTP errors and transport failures are reported without internals.
   */
  public function testHttpAndTransferErrorsAreRejected(): void {
    $fetcher = $this->fetcher(
      [
        new Response(404),
        new ConnectException('cURL error 7: Failed to connect to 93.184.216.34', new Request('GET', 'https://images.example.com/b.png')),
      ],
      ['images.example.com' => ['93.184.216.34']],
    );

    $this->assertRejected('http_status', $fetcher, 'https://images.example.com/a.png');
    try {
      $fetcher->fetch('https://images.example.com/b.png', 1048576, self::EXTENSIONS);
      $this->fail('A transfer error must be rejected.');
    }
    catch (RemoteImageException $e) {
      $this->assertSame('transfer', $e->getReason());
      $this->assertStringNotContainsString('93.184.216.34', $e->getMessage());
    }
  }

}
