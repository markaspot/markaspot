<?php

namespace Drupal\markaspot_contact\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\markaspot_contact\ContactServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for contact form REST API.
 */
class ContactController extends ControllerBase {

  /**
   * The contact service.
   *
   * @var \Drupal\markaspot_contact\ContactServiceInterface
   */
  protected $contactService;

  /**
   * Constructs a ContactController object.
   *
   * @param \Drupal\markaspot_contact\ContactServiceInterface $contact_service
   *   The contact service.
   */
  public function __construct(
    ContactServiceInterface $contact_service,
  ) {
    $this->contactService = $contact_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('markaspot_contact.contact')
    );
  }

  /**
   * Handles contact form submission.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response.
   */
  public function submit(Request $request) {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (empty($data)) {
      return new JsonResponse(['message' => 'No data provided.'], 400);
    }

    $result = $this->contactService->submitContactForm($data);

    if ($result['success']) {
      return new JsonResponse([
        'message' => $result['message'],
        'success' => TRUE,
      ]);
    }

    $code = $result['code'] ?? 500;
    $response = ['message' => $result['message']];

    if (!empty($result['errors'])) {
      $response['errors'] = $result['errors'];
    }

    return new JsonResponse($response, $code);
  }

  /**
   * Returns contact form metadata.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with form information.
   */
  public function info() {
    $info = $this->contactService->getFormInfo();
    return new JsonResponse($info);
  }

}
