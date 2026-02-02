<?php

namespace Drupal\markaspot_archive\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Archives service request nodes.
 *
 * @QueueWorker(
 *   id = "markaspot_archive_queue_worker",
 *   title = @Translation("Archive Service Request Nodes"),
 *   cron = {"time" = 60}
 * )
 */
class ArchiveQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The archive service.
   *
   * @var \Drupal\markaspot_archive\ArchiveServiceInterface
   */
  protected $archiveService;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new ArchiveQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\markaspot_archive\ArchiveServiceInterface $archive_service
   *   The archive service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $archive_service, LoggerChannelInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->archiveService = $archive_service;
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
      $container->get('markaspot_archive.archive'),
      $container->get('logger.factory')->get('markaspot_archive')
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

    $this->logger->notice('Starting processing for node ID: @nid', ['@nid' => $nid]);

    // Load the node by ID.
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node) {
      $this->logger->warning('Node with ID @nid not found.', ['@nid' => $nid]);
      return;
    }

    try {
      // Load configuration.
      $config = \Drupal::configFactory()->getEditable('markaspot_archive.settings');
      
      // Check if the node is already archived
      $archived_status = $config->get('status_archived');
      $current_status = $node->get('field_status')->target_id;
      
      if ($current_status == $archived_status) {
        $this->logger->notice('Node ID @nid is already archived (status @status). Skipping.', [
          '@nid' => $nid,
          '@status' => $archived_status
        ]);
        return;
      }
      
      // Check if the node is still eligible for archiving
      $archivable_statuses = $config->get('status_archivable');
      if (!isset($archivable_statuses[$current_status])) {
        $this->logger->notice('Node ID @nid is no longer in an archivable status (current: @current). Skipping.', [
          '@nid' => $nid,
          '@current' => $current_status
        ]);
        return;
      }

      // Unpublish node if configured.
      if ($config->get('unpublish') == 1) {
        $node->setUnpublished();
        $this->logger->notice('Node ID @nid unpublished.', ['@nid' => $nid]);
      }

      // Anonymize fields if configured.
      if ($config->get('anonymize') == 1) {
        // Ensure fields list is an array.
        $anonymize_fields = (array) $config->get('anonymize_fields');
        if (!empty($anonymize_fields)) {
          // For backward compatibility: transform numeric keys to field machine names
          $processed_fields = [];
          foreach ($anonymize_fields as $key => $value) {
            // If the key is numeric, try to find a matching field definition
            if (is_numeric($key) || is_numeric($value)) {
              // Look for fields that might contain personal data
              $personal_fields = [
                'field_e_mail', 'field_first_name', 'field_last_name',
                'field_phone', 'field_address', 'field_citizen'
              ];
              foreach ($personal_fields as $field) {
                if ($node->hasField($field)) {
                  $processed_fields[$field] = $field;
                }
              }
            } else {
              // Already a field machine name
              $processed_fields[$key] = $value;
            }
          }
          markaspot_archive_anonymize($node, $processed_fields);
          $this->logger->notice('Node ID @nid anonymized with fields: @fields', [
            '@nid' => $nid,
            '@fields' => implode(', ', array_keys($processed_fields))
          ]);
        }
      }

      // Update the node status to "archived".
      $node->field_status->target_id = $config->get('status_archived');
      $node->save();
      $this->logger->notice('Node ID @nid archived successfully.', ['@nid' => $nid]);
    }
    catch (\Throwable $e) {
      $this->logger->critical('Queue processing failed for node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage() . "\n" . $e->getTraceAsString()
      ]);
    }
  }
}
