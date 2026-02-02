<?php

namespace Drupal\markaspot_publisher\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Publishes service request nodes.
 *
 * @QueueWorker(
 *   id = "markaspot_publisher_queue_worker",
 *   title = @Translation("Publish Service Request Nodes"),
 *   cron = {"time" = 60}
 * )
 */
class PublisherQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The publisher service.
   *
   * @var \Drupal\markaspot_publisher\PublisherServiceInterface
   */
  protected $publisherService;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new PublisherQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\markaspot_publisher\PublisherServiceInterface $publisher_service
   *   The publisher service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $publisher_service, LoggerChannelInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->publisherService = $publisher_service;
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
      $container->get('markaspot_publisher.publisher'),
      $container->get('logger.factory')->get('markaspot_publisher')
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
      $config = \Drupal::configFactory()->getEditable('markaspot_publisher.settings');
      
      // Check if the node is already published
      if ($node->isPublished()) {
        $this->logger->notice('Node ID @nid is already published. Skipping.', [
          '@nid' => $nid
        ]);
        return;
      }
      
      // Check if the node is still eligible for publishing based on its field_status
      $current_status = $node->get('field_status')->target_id;
      $publishable_statuses = $config->get('status_publishable');
      if (!isset($publishable_statuses[$current_status])) {
        $this->logger->notice('Node ID @nid is no longer in a publishable status (current: @current). Skipping.', [
          '@nid' => $nid,
          '@current' => $current_status
        ]);
        return;
      }

      // Publish the node without changing field_status
      $node->setPublished();
      $node->save();
      $this->logger->notice('Node ID @nid published successfully.', ['@nid' => $nid]);
    }
    catch (\Exception $e) {
      $this->logger->critical('Queue processing failed for node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage() . "\n" . $e->getTraceAsString()
      ]);
    }
  }
}
