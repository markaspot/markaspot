<?php

namespace Drupal\markaspot_resubmission;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;

/**
 * Service for managing resubmission reminders.
 */
class ReminderManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a ReminderManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $logger,
    TimeInterface $time
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->time = $time;
  }

  /**
   * Check if a node should receive a reminder.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return bool
   *   TRUE if the node should receive a reminder, FALSE otherwise.
   */
  public function shouldSendReminder(NodeInterface $node) {
    $config = $this->configFactory->get('markaspot_resubmission.settings');
    $reminder_interval = $config->get('reminder_interval') ?: 604800; // Default 7 days
    $max_reminders = $config->get('max_reminders') ?: 0; // 0 = unlimited

    // Get the last reminder for this node.
    $last_reminder = $this->getLastReminder($node->id());

    // Check if we've hit the max reminder limit.
    if ($max_reminders > 0 && $last_reminder) {
      $reminder_count = $this->getReminderCount($node->id());
      if ($reminder_count >= $max_reminders) {
        $this->logger->info('Node @nid has reached max reminders (@count/@max).', [
          '@nid' => $node->id(),
          '@count' => $reminder_count,
          '@max' => $max_reminders,
        ]);
        return FALSE;
      }
    }

    // If no previous reminder, send one.
    if (!$last_reminder) {
      return TRUE;
    }

    // Check if enough time has passed since last reminder.
    $time_since_last = $this->time->getRequestTime() - $last_reminder->getSentTimestamp();
    if ($time_since_last >= $reminder_interval) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Create a reminder record.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string $recipient_email
   *   The email address the reminder was sent to.
   * @param string $status
   *   The status (sent, failed, etc).
   * @param string|null $error_message
   *   Optional error message if failed.
   *
   * @return \Drupal\markaspot_resubmission\Entity\ResubmissionReminder|null
   *   The created reminder entity or NULL on failure.
   */
  public function createReminder(NodeInterface $node, $recipient_email, $status = 'sent', $error_message = NULL) {
    try {
      $storage = $this->entityTypeManager->getStorage('resubmission_reminder');
      $reminder_count = $this->getReminderCount($node->id()) + 1;

      // Get node status.
      $node_status = '';
      if ($node->hasField('field_status')) {
        $status_term = $node->get('field_status')->entity;
        if ($status_term) {
          $node_status = $status_term->label();
        }
      }

      $reminder = $storage->create([
        'nid' => $node->id(),
        'sent_timestamp' => $this->time->getRequestTime(),
        'recipient_email' => $recipient_email,
        'status' => $status,
        'reminder_count' => $reminder_count,
        'node_status' => $node_status,
        'error_message' => $error_message,
      ]);

      $reminder->save();

      $this->logger->info('Created reminder #@count for node @nid sent to @email.', [
        '@count' => $reminder_count,
        '@nid' => $node->id(),
        '@email' => $recipient_email,
      ]);

      return $reminder;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create reminder for node @nid: @error', [
        '@nid' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get the last reminder for a node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return \Drupal\markaspot_resubmission\Entity\ResubmissionReminder|null
   *   The last reminder or NULL if none found.
   */
  public function getLastReminder($nid) {
    $storage = $this->entityTypeManager->getStorage('resubmission_reminder');

    $query = $storage->getQuery()
      ->condition('nid', $nid)
      ->sort('sent_timestamp', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $ids = $query->execute();

    if (!empty($ids)) {
      return $storage->load(reset($ids));
    }

    return NULL;
  }

  /**
   * Get the total reminder count for a node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return int
   *   The number of reminders sent.
   */
  public function getReminderCount($nid) {
    $storage = $this->entityTypeManager->getStorage('resubmission_reminder');

    $query = $storage->getQuery()
      ->condition('nid', $nid)
      ->accessCheck(FALSE);

    return $query->count()->execute();
  }

  /**
   * Get all reminders for a node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return \Drupal\markaspot_resubmission\Entity\ResubmissionReminder[]
   *   Array of reminder entities.
   */
  public function getReminderHistory($nid) {
    $storage = $this->entityTypeManager->getStorage('resubmission_reminder');

    $query = $storage->getQuery()
      ->condition('nid', $nid)
      ->sort('sent_timestamp', 'DESC')
      ->accessCheck(FALSE);

    $ids = $query->execute();

    return !empty($ids) ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Get statistics about reminders.
   *
   * @return array
   *   Array with reminder statistics.
   */
  public function getStatistics() {
    $storage = $this->entityTypeManager->getStorage('resubmission_reminder');

    $total_query = $storage->getQuery()->accessCheck(FALSE);
    $total = $total_query->count()->execute();

    $sent_query = $storage->getQuery()
      ->condition('status', 'sent')
      ->accessCheck(FALSE);
    $sent = $sent_query->count()->execute();

    $failed_query = $storage->getQuery()
      ->condition('status', 'failed')
      ->accessCheck(FALSE);
    $failed = $failed_query->count()->execute();

    return [
      'total' => $total,
      'sent' => $sent,
      'failed' => $failed,
    ];
  }

  /**
   * Clean up old reminders for resolved service requests.
   *
   * @param int $days
   *   Delete reminders for nodes resolved more than X days ago.
   *
   * @return int
   *   Number of reminders deleted.
   */
  public function cleanupOldReminders($days = 365) {
    // Get nodes that have been closed/resolved.
    $closed_status_tids = $this->getClosedStatusTermIds();

    if (empty($closed_status_tids)) {
      return 0;
    }

    $cutoff_date = strtotime('-' . $days . ' days');

    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'service_request')
      ->condition('field_status', $closed_status_tids, 'IN')
      ->condition('changed', $cutoff_date, '<=')
      ->accessCheck(FALSE);

    $nids = $query->execute();

    if (empty($nids)) {
      return 0;
    }

    // Delete reminders for these nodes.
    $reminder_storage = $this->entityTypeManager->getStorage('resubmission_reminder');
    $reminder_query = $reminder_storage->getQuery()
      ->condition('nid', $nids, 'IN')
      ->accessCheck(FALSE);

    $reminder_ids = $reminder_query->execute();

    if (!empty($reminder_ids)) {
      $reminders = $reminder_storage->loadMultiple($reminder_ids);
      $reminder_storage->delete($reminders);

      $this->logger->info('Cleaned up @count old reminders.', [
        '@count' => count($reminder_ids),
      ]);

      return count($reminder_ids);
    }

    return 0;
  }

  /**
   * Get term IDs for closed/resolved statuses.
   *
   * @return array
   *   Array of term IDs.
   */
  protected function getClosedStatusTermIds() {
    // Get the status vocabulary.
    $config = $this->configFactory->get('markaspot_resubmission.settings');
    $vocabulary = $config->get('tax_status') ?: 'service_status';

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $term_storage->getQuery()
      ->condition('vid', $vocabulary)
      ->accessCheck(FALSE);

    $tids = $query->execute();

    if (empty($tids)) {
      return [];
    }

    // Filter for terms that indicate closed/resolved status.
    // Look for terms with names like "closed", "resolved", "done", "completed".
    $closed_terms = [];
    $terms = $term_storage->loadMultiple($tids);

    foreach ($terms as $term) {
      $name = strtolower($term->label());
      if (preg_match('/(closed|resolved|done|completed|erledigt|geschlossen)/', $name)) {
        $closed_terms[] = $term->id();
      }
    }

    return $closed_terms;
  }

}
