<?php

namespace Drupal\markaspot_open311\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
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
  public static function getSubscribedEvents(): array {
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

    if ($this->isCredentialedRequest($request)) {
      $response->headers->set('Cache-Control', 'private, no-store');
      $response->headers->set('X-Cache-Policy', 'private, no-store');
      $response->setVary(array_merge(
        $response->getVary(),
        ['Authorization', 'Cookie']
      ));
      return;
    }

    // Set a short max-age for translated content to ensure freshness.
    // This allows browser caching while keeping translations relatively fresh.
    $response->headers->set('Cache-Control', 'public, max-age=60');
    $response->headers->set('X-Cache-Policy', 'public, max-age=60');
  }

  /**
   * Detects requests whose response must not be stored by shared caches.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE when credentials are present.
   */
  protected function isCredentialedRequest(Request $request): bool {
    $queryString = $request->getQueryString() ?: '';

    return str_contains($queryString, 'api_key')
      || $request->query->has('api_key')
      || $request->request->has('api_key')
      || $request->headers->has('api_key')
      || $request->headers->has('api-key')
      || $request->headers->has('apikey')
      || $request->headers->has('x-api-key')
      || $request->headers->has('authorization')
      || $request->headers->has('cookie')
      || $request->server->has('HTTP_API_KEY');
  }

}
