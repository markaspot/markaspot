<?php

namespace Drupal\markaspot_bbox_cache\EventSubscriber;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
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
    if (!str_contains($path, '/georeport/v2/requests')) {
      return;
    }

    // Only cache GET requests.
    if ($request->getMethod() !== 'GET') {
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
    $cache_time = $config->get('cache_time') ?? 180;
    $cache_by_zoom = $config->get('cache_by_zoom') ?? FALSE;
    $exclude_params = $config->get('exclude_params') ?? [];

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
    $cache_key = 'bbox_request:' . md5(serialize($cache_key_params));

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

      // Set cache control headers.
      $response->setMaxAge($cache_time);
      $response->setSharedMaxAge($cache_time);
      $response->headers->set('Cache-Control', 'public, max-age=' . $cache_time);

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
    if (!str_contains($path, '/georeport/v2/requests')) {
      return;
    }

    // Only cache GET requests.
    if ($request->getMethod() !== 'GET') {
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
    if ($response->getStatusCode() !== 200) {
      return;
    }

    $config = $this->configFactory->get('markaspot_bbox_cache.settings');
    $cache_time = $config->get('cache_time') ?? 180;
    $cache_by_zoom = $config->get('cache_by_zoom') ?? FALSE;
    $exclude_params = $config->get('exclude_params') ?? [];

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
    $cache_key = 'bbox_request:' . md5(serialize($cache_key_params));

    $cache_data = [
      'content' => $response->getContent(),
      'headers' => $response->headers->all(),
    ];

    $cache_tags = [
      'markaspot_bbox_cache',
      'node_list:service_request',
    ];

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
