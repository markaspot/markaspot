<?php

namespace Drupal\markaspot_feedback\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Processes service request nodes for feedback.
 *
 * @QueueWorker(
 *   id = "markaspot_feedback_queue_worker",
 *   title = @Translation("Process Service Request Nodes for Feedback"),
 *   cron = {"time" = 60}
 * )
 */
class FeedbackQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The feedback service.
   *
   * @var \Drupal\markaspot_feedback\FeedbackServiceInterface
   */
  protected $feedbackService;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new FeedbackQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\markaspot_feedback\FeedbackServiceInterface $feedback_service
   *   The feedback service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $feedback_service, LoggerChannelInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->feedbackService = $feedback_service;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('markaspot_feedback.feedback'),
      $container->get('logger.factory')->get('markaspot_feedback')
    );
  }

  /**
   * Processes a single queued item.
   *
   * @param mixed $data
   *   The data that was added to the queue. Here we expect an associative array,
   *   e.g. ['nid' => 123].
   */
  public function processItem($data) {
    $nid = isset($data['nid']) ? $data['nid'] : NULL;
    if (!$nid) {
      $this->logger->warning('Queue item is missing a node ID.');
      return;
    }

    $this->logger->notice('Starting feedback processing for node ID: @nid', ['@nid' => $nid]);

    // Load the node by ID.
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node) {
      $this->logger->warning('Node with ID @nid not found.', ['@nid' => $nid]);
      return;
    }

    try {
      // Load configuration.
      $config = \Drupal::configFactory()->getEditable('markaspot_feedback.settings');
      
      // Check if the node is in the proper status for feedback
      $resubmissive_statuses = $config->get('status_resubmissive');
      $current_status = $node->get('field_status')->target_id;
      
      if (!isset($resubmissive_statuses[$current_status])) {
        $this->logger->notice('Node ID @nid is not in a status eligible for feedback (current: @current). Skipping.', [
          '@nid' => $nid,
          '@current' => $current_status
        ]);
        return;
      }
      
      // Check if feedback has already been requested for this node
      $processed_nids = \Drupal::state()->get('markaspot_feedback.processed_nids', []);
      if (in_array($nid, $processed_nids)) {
        $this->logger->notice('Feedback has already been requested for node ID @nid. Skipping.', [
          '@nid' => $nid
        ]);
        return;
      }
      
      // Check if the node has a valid email address to send feedback to
      $hasEmail = FALSE;
      
      // Check if the node has a field_e_mail field and it's not empty
      if ($node->hasField('field_e_mail') && !$node->get('field_e_mail')->isEmpty()) {
        $hasEmail = TRUE;
      }
      // Fallback to the node author's email as a last resort
      else {
        $user = $node->getOwner();
        if ($user && $user->getEmail()) {
          $hasEmail = TRUE;
        }
      }
      
      if (!$hasEmail) {
        $this->logger->warning('Node ID @nid has no email address (field_e_mail or author). Cannot send feedback request.', [
          '@nid' => $nid
        ]);
        return;
      }
      
      // Process this node for feedback
      $result = $this->feedbackService->processFeedbackForNode($node);
      
      if ($result) {
        $this->logger->notice('Node ID @nid processed for feedback successfully.', ['@nid' => $nid]);
      } else {
        $this->logger->warning('Failed to process feedback for node ID @nid.', ['@nid' => $nid]);
      }
    }
    catch (\Exception $e) {
      $this->logger->critical('Queue processing failed for node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage() . "\n" . $e->getTraceAsString()
      ]);
    }
  }
}