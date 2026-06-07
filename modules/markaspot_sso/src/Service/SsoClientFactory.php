<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Settings;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds OneLogin PHP-SAML settings from Drupal configuration.
 */
final class SsoClientFactory {

  /**
   * Constructs a SSO settings builder.
   */
  public function __construct(
    private readonly SsoProviderManager $providerManager,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * Builds an Auth instance for the provider.
   */
  public function auth(string $provider_id, bool $sp_validation_only = FALSE): Auth {
    return new Auth($this->settingsArray($provider_id), $sp_validation_only);
  }

  /**
   * Builds a Settings instance for the provider.
   */
  public function settings(string $provider_id, bool $sp_validation_only = FALSE): Settings {
    return new Settings($this->settingsArray($provider_id), $sp_validation_only);
  }

  /**
   * Builds a PHP-SAML settings array.
   *
   * @return array<string, mixed>
   *   Settings accepted by OneLogin\Saml2\Auth.
   */
  public function settingsArray(string $provider_id): array {
    $provider = $this->providerManager->provider($provider_id);
    $acs_url = $this->value($provider, 'sp_acs_url')
        ?: $this->absoluteUrl("/auth/sso/$provider_id/acs");
    $entity_id = $this->value($provider, 'sp_entity_id')
        ?: $this->absoluteUrl("/auth/sso/$provider_id/metadata");
    $security = is_array($provider['security'] ?? NULL) ? $provider['security'] : [];
    $name_id_format = $this->value($provider, 'name_id_format')
        ?: 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent';

    return [
      'strict' => TRUE,
      'debug' => FALSE,
      'baseurl' => $this->absoluteUrl('/'),
      'sp' => [
        'entityId' => $entity_id,
        'assertionConsumerService' => [
          'url' => $acs_url,
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
        'NameIDFormat' => $name_id_format,
        'x509cert' => $this->normalizePem($this->value($provider, 'sp_x509_cert')),
        'privateKey' => $this->spPrivateKey($provider, $provider_id),
      ],
      'idp' => [
        'entityId' => $this->value($provider, 'idp_entity_id'),
        'singleSignOnService' => [
          'url' => $this->value($provider, 'idp_sso_url'),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'x509cert' => $this->normalizePem($this->value($provider, 'idp_x509_cert')),
      ],
      'security' => [
        'authnRequestsSigned' => (bool) ($security['authn_requests_signed'] ?? TRUE),
        'wantAssertionsSigned' => (bool) ($security['want_assertions_signed'] ?? TRUE),
        'wantMessagesSigned' => (bool) ($security['want_messages_signed'] ?? FALSE),
        'wantNameId' => TRUE,
        'wantNameIdEncrypted' => FALSE,
        'requestedAuthnContext' => FALSE,
        'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256,
        'digestAlgorithm' => XMLSecurityDSig::SHA256,
      ],
    ];
  }

  /**
   * Reads a scalar provider setting.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $key
   *   Provider setting key.
   */
  private function value(array $provider, string $key): string {
    $value = $provider[$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Normalizes PEM text configured through Drupal forms or environment import.
   */
  private function normalizePem(string $value): string {
    return str_replace('\n', "\n", trim($value));
  }

  /**
   * Loads the SP private key from env or an operator-controlled file path.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $provider_id
   *   Provider machine name.
   */
  private function spPrivateKey(array $provider, string $provider_id): string {
    $env_suffix = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $provider_id) ?? $provider_id);
    $env_name = 'MARKASPOT_SSO_' . $env_suffix . '_SP_PRIVATE_KEY';
    $env_value = getenv($env_name);
    if (is_string($env_value) && trim($env_value) !== '') {
      return $this->normalizePem($env_value);
    }

    $path = $this->value($provider, 'sp_private_key_path');
    if (str_starts_with($path, '$')) {
      $env_path = getenv(substr($path, 1));
      $path = is_string($env_path) ? trim($env_path) : '';
    }
    if ($path === '' || !is_readable($path)) {
      return '';
    }

    $contents = file_get_contents($path);
    return is_string($contents) ? $this->normalizePem($contents) : '';
  }

  /**
   * Builds an absolute URL on the current Drupal host.
   */
  private function absoluteUrl(string $path): string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return $path;
    }

    return rtrim($request->getSchemeAndHttpHost(), '/') . '/' . ltrim($path, '/');
  }

}
