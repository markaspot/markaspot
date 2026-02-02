<?php

namespace Drupal\markaspot_ui\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Event subscriber to redirect anonymous users from Drupal UI paths.
 *
 * This subscriber redirects anonymous users from standard Drupal admin and
 * content creation paths to /user/login, while preserving JSON:API access
 * and other API endpoints required for the headless frontend.
 *
 * This feature is controlled by the 'headless_mode_protection' setting in
 * markaspot_ui.settings configuration. When disabled (default), this subscriber
 * does nothing, allowing traditional Drupal themes to work normally.
 *
 * Key behaviors when enabled:
 * - Redirects anonymous users from admin/content creation paths
 * - Preserves JSON:API endpoints (/jsonapi/*)
 * - Preserves authentication endpoints (/user/login, /user/password, etc.)
 * - Allows authenticated users full access
 * - Uses 302 redirects (temporary) for flexibility
 *
 * Configuration:
 * - Enable/disable at: /admin/config/markaspot/ui
 * - Default: disabled (for backward compatibility)
 */
class AnonymousRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs an AnonymousRedirectSubscriber object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(AccountInterface $current_user, LoggerInterface $logger, ConfigFactoryInterface $config_factory) {
    $this->currentUser = $current_user;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run very early in the request cycle, before access checks.
    // Priority 300 ensures we run before most other subscribers.
    // Core routing runs at priority 32, so we run well before that.
    $events[KernelEvents::REQUEST][] = ['onRequest', 300];
    return $events;
  }

  /**
   * Redirects anonymous users from protected paths to /user/login.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    // Only process main requests, not subrequests.
    if (!$event->isMainRequest()) {
      return;
    }

    // Check if headless mode protection is enabled.
    $config = $this->configFactory->get('markaspot_ui.settings');
    if (!$config->get('headless_mode_protection')) {
      // Feature is disabled, allow all access.
      return;
    }

    // Allow authenticated users full access.
    if ($this->currentUser->isAuthenticated()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Paths that should redirect anonymous users.
    // In a headless setup, all content display happens via the Nuxt frontend.
    // Drupal is only used for API access and admin, so we block all UI paths.
    $redirect_patterns = [
      '/admin',
      '/node',      // All node paths (listing, view, edit, add, etc.)
      '/user/register',
      '/comment',
      '/group',
      '/media',
      '/taxonomy',  // Taxonomy management
    ];

    // Check if path matches any redirect pattern.
    foreach ($redirect_patterns as $pattern) {
      if (str_starts_with($path, $pattern)) {
        // Log as warning for security monitoring.
        $this->logger->warning('Anonymous user attempted to access protected path: @path (IP: @ip, User-Agent: @user_agent)', [
          '@path' => $path,
          '@ip' => $request->getClientIp(),
          '@user_agent' => $request->headers->get('User-Agent'),
        ]);
        $this->redirectToLogin($event, $path);
        return;
      }
    }

    // If we get here, the path didn't match a redirect pattern.
    // All other paths are allowed for anonymous users (JSON:API, assets, etc.).
  }

  /**
   * Redirects to the login page with destination parameter.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   * @param string $original_path
   *   The original path the user attempted to access.
   */
  protected function redirectToLogin(RequestEvent $event, string $original_path): void {
    // Build login URL with destination parameter.
    $login_url = Url::fromRoute('user.login', [], [
      'query' => ['destination' => $original_path],
      'absolute' => TRUE,
    ])->toString();

    // Create redirect response (302 = temporary redirect).
    $response = new RedirectResponse($login_url, 302);
    $event->setResponse($response);
  }

}
