<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Validates RelayState targets before redirects.
 */
final class SsoRelayStateValidator {

  /**
   * Constructs the RelayState validator.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * Returns a safe RelayState target or the provider default.
   */
  public function sanitize(string $provider_id, mixed $relay_state): string {
    $provider = $this->provider($provider_id);
    $default = $this->defaultRelayPath($provider);
    if (!is_string($relay_state) || trim($relay_state) === '') {
      return $default;
    }

    $target = trim($relay_state);
    if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target)) {
      return $default;
    }

    if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
      return $target;
    }

    $parts = parse_url($target);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
      return $default;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return $default;
    }

    $host = strtolower((string) $parts['host']);
    if ($scheme !== 'https' && !$this->isLocalHost($host)) {
      return $default;
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : NULL;
    $host_with_port = $port !== NULL ? "$host:$port" : $host;
    $allowed_hosts = $this->allowedHosts($provider);
    if (in_array($host, $allowed_hosts, TRUE) || in_array($host_with_port, $allowed_hosts, TRUE)) {
      return $target;
    }

    return $default;
  }

  /**
   * Allows HTTP RelayState only for local development hosts.
   */
  private function isLocalHost(string $host): bool {
    return $host === 'localhost'
        || $host === '127.0.0.1'
        || $host === '::1'
        || str_ends_with($host, '.ddev.site');
  }

  /**
   * Returns the configured default RelayState path.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function defaultRelayPath(array $provider): string {
    $path = is_string($provider['default_relay_path'] ?? NULL)
        ? trim($provider['default_relay_path'])
        : '/';
    return str_starts_with($path, '/') && !str_starts_with($path, '//') ? $path : '/';
  }

  /**
   * Returns allowed RelayState hosts.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   *
   * @return string[]
   *   Lowercase host names, optionally with ports.
   */
  private function allowedHosts(array $provider): array {
    $hosts = [];
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $hosts[] = strtolower($request->getHost());
      $hosts[] = strtolower($request->getHost() . ':' . $request->getPort());
    }

    foreach (($provider['allowed_relay_hosts'] ?? []) as $host) {
      if (is_scalar($host) && trim((string) $host) !== '') {
        $hosts[] = strtolower(trim((string) $host));
      }
    }

    return array_values(array_unique($hosts));
  }

  /**
   * Returns a provider configuration.
   *
   * @return array<string, mixed>
   *   Provider configuration.
   */
  private function provider(string $provider_id): array {
    $provider = $this->configFactory
      ->get('markaspot_sso.settings')
      ->get("providers.$provider_id");
    return is_array($provider) ? $provider : [];
  }

}
