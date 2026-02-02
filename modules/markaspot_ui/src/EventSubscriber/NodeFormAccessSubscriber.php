<?php

namespace Drupal\markaspot_ui\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Event subscriber to block anonymous users from accessing node/add forms.
 *
 * This subscriber prevents anonymous users from accessing the Drupal UI
 * for creating content via node/add routes, while still allowing them
 * to create content via JSON:API endpoints.
 */
class NodeFormAccessSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * Constructs a NodeFormAccessSubscriber object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(AccountInterface $current_user, RouteMatchInterface $route_match) {
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run early in the request cycle, but after routing.
    $events[KernelEvents::REQUEST][] = ['onRequest', 28];
    return $events;
  }

  /**
   * Blocks anonymous users from accessing node/add routes.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    // Only process main requests, not subrequests.
    if (!$event->isMainRequest()) {
      return;
    }

    // Allow authenticated users full access.
    if ($this->currentUser->isAuthenticated()) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();

    // Block access to node add forms for anonymous users.
    // Routes to block:
    // - node.add_page: /node/add (lists all content types)
    // - node.add: /node/add/{node_type} (specific content type form)
    if ($route_name === 'node.add_page' || $route_name === 'node.add') {
      throw new AccessDeniedHttpException('Anonymous users cannot access content creation forms. Please log in.');
    }
  }

}
