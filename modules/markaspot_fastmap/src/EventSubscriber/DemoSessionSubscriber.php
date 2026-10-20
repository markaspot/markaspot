<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ends demo staff sessions that are not valid (any more).
 *
 * Valid means started by the demo endpoint and younger than
 * DemoStaffAccount::MAX_SESSION_AGE. Runs right after authentication, so
 * the rest of the request is anonymous. Regular accounts cost one name
 * comparison.
 */
final class DemoSessionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly DemoStaffAccount $staffAccount,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // AuthenticationSubscriber sets the current user at priority 300.
    return [KernelEvents::REQUEST => ['onRequest', 299]];
  }

  /**
   * Logs out a demo account whose session is not valid.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->currentUser->isAuthenticated()) {
      return;
    }
    $request = $event->getRequest();
    if (!$request->hasSession() || !$this->staffAccount->isDemoAccount($this->currentUser)) {
      return;
    }
    if (!$this->staffAccount->isSessionExpired($this->currentUser, $request->getSession())) {
      return;
    }
    $this->logger->notice('Demo session of @name ended: not started by the demo endpoint or older than @max seconds.', [
      '@name' => $this->currentUser->getAccountName(),
      '@max' => DemoStaffAccount::MAX_SESSION_AGE,
    ]);
    user_logout();
  }

}
