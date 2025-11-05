<?php

namespace Drupal\markaspot_ui\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects users after login based on configuration.
 */
class LoginRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a LoginRedirectSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AccountProxyInterface $current_user, RequestStack $request_stack) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after AuthenticationSubscriber (priority 300) but before RedirectResponseSubscriber (priority 0).
    $events[KernelEvents::RESPONSE][] = ['onResponse', 10];
    return $events;
  }

  /**
   * Redirects user after login if configured.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    // Only process master requests.
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $response = $event->getResponse();

    // Only act on redirects after login.
    if (!$response instanceof RedirectResponse) {
      return;
    }

    // Check if this is a login form submission.
    $route_name = $request->attributes->get('_route');
    if ($route_name !== 'user.login' || $request->getMethod() !== 'POST') {
      return;
    }

    // Check if user is authenticated.
    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    // Check if login redirect is enabled.
    $config = $this->configFactory->get('markaspot_ui.settings');
    if (!$config->get('login_redirect_enabled')) {
      return;
    }

    // Check if this is a password reset login.
    $session = $request->getSession();
    if ($session && $session->has('pass_reset_' . $this->currentUser->id())) {
      // Let Drupal core handle password reset redirect.
      return;
    }

    // Check if there's already a destination parameter (respect existing redirects).
    if ($request->query->has('destination')) {
      return;
    }

    // Get redirect path from config.
    $redirect_path = $config->get('login_redirect_path') ?: '/admin/content/management';

    // Validate that it's an internal path.
    try {
      $url = Url::fromUserInput($redirect_path);
      if ($url->isExternal()) {
        // Log error and skip redirect for security.
        \Drupal::logger('markaspot_ui')->error('Login redirect path is external: @path', ['@path' => $redirect_path]);
        return;
      }

      // Create new redirect response.
      $event->setResponse(new RedirectResponse($url->toString()));
    }
    catch (\Exception $e) {
      // Invalid path - log and skip redirect.
      \Drupal::logger('markaspot_ui')->error('Invalid login redirect path: @path - @error', [
        '@path' => $redirect_path,
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
