<?php

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for generating frontend URLs.
 */
class FrontendUrlService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new FrontendUrlService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\markaspot_nuxt\Service\PublicUrlValidator $publicUrlValidator
   *   Validator for browser- and mail-facing public base URLs.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected PublicUrlValidator $publicUrlValidator,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get the frontend base URL.
   *
   * @return string|null
   *   The frontend base URL or NULL if not configured.
   */
  public function getFrontendBaseUrl() {
    return $this->resolveFrontendBaseUrl([
      'FRONTEND_BASE_URL',
      'NUXT_PUBLIC_SITE_URL',
      'NUXT_SITE_URL',
    ]);
  }

  /**
   * Get the frontend base URL for notification and mail links.
   *
   * @return string|null
   *   The frontend base URL or NULL if not configured.
   */
  public function getNotificationFrontendBaseUrl(): ?string {
    // A tenant's public runtime host is authoritative for mail. In particular,
    // do not let an imported or shared frontend config send bearer links to a
    // FastMap container host when this host-specific mail override is set.
    $raw_mail_frontend_base_url = getenv('MARKASPOT_MAIL_FRONTEND_BASE_URL');
    if ($raw_mail_frontend_base_url !== FALSE && trim($raw_mail_frontend_base_url) !== '') {
      // A configured host-specific mail URL is authoritative. Fail closed when
      // it is unsafe instead of falling back to a possibly foreign config URL.
      return $this->normalizeFrontendBaseUrl($raw_mail_frontend_base_url);
    }

    return $this->resolveFrontendBaseUrl([
      'FRONTEND_BASE_URL',
      'NUXT_PUBLIC_SITE_URL',
      'NUXT_SITE_URL',
    ]);
  }

  /**
   * Resolve the configured frontend URL plus selected environment fallbacks.
   *
   * @param string[] $env_names
   *   Environment variable names to try before active config.
   *
   * @return string|null
   *   The normalized frontend base URL or NULL if not configured.
   */
  protected function resolveFrontendBaseUrl(array $env_names): ?string {
    // Host-specific runtime configuration is authoritative. Shared exported
    // config must never redirect browser sessions or bearer links to another
    // environment's frontend.
    foreach ($env_names as $env_name) {
      $raw_env_value = getenv($env_name);
      if ($raw_env_value !== FALSE && trim($raw_env_value) !== '') {
        // A configured but unsafe runtime value is an operational error. Fail
        // closed instead of falling through to a possibly foreign config URL.
        return $this->normalizeFrontendBaseUrl($raw_env_value);
      }
    }

    $config = $this->configFactory->get('markaspot_nuxt.settings');
    $frontend_enabled = $config->get('frontend_enabled');

    if ($frontend_enabled) {
      $frontend_url = $this->normalizeFrontendBaseUrl((string) $config->get('frontend_base_url'));
      if ($frontend_url) {
        return $frontend_url;
      }
    }

    return NULL;
  }

  /**
   * Generate a frontend URL for a specific path.
   *
   * @param string $path
   *   The path to append to the frontend base URL.
   * @param string $fallback_route
   *   Deprecated. Backend URL fallback is intentionally disabled for mail-safe
   *   frontend links.
   * @param array $route_parameters
   *   Optional parameters for the fallback route.
   *
   * @return string
   *   The complete frontend URL, or an empty string when no safe frontend base
   *   URL is configured.
   */
  public function generateFrontendUrl($path, $fallback_route = NULL, array $route_parameters = []) {
    $frontend_base_url = $this->getFrontendBaseUrl();

    if ($frontend_base_url) {
      return $frontend_base_url . '/' . ltrim($path, '/');
    }

    return '';
  }

  /**
   * Generate a confirmation URL for a given UUID.
   *
   * @param string $uuid
   *   The UUID of the service request.
   *
   * @return string
   *   The confirmation URL.
   */
  public function generateConfirmationUrl($uuid) {
    return $this->generateFrontendUrl(
      'confirm/' . $uuid,
      'markaspot_confirm.doConfirm',
      ['uuid' => $uuid]
    );
  }

  /**
   * Check if frontend URL generation is enabled.
   *
   * @return bool
   *   TRUE if frontend URL generation is enabled.
   */
  public function isFrontendEnabled() {
    $config = $this->configFactory->get('markaspot_nuxt.settings');
    return (bool) $config->get('frontend_enabled');
  }

  /**
   * Normalizes a public frontend base URL.
   *
   * @param string $url
   *   Candidate frontend base URL.
   *
   * @return string|null
   *   Normalized URL without trailing slash, or NULL for unsafe/internal URLs.
   */
  public function normalizeFrontendBaseUrl(string $url): ?string {
    $url = $this->publicUrlValidator->normalizeBaseUrl($url);
    if ($url === NULL) {
      return NULL;
    }

    $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
    // Frontend base URLs intentionally require DNS names. Browser-facing
    // tenant links must not depend on a literal public IP address.
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return NULL;
    }

    return $url;
  }

}
