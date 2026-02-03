<?php

namespace Drupal\markaspot_open311\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds cache headers for GeoReport API responses.
 *
 * Ensures that responses vary by Accept-Language header to prevent
 * serving cached translations for the wrong language.
 */
class GeoreportCacheSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Run after ResourceResponseSubscriber (priority 128).
    $events[KernelEvents::RESPONSE][] = ['onResponse', 100];
    return $events;
  }

  /**
   * Adds Vary header for Accept-Language on GeoReport responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event) {
    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Only apply to GeoReport API endpoints.
    if (strpos($path, '/georeport/') === FALSE) {
      return;
    }

    $response = $event->getResponse();

    // Add Vary header for Accept-Language to ensure HTTP caches
    // serve different responses for different languages.
    $response->setVary(array_merge(
      $response->getVary(),
      ['Accept-Language']
    ));

    // For cacheable responses, add the language cache context.
    if ($response instanceof CacheableResponseInterface) {
      $response->getCacheableMetadata()->addCacheContexts([
        'languages:language_content',
        'url.query_args:langcode',
      ]);
    }

    // Set a short max-age for translated content to ensure freshness.
    // This allows browser caching while keeping translations relatively fresh.
    $response->headers->set('Cache-Control', 'public, max-age=60');
  }

}
