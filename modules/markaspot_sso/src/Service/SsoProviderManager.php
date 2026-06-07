<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads and validates configured SSO providers.
 */
final class SsoProviderManager {
  private const DEV_ENVIRONMENTS = ['dev', 'local', 'ddev', 'development'];

  /**
   * Constructs the provider manager.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Returns a configured provider.
   *
   * @return array<string, mixed>
   *   Provider configuration.
   */
  public function provider(string $provider_id): array {
    $this->assertValidProviderId($provider_id);

    $provider = $this->configFactory
      ->get('markaspot_sso.settings')
      ->get("providers.$provider_id");
    if (!is_array($provider)) {
      throw new NotFoundHttpException(sprintf('Unknown SSO provider "%s".', $provider_id));
    }

    return $provider;
  }

  /**
   * Returns an enabled provider.
   *
   * @return array<string, mixed>
   *   Provider configuration.
   */
  public function enabledProvider(string $provider_id): array {
    $provider = $this->provider($provider_id);
    if (empty($provider['enabled'])) {
      throw new AccessDeniedHttpException('SSO provider is disabled.');
    }
    return $provider;
  }

  /**
   * Returns frontend-safe enabled providers for a jurisdiction.
   *
   * @return array<int, array{id: string, label: string}>
   *   Provider IDs and labels only.
   */
  public function providersForJurisdiction(int $jurisdiction_id): array {
    if ($jurisdiction_id <= 0) {
      return [];
    }

    $providers = $this->configFactory
      ->get('markaspot_sso.settings')
      ->get('providers');
    if (!is_array($providers)) {
      return [];
    }

    $result = [];
    foreach ($providers as $provider_id => $provider) {
      if (!is_string($provider_id) || !is_array($provider) || empty($provider['enabled'])) {
        continue;
      }
      if ((int) ($provider['jurisdiction_id'] ?? 0) !== $jurisdiction_id) {
        continue;
      }
      if ($this->isMockProvider($provider) && !$this->mockAllowed()) {
        continue;
      }
      $label = is_scalar($provider['label'] ?? NULL) ? trim((string) $provider['label']) : '';
      $result[] = [
        'id' => $provider_id,
        'label' => $label !== '' ? $label : $provider_id,
      ];
    }

    return $result;
  }

  /**
   * Returns TRUE when this provider is the dev-only mock profile.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  public function isMockProvider(array $provider): bool {
    return in_array($provider['profile'] ?? NULL, ['generic_mock', 'sso_mock'], TRUE)
        || !empty($provider['mock']);
  }

  /**
   * Throws when a mock provider is used outside an explicit dev context.
   */
  public function assertMockAllowed(array $provider): void {
    if (!$this->isMockProvider($provider)) {
      return;
    }
    if ($this->mockAllowed()) {
      return;
    }
    throw new AccessDeniedHttpException('SSO mock providers are disabled in this environment.');
  }

  /**
   * Returns TRUE when dev-only mock providers may run.
   */
  public function mockAllowed(): bool {
    $explicit = getenv('MARKASPOT_SSO_MOCK');
    if (!is_string($explicit) || $explicit === '') {
      return FALSE;
    }
    if (!in_array(strtolower($explicit), ['1', 'true', 'yes'], TRUE)) {
      return FALSE;
    }

    if (getenv('IS_DDEV_PROJECT') === 'true') {
      return TRUE;
    }

    $environment = getenv('MARKASPOT_ENV');

    return is_string($environment)
        && in_array(strtolower($environment), self::DEV_ENVIRONMENTS, TRUE);
  }

  /**
   * Validates provider IDs before they reach config or route consumers.
   */
  private function assertValidProviderId(string $provider_id): void {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $provider_id)) {
      throw new NotFoundHttpException('Invalid SSO provider id.');
    }
  }

}
