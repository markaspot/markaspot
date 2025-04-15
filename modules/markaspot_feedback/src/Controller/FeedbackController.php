<?php

namespace Drupal\markaspot_feedback\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Controller for feedback-related requests.
 */
class FeedbackController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a FeedbackController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('state'),
      $container->get('config.factory')
    );
  }

  /**
   * Updates feedback for a service request.
   *
   * @param string $uuid
   *   The UUID of the service request.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response.
   */
  public function updateFeedback($uuid, Request $request) {
    $logger = $this->loggerFactory->get('markaspot_feedback');
    
    try {
      // Get the JSON data from the request body
      $content = $request->getContent();
      $data = json_decode($content, TRUE);
      
      if (empty($data)) {
        $logger->warning('Empty request data for feedback update on UUID: @uuid', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => 'No data provided'], 400);
      }
      
      // Load the node by UUID
      $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);
      
      if (empty($nodes)) {
        $logger->warning('No node found with UUID: @uuid', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => 'Service request not found'], 404);
      }
      
      $node = reset($nodes);
      
      // Check if the node is a service request
      if ($node->getType() != 'service_request') {
        $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => 'Not a service request'], 400);
      }
      
      // Check if feedback already exists
      if ($node->hasField('field_feedback') && !$node->get('field_feedback')->isEmpty()) {
        $logger->warning('Feedback already exists for node @nid, ignoring update attempt', ['@nid' => $node->id()]);
        
        // Include field_has_feedback flag in the response if it exists
        $field_has_feedback = false;
        if ($node->hasField('field_has_feedback')) {
          $field_has_feedback = (bool) $node->get('field_has_feedback')->value;
        }
        
        return new JsonResponse([
          'message' => 'Feedback already exists for this service request', 
          'existing_feedback' => $node->get('field_feedback')->value,
          'nid' => $node->id(),
          'field_has_feedback' => $field_has_feedback
        ], 409); // 409 Conflict - indicating the request couldn't be completed due to a conflict
      }
      
      // Update the feedback field if it doesn't exist yet
      if (isset($data['feedback'])) {
        $node->set('field_feedback', $data['feedback']);
        
        // Set the field_has_feedback flag to TRUE
        if ($node->hasField('field_has_feedback')) {
          $node->set('field_has_feedback', TRUE);
          $logger->notice('Setting field_has_feedback to TRUE for node @nid', [
            '@nid' => $node->id(),
          ]);
        } else {
          $logger->warning('field_has_feedback not found on node @nid', [
            '@nid' => $node->id(),
          ]);
        }
      }
      
      // Update the status if set_status flag is set
      if (!empty($data['set_status'])) {
        // Get configuration for status to set when feedback is submitted
        $config = $this->configFactory->get('markaspot_feedback.settings');
        $progress_statuses = $config->get('set_progress_tid');
        if (!empty($progress_statuses)) {
          $progress_tid = is_array($progress_statuses) ? reset($progress_statuses) : $progress_statuses;
          $node->set('field_status', $progress_tid);
          $logger->notice('Setting service request @nid status to @status via feedback', [
            '@nid' => $node->id(),
            '@status' => $progress_tid,
          ]);
        }
      }
      
      // Save the node
      $node->save();
      
      $logger->notice('Updated feedback for node @nid (UUID: @uuid)', [
        '@nid' => $node->id(),
        '@uuid' => $uuid,
      ]);
      
      // Get the value of field_has_feedback for the response
      $field_has_feedback = false;
      if ($node->hasField('field_has_feedback')) {
        $field_has_feedback = (bool) $node->get('field_has_feedback')->value;
      }
      
      return new JsonResponse([
        'message' => 'Feedback updated successfully',
        'nid' => $node->id(),
        'field_has_feedback' => $field_has_feedback,
      ]);
    }
    catch (\Exception $e) {
      $logger->error('Error updating feedback for UUID @uuid: @error', [
        '@uuid' => $uuid,
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse(['message' => 'Error processing feedback'], 500);
    }
  }

  /**
   * Gets service request data by UUID.
   *
   * @param string $uuid
   *   The UUID of the service request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with service request data.
   */
  public function getServiceRequest($uuid) {
    $logger = $this->loggerFactory->get('markaspot_feedback');
    
    try {
      // Load the node by UUID
      $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);
      
      if (empty($nodes)) {
        $logger->warning('No node found with UUID: @uuid', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => 'Service request not found'], 404);
      }
      
      $node = reset($nodes);
      
      // Check if the node is a service request
      if ($node->getType() != 'service_request') {
        $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => 'Not a service request'], 400);
      }
      
      // Build response data
      $response_data = [
        'nid' => $node->id(),
        'uuid' => $node->uuid(),
        'title' => $node->getTitle(),
        'created' => $node->getCreatedTime(),
        'changed' => $node->getChangedTime(),
        'status' => $node->hasField('field_status') ? $node->get('field_status')->target_id : null,
        'has_feedback' => $node->hasField('field_feedback') && !$node->get('field_feedback')->isEmpty(),
      ];
      
      // Include the field_has_feedback flag in the response if it exists
      if ($node->hasField('field_has_feedback')) {
        $response_data['field_has_feedback'] = (bool) $node->get('field_has_feedback')->value;
      }
      
      // Add feedback if it exists
      if ($node->hasField('field_feedback') && !$node->get('field_feedback')->isEmpty()) {
        $response_data['feedback'] = $node->get('field_feedback')->value;
      }
      
      $logger->notice('Retrieved service request data for node @nid (UUID: @uuid)', [
        '@nid' => $node->id(),
        '@uuid' => $uuid,
      ]);
      
      return new JsonResponse($response_data);
    }
    catch (\Exception $e) {
      $logger->error('Error getting service request for UUID @uuid: @error', [
        '@uuid' => $uuid,
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse(['message' => 'Error retrieving service request'], 500);
    }
  }
}