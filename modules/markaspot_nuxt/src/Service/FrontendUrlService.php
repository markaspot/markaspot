<?php

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;

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
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get the frontend base URL.
   *
   * @return string|null
   *   The frontend base URL or NULL if not configured.
   */
  public function getFrontendBaseUrl() {
    $config = $this->configFactory->get('markaspot_nuxt.settings');
    $frontend_enabled = $config->get('frontend_enabled');

    if ($frontend_enabled) {
      $frontend_url = $config->get('frontend_base_url');
      if ($frontend_url) {
        return rtrim($frontend_url, '/');
      }
    }

    // Fallback to environment variable for backward compatibility.
    $frontend_base_url_env = getenv('FRONTEND_BASE_URL');
    if ($frontend_base_url_env) {
      return rtrim($frontend_base_url_env, '/');
    }

    return NULL;
  }

  /**
   * Generate a frontend URL for a specific path.
   *
   * @param string $path
   *   The path to append to the frontend base URL.
   * @param string $fallback_route
   *   Optional Drupal route to use as fallback if no frontend URL is configured.
   * @param array $route_parameters
   *   Optional parameters for the fallback route.
   *
   * @return string
   *   The complete URL.
   */
  public function generateFrontendUrl($path, $fallback_route = NULL, array $route_parameters = []) {
    $frontend_base_url = $this->getFrontendBaseUrl();

    if ($frontend_base_url) {
      return $frontend_base_url . '/' . ltrim($path, '/');
    }

    // Fallback to Drupal route if provided.
    if ($fallback_route) {
      $url = Url::fromRoute($fallback_route, $route_parameters);
      return $url->setAbsolute()->toString();
    }

    // Final fallback to current site base URL + path.
    global $base_url;
    return rtrim($base_url, '/') . '/' . ltrim($path, '/');
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

}
