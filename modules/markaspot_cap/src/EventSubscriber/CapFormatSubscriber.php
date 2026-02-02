<?php

namespace Drupal\markaspot_cap\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Subscribes to kernel events to handle CAP format requests.
 */
class CapFormatSubscriber implements EventSubscriberInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Priority 99 to run early, before routing.
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 99],
    ];
  }

  /**
   * Sets the request format based on URL path.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException
   *   Thrown if emergency mode is not active.
   */
  public function onKernelRequest(RequestEvent $event) {
    $request = $event->getRequest();
    $pathInfo = $request->getPathInfo();

    // Check if the path is a CAP API endpoint.
    if (preg_match('#^/api/cap/v1/alerts#', $pathInfo)) {
      // Check if emergency mode is active.
      $emergencyConfig = $this->configFactory->get('markaspot_emergency.settings');
      $emergencyStatus = $emergencyConfig->get('emergency_mode.status');

      if ($emergencyStatus !== 'active') {
        throw new ServiceUnavailableHttpException(
          NULL,
          'CAP export is only available when emergency mode is active.'
        );
      }

      // Set the request format to 'cap'.
      $request->setRequestFormat('cap');
    }
  }

}
