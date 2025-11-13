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
    $queryContainsApiKey = strpos($request->getQueryString() ?: '', 'api_key') !== FALSE;
    $headerContainsApiKey = $request->headers->has('apikey');
    $formContainsApiKey = $request->request->has('api_key');

    // Only clear cookies for anonymous API key requests
    // Preserve session cookies for authenticated users (e.g., passwordless auth)
    if ($queryContainsApiKey || $headerContainsApiKey || $formContainsApiKey) {
      // Check session UID directly instead of currentUser() to avoid timing issues
      // currentUser()->isAnonymous() may not be reliable at this point in the request cycle
      $session = $request->getSession();
      $uid = $session->get('uid');

      // Only clear session for anonymous users (uid is 0 or not set)
      if (empty($uid) || $uid == 0) {
        $session_name = \session_name();
        $event->getResponse()->headers->clearCookie($session_name);
        $session->clear();
      }
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
