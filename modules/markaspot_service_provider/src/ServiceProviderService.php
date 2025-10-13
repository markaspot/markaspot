<?php

namespace Drupal\markaspot_service_provider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;

/**
 * Service for processing service provider notifications and workflows.
 */
class ServiceProviderService {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

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
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs a new ServiceProviderService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    QueueFactory $queue_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager,
    Token $token
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->queueFactory = $queue_factory;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->token = $token;
  }

  /**
   * Sends a notification email to the assigned service provider.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string|null $recipient_email
   *   Optional recipient email. If not provided, will use service provider's configured email.
   *
   * @return bool
   *   TRUE if the email was sent successfully, FALSE otherwise.
   */
  public function sendServiceProviderNotification(NodeInterface $node, $recipient_email = NULL) {
    try {
      $logger = $this->loggerFactory->get('markaspot_service_provider');

      // Check if the node is a service request.
      if ($node->getType() != 'service_request') {
        $logger->warning('Node @nid is not a service request.', ['@nid' => $node->id()]);
        return FALSE;
      }

      // Check if node has service provider assigned.
      if (!$node->hasField('field_service_provider') || $node->get('field_service_provider')->isEmpty()) {
        $logger->warning('Node @nid has no service provider assigned.', ['@nid' => $node->id()]);
        return FALSE;
      }

      // Get service provider email if not provided.
      if (empty($recipient_email)) {
        $service_provider_term = $node->get('field_service_provider')->entity;
        if (!$service_provider_term) {
          $logger->warning('Could not load service provider term for node @nid.', ['@nid' => $node->id()]);
          return FALSE;
        }

        if (!$service_provider_term->hasField('field_sp_email') || $service_provider_term->get('field_sp_email')->isEmpty()) {
          $logger->warning('Service provider for node @nid has no email configured.', ['@nid' => $node->id()]);
          return FALSE;
        }

        // Get the first email from the multi-value field.
        $emails = $service_provider_term->get('field_sp_email')->getValue();
        $recipient_email = $emails[0]['value'];
      }

      $config = $this->configFactory->get('markaspot_service_provider.settings');
      $mailtext = $config->get('mailtext') ?: 'A new service request has been assigned to you.';
      $mail_subject = $config->get('mail_subject') ?: 'Service Request Assignment: [node:title]';

      // Replace tokens in the mail text and subject.
      $mailtext = $this->token->replace($mailtext, ['node' => $node]);
      $mail_subject = $this->token->replace($mail_subject, ['node' => $node]);

      // Build the response URL using node UUID.
      $node_uuid = $node->uuid();
      $response_url = \Drupal::request()->getSchemeAndHttpHost() . '/service-response/' . $node_uuid;

      // Prepare mail parameters.
      $site_config = $this->configFactory->get('system.site');
      $langcode = $this->languageManager->getDefaultLanguage()->getId();

      $params = [
        'subject' => $mail_subject,
        'body' => [
          'text' => $mailtext,
          'node' => $node,
          'response_url' => $response_url,
        ],
      ];

      // Send the email.
      $result = $this->mailManager->mail(
        'markaspot_service_provider',
        'service_provider_notification',
        $recipient_email,
        $langcode,
        $params,
        $site_config->get('mail')
      );

      if (!empty($result['result'])) {
        $logger->notice('Service provider notification email sent for node @nid to @email.', [
          '@nid' => $node->id(),
          '@email' => $recipient_email,
        ]);
        return TRUE;
      }

      return FALSE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('markaspot_service_provider')->error('Error sending service provider notification for node @nid: @error', [
        '@nid' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets all valid email addresses for a service provider assigned to a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array
   *   Array of valid email addresses.
   */
  public function getServiceProviderEmails(NodeInterface $node) {
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
   * Checks if a node allows multiple completions (reassignment).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return bool
   *   TRUE if reassignment is allowed, FALSE otherwise.
   */
  public function isReassignmentAllowed(NodeInterface $node) {
    if (!$node->hasField('field_reassign_sp') || $node->get('field_reassign_sp')->isEmpty()) {
      return FALSE;
    }

    return (bool) $node->get('field_reassign_sp')->value;
  }

  /**
   * Gets completion notes for a service request.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array
   *   Array of completion notes.
   */
  public function getCompletionNotes(NodeInterface $node) {
    if (!$node->hasField('field_service_provider_notes')) {
      return [];
    }

    $notes = $node->get('field_service_provider_notes')->getValue();
    return array_map(function($note) {
      return $note['value'];
    }, $notes);
  }

}
