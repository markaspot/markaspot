<?php

namespace Drupal\markaspot_open311\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Open311 Server event subscriber.
 */
class RemoveCookieSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * Sets extra headers on any responses, also subrequest ones.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function onRespond(ResponseEvent $event) {
    $request = $event->getRequest();
    $queryContainsApiKey = strpos($request->getQueryString() ?: '', 'api_key') !== false;
    $headerContainsApiKey = $request->headers->has('apikey');
    $formContainsApiKey = $request->request->has('api_key');
    if ($queryContainsApiKey || $headerContainsApiKey || $formContainsApiKey) {
      $session = \session_name();
      $event->getResponse()->headers->clearCookie($session);
      $event->getRequest()->getSession()->clear();
      // Optionally add a log message or some other form of notification.
    }
  }


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Priority 2, so that it runs before FinishResponseSubscriber, which will
    // expose the cacheability metadata in the form of headers.
    $events[KernelEvents::RESPONSE][] = ['onRespond', 2];
    return $events;
  }

}
