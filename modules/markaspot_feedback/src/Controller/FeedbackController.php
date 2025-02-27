<?php

namespace Drupal\markaspot_feedback\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\markaspot_feedback\FeedbackServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the feedback REST endpoint.
 */
class FeedbackController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The feedback service.
   *
   * @var \Drupal\markaspot_feedback\FeedbackServiceInterface
   */
  protected $feedbackService;

  /**
   * Constructs a FeedbackController object.
   *
   * @param \Drupal\markaspot_feedback\FeedbackServiceInterface $feedback_service
   *   The feedback service.
   */
  public function __construct(FeedbackServiceInterface $feedback_service) {
    $this->feedbackService = $feedback_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('markaspot_feedback.feedback')
    );
  }

  /**
   * Get service request info by UUID.
   *
   * @param string $uuid
   *   The UUID of the service request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function getServiceRequest($uuid) {
    // Get the node using the FeedbackService
    $node = $this->feedbackService->get($uuid);
    
    if (!$node) {
      throw new NotFoundHttpException('Service request not found');
    }
    
    // Build a simplified response with just the needed fields
    $data = [
      'nid' => $node->id(),
      'uuid' => $node->uuid(),
      'title' => $node->getTitle(),
      'field_feedback' => $node->field_feedback->value,
    ];
    
    return new JsonResponse($data);
  }

  /**
   * Update feedback for a service request via REST API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $uuid
   *   The UUID of the service request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function updateFeedback(Request $request, $uuid) {
    $data = json_decode($request->getContent(), TRUE);
    
    // Validate request data
    if (!isset($data['feedback']) || empty($data['feedback'])) {
      return new JsonResponse(['error' => 'Feedback content is required'], 400);
    }
    
    // Get the node
    $node = $this->feedbackService->get($uuid);
    if (!$node) {
      throw new NotFoundHttpException('Service request not found');
    }
    
    // Check if feedback already exists
    if (!empty($node->field_feedback->value)) {
      return new JsonResponse(['error' => 'Feedback already exists for this service request'], 400);
    }
    
    // Update the node with feedback
    $node->field_feedback->value = $data['feedback'];
    
    // Set status to in progress if requested
    if (isset($data['set_status']) && $data['set_status']) {
      $config = $this->config('markaspot_feedback.settings');
      $node->field_status->target_id = key($config->get('set_progress_tid'));
      $change_status = TRUE;
    }
    else {
      $change_status = FALSE;
    }
    
    // Add status note paragraph
    $new_status_note = $this->feedbackService->createParagraph($change_status);
    $node->field_status_notes[] = $new_status_note;
    
    $node->save();
    
    return new JsonResponse([
      'message' => 'Feedback successfully submitted', 
      'uuid' => $uuid
    ], 200);
  }
}