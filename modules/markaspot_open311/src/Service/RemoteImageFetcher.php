<?php

namespace Drupal\markaspot_open311\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Log\LoggerInterface;

/**
 * Downloads a remote image for an Open311 media_url without SSRF exposure.
 *
 * The URL comes straight from an API client, so every step treats it as
 * hostile:
 * - only http/https on the default ports, no credentials in the URL;
 * - every resolved address must be globally routable (no private, loopback,
 *   link-local, CGNAT, reserved, multicast or NAT64 range), and the
 *   connection is pinned to the checked address so a second DNS answer
 *   cannot point it somewhere else (DNS rebinding);
 * - redirects are followed manually, at most three hops, and every hop goes
 *   through the same URL and address checks;
 * - the body is capped while it streams in;
 * - the payload must be a PNG, GIF or JPEG, and the file extension is
 *   derived from the detected type, never from the URL.
 */
class RemoteImageFetcher {

  /**
   * Image types accepted for request images, keyed by IMAGETYPE_* constant.
   */
  protected const IMAGE_TYPES = [
    IMAGETYPE_JPEG => ['extension' => 'jpg', 'mime' => 'image/jpeg'],
    IMAGETYPE_PNG => ['extension' => 'png', 'mime' => 'image/png'],
    IMAGETYPE_GIF => ['extension' => 'gif', 'mime' => 'image/gif'],
  ];

  /**
   * Ranges FILTER_FLAG_GLOBAL_RANGE lets through but we never want to reach.
   */
  protected const EXTRA_BLOCKED_RANGES = [
    // IPv4 multicast.
    '224.0.0.0/4',
    // NAT64 well-known and local-use prefixes embed IPv4 targets.
    '64:ff9b::/96',
    '64:ff9b:1::/48',
    // IPv6 multicast.
    'ff00::/8',
    // Deprecated IPv4-compatible IPv6 (::a.b.c.d).
    '::/96',
  ];

  /**
   * Maximum number of redirects followed, each one re-validated.
   */
  protected const MAX_REDIRECTS = 3;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * Whether the transport can enforce the pinning and size options.
   *
   * CURLOPT_RESOLVE and the size cap only work with Guzzle's curl handler,
   * which it picks whenever ext-curl is loaded.
   */
  protected function transportIsSupported(): bool {
    return extension_loaded('curl');
  }

  /**
   * Fetches and validates one remote image.
   *
   * @param string $url
   *   The URL supplied by the API client.
   * @param int $maxBytes
   *   Hard size limit for the response body.
   * @param string[] $allowedExtensions
   *   Lowercase extensions the target field accepts (e.g. png, gif, jpg).
   *
   * @return array{data: string, extension: string, mime: string}
   *   The image bytes and the extension/MIME type detected from them.
   *
   * @throws \Drupal\markaspot_open311\Service\RemoteImageException
   *   When the URL, the target address or the payload is not acceptable.
   */
  public function fetch(string $url, int $maxBytes, array $allowedExtensions): array {
    if (!$this->transportIsSupported()) {
      throw new RemoteImageException('transport', 'Remote media imports are not available on this server.');
    }

    $current = trim($url);
    for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
      $target = $this->validateUrl($current);
      $address = $this->resolvePublicAddress($target['host']);
      $result = $this->download($target, $address, $maxBytes);
      if (isset($result['location'])) {
        try {
          $current = (string) UriResolver::resolve(new Uri($target['url']), new Uri($result['location']));
        }
        catch (\InvalidArgumentException $e) {
          throw new RemoteImageException('redirect', 'The media URL redirects to an invalid location.', $e);
        }
        continue;
      }
      return $this->detectImage($result['data'], $allowedExtensions);
    }

