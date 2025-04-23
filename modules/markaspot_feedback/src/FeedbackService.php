<?php

namespace Drupal\markaspot_feedback;

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
 * Service for processing feedback for service request nodes.
 */
class FeedbackService implements FeedbackServiceInterface {

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
   * Constructs a new FeedbackService object.
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
   * {@inheritdoc}
   */
  public function loadEligibleNodes($limit = 50) {
    $config = $this->configFactory->get('markaspot_feedback.settings');
    $days = $config->get('days');
    $feedback_eligible_statuses = $config->get('feedback_eligible_statuses') ?: $config->get('status_feedback_enabled');
    
    if (empty($feedback_eligible_statuses)) {
      $this->loggerFactory->get('markaspot_feedback')->warning('No feedback eligible status terms configured.');
      return [];
    }
    
    // Calculate the timestamp for 'days' ago.
    $timestamp = strtotime('-' . $days . ' days');
    
    // Build a query that finds nodes:
    // 1. Of type 'service_request'
    // 2. That are published
    // 3. With the specified status term IDs
    // 4. That were last updated before 'days' ago
    // 5. That haven't been processed for feedback yet
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_status', 's', 'n.nid = s.entity_id');
    $query->fields('n', ['nid'])
      ->condition('n.type', 'service_request')
      // Only include published nodes
      ->condition('n.status', 1)
      ->condition('s.field_status_target_id', array_keys($feedback_eligible_statuses), 'IN')
      ->condition('n.changed', $timestamp, '<')
      ->orderBy('n.nid', 'ASC')
      ->range(0, $limit);
    
    // Exclude nodes that were already processed for feedback.
    $processed_nids = $this->state->get('markaspot_feedback.processed_nids', []);
    if (!empty($processed_nids)) {
      $query->condition('n.nid', $processed_nids, 'NOT IN');
    }
    
    $result = $query->execute()->fetchCol();
    
    // Log a simple summary
    $logger = $this->loggerFactory->get('markaspot_feedback');
    if (!empty($result)) {
      $logger->notice('Found @count service requests eligible for feedback processing', [
        '@count' => count($result),
      ]);
    } else {
      $logger->notice('No service requests found eligible for feedback processing');
    }
    
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function processFeedbackForNode(NodeInterface $node) {
    try {
      $config = $this->configFactory->get('markaspot_feedback.settings');
      $logger = $this->loggerFactory->get('markaspot_feedback');
      
      // Check if the node is a service request.
      if ($node->getType() != 'service_request') {
        $logger->warning('Node @nid is not a service request.', ['@nid' => $node->id()]);
        return FALSE;
      }
      
      // Check if the node is published
      if (!$node->isPublished()) {
        $logger->warning('Node @nid is not published, skipping feedback processing.', ['@nid' => $node->id()]);
        return FALSE;
      }
      
      // Check if the node has the required field_status field
      if (!$node->hasField('field_status') || $node->get('field_status')->isEmpty()) {
        $logger->warning('Node @nid is missing the field_status field or it is empty.', ['@nid' => $node->id()]);
        return FALSE;
      }
      
      // Check if the node status is eligible for feedback.
      $feedback_eligible_statuses = $config->get('feedback_eligible_statuses') ?: $config->get('status_feedback_enabled');
      $current_status = $node->get('field_status')->target_id;
      
      if (!isset($feedback_eligible_statuses[$current_status])) {
        $logger->notice('Node @nid is not eligible for feedback.', ['@nid' => $node->id()]);
        return FALSE;
      }
      
      // Check if this node has already been processed for feedback
      $processed_nids = $this->state->get('markaspot_feedback.processed_nids', []);
      if (in_array($node->id(), $processed_nids)) {
        $logger->notice('Node @nid has already been processed for feedback.', [
          '@nid' => $node->id(),
        ]);
        return FALSE;
      }
      
      // Check if the node has a field_e_mail field and it's not empty
      $recipientEmail = NULL;
      if ($node->hasField('field_e_mail') && !$node->get('field_e_mail')->isEmpty()) {
        $recipientEmail = $node->get('field_e_mail')->value;
      }
      // Fallback to the node author's email as a last resort
      else {
        $user = $node->getOwner();
        if ($user && $user->getEmail()) {
          $recipientEmail = $user->getEmail();
        }
      }
      
      // Send the email if we found a recipient
      if ($recipientEmail) {
        $this->sendFeedbackRequestEmail($node, $recipientEmail);
        $logger->notice('Feedback request email sent for node @nid to @email.', [
          '@nid' => $node->id(),
          '@email' => $recipientEmail,
        ]);
      }
      else {
        $logger->warning('No email address found for node @nid (checked field_e_mail and author).', [
          '@nid' => $node->id(),
        ]);
        return FALSE;
      }
      
      // Set the node status to the progress status, if configured.
      $progress_statuses = $config->get('set_progress_tid');
      if (!empty($progress_statuses)) {
        $progress_tid = key($progress_statuses);
        $node->set('field_status', $progress_tid);
        
        // Add a status note if configured.
        $status_note = $config->get('set_status_note');
        if (!empty($status_note)) {
          // Create a status note paragraph and attach it to the node.
          $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
          $status_note_paragraph = $paragraph_storage->create([
            'type' => 'status',
            'field_status_note' => [
              'value' => $status_note,
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
        
        // Save the node.
        $node->save();
        $logger->notice('Node @nid processed for feedback: status updated to @status', [
          '@nid' => $node->id(),
          '@status' => $progress_tid,
        ]);
        
        // Mark this node as processed.
        $processed_nids = $this->state->get('markaspot_feedback.processed_nids', []);
        $processed_nids[] = $node->id();
        $this->state->set('markaspot_feedback.processed_nids', $processed_nids);
        
        return TRUE;
      }
      
      $logger->warning('No progress status configured for feedback.');
      return FALSE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('markaspot_feedback')->error('Error processing feedback for node @nid: @error', [
        '@nid' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Sends a feedback request email for a service request.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to send feedback request for.
   * @param string $to
   *   The email address to send the feedback request to.
   *
   * @return bool
   *   TRUE if the email was sent successfully, FALSE otherwise.
   */
  protected function sendFeedbackRequestEmail(NodeInterface $node, $to) {
    try {
      $config = $this->configFactory->get('markaspot_feedback.settings');
      $mailtext = $config->get('mailtext') ?: 'Hello [node:author:name]!';
      
      // Replace tokens in the mail text.
      $mailtext = $this->token->replace($mailtext, ['node' => $node]);
      
      // Simply use the node's UUID for the feedback URL.
      // This matches the frontend's expected format and simplifies the process.
      $node_uuid = $node->uuid();
      
      $feedback_url = \Drupal::request()->getSchemeAndHttpHost() . 
                     '/feedback/' . $node_uuid;
      
      // Prepare mail parameters.
      $site_config = $this->configFactory->get('system.site');
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
      
      // Get the email subject from the configuration, or use a default if not set.
      $mail_subject = $config->get('mail_subject') ?: 'Feedback request for service request #[node:request_id]';
      // Replace tokens in the mail subject.
      $mail_subject = $this->token->replace($mail_subject, ['node' => $node]);
      
      $params = [
        'subject' => $mail_subject,
        'body' => [
          'text' => $mailtext,
          'node' => $node,
          'feedback_url' => $feedback_url,
        ],
      ];
      
      // Send the email.
      $result = $this->mailManager->mail(
        'markaspot_feedback',
        'feedback_request',
        $to,
        $langcode,
        $params,
        $site_config->get('mail')
      );
      
      return !empty($result['result']);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('markaspot_feedback')->error('Error sending feedback request email for node @nid: @error', [
        '@nid' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function queueNodeForProcessing($nid) {
    try {
      // Get the feedback queue.
      $queue = $this->queueFactory->get('markaspot_feedback_queue_worker');
      
      // Add the node ID to the queue.
      $queue->createItem(['nid' => $nid]);
      
      $this->loggerFactory->get('markaspot_feedback')->notice('Node @nid added to the feedback queue.', [
        '@nid' => $nid,
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('markaspot_feedback')->error('Error adding node @nid to the feedback queue: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStatistics() {
    // Get statistics from state storage
    $stats = $this->state->get('markaspot_feedback.stats', [
      'total_processed' => 0,
      'last_processed_nid' => 0,
      'last_run_count' => 0,
      'last_run_time' => 0,
    ]);
    
    // Add some additional stats
    $stats['pending_queue_count'] = $this->queueFactory->get('markaspot_feedback_queue_worker')->numberOfItems();
    $stats['processed_nids_count'] = count($this->state->get('markaspot_feedback.processed_nids', []));
    
    // Get information about configured status terms
    $status_terms = [];
    $config = $this->configFactory->get('markaspot_feedback.settings');
    $status_vocabulary = $config->get('tax_status') ?: 'service_status';
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $status_terms_resubmissive = $config->get('status_resubmissive');
    
    foreach ($status_terms_resubmissive as $tid => $enabled) {
      if ($term = $term_storage->load($tid)) {
        $status_terms[$tid] = $term->label();
      }
    }
    
    $stats['status_terms'] = $status_terms;
    
    return $stats;
  }

}