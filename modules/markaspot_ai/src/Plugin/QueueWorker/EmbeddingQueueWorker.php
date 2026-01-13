<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates AI embeddings for service requests.
 *
 * This queue worker processes service request nodes to generate vector
 * embeddings from their content. After embedding generation, nodes are
 * queued for duplicate scanning.
 *
 * @QueueWorker(
 *   id = "markaspot_ai_embedding",
 *   title = @Translation("Generate AI Embeddings"),
 *   cron = {"time" = 300}
 * )
 */
class EmbeddingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The embedding service.
   *
   * @var \Drupal\markaspot_ai\Service\EmbeddingService
   */
  protected EmbeddingService $embeddingService;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

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
   * The sentiment service.
   *
   * @var \Drupal\markaspot_ai\Service\SentimentService
   */
  protected SentimentService $sentimentService;

  /**
   * Constructs a new EmbeddingQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\markaspot_ai\Service\EmbeddingService $embedding_service
   *   The embedding service.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\markaspot_ai\Service\SentimentService $sentiment_service
   *   The sentiment service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EmbeddingService $embedding_service,
    QueueFactory $queue_factory,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $config_factory,
    SentimentService $sentiment_service,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->embeddingService = $embedding_service;
    $this->queueFactory = $queue_factory;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->sentimentService = $sentiment_service;
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
      $container->get('markaspot_ai.embedding'),
      $container->get('queue'),
      $container->get('logger.factory')->get('markaspot_ai'),
      $container->get('config.factory'),
      $container->get('markaspot_ai.sentiment')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = isset($data['nid']) ? (int) $data['nid'] : NULL;

    if (!$nid) {
      $this->logger->warning('Embedding queue item missing node ID.');
      return;
    }

    // Load the node.
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      $this->logger->warning('Node @nid not found for embedding generation.', [
        '@nid' => $nid,
      ]);
      return;
    }

    // Skip if not a service_request.
    if ($node->bundle() !== 'service_request') {
      $this->logger->debug('Skipping node @nid - not a service_request bundle.', [
        '@nid' => $nid,
      ]);
      return;
    }

    // Skip unpublished nodes unless explicitly requested.
    if (!$node->isPublished() && empty($data['include_unpublished'])) {
      $this->logger->debug('Skipping unpublished node @nid.', ['@nid' => $nid]);
      return;
    }

    try {
      // Build text content for embedding.
      $textParts = [];

      // Add title.
      $textParts[] = $node->getTitle();

      // Add body content.
      if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
        $body = $node->get('body')->value;
        // Strip HTML tags and decode entities.
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textParts[] = $body;
      }

      // Add address.
      if ($node->hasField('field_address') && !$node->get('field_address')->isEmpty()) {
        $textParts[] = $node->get('field_address')->value;
      }

      // Add category name if available.
      if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
        $category = $node->get('field_category')->entity;
        if ($category) {
          $textParts[] = 'Category: ' . $category->label();
        }
      }

      // Combine text parts.
      $text = implode("\n\n", array_filter($textParts));

      if (empty(trim($text))) {
        $this->logger->warning('Node @nid has no content to embed.', ['@nid' => $nid]);
        return;
      }

      // Generate text hash for change detection.
      $textHash = $this->embeddingService->generateTextHash($text);

      // Check if embedding already exists with the same hash.
      if ($this->embeddingService->embeddingExists($nid, $textHash)) {
        $this->logger->debug('Embedding for node @nid already up-to-date (hash match).', [
          '@nid' => $nid,
        ]);

        // Still queue for duplicate scan if this is a new node.
        if (!empty($data['is_new'])) {
          $this->queueDuplicateScan($nid);
        }

        // Still analyze sentiment (idempotent - skips if already analyzed).
        $this->analyzeSentiment($node);
        return;
      }

      // Generate embedding via AI service.
      $this->logger->debug('Generating embedding for node @nid...', ['@nid' => $nid]);

      $result = $this->embeddingService->generateEmbedding($text);

      // Store the embedding.
      $this->embeddingService->storeEmbedding(
        $nid,
        'node',
        'content',
        $result['vector'],
        $textHash,
        $result['model']
      );

      $this->logger->info('Generated embedding for node @nid (@dims dimensions, @model).', [
        '@nid' => $nid,
        '@dims' => $result['dimensions'],
        '@model' => $result['model'],
      ]);

      // Queue for duplicate scanning.
      $this->queueDuplicateScan($nid);

      // Analyze sentiment if enabled.
      $this->analyzeSentiment($node);

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate embedding for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);

      // Re-throw to allow queue to retry.
      throw $e;
    }
  }

  /**
   * Queues a node for duplicate scanning.
   *
   * @param int $nid
   *   The node ID to queue.
   */
  protected function queueDuplicateScan(int $nid): void {
    $config = $this->configFactory->get('markaspot_ai.settings');

    // Only queue if duplicate detection is enabled.
    if (!$config->get('duplicate_detection.enabled')) {
      return;
    }

    $queue = $this->queueFactory->get('markaspot_ai_duplicate_scan');
    $queue->createItem(['nid' => $nid]);

    $this->logger->debug('Queued node @nid for duplicate scanning.', ['@nid' => $nid]);
  }

  /**
   * Analyzes sentiment for a node if enabled.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to analyze.
   */
  protected function analyzeSentiment(NodeInterface $node): void {
    $config = $this->configFactory->get('markaspot_ai.settings');

    // Only analyze if sentiment analysis is enabled.
    if (!$config->get('sentiment_analysis.enabled')) {
      return;
    }

    $nid = (int) $node->id();

    try {
      $result = $this->sentimentService->analyzeNode($node);

      if ($result !== NULL) {
        $this->logger->debug('Analyzed sentiment for node @nid: @sentiment (score: @score).', [
          '@nid' => $nid,
          '@sentiment' => $result['sentiment'],
          '@score' => $result['score'],
        ]);
      }
    }
    catch (\Exception $e) {
      // Log but don't throw - sentiment analysis failure shouldn't
      // block embedding generation.
      $this->logger->warning('Sentiment analysis failed for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