    throw new RemoteImageException('redirect', 'The media URL redirects too often.');
  }

  /**
   * Checks scheme, host, port and credentials of the URL.
   *
   * @return array{url: string, scheme: string, host: string, port: int}
   *   The normalized target.
   */
  protected function validateUrl(string $url): array {
    $parts = parse_url($url);
    if ($url === '' || $parts === FALSE || empty($parts['host']) || empty($parts['scheme'])) {
      throw new RemoteImageException('invalid_url', 'The media URL is not a valid absolute URL.');
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      throw new RemoteImageException('scheme', 'Only http and https media URLs are supported.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      throw new RemoteImageException('credentials', 'Media URLs must not contain credentials.');
    }

    $defaultPort = $scheme === 'https' ? 443 : 80;
    $port = $parts['port'] ?? $defaultPort;
    if ($port !== $defaultPort) {
      throw new RemoteImageException('port', 'Media URLs must use the default port of their scheme.');
    }

    // parse_url() keeps the brackets around IPv6 literals.
    $host = strtolower(trim($parts['host'], '[]'));

    return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port];
  }

  /**
   * Resolves the host and returns an address that is safe to connect to.
   *
   * Every address the host resolves to has to be public: a host with one
   * public and one internal record is rejected as a whole.
   */
  protected function resolvePublicAddress(string $host): string {
    $addresses = filter_var($host, FILTER_VALIDATE_IP) !== FALSE
      ? [$host]
      : $this->resolveHost($host);

    if ($addresses === []) {
      throw new RemoteImageException('unresolvable', 'The media URL host could not be resolved.');
    }

    foreach ($addresses as $address) {
      if (!$this->isPublicAddress($address)) {
        throw new RemoteImageException('blocked_address', 'The media URL points to a non-public address.');
      }
    }

    return $addresses[0];
  }

  /**
   * Returns the A and AAAA records of a host name.
   *
   * @return string[]
   *   IPv4 addresses first, then IPv6.
   */
  protected function resolveHost(string $host): array {
    $addresses = gethostbynamel($host) ?: [];
    $records = @dns_get_record($host, DNS_AAAA) ?: [];
    foreach ($records as $record) {
      if (!empty($record['ipv6'])) {
        $addresses[] = $record['ipv6'];
      }
    }
    return array_values(array_unique($addresses));
  }

  /**
   * Whether an IP address is globally routable and not on the extra blocklist.
   */
  public function isPublicAddress(string $address): bool {
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === FALSE) {
      return FALSE;
    }
    foreach (self::EXTRA_BLOCKED_RANGES as $range) {
      if ($this->addressInRange($address, $range)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Checks whether an address lies inside a CIDR range of the same family.
   */
  protected function addressInRange(string $address, string $range): bool {
    [$subnet, $bits] = explode('/', $range);
    $addressBinary = inet_pton($address);
    $subnetBinary = inet_pton($subnet);
    if ($addressBinary === FALSE || $subnetBinary === FALSE || strlen($addressBinary) !== strlen($subnetBinary)) {
      return FALSE;
    }

    $bits = (int) $bits;
    $fullBytes = intdiv($bits, 8);
    if (substr($addressBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
      return FALSE;
    }
    $remainingBits = $bits % 8;
    if ($remainingBits === 0) {
      return TRUE;
    }
    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($addressBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
  }

  /**
   * Downloads the body with a pinned address and a size cap.
   *
   * Redirects are not followed here; the Location is handed back so the
   * caller can validate the next hop like the first one.
   *
   * @param array{url: string, scheme: string, host: string, port: int} $target
   *   The validated target.
   * @param string $address
   *   The checked address to connect to.
   * @param int $maxBytes
   *   Hard size limit for the response body.
   *
   * @return array{data?: string, location?: string}
   *   Either the body or the redirect target.
   */
  protected function download(array $target, string $address, int $maxBytes): array {

    $options = [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
      'connect_timeout' => 5,
      'timeout' => 20,
      'headers' => [
        'Accept' => 'image/jpeg, image/png, image/gif',
      ],
      // Abort as soon as the body grows past the limit, whatever the
      // Content-Length header claims.
      'progress' => static function ($downloadTotal, $downloaded) use ($maxBytes): void {
        if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
          throw new RemoteImageException('too_large', 'The media file exceeds the size limit.');
        }
      },
      // A proxy would resolve the host itself and undo the pinning below.
      'proxy' => '',
      'curl' => [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE_LARGE => $maxBytes,
        CURLOPT_NOPROXY => '*',
      ],
    ];
    // Connect to the address we checked, not to whatever a second DNS lookup
    // returns. An IP literal has nothing to resolve, and curl cannot parse a
    // RESOLVE entry for an IPv6 host anyway.
    if (filter_var($target['host'], FILTER_VALIDATE_IP) === FALSE) {
      $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;
      $options['curl'][CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $pinned];
    }

    try {
      $response = $this->httpClient->request('GET', $target['url'], $options);
    }
    catch (RemoteImageException $e) {
      throw $e;
    }
    catch (TransferException $e) {
      $previous = $e->getPrevious();
      if ($previous instanceof RemoteImageException) {
        throw $previous;
      }
      // cURL error 63: CURLOPT_MAXFILESIZE_LARGE hit.
      if ($e instanceof RequestException && ($e->getHandlerContext()['errno'] ?? NULL) === 63) {
        throw new RemoteImageException('too_large', 'The media file exceeds the size limit.', $e);
      }
      $this->logger?->notice('Open311 media_url download failed for host @host: @message', [
        '@host' => $target['host'],
        '@message' => $e->getMessage(),
      ]);
      throw new RemoteImageException('transfer', 'The media file could not be downloaded.', $e);
    }

    $status = $response->getStatusCode();
    if (in_array($status, [301, 302, 303, 307, 308], TRUE)) {
      $locations = $response->getHeader('Location');
      $location = count($locations) === 1 ? trim($locations[0]) : '';
      if ($location === '') {
        throw new RemoteImageException('redirect', 'The media URL redirects without a target.');
      }
      return ['location' => $location];
    }
    if ($status !== 200) {
      throw new RemoteImageException('http_status', sprintf('The media URL returned HTTP %d.', $status));
    }

    $declaredLength = $response->getHeaderLine('Content-Length');
    if ($declaredLength !== '' && (int) $declaredLength > $maxBytes) {
      throw new RemoteImageException('too_large', 'The media file exceeds the size limit.');
    }

    $body = $response->getBody();
    $data = '';
    while (!$body->eof()) {
      $data .= $body->read(65536);
      if (strlen($data) > $maxBytes) {
        throw new RemoteImageException('too_large', 'The media file exceeds the size limit.');
      }
    }

    return ['data' => $data];
  }

  /**
   * Detects the image type from the bytes and checks it against the field.
   *
   * @return array{data: string, extension: string, mime: string}
   *   The image bytes and the detected extension and MIME type.
   */
  protected function detectImage(string $data, array $allowedExtensions): array {
    $info = $data === '' ? FALSE : @getimagesizefromstring($data);
    $type = is_array($info) ? ($info[2] ?? NULL) : NULL;
    if ($type === NULL || !isset(self::IMAGE_TYPES[$type])) {
      throw new RemoteImageException('not_an_image', 'The media URL does not point to a PNG, GIF or JPEG image.');
    }

    $detected = self::IMAGE_TYPES[$type];
    $allowed = array_map('strtolower', $allowedExtensions);
    $accepted = $detected['extension'] === 'jpg'
      ? array_intersect(['jpg', 'jpeg'], $allowed) !== []
      : in_array($detected['extension'], $allowed, TRUE);
    if (!$accepted) {
      throw new RemoteImageException('extension', 'The media file type is not allowed for request images.');
    }

    return ['data' => $data] + $detected;
  }

}
