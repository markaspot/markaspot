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
    ConfigFactoryInterface $config_factory,
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
      // Get the JSON data from the request body.
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      if (empty($data)) {
        $logger->warning('Empty request data for feedback update on UUID: @uuid', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => $this->t('No data provided')], 400);
      }

      // Load the node by UUID.
      $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);

      if (empty($nodes)) {
        $logger->warning('No node found with UUID: @uuid', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => $this->t('Service request not found')], 404);
      }

      $node = reset($nodes);

      // Check if the node is a service request.
      if ($node->getType() != 'service_request') {
        $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => $this->t('Not a service request')], 400);
      }

      // Enforce that the node is eligible to receive citizen feedback.
      $config = $this->configFactory->get('markaspot_feedback.settings');
      $eligible_statuses = $config->get('feedback_eligible_statuses') ?: $config->get('status_feedback_enabled');

      if (!$node->hasField('field_status') || $node->get('field_status')->isEmpty()) {
        $logger->warning('Node @nid has no field_status; rejecting citizen feedback', ['@nid' => $node->id()]);
        return new JsonResponse([
          'message' => $this->t('This service request cannot receive feedback at this time'),
          'reason' => 'missing_status_field',
        ], 403);
      }

      $current_status_tid = $node->get('field_status')->target_id;
      if (empty($eligible_statuses) || !isset($eligible_statuses[$current_status_tid])) {
        $logger->notice('Node @nid status @status not eligible for citizen feedback', [
          '@nid' => $node->id(),
          '@status' => $current_status_tid,
        ]);
        return new JsonResponse([
          'message' => $this->t('This service request is not eligible for feedback'),
          'reason' => 'status_not_eligible',
          'status_tid' => $current_status_tid,
        ], 403);
      }

      // Check if citizen feedback already exists (single value)
      $feedback_field = 'field_feedback';
      if ($node->hasField($feedback_field) && !$node->get($feedback_field)->isEmpty()) {
        $logger->warning('Feedback already exists for node @nid, ignoring update attempt', [
          '@nid' => $node->id(),
        ]);

        // Include field_has_feedback flag in the response if it exists.
        $field_has_feedback = FALSE;
        if ($node->hasField('field_has_feedback')) {
          $field_has_feedback = (bool) $node->get('field_has_feedback')->value;
        }

        return new JsonResponse([
          'message' => $this->t('Feedback already exists for this service request'),
          'existing_feedback' => $node->get($feedback_field)->value,
          'nid' => $node->id(),
          'field_has_feedback' => $field_has_feedback,
        // 409 Conflict
        ], 409);
      }

      // Validate email against the original author's email.
      if (!empty($data['email_verification'])) {
        $validation_result = $this->validateAuthorEmail($node, $data['email_verification']);

        if ($validation_result !== TRUE) {
          $logger->warning('Author email validation failed for @email on node @nid: @reason', [
            '@email' => $data['email_verification'],
            '@nid' => $node->id(),
            '@reason' => $validation_result,
          ]);
          return new JsonResponse([
            'message' => $validation_result,
          ], 403);
        }
      }

      // Update the feedback field.
      if (isset($data['feedback'])) {
        // Citizen feedback - single value field.
        $node->set('field_feedback', $data['feedback']);

        // Set the field_has_feedback flag to TRUE.
        if ($node->hasField('field_has_feedback')) {
          $node->set('field_has_feedback', TRUE);
        }
      }

      // Update the status if set_status flag is set (reopen)
      if (!empty($data['set_status'])) {
        $progress_statuses = $config->get('set_progress_tid');
        if (!empty($progress_statuses)) {
          $progress_tid = is_array($progress_statuses) ? reset($progress_statuses) : $progress_statuses;
          $node->set('field_status', $progress_tid);

          // Add status note if configured.
          $status_note = $config->get('set_status_note');
          if (!empty($status_note)) {
            $this->addStatusNote($node, $status_note);
          }
        }
      }

      // Save the node.
      $node->save();

      $logger->notice('Updated feedback for node @nid (UUID: @uuid)', [
        '@nid' => $node->id(),
        '@uuid' => $uuid,
      ]);

      // Get the value of field_has_feedback for the response.
      $field_has_feedback = FALSE;
      if ($node->hasField('field_has_feedback')) {
        $field_has_feedback = (bool) $node->get('field_has_feedback')->value;
      }

      return new JsonResponse([
        'message' => $this->t('Feedback updated successfully'),
        'nid' => $node->id(),
        'field_has_feedback' => $field_has_feedback,
        'success' => TRUE,
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
      // Load the node by UUID.
      $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);

      if (empty($nodes)) {
        $logger->warning('No node found with UUID: @uuid', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => $this->t('Service request not found')], 404);
      }

      $node = reset($nodes);

      // Check if the node is a service request.
      if ($node->getType() != 'service_request') {
        $logger->warning('Node with UUID @uuid is not a service request', ['@uuid' => $uuid]);
        return new JsonResponse(['message' => $this->t('Not a service request')], 400);
      }

      // Build response data.
      $response_data = [
        'nid' => $node->id(),
        'uuid' => $node->uuid(),
        'title' => $node->getTitle(),
        'created' => $node->getCreatedTime(),
        'changed' => $node->getChangedTime(),
        'status' => $node->hasField('field_status') ? $node->get('field_status')->target_id : NULL,
        'has_feedback' => $node->hasField('field_feedback') && !$node->get('field_feedback')->isEmpty(),
      ];

      // Compute whether the node is eligible to receive citizen feedback now.
      $is_receivable = FALSE;
      $eligible_statuses = $this->configFactory->get('markaspot_feedback.settings')->get('feedback_eligible_statuses')
        ?: $this->configFactory->get('markaspot_feedback.settings')->get('status_feedback_enabled');
      $current_status_tid = $node->hasField('field_status') && !$node->get('field_status')->isEmpty()
        ? $node->get('field_status')->target_id
        : NULL;
      $already_has_feedback = $response_data['has_feedback'];
      if (!empty($eligible_statuses) && $current_status_tid && isset($eligible_statuses[$current_status_tid]) && !$already_has_feedback) {
        $is_receivable = TRUE;
      }
      $response_data['is_receivable'] = $is_receivable;

      // Include the field_has_feedback flag in the response if it exists.
      if ($node->hasField('field_has_feedback')) {
        $response_data['field_has_feedback'] = (bool) $node->get('field_has_feedback')->value;
      }

      // Add feedback if it exists.
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
   * Validates that the provided email matches the original service request author.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The service request node.
   * @param string $email
   *   The email to validate.
   *
   * @return bool|string
   *   TRUE if the email matches the author, error message string otherwise.
   */
  protected function validateAuthorEmail($node, $email) {
    // Check if the node has an author email field.
    if (!$node->hasField('field_e_mail') || $node->get('field_e_mail')->isEmpty()) {
      return $this->t('No author email found for this service request');
    }

    // Get the original author's email.
    $author_email = $node->get('field_e_mail')->value;
    if (empty($author_email)) {
      return $this->t('Author email is not configured for this service request');
    }

    // Case-insensitive email comparison.
    $provided_email_clean = strtolower(trim($email));
    $author_email_clean = strtolower(trim($author_email));

    if ($provided_email_clean === $author_email_clean) {
      return TRUE;
    }

    // Log the validation failure.
    $error_message = $this->t('Email "@provided" does not match the original service request author email', [
      '@provided' => $email,
    ]);

    $this->loggerFactory->get('markaspot_feedback')->warning('Author email validation failed for node @nid', [
      '@nid' => $node->id(),
    ]);

    return $error_message;
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

}
