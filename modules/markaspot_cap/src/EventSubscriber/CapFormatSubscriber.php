<?php

namespace Drupal\markaspot_cap\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Sets the request format to 'cap' for matched CAP routes.
 *
 * The access gate (emergency-active check) lives in EmergencyActiveAccessCheck.
 * This subscriber only sets the format so the CapEncoder is invoked by the
 * serializer. It matches on the '_route' request attribute, which Drupal sets
 * after routing -- so percent-encoded paths like '/api/c%61p/v1/alerts' are
 * already decoded and routed before we run (priority < 32 keeps us after the
 * router subscriber at priority 32).
 */
class CapFormatSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 28],
    ];
  }

  /**
   * Sets 'cap' as the request format for CAP alert routes.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $routeName = $request->attributes->get('_route', '');

    if (str_starts_with($routeName, 'markaspot_cap.')) {
      $request->setRequestFormat('cap');
    }
  }

}
