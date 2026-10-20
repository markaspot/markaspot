<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\DemoStaffAccount;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Enforces the session and path rules of demo staff accounts.
 *
 * A session that the demo endpoint did not start, or that is older than
 * DemoStaffAccount::MAX_SESSION_AGE, is logged out; the rest of the request
 * is anonymous. A valid demo session gets 403 on the denied paths
 * (DemoStaffAccount::isDeniedPath()). Runs right after authentication;
 * regular accounts cost one name comparison.
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
    // AuthenticationSubscriber sets the current user at priority 300, the
    // router matches at 32 and route access is checked at 30.
    return [
      KernelEvents::REQUEST => [
        ['onRequest', 299],
        ['onRoutedRequest', 31],
      ],
    ];
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
      if (DemoStaffAccount::isDeniedPath($request->getPathInfo(), $request->getMethod())) {
        throw new AccessDeniedHttpException('Demo staff accounts cannot use this function.');
      }
      return;
    }
    $this->logger->notice('Demo session of @name ended: not started by the demo endpoint or older than @max seconds.', [
      '@name' => $this->currentUser->getAccountName(),
      '@max' => DemoStaffAccount::MAX_SESSION_AGE,
    ]);
    user_logout();
  }

  /**
   * Denies a demo session the matched route of a denied path.
   *
   * The request path can carry a language prefix ("/de/jsonapi/user/user")
   * or another spelling; the route path ("/jsonapi/user/user") cannot.
   */
  public function onRoutedRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->currentUser->isAuthenticated() || !$this->staffAccount->isDemoAccount($this->currentUser)) {
      return;
    }
    $request = $event->getRequest();
    $route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    if ($route instanceof Route && DemoStaffAccount::isDeniedPath($route->getPath(), $request->getMethod())) {
      throw new AccessDeniedHttpException('Demo staff accounts cannot use this function.');
    }
  }

}
