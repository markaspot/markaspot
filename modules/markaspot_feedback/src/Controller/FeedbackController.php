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
        return new JsonResponse(['message' => $this->t('No data provided')], 400);
      }
      
      // Load the node by UUID
      $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);
      
      if (empty($nodes)) {
        $logger->warning('No node found with UUID: @uuid', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => $this->t('Service request not found')], 404);
      }
      
      $node = reset($nodes);
      
      // Check if the node is a service request
      if ($node->getType() != 'service_request') {
        $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => $this->t('Not a service request')], 400);
      }
      
      // Check if this is service provider mode
      $is_service_provider_mode = !empty($data['service_provider_mode']);
      
      // For citizen mode, check if citizen feedback already exists (single value)
      if (!$is_service_provider_mode) {
        $feedback_field = 'field_feedback';
        if ($node->hasField($feedback_field) && !$node->get($feedback_field)->isEmpty()) {
          $logger->warning('Feedback already exists for node @nid, ignoring update attempt', [
            '@nid' => $node->id()
          ]);
          
          // Include field_has_feedback flag in the response if it exists
          $field_has_feedback = false;
          if ($node->hasField('field_has_feedback')) {
            $field_has_feedback = (bool) $node->get('field_has_feedback')->value;
          }
          
          return new JsonResponse([
            'message' => $this->t('Feedback already exists for this service request'), 
            'existing_feedback' => $node->get($feedback_field)->value,
            'nid' => $node->id(),
            'field_has_feedback' => $field_has_feedback,
            'service_provider_mode' => false
          ], 409); // 409 Conflict - indicating the request couldn't be completed due to a conflict
        }
      }
      
      // For service provider mode, check if multiple completions are allowed
      if ($is_service_provider_mode) {
        $existing_completions = $node->get('field_service_provider_notes')->getValue();
        
        // If there are existing completions, check reassignment flag
        if (!empty($existing_completions)) {
          $reassignment_allowed = $node->get('field_reassign_sp')->value;
          if (!$reassignment_allowed) {
            $logger->warning('Service provider @email attempted completion without reassignment flag for node @nid', [
              '@email' => $data['email_verification'],
              '@nid' => $node->id()
            ]);
            return new JsonResponse([
              'message' => $this->t('Service request already completed. Contact administrator for reassignment.'),
              'service_provider_mode' => true
            ], 403); // 403 Forbidden
          }
        }
      }
      
      // For service provider mode, validate email against service provider field
      if ($is_service_provider_mode && !empty($data['email_verification'])) {
        $validation_result = $this->validateServiceProviderEmail($node, $data['email_verification']);
        
        if ($validation_result !== TRUE) {
          $logger->warning('Service provider validation failed for @email on node @nid: @reason', [
            '@email' => $data['email_verification'],
            '@nid' => $node->id(),
            '@reason' => $validation_result
          ]);
          return new JsonResponse([
            'message' => $validation_result,
            'service_provider_mode' => true
          ], 403); // 403 Forbidden
        }
      }
      
      // Update the feedback field
      if (isset($data['feedback'])) {
        if ($is_service_provider_mode) {
          // Add service provider completion to multi-value field with metadata
          $this->addServiceProviderCompletion($node, $data['email_verification'], $data['feedback']);
          
          $logger->notice('Added service provider completion for node @nid', [
            '@nid' => $node->id(),
          ]);
        } else {
          // Citizen feedback - single value field
          $node->set('field_feedback', $data['feedback']);
          
          $logger->notice('Updated field_feedback for node @nid with citizen feedback', [
            '@nid' => $node->id(),
          ]);
          
          // Set the field_has_feedback flag to TRUE for citizen feedback only
          if ($node->hasField('field_has_feedback')) {
            $node->set('field_has_feedback', TRUE);
            $logger->notice('Setting field_has_feedback to TRUE for node @nid', [
              '@nid' => $node->id(),
            ]);
          }
        }
      }
      
      // Update the status if set_status flag is set
      if (!empty($data['set_status'])) {
        $config = $this->configFactory->get('markaspot_feedback.settings');
        
        if ($is_service_provider_mode) {
          // For service provider mode, use service provider completion status
          $completion_status = $config->get('service_provider_completion_status_tid');
          if (!empty($completion_status)) {
            $status_tid = is_array($completion_status) ? reset($completion_status) : $completion_status;
            $node->set('field_status', $status_tid);
            $logger->notice('Setting service request @nid status to @status via service provider completion', [
              '@nid' => $node->id(),
              '@status' => $status_tid,
            ]);
            
            // Add service provider status note if configured
            $service_provider_status_note = $config->get('service_provider_status_note');
            if (!empty($service_provider_status_note)) {
              $this->addStatusNote($node, $service_provider_status_note);
              $logger->notice('Added service provider status note for node @nid', [
                '@nid' => $node->id(),
              ]);
            }
          }
        } else {
          // For citizen feedback, use regular progress status (reopen)
          $progress_statuses = $config->get('set_progress_tid');
          if (!empty($progress_statuses)) {
            $progress_tid = is_array($progress_statuses) ? reset($progress_statuses) : $progress_statuses;
            $node->set('field_status', $progress_tid);
            $logger->notice('Setting service request @nid status to @status via citizen feedback', [
              '@nid' => $node->id(),
              '@status' => $progress_tid,
            ]);
            
            // Add citizen status note if configured (existing logic)
            $citizen_status_note = $config->get('set_status_note');
            if (!empty($citizen_status_note)) {
              $this->addStatusNote($node, $citizen_status_note);
              $logger->notice('Added citizen status note for node @nid', [
                '@nid' => $node->id(),
              ]);
            }
          }
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
      
      $success_message = $is_service_provider_mode ? $this->t('Service request completed by service provider') : $this->t('Feedback updated successfully');
      
      return new JsonResponse([
        'message' => $success_message,
        'nid' => $node->id(),
        'field_has_feedback' => $field_has_feedback,
        'service_provider_mode' => $is_service_provider_mode,
        'success' => true
      ]);
    }
    catch (\Exception $e) {
      $logger->error('Error updating feedback for UUID @uuid: @error', [
        '@uuid' => $uuid,
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse(['message' => $this->t('Error processing feedback')], 500);
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
        return new JsonResponse(['message' => $this->t('Service request not found')], 404);
      }
      
      $node = reset($nodes);
      
      // Check if the node is a service request
      if ($node->getType() != 'service_request') {
        $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => $this->t('Not a service request')], 400);
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
      
      return new JsonResponse(['message' => $this->t('Error retrieving service request')], 500);
    }
  }

  /**
   * Validates that the provided email matches the assigned service provider.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The service request node.
   * @param string $email
   *   The email to validate.
   *
   * @return bool|string
   *   TRUE if the email matches the service provider, error message string otherwise.
   */
  protected function validateServiceProviderEmail($node, $email) {
    // Check if the node has a service provider assigned
    if (!$node->hasField('field_service_provider') || $node->get('field_service_provider')->isEmpty()) {
      return $this->t('No service provider assigned to this service request');
    }

    // Get the referenced service provider taxonomy term
    $service_provider_term = $node->get('field_service_provider')->entity;
    if (!$service_provider_term) {
      return $this->t('Service provider configuration error');
    }

    // Check if the service provider has an email field
    if (!$service_provider_term->hasField('field_sp_email') || $service_provider_term->get('field_sp_email')->isEmpty()) {
      return $this->t('Service provider has no email address configured');
    }

    // Get the service provider email and compare with provided email
    $service_provider_email = $service_provider_term->get('field_sp_email')->value;
    
    // Case-insensitive email comparison
    if (strtolower(trim($email)) === strtolower(trim($service_provider_email))) {
      return TRUE;
    }
    
    return $this->t('Email does not match assigned service provider');
  }

  /**
   * Add a status note paragraph to a service request node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string $note_text
   *   The status note text to add.
   */
  protected function addStatusNote($node, $note_text) {
    // Create a status note paragraph and attach it to the node.
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $status_note_paragraph = $paragraph_storage->create([
      'type' => 'status',
      'field_status_note' => [
        'value' => $note_text,
        'format' => 'full_html',
      ],
    ]);
    $status_note_paragraph->save();
    
    // Add the status note paragraph to the node.
    $notes = $node->get('field_notes')->getValue();
    $notes[] = [
      'target_id' => $status_note_paragraph->id(),
      'target_revision_id' => $status_note_paragraph->getRevisionId(),
    ];
    $node->set('field_notes', $notes);
  }

  /**
   * Add a service provider completion entry to the multi-value notes field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string $email
   *   The service provider email.
   * @param string $completion_notes
   *   The completion notes from the service provider.
   */
  protected function addServiceProviderCompletion($node, $email, $completion_notes) {
    // Get service provider information
    $service_provider_name = '';
    if ($node->hasField('field_service_provider') && !$node->get('field_service_provider')->isEmpty()) {
      $service_provider_term = $node->get('field_service_provider')->entity;
      if ($service_provider_term) {
        $service_provider_name = $service_provider_term->getName();
      }
    }

    // Create translatable metadata footer with German formatting
    $timestamp = \Drupal::service('date.formatter')->format(time(), 'custom', 'd.m.Y - H:i', 'Europe/Berlin');
    
    $metadata_footer = "\n\n---\n" . 
      t('Completed by: @email', ['@email' => $email]) . "\n" .
      t('Completed on: @timestamp', ['@timestamp' => $timestamp]) . "\n" .
      t('Service Provider: @name', ['@name' => $service_provider_name ?: t('Unknown')]);

    $completion_entry = $completion_notes . $metadata_footer;

    // Add to multi-value field
    if ($node->hasField('field_service_provider_notes')) {
      $current_notes = $node->get('field_service_provider_notes')->getValue();
      $current_notes[] = [
        'value' => $completion_entry,
        'format' => 'full_html',
      ];
      $node->set('field_service_provider_notes', $current_notes);
    }
  }
}