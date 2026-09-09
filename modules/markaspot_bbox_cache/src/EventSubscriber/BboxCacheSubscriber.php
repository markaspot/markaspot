<?php

namespace Drupal\markaspot_bbox_cache\EventSubscriber;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber for bbox caching.
 */
class BboxCacheSubscriber implements EventSubscriberInterface {

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The start time for measuring execution.
   *
   * @var float
   */
  protected float $startTime;

  /**
   * Constructs a BboxCacheSubscriber object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(CacheBackendInterface $cache, ConfigFactoryInterface $config_factory) {
    $this->cache = $cache;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 100],
      KernelEvents::RESPONSE => ['onResponse', -100],
    ];
  }

  /**
   * Handles the request event to check for cached responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    $this->startTime = microtime(TRUE);

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Only handle georeport requests endpoints.
    if (!preg_match('@^/georeport/v2/requests(?:\.json|\.xml)?$@', $path)) {
      return;
    }

    // Only cache GET requests.
    if ($request->getMethod() !== 'GET' || $this->isCredentialedRequest($request)) {
      return;
    }

    $query_params = $request->query->all();

    // Skip caching if debug parameter is present.
    if (isset($query_params['debug'])) {
      return;
    }

    // Only cache bbox requests.
    if (!isset($query_params['bbox'])) {
      return;
    }

    $config = $this->configFactory->get('markaspot_bbox_cache.settings');
    $cache_by_zoom = $config->get('cache_by_zoom') ?? FALSE;
    // Only non-semantic parameters may be omitted from a shared cache key.
    $exclude_params = array_intersect($config->get('exclude_params') ?? [], ['timestamp', '_']);

    // Build cache key from query parameters.
    $cache_key_params = $query_params;

    // Remove excluded parameters from cache key.
    foreach ($exclude_params as $param) {
      unset($cache_key_params[$param]);
    }

    // Handle zoom-based caching.
    if ($cache_by_zoom && isset($cache_key_params['zoom'])) {
      $zoom_level = (int) $cache_key_params['zoom'];
      // Group zoom levels for better cache hits.
      $cache_key_params['zoom_group'] = $this->getZoomGroup($zoom_level);
      unset($cache_key_params['zoom']);
    }

    ksort($cache_key_params);
    $cache_key = 'bbox_request:v2:' . md5(serialize([
      $request->getSchemeAndHttpHost(), $path,
      $request->headers->get('Accept'), $request->headers->get('Accept-Language'),
      $cache_key_params,
    ]));

    // Try to get from cache.
    $cached = $this->cache->get($cache_key);

    if ($cached && $cached->valid) {
      $response = new Response();
      $response->setContent($cached->data['content']);
      $response->headers->replace($cached->data['headers']);
      $response->headers->set('X-Bbox-Cache', 'HIT');
      $response->headers->set('X-Cache-Key', $cache_key);

      $execution_time = round((microtime(TRUE) - $this->startTime) * 1000, 2);
      $response->headers->set('X-API-Execution-Time', $execution_time . 'ms');

      $event->setResponse($response);
    }
  }

  /**
   * Handles the response event to cache successful responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $response = $event->getResponse();
    $path = $request->getPathInfo();

    // Only handle georeport requests endpoints.
    if (!preg_match('@^/georeport/v2/requests(?:\.json|\.xml)?$@', $path)) {
      return;
    }

    // Only cache GET requests.
    if ($request->getMethod() !== 'GET' || $this->isCredentialedRequest($request)) {
      return;
    }

    $query_params = $request->query->all();

    // Skip caching if debug parameter is present or no bbox.
    if (isset($query_params['debug']) || !isset($query_params['bbox'])) {
      return;
    }

    // Skip if already cached (has our cache header)
    if ($response->headers->has('X-Bbox-Cache')) {
      return;
    }

    // Only cache successful responses.
    if ($response->getStatusCode() !== 200
      || !$response->isCacheable()
      || ($response instanceof CacheableResponseInterface && $response->getCacheableMetadata()->getCacheMaxAge() === 0)
      || $response->headers->has('Set-Cookie')) {
      return;
    }

    $config = $this->configFactory->get('markaspot_bbox_cache.settings');
    $cache_time = $config->get('cache_time') ?? 180;
    if ($response->getMaxAge() !== NULL) {
      $cache_time = min($cache_time, $response->getMaxAge());
    }
    if ($response instanceof CacheableResponseInterface && $response->getCacheableMetadata()->getCacheMaxAge() >= 0) {
      $cache_time = min($cache_time, $response->getCacheableMetadata()->getCacheMaxAge());
    }
    $cache_by_zoom = $config->get('cache_by_zoom') ?? FALSE;
    // Only non-semantic parameters may be omitted from a shared cache key.
    $exclude_params = array_intersect($config->get('exclude_params') ?? [], ['timestamp', '_']);

    // Build cache key from query parameters.
    $cache_key_params = $query_params;

    // Remove excluded parameters from cache key.
    foreach ($exclude_params as $param) {
      unset($cache_key_params[$param]);
    }

    // Handle zoom-based caching.
    if ($cache_by_zoom && isset($cache_key_params['zoom'])) {
      $zoom_level = (int) $cache_key_params['zoom'];
      // Group zoom levels for better cache hits.
      $cache_key_params['zoom_group'] = $this->getZoomGroup($zoom_level);
      unset($cache_key_params['zoom']);
    }

    ksort($cache_key_params);
    $cache_key = 'bbox_request:v2:' . md5(serialize([
      $request->getSchemeAndHttpHost(), $path,
      $request->headers->get('Accept'), $request->headers->get('Accept-Language'),
      $cache_key_params,
    ]));

    $cache_data = [
      'content' => $response->getContent(),
      'headers' => $response->headers->all(),
    ];

    $cache_tags = [
      'markaspot_bbox_cache',
      'node_list:service_request',
      'group_list',
    ];
    if ($response instanceof CacheableResponseInterface) {
      $cache_tags = array_unique(array_merge($cache_tags, $response->getCacheableMetadata()->getCacheTags()));
    }

    // Add category-specific cache tags if category parameter exists.
    if (isset($query_params['service_code'])) {
      $cache_tags[] = 'taxonomy_term_list:service_category';
      $cache_tags[] = 'service_code:' . $query_params['service_code'];
    }

    $this->cache->set(
      $cache_key,
      $cache_data,
      time() + $cache_time,
      $cache_tags
    );

    $response->headers->set('X-Bbox-Cache', 'MISS');
    $response->headers->set('X-Cache-Key', $cache_key);

    // Add cache control headers.
    $response->setMaxAge($cache_time);
    $response->setSharedMaxAge($cache_time);
    $response->headers->set('Cache-Control', 'public, max-age=' . $cache_time);

    $execution_time = round((microtime(TRUE) - $this->startTime) * 1000, 2);
    $response->headers->set('X-API-Execution-Time', $execution_time . 'ms');
  }

  /**
   * Rejects credentials before the authentication listeners have run.
   */
  protected function isCredentialedRequest(Request $request): bool {
    $settings = $this->configFactory->get('services_api_key_auth.settings');
    $header = $settings->get('api_key_request_header_name');
    $query = $settings->get('api_key_get_parameter_name');
    $body = $settings->get('api_key_post_parameter_name');
    foreach (['authorization', 'cookie', 'api_key', 'api-key', 'apikey', 'x-api-key'] as $name) {
      if ($request->headers->has($name)) {
        return TRUE;
      }
    }
    return $request->cookies->count() > 0
      || $request->query->has('api_key')
      || $request->request->has('api_key')
      || $request->server->has('HTTP_API_KEY')
      || ($header && $request->headers->has($header))
      || ($query && $request->query->has($query))
      || ($body && $request->request->has($body));
  }

  /**
   * Groups zoom levels for better cache efficiency.
   *
   * @param int $zoom
   *   The zoom level.
   *
   * @return int
   *   The zoom group.
   */
  protected function getZoomGroup(int $zoom): int {
    // Group zoom levels to reduce cache fragmentation.
    if ($zoom <= 10) {
      // City/region level.
      return 1;
    }
    elseif ($zoom <= 15) {
      // District level.
      return 2;
    }
    else {
      // Street level.
      return 3;
    }
  }

}
