<?php

declare(strict_types=1);

namespace Drupal\markaspot_notification\EventSubscriber;

use Drupal\markaspot_notification\NotificationCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Seeds the NotificationCollector with the current node UUID on each request.
 *
 * Runs at priority 100 (before JSON:API processing) on the main request only.
 * Matches JSON:API paths of the form:
 *   /jsonapi/node/service_request/{uuid}
 * and extracts the UUID so downstream recorders can attribute their events to
 * the correct node without needing access to the route match.
 */
class RequestContextSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a RequestContextSubscriber.
   *
   * @param \Drupal\markaspot_notification\NotificationCollector $collector
   *   The notification collector service.
   */
  public function __construct(
    private readonly NotificationCollector $collector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [['onRequest', 100]],
    ];
  }

  /**
   * Parses the node UUID from JSON:API service_request paths.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The kernel request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if ($event->getRequest()->getMethod() !== 'PATCH') {
      return;
    }
    $path = $event->getRequest()->getPathInfo();
    if (preg_match('|^/jsonapi/node/service_request/([0-9a-f-]{36})(?:[/?].*)?$|', $path, $m)) {
      $this->collector->setCurrentNodeUuid($m[1]);
    }
  }

}
