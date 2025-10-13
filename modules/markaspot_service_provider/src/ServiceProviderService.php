<?php

namespace Drupal\markaspot_service_provider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Service for processing service provider workflows.
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
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new ServiceProviderService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
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
