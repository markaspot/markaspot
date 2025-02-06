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

      // Unpublish node if configured.
      if ($config->get('unpublish') == 1) {
        $node->setUnpublished();
        $this->logger->notice('Node ID @nid unpublished.', ['@nid' => $nid]);
      }

      // Anonymize fields if configured.
      if ($config->get('anonymize') == 1) {
        $anonymize_fields = $config->get('anonymize_fields');
        markaspot_archive_anonymize($node, $anonymize_fields);
        $this->logger->notice('Node ID @nid anonymized.', ['@nid' => $nid]);
      }

      // Update the node status to "archived".
      $node->field_status->target_id = $config->get('status_archived');
      $node->save();
      $this->logger->notice('Node ID @nid archived successfully.', ['@nid' => $nid]);
    }
    catch (\Exception $e) {
      $this->logger->critical('Queue processing failed for node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage() . "\n" . $e->getTraceAsString()
      ]);
    }
  }
}
