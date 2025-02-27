<?php

namespace Drupal\markaspot_feedback;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class FeedbackService.
 *
 * Gets all service requests that need a refreshment.
 */
class FeedbackService implements FeedbackServiceInterface {

  /**
   * Entity manager Service Object.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;


  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new FeedbackService object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(EntityTypeManager $entity_type_manager, ConfigFactoryInterface $config_factory, LoggerInterface $logger, MessengerInterface $messenger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('markaspot_feedback'),
      $container->get('messenger')
    );
  }

  /**
   * Helper function.
   *
   * @return array
   *   return $result.
   */
  public function arrayFlatten($array) {
    $result = [];
    foreach ($array as $value) {
      $result[] = $value;
    }
    return $result;
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  public function get($uuid) {

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('uuid', $uuid);
    $query->accessCheck(FALSE);

    $nid = $query->execute();
    $nid = array_values($nid);

    if (!empty($nid)) {
      return $storage->load($nid[0]);
    } else {
      return FALSE;
    }
  }

  /**
   * Load nodes by status.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of entity objects indexed by their IDs. Returns an empty array
   *   if no matching entities are found.
   */
  public function load(): array {
    $config = $this->configFactory->get('markaspot_feedback.settings');
    $days = $config->get('days');
    $tids = $this->arrayFlatten($config->get('status_resubmissive'));
    $storage = $this->entityTypeManager->getStorage('node');

    // Use entity_id as cursor for pagination to avoid offset/range memory issues
    $last_processed_nid = \Drupal::state()->get('markaspot_feedback.last_processed_nid', 0);
    
    // Time-based filtering: Only process nodes that were marked as completed 
    // between X days ago and X+10 days ago
    // This creates a 10-day window of eligibility
    $newest_date = ($days !== '') ? strtotime(' - ' . $days . ' days') : strtotime(' - 30 days');
    $oldest_date = ($days !== '') ? strtotime(' - ' . ($days + 10) . ' days') : strtotime(' - 40 days');
    
    // Get a small batch for processing (only 10 nodes)
    $query = $storage->getQuery()
      ->condition('type', 'service_request')
      ->condition('field_status', $tids, 'IN')
      // Only get nodes that have been in status 5 for between $oldest_date and $newest_date
      // This uses the 'changed' timestamp which is updated when status changes
      ->condition('changed', $oldest_date, '>=')
      ->condition('changed', $newest_date, '<=')
      ->condition('nid', $last_processed_nid, '>')
      ->condition('field_e_mail', '', '<>') // Only process nodes with an email
      ->sort('nid', 'ASC')
      ->range(0, 10);
    $query->accessCheck(FALSE);

    $nids = $query->execute();
    
    if (!empty($nids)) {
      // Update the last processed nid for the next batch
      $last_nid = max($nids);
      \Drupal::state()->set('markaspot_feedback.last_processed_nid', $last_nid);
      
      $this->logger->notice('Processing @count feedback requests for nodes changed between @oldest and @newest (nids up to @last_nid)', [
        '@count' => count($nids),
        '@oldest' => date('Y-m-d', $oldest_date),
        '@newest' => date('Y-m-d', $newest_date),
        '@last_nid' => $last_nid
      ]);
      
      // If we're near the end, reset to start over on the next run
      $highest_query = $storage->getQuery()
        ->condition('type', 'service_request')
        ->condition('field_status', $tids, 'IN')
        ->condition('changed', $oldest_date, '>=')
        ->condition('changed', $newest_date, '<=')
        ->sort('nid', 'DESC')
        ->range(0, 1);
      $highest_query->accessCheck(FALSE);
      $highest_nids = $highest_query->execute();
      
      if (!empty($highest_nids) && $last_nid >= reset($highest_nids)) {
        \Drupal::state()->set('markaspot_feedback.last_processed_nid', 0);
        $this->logger->notice('Reached the end of the eligible node IDs, resetting cursor for next run');
      }
    } else {
      // No results found, reset to start over
      \Drupal::state()->set('markaspot_feedback.last_processed_nid', 0);
      $this->logger->notice('No feedback requests found in current batch, resetting cursor');
    }
    
    return $storage->loadMultiple($nids);
  }

  /**
   * Save nodes with new status and status note.
   *
   */
  public function saveNode($form_state) {
    $config = $this->configFactory->get('markaspot_feedback.settings');
    $node = $this->get($form_state->getValue('uuid'));
    $node->field_feedback->value = $form_state->getValue('feedback');
    if ($form_state->getValue('set_status') == 1) {
      $node->field_status->target_id = key($config->get('set_progress_tid'));
      $change_status = TRUE;
    } else {
      $change_status = FALSE;
    }
    $new_status_note = $this->createParagraph($change_status);
    $node->field_status_notes[] = $new_status_note;

    $node->save();
  }

  /**
   * Create Status Note Paragraph.
   *
   * @return array
   *   Return paragraph reference.
   */
  public function createParagraph($change_status) {

    $config = $this->configFactory->get('markaspot_feedback.settings');
    if (isset($change_status)){
      $tid = key($config->get('set_progress_tid'));
    } else {
      $tid = key($config->get('set_archive_tid'));
    }
    $paragraph = Paragraph::create([
      'type' => 'status',
      'field_status_term' => ['target_id' => $tid],
      'field_status_note' => ['value' => $config->get('set_status_note')],
    ]);
    $paragraph->save();
    if (null !== $paragraph->id()) {
      return [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }
  }
}
