<?php

namespace Drupal\markaspot_open311\EventSubscriber;

use Drupal\rest\ResourceResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class GeoreportException.
 *
 * @package Drupal\markaspot_open311\EventSubscriber
 */
class GeoreportException implements EventSubscriberInterface {

  private $request;

  /**
   *
   */
  public function __construct() {
    $this->request = \Drupal::request();
  }

  /**
   * {@inheritdoc}
   */
  static public function getSubscribedEvents() {
    $events[KernelEvents::EXCEPTION][] = array('onException');
    return $events;
  }

  /**
   * Reacting on Exception.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event
   */
  public function onException(GetResponseForExceptionEvent $event) {

    $exception = $event->getException();
    $current_path = \Drupal::service('path.current')->getPath();

    if (strstr($current_path, 'georeport')) {

      $exceptionCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : $exception->getCode();

      $data = array(
        'error' => array(
          'code' => $exceptionCode,
          'message' => $exception->getMessage(),
        ),
      );

      $request_format = pathinfo($current_path, PATHINFO_EXTENSION);
      $request_format = isset($request_format) ? $request_format : 'html';

      if ($request_format == 'json' || $request_format == 'xml') {
        $content = \Drupal::service('serializer')
          ->serialize($data, $request_format);
      }
      else {
        $content = $exception->getMessage();
      }

      // Create response, set status code etc.
      $status_code = ($exceptionCode == 0) ? 500 : $exceptionCode;
      $response = new ResourceResponse($content, $status_code);

      $response->setContent($content);

      $event->setResponse($response);
    }
  }

}
