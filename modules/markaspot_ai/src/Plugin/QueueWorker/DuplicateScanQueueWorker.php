<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scans for duplicate service requests after embedding generation.
 *
 * This queue worker runs after embeddings are generated to identify
 * potential duplicate service requests based on semantic similarity
 * and geographic proximity.
 *
 * @QueueWorker(
 *   id = "markaspot_ai_duplicate_scan",
 *   title = @Translation("Scan for Duplicate Service Requests"),
 *   cron = {"time" = 120}
 * )
 */
class DuplicateScanQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The duplicate detection service.
   *
   * @var \Drupal\markaspot_ai\Service\DuplicateDetectionService
   */
  protected DuplicateDetectionService $duplicateDetectionService;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a new DuplicateScanQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\markaspot_ai\Service\DuplicateDetectionService $duplicate_detection_service
   *   The duplicate detection service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    DuplicateDetectionService $duplicate_detection_service,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->duplicateDetectionService = $duplicate_detection_service;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('markaspot_ai.duplicate_detection'),
      $container->get('logger.factory')->get('markaspot_ai'),
      $container->get('config.factory'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = $data['nid'] ?? NULL;

    if (!$nid) {
      $this->logger->warning('Duplicate scan queue item missing node ID.');
      return;
    }

    $config = $this->configFactory->get('markaspot_ai.settings');

    // Check if duplicate detection is enabled.
    if (!$config->get('duplicate_detection.enabled')) {
      $this->logger->debug('Duplicate detection disabled, skipping scan for node @nid.', [
        '@nid' => $nid,
      ]);
      return;
    }

    // Load the node.
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      $this->logger->warning('Node @nid not found for duplicate scanning.', [
        '@nid' => $nid,
      ]);
      return;
    }

    // Skip if not a service_request.
    if ($node->bundle() !== 'service_request') {
      $this->logger->debug('Skipping duplicate scan for node @nid - not a service_request.', [
        '@nid' => $nid,
      ]);
      return;
    }

    // Skip unpublished nodes.
    if (!$node->isPublished()) {
      $this->logger->debug('Skipping duplicate scan for unpublished node @nid.', [
        '@nid' => $nid,
      ]);
      return;
    }

    try {
      $this->logger->debug('Starting duplicate scan for node @nid...', ['@nid' => $nid]);

      // Find potential duplicates.
      $duplicates = $this->duplicateDetectionService->findDuplicates($node);

      if (empty($duplicates)) {
        $this->logger->debug('No duplicates found for node @nid.', ['@nid' => $nid]);
        return;
      }

      $this->logger->info('Found @count potential duplicates for node @nid.', [
        '@count' => count($duplicates),
        '@nid' => $nid,
      ]);

      // Store each match.
      $threshold = (float) $config->get('duplicate_detection.similarity_threshold') ?? 0.85;

      foreach ($duplicates as $match) {
        // Only store matches above threshold (should already be filtered).
        if ($match['similarity'] >= $threshold) {
          $this->duplicateDetectionService->storeDuplicateMatch(
            $nid,
            $match['nid'],
            $match['similarity'],
            $match['distance_meters']
          );
        }
      }

      // Auto-flag node if configured.
      if ($config->get('duplicate_detection.auto_flag') && !empty($duplicates)) {
        $this->autoFlagNode($node, $duplicates);
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to scan for duplicates for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);

      // Re-throw to allow queue to retry.
      throw $e;
    }
  }

  /**
   * Auto-flags a node as a potential duplicate.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to flag.
   * @param array $duplicates
   *   Array of duplicate matches found.
   */
  protected function autoFlagNode($node, array $duplicates): void {
    // Check if node has a flag field (implementation-specific).
    // This could use the Flag module or a custom field.
    if (!$node->hasField('field_duplicate_flag')) {
      // If using the Flag module, flag programmatically.
      if ($this->moduleHandler->moduleExists('flag')) {
        try {
          /** @var \Drupal\flag\FlagServiceInterface $flag_service */
          $flag_service = \Drupal::service('flag');
          $flag = $flag_service->getFlagById('potential_duplicate');

          if ($flag) {
            $flag_service->flag($flag, $node);
            $this->logger->info('Auto-flagged node @nid as potential duplicate.', [
              '@nid' => $node->id(),
            ]);
          }
        }
        catch (\Exception $e) {
          $this->logger->warning('Could not auto-flag node @nid: @message', [
            '@nid' => $node->id(),
            '@message' => $e->getMessage(),
          ]);
        }
      }
      return;
    }

    // Use custom field if available.
    try {
      $node->set('field_duplicate_flag', TRUE);
      $node->save();

      $this->logger->info('Auto-flagged node @nid as potential duplicate (found @count matches).', [
        '@nid' => $node->id(),
        '@count' => count($duplicates),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not auto-flag node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
