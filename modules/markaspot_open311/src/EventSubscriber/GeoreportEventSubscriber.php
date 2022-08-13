<?php

namespace Drupal\markaspot_open311\EventSubscriber;

use Drupal\Core\Path\CurrentPathStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Class GeoreportEventSubscriber.
 *
 * Overrides Exception with parsable error descriptions.
 *
 * @package Drupal\markaspot_open311\EventSubscriber
 */
class GeoreportEventSubscriber implements EventSubscriberInterface {

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $currentPath;

  /**
   * Constructs a ResourceResponseSubscriber object.
   *
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path service.
   */
  public function __construct(SerializerInterface $serializer, CurrentPathStack $current_path) {
    $this->serializer = $serializer;
    $this->currentPath = $current_path;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::EXCEPTION][] = ['onException'];
    return $events;
  }

  /**
   * Reacting on Exception.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The exception event.
   */
  public function onException(ExceptionEvent $event) {

    $exception = $event->getThrowable();
    $current_path = $this->currentPath->getPath();

    if (strstr($current_path, 'georeport')) {

      $exceptionCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : $exception->getCode();

      if ($exceptionCode == 400 && is_countable($exception->getViolations())) {
        $errors = [];
        $violations = $exception->getViolations();

        for ($i = 0; $i < $violations->count(); $i++) {
          $violation = $violations->get($i);
          $geoReportErrorCode = $violation->getConstraint()->geoReportErrorCode ?? NULL;
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
              $error['code'] = $geoReportErrorCode ?? '400 - Bad Request';
          }

          $error['description'] = strip_tags($violation->getMessage());
          $errors[] = $error;
        }
      }
      else {
        $errors['error']['code'] = $exceptionCode;
        $errors['error']['description'] = $exception->getMessage();
      }

      $request_format = pathinfo($current_path, PATHINFO_EXTENSION);
      $request_format = $request_format ?? 'html';

      if ($request_format == 'json' || $request_format == 'xml') {
        $content = $this->serializer->serialize($errors, $request_format);

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
