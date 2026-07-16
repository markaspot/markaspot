<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Validates public-form HTTP URLs used in browser and mail output.
 *
 * This is a syntactic output guard. It deliberately does not resolve DNS and
 * must not be used as an SSRF boundary for server-side requests.
 */
final class PublicUrlValidator {

  /**
   * IP ranges that must never be rendered as publicly reachable targets.
   */
  private const NON_PUBLIC_CIDRS = [
    '0.0.0.0/8',
    '10.0.0.0/8',
    '100.64.0.0/10',
    '127.0.0.0/8',
    '169.254.0.0/16',
    '172.16.0.0/12',
    '192.0.0.0/24',
    '192.0.2.0/24',
    '192.88.99.0/24',
    '192.168.0.0/16',
    '198.18.0.0/15',
    '198.51.100.0/24',
    '203.0.113.0/24',
    '224.0.0.0/4',
    '240.0.0.0/4',
    '::/128',
    '::1/128',
    '64:ff9b::/96',
    '64:ff9b:1::/48',
    '100::/64',
    '2001:2::/48',
    '2001:10::/28',
    '2001:20::/28',
    '3fff::/20',
    'fc00::/7',
    'fe80::/10',
    'fec0::/10',
    '2001:db8::/32',
    '2002::/16',
    'ff00::/8',
  ];

  /**
   * DNS suffixes reserved for local or service-discovery use.
   */
  private const INTERNAL_HOST_SUFFIXES = [
    '.internal',
    '.invalid',
    '.arpa',
    '.lan',
    '.local',
    '.localdomain',
    '.localhost',
    '.home.arpa',
    '.example',
    '.onion',
    '.svc',
    '.test',
  ];

  /**
   * Returns whether a URL is a public HTTP(S) target.
   */
  public function isPublicHttpUrl(string $url): bool {
    $url = trim(str_replace(["\r", "\n", "\0"], '', $url));
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
      return FALSE;
    }

    $parts = parse_url($url);
    if (!is_array($parts)
      || empty($parts['scheme'])
      || empty($parts['host'])
      || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], TRUE)
      || isset($parts['user'])
      || isset($parts['pass'])
    ) {
      return FALSE;
    }

    return $this->isPublicHost((string) $parts['host']);
  }

  /**
   * Normalizes a public HTTP(S) base URL without query or fragment.
   */
  public function normalizeBaseUrl(string $url): ?string {
    $url = rtrim(trim(str_replace(["\r", "\n", "\0"], '', $url)), '/');
    if (!$this->isPublicHttpUrl($url)) {
      return NULL;
    }

    $parts = parse_url($url);
    if (!is_array($parts)
      || isset($parts['query'])
      || isset($parts['fragment'])
      || (isset($parts['path']) && preg_match('/[\x00-\x20"\'<>`]/', (string) $parts['path']) === 1)
    ) {
      return NULL;
    }

    return $url;
  }

  /**
   * Normalizes a public HTTP(S) origin without a deployment path.
   */
  public function normalizeOrigin(string $url): ?string {
    $url = $this->normalizeBaseUrl($url);
    if ($url === NULL) {
      return NULL;
    }

    $parts = parse_url($url);
    if (!is_array($parts)
      || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
    ) {
      return NULL;
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = (string) $parts['host'];
    if (str_contains($host, ':')) {
      $host = '[' . trim($host, '[]') . ']';
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $scheme . '://' . $host . $port;
  }

  /**
   * Returns whether a host is suitable for a public URL.
   */
  public function isPublicHost(string $host): bool {
    $host = rtrim(strtolower(trim($host, "[] \t\n\r\0\x0B")), '.');
    if ($host === '' || $host === 'localhost') {
      return FALSE;
    }

    foreach (self::INTERNAL_HOST_SUFFIXES as $suffix) {
      if (str_ends_with($host, $suffix)) {
        return FALSE;
      }
    }

    // IPv4-mapped IPv6 literals are not valid public deployment origins and
    // can otherwise bypass IPv4 CIDR checks through alternate encodings.
    if (str_starts_with($host, '::ffff:')) {
      return FALSE;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return !IpUtils::checkIp($host, self::NON_PUBLIC_CIDRS);
    }

    if (!str_contains($host, '.')) {
      return FALSE;
    }

    // Reject alternative numeric IPv4 forms such as 127.1, 0177.0.0.1,
    // hexadecimal segments and single-integer representations.
    if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host)) {
      return FALSE;
    }

    return preg_match(
      '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
      $host,
    ) === 1;
  }

  /**
   * Rewrites internal generated URLs onto a public base or to a safe path.
   */
  public function rebaseGeneratedUrl(string $url, string $publicBase): string {
    $candidate = str_starts_with($url, '//') ? 'https:' . $url : $url;
    if ($this->isPublicHttpUrl($candidate)) {
      return $candidate;
    }

    return $this->forceRebaseUrl($candidate, $publicBase);
  }

  /**
   * Rewrites a generated URL onto the configured public origin.
   */
  private function forceRebaseUrl(string $url, string $publicBase): string {
    $candidate = str_starts_with($url, '//') ? 'https:' . $url : $url;

    $parts = parse_url($candidate);
    $path = is_array($parts) && isset($parts['path'])
      ? (string) $parts['path']
      : '/';
    $path = '/' . ltrim($path, '/');
    if (is_array($parts) && isset($parts['query'])) {
      $path .= '?' . $parts['query'];
    }
    if (is_array($parts) && isset($parts['fragment'])) {
      $path .= '#' . $parts['fragment'];
    }

    $base = $this->normalizeOrigin($publicBase);
    return $base !== NULL ? $base . $path : $path;
  }

  /**
   * Rewrites non-public absolute URLs in rendered mail or token output.
   *
   * Request-derived local hosts may be supplied so a poisoned but
   * public-looking Host header is replaced instead of preserved.
   */
  public function rewriteNonPublicHttpUrls(string $value, string $publicBase, array $localHosts = []): string {
    $localHosts = array_map(
      static fn(string $host): string => rtrim(strtolower(trim($host, '[]')), '.'),
      $localHosts,
    );

    return (string) preg_replace_callback(
      '~(?:(?<![a-z0-9+.-])https?://|(?<![a-z0-9:])//)[^\s<>"\']+~i',
      function (array $matches) use ($publicBase, $localHosts): string {
        $url = $matches[0];
        $candidate = str_starts_with($url, '//') ? 'https:' . $url : $url;
        $host = parse_url($candidate, PHP_URL_HOST);
        $normalizedHost = is_string($host)
          ? rtrim(strtolower(trim($host, '[]')), '.')
          : '';
        if ($normalizedHost !== '' && in_array($normalizedHost, $localHosts, TRUE)) {
          return $this->forceRebaseUrl($candidate, $publicBase);
        }
        return $this->rebaseGeneratedUrl($url, $publicBase);
      },
      $value,
    );
  }

  /**
   * Returns a URL authority suitable for logs without path or credentials.
   */
  public function redactForLog(string $url): string {
    $candidate = str_starts_with($url, '//') ? 'https:' . $url : $url;
    $parts = parse_url($candidate);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return '[invalid URL]';
    }

    $host = (string) $parts['host'];
    if (str_contains($host, ':')) {
      $host = '[' . trim($host, '[]') . ']';
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return strtolower((string) $parts['scheme']) . '://' . $host . $port . '/[redacted]';
  }

}
