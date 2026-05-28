<?php

namespace Drupal\markaspot_media\EventSubscriber;

use Drupal\markaspot_media\Service\RequestImageUploadStorageGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prepares private storage before JSON:API file uploads.
 */
class RequestImageUploadStorageSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a request-image upload storage subscriber.
   */
  public function __construct(
    protected RequestImageUploadStorageGuard $storageGuard,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Runs after routing has populated _route, before the controller receives
    // the upload stream and tries to move it into private://.
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 20],
    ];
  }

  /**
   * Ensures private S3 storage is ready for JSON:API uploads.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if ($request->getMethod() !== 'POST') {
      return;
    }

    if (!$this->isJsonApiFileUploadRoute((string) $request->attributes->get('_route', ''))) {
      return;
    }

    $this->storageGuard->ensureReady();
  }

  /**
   * Checks whether this is a JSON:API file upload route.
   */
  protected function isJsonApiFileUploadRoute(string $route): bool {
    return str_starts_with($route, 'jsonapi.')
      && str_contains($route, '.file_upload.')
      && str_contains($route, 'request_image')
      && str_contains($route, 'field_media_image');
  }

}
