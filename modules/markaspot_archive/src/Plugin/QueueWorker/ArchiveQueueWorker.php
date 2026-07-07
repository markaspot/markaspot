<?php

namespace Drupal\markaspot_archive\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_archive\ArchiveServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Archives service request nodes.
 *
 * @QueueWorker(
 *   id = "markaspot_archive_queue_worker",
 *   title = @Translation("Archive Service Request Nodes"),
 *   cron = {"time" = 60}
 * )
 */
class ArchiveQueueWorker extends QueueWorkerBase implements
  ContainerFactoryPluginInterface {

  /**
   * Maximum attempts before a node is marked as failed.
   */
  public const MAX_ATTEMPTS = 3;

  /**
   * State key for nodes that exceeded the queue retry limit.
   */
  public const FAILED_NIDS_STATE = 'markaspot_archive.failed_nids';

  /**
   * Seconds before a failed node id may be enqueued again.
   */
  public const FAILED_NIDS_TTL = 604800;

  /**
   * The archive service.
   *
   * @var \Drupal\markaspot_archive\ArchiveServiceInterface
   */
  protected $archiveService;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config factory.
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
   * Queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   Queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ArchiveServiceInterface $archive_service,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $logger,
    QueueFactory $queue_factory,
    StateInterface $state,
    TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->archiveService = $archive_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('markaspot_archive.archive'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('markaspot_archive'),
      $container->get('queue'),
      $container->get('state'),
      $container->get('datetime.time')
    );
  }

  /**
   * Processes a single queued item.
   *
   * @param mixed $data
   *   The data that was added to the queue. Here we expect an associative
   *   array, e.g. ['nid' => 123].
   */
  public function processItem($data) {
    $nid = $data['nid'] ?? NULL;
    if (!$nid) {
      $this->logger->warning('Queue item is missing a node ID.');
      return;
    }
    $nid = (int) $nid;
    $attempts = max(1, (int) ($data['attempts'] ?? 1));

    try {
      if ($this->isNodeMarkedFailed($nid)) {
        $this->logger->notice(
          'Node ID @nid previously failed archiving; skipping queued ' .
          'duplicate.',
          ['@nid' => $nid]
        );
        return;
      }

      $this->logger->notice(
        'Starting processing for node ID: @nid',
        ['@nid' => $nid]
      );

      // Load the node by ID.
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$node) {
        $this->logger->warning(
          'Node with ID @nid not found.',
          ['@nid' => $nid]
        );
        return;
      }

      // Load configuration.
      $config = $this->configFactory->get('markaspot_archive.settings');

      // Check if the node is already archived.
      $archived_status = $config->get('status_archived');
      $current_status = $node->get('field_status')->target_id;

      if ($current_status == $archived_status) {
        $this->logger->notice(
          'Node ID @nid is already archived (status @status). Skipping.',
          [
            '@nid' => $nid,
            '@status' => $archived_status,
          ]
        );
        return;
      }

      // Check if the node is still eligible for archiving.
      $archivable_statuses = $config->get('status_archivable');
      if (!isset($archivable_statuses[$current_status])) {
        $this->logger->notice(
          'Node ID @nid is no longer in an archivable status ' .
          '(current: @current). Skipping.',
          [
            '@nid' => $nid,
            '@current' => $current_status,
          ]
        );
        return;
      }

      // Unpublish node if configured.
      if ($config->get('unpublish') == 1) {
        $node->setUnpublished();
        $this->logger->notice('Node ID @nid unpublished.', ['@nid' => $nid]);
      }

      // Anonymize fields if configured.
      $anonymized_values = [];
      if ($config->get('anonymize') == 1) {
        $processed_fields = $this->archiveService->normalizeConfiguredFields(
          (array) $config->get('anonymize_fields')
        );
        if ($processed_fields !== []) {
          $anonymized_values = $this->archiveService->anonymize(
            $node,
            $processed_fields
          );
          if ($anonymized_values !== []) {
            $this->logger->notice(
              'Node ID @nid anonymized with fields: @fields',
              [
                '@nid' => $nid,
                '@fields' => implode(', ', array_keys($anonymized_values)),
              ]
            );
          }
        }
      }

      // Save current anonymized values before final archive status.
      if ($anonymized_values !== []) {
        $node->save();
        $revision_rows = $this->archiveService->anonymizeRevisions(
          $node,
          $anonymized_values
        );
        if ($revision_rows > 0) {
          $this->logger->notice(
            'Node ID @nid previous revisions anonymized in @rows field rows.',
            [
              '@nid' => $nid,
              '@rows' => $revision_rows,
            ]
          );
        }
      }

      // Update the node status to "archived".
      $node->field_status->target_id = $config->get('status_archived');
      $node->save();
      $this->logger->notice(
        'Node ID @nid archived successfully.',
        ['@nid' => $nid]
      );
    }
    catch (DatabaseException $e) {
      throw new SuspendQueueException(sprintf(
        'Database unavailable while processing archive item for node %d: %s',
        $nid,
        $e->getMessage()
      ), 0, $e);
    }
    catch (SuspendQueueException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      try {
        $this->handleProcessingFailure($nid, $attempts, $e);
      }
      catch (DatabaseException $database_exception) {
        throw new SuspendQueueException(sprintf(
          'Database unavailable while retrying archive item for node %d: %s',
          $nid,
          $database_exception->getMessage()
        ), 0, $database_exception);
      }
    }
  }

  /**
   * Handles a non-infrastructure queue processing failure.
   *
   * @param int $nid
   *   Node id.
   * @param int $attempts
   *   Current attempt count.
   * @param \Throwable $exception
   *   Failure that occurred while processing the item.
   */
  protected function handleProcessingFailure(
    int $nid,
    int $attempts,
    \Throwable $exception,
  ): void {
    if ($attempts >= self::MAX_ATTEMPTS) {
      if ($this->markNodeAsFailed($nid)) {
        $this->logger->critical(
          'Queue processing failed for node @nid; giving up after ' .
          '3 attempts: @error',
          [
            '@nid' => $nid,
            '@error' => $exception->getMessage(),
          ]
        );
      }
      return;
    }

    $next_attempt = $attempts + 1;
    $this->queueFactory
      ->get('markaspot_archive_queue_worker')
      ->createItem([
        'nid' => $nid,
        'attempts' => $next_attempt,
      ]);
    $this->logger->warning(
      'Queue processing failed for node @nid on attempt @attempt of ' .
      '@max; retrying: @error',
      [
        '@nid' => $nid,
        '@attempt' => $attempts,
        '@max' => self::MAX_ATTEMPTS,
        '@error' => $exception->getMessage(),
      ]
    );
  }

  /**
   * Returns TRUE when the node is currently marked as a final failure.
   */
  protected function isNodeMarkedFailed(int $nid): bool {
    $failed_nids = $this->activeFailedNids();
    return isset($failed_nids[$nid]);
  }

  /**
   * Marks a node as a final failure.
   *
   * @return bool
   *   TRUE when this call created the failure entry.
   */
  protected function markNodeAsFailed(int $nid): bool {
    $failed_nids = $this->activeFailedNids();
    if (isset($failed_nids[$nid])) {
      return FALSE;
    }
    $failed_nids[$nid] = $this->time->getRequestTime();
    $this->state->set(self::FAILED_NIDS_STATE, $failed_nids);
    return TRUE;
  }

  /**
   * Returns active failed node ids and removes expired entries.
   *
   * @return array<int, int>
   *   Failed node ids keyed by node id with their failure timestamp.
   */
  protected function activeFailedNids(): array {
    $stored = (array) $this->state->get(self::FAILED_NIDS_STATE, []);
    $active = [];
    $cutoff = $this->time->getRequestTime() - self::FAILED_NIDS_TTL;
    foreach ($stored as $nid => $timestamp) {
      if ((int) $timestamp >= $cutoff) {
        $active[(int) $nid] = (int) $timestamp;
      }
    }
    if ($active != $stored) {
      $this->state->set(self::FAILED_NIDS_STATE, $active);
    }
    return $active;
  }

}
