<?php

namespace Drupal\markaspot_open311\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 *
 */
class RequestFormatSubscriber implements EventSubscriberInterface {

  /**
   *
   */
  public static function getSubscribedEvents() {
    // The priority must be high enough to run before the routing listener.
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 100],
    ];
  }

  /**
   *
   */
  public function onKernelRequest(RequestEvent $event) {
    $request = $event->getRequest();
    $pathInfo = $request->getPathInfo();

    // Check if the path corresponds to the georeport v2 standard.
    if (preg_match('/\/georeport\/v2\/.*\.(json|xml)$/', $pathInfo, $matches)) {
      // Set the request format based on the suffix.
      $request->setRequestFormat($matches[1]);
    }
  }

}
