<?php

namespace Drupal\markaspot_open311\EventSubscriber;

use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpFoundation\Response;
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

      if ($exceptionCode == 400 && count($exception->getViolations())) {
        $errors = [];
        $violations = $exception->getViolations();

        for ($i = 0; $i < $violations->count() ; $i++) {
          $violation = $violations->get($i);
          switch ($violation->getPropertyPath()) {
            case 'field_category':
              $error['code'] = '103 - service_code';
              break;
            case 'field_category.0.target_id':
              $error['code'] = '104 - service_code not valid';
              break;
            case 'field_status.0.target_id':
              $error['code'] = '105 - Status not valid';
              break;
            case 'field_organisation.0.target_id':
              $error['code'] = '106 - Organisation not valid';
              break;
            default:
              $error['code'] = '400 - Bad Request';
          }

          $error['description'] = strip_tags($violation->getMessage());
          $errors[] = $error;
        }
      } else {
        $errors['error']['code'] = $exceptionCode;
        $errors['error']['description'] = $exception->getMessage();
      }

      $request_format = pathinfo($current_path, PATHINFO_EXTENSION);
      $request_format = isset($request_format) ? $request_format : 'html';

      if ($request_format == 'json' || $request_format == 'xml') {
        $content = \Drupal::service('serializer')
          ->serialize($errors, $request_format);


      }





      // Create response, set status code etc.
      $status_code = ($exceptionCode == 0) ? 500 : $exceptionCode;
      // $response = new ResourceResponse($errors, $status_code);
      $response = new Response($content, $status_code);
      $response->headers->set('Content-Type', 'application/' . $request_format);

      // $response->setContent($content);

      $event->setResponse($response);
    }
  }

}
