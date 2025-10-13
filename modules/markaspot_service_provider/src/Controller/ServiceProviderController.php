<?php

namespace Drupal\markaspot_service_provider\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Controller for service provider response requests.
 */
class ServiceProviderController extends ControllerBase {

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
   * Constructs a ServiceProviderController object.
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
   * Updates service provider response for a service request.
   *
   * @param string $uuid
   *   The UUID of the service request.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response.
   */
  public function updateResponse($uuid, Request $request) {
    $logger = $this->loggerFactory->get('markaspot_service_provider');

    try {
      // Get the JSON data from the request body.
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      if (empty($data)) {
        $logger->warning('Empty request data for service provider response on UUID: @uuid', ['@uuid' => $uuid]);
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

      // Normalize email verification from either JSON body or query param.
      $email_verification = isset($data['email_verification']) ? $data['email_verification'] : $request->query->get('email_verification');

      // Check if multiple completions are allowed.
      $existing_completions = $node->get('field_service_provider_notes')->getValue();

      if (!empty($existing_completions)) {
        $reassignment_allowed = FALSE;
        if ($node->hasField('field_reassign_sp') && !$node->get('field_reassign_sp')->isEmpty()) {
          $reassignment_allowed = $node->get('field_reassign_sp')->value;
        }
        if (!$reassignment_allowed) {
          $logger->warning('Service provider @email attempted completion without reassignment flag for node @nid', [
            '@email' => $email_verification,
            '@nid' => $node->id()
          ]);
          return new JsonResponse([
            'message' => $this->t('Service request already completed. Contact administrator for reassignment.'),
          ], 403);
        }
      }

      // Validate email against service provider field.
      if (!empty($email_verification)) {
        $validation_result = $this->validateServiceProviderEmail($node, $email_verification);

        if ($validation_result !== TRUE) {
          $logger->warning('Service provider validation failed for @email on node @nid: @reason', [
            '@email' => $email_verification,
            '@nid' => $node->id(),
            '@reason' => $validation_result
          ]);
          return new JsonResponse([
            'message' => $validation_result,
          ], 403);
        }
      }

      // Update the completion notes.
      if (isset($data['completion_notes'])) {
        if ($node->hasField('field_service_provider_notes')) {
          $this->addServiceProviderCompletion($node, $email_verification, $data['completion_notes']);
        }
        elseif ($node->hasField('field_sp_feedback')) {
          $node->set('field_sp_feedback', $data['completion_notes']);
        }

        $logger->notice('Added service provider completion for node @nid', [
          '@nid' => $node->id(),
        ]);
      }

      // Update the status if set_status flag is set.
      if (!empty($data['set_status'])) {
        $config = $this->configFactory->get('markaspot_service_provider.settings');
        $completion_status = $config->get('completion_status_tid');

        if (!empty($completion_status)) {
          $status_tid = is_array($completion_status) ? reset($completion_status) : $completion_status;
          $node->set('field_status', $status_tid);
          $logger->notice('Setting service request @nid status to @status via service provider completion', [
            '@nid' => $node->id(),
            '@status' => $status_tid,
          ]);

          // Add service provider status note if configured.
          $status_note = $config->get('status_note');
          if (!empty($status_note)) {
            $this->addStatusNote($node, $status_note);
            $logger->notice('Added service provider status note for node @nid', [
              '@nid' => $node->id(),
            ]);
          }
        }
      }

      // Save the node.
      $node->save();

      $logger->notice('Updated service provider response for node @nid (UUID: @uuid)', [
        '@nid' => $node->id(),
        '@uuid' => $uuid,
      ]);

      return new JsonResponse([
        'message' => $this->t('Service request completed by service provider'),
        'nid' => $node->id(),
        'success' => TRUE
      ]);
    }
    catch (\Exception $e) {
      $logger->error('Error updating service provider response for UUID @uuid: @error', [
        '@uuid' => $uuid,
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse(['message' => $this->t('Error processing response')], 500);
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
    $logger = $this->loggerFactory->get('markaspot_service_provider');

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
      ];

      // Add service provider email information.
      $service_provider_emails = $this->getServiceProviderEmails($node);
      if (!empty($service_provider_emails)) {
        $response_data['service_provider_emails'] = $service_provider_emails;
        $response_data['service_provider_emails_count'] = count($service_provider_emails);
      }

      // Add completion notes if they exist.
      if ($node->hasField('field_service_provider_notes')) {
        $notes = $node->get('field_service_provider_notes')->getValue();
        $response_data['completions'] = array_map(function($note) {
          return $note['value'];
        }, $notes);
        $response_data['completion_count'] = count($notes);
      }

      // Check reassignment status.
      if ($node->hasField('field_reassign_sp')) {
        $response_data['reassignment_allowed'] = (bool) $node->get('field_reassign_sp')->value;
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
    // Check if the node has a service provider assigned.
    if (!$node->hasField('field_service_provider') || $node->get('field_service_provider')->isEmpty()) {
      return $this->t('No service provider assigned to this service request');
    }

    // Get the referenced service provider taxonomy term.
    $service_provider_term = $node->get('field_service_provider')->entity;
    if (!$service_provider_term) {
      return $this->t('Service provider configuration error');
    }

    // Check if the service provider has an email field.
    if (!$service_provider_term->hasField('field_sp_email') || $service_provider_term->get('field_sp_email')->isEmpty()) {
      return $this->t('Service provider has no email address configured');
    }

    // Get all service provider emails (multi-value field).
    $service_provider_emails = $service_provider_term->get('field_sp_email')->getValue();
    $valid_emails = [];
    $provided_email_clean = strtolower(trim($email));

    // Check against all configured email addresses.
    foreach ($service_provider_emails as $email_item) {
      $sp_email_clean = strtolower(trim($email_item['value']));
      $valid_emails[] = $email_item['value'];

      if ($provided_email_clean === $sp_email_clean) {
        $this->loggerFactory->get('markaspot_service_provider')->notice('Service provider email validation successful: @provided matched @configured for node @nid', [
          '@provided' => $email,
          '@configured' => $email_item['value'],
          '@nid' => $node->id(),
        ]);
        return TRUE;
      }
    }

    // Enhanced error message showing valid emails.
    $valid_emails_string = implode(', ', $valid_emails);
    $error_message = $this->t('Email "@provided" does not match any configured service provider email. Valid emails: @valid_emails', [
      '@provided' => $email,
      '@valid_emails' => $valid_emails_string,
    ]);

    $this->loggerFactory->get('markaspot_service_provider')->warning('Service provider email validation failed for node @nid: @error', [
      '@nid' => $node->id(),
      '@error' => $error_message,
    ]);

    return $error_message;
  }

  /**
   * Get all valid email addresses for a service provider.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array
   *   Array of valid email addresses for the assigned service provider.
   */
  protected function getServiceProviderEmails($node) {
    $valid_emails = [];

    if (!$node->hasField('field_service_provider') || $node->get('field_service_provider')->isEmpty()) {
      return $valid_emails;
    }

    $service_provider_term = $node->get('field_service_provider')->entity;
    if (!$service_provider_term) {
      return $valid_emails;
    }

    if (!$service_provider_term->hasField('field_sp_email') || $service_provider_term->get('field_sp_email')->isEmpty()) {
      return $valid_emails;
    }

    $service_provider_emails = $service_provider_term->get('field_sp_email')->getValue();

    foreach ($service_provider_emails as $email_item) {
      if (!empty($email_item['value'])) {
        $valid_emails[] = trim($email_item['value']);
      }
    }

    return $valid_emails;
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
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $status_note_paragraph = $paragraph_storage->create([
      'type' => 'status',
      'field_status_note' => [
        'value' => $note_text,
        'format' => 'full_html',
      ],
    ]);
    $status_note_paragraph->save();

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
    // Get service provider information.
    $service_provider_name = '';
    if ($node->hasField('field_service_provider') && !$node->get('field_service_provider')->isEmpty()) {
      $service_provider_term = $node->get('field_service_provider')->entity;
      if ($service_provider_term) {
        $service_provider_name = $service_provider_term->getName();
      }
    }

    // Create translatable metadata footer with German formatting.
    $timestamp = \Drupal::service('date.formatter')->format(time(), 'custom', 'd.m.Y - H:i', 'Europe/Berlin');

    $metadata_footer = "\n\n---\n" .
      $this->t('Completed by: @email', ['@email' => $email]) . "\n" .
      $this->t('Completed on: @timestamp', ['@timestamp' => $timestamp]) . "\n" .
      $this->t('Service Provider: @name', ['@name' => $service_provider_name ?: $this->t('Unknown')]);

    $completion_entry = $completion_notes . $metadata_footer;

    // Add to multi-value field.
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
