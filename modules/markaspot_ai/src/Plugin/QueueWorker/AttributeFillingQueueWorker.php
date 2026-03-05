<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fills service definition attributes for service requests using AI.
 *
 * This queue worker processes service request nodes to fill their
 * category-specific attributes based on the description and photos.
 *
 * @QueueWorker(
 *   id = "markaspot_ai_attribute_filling",
 *   title = @Translation("AI Attribute Filling"),
 *   cron = {"time" = 300}
 * )
 */
class AttributeFillingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The attribute filling service.
   *
   * @var \Drupal\markaspot_ai\Service\AttributeFillingService
   */
  protected AttributeFillingService $attributeFillingService;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a new AttributeFillingQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\markaspot_ai\Service\AttributeFillingService $attribute_filling_service
   *   The attribute filling service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AttributeFillingService $attribute_filling_service,
    LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->attributeFillingService = $attribute_filling_service;
    $this->logger = $logger;
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
      $container->get('markaspot_ai.attribute_filling'),
      $container->get('logger.factory')->get('markaspot_ai')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = isset($data['nid']) ? (int) $data['nid'] : NULL;

    if (!$nid) {
      $this->logger->warning('Attribute filling queue item missing node ID.');
      return;
    }

    // Load the node.
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      $this->logger->warning('Node @nid not found for attribute filling.', [
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

    // GDPR safety net: skip if AI processing is disabled for this jurisdiction.
    if (!_markaspot_ai_is_ai_enabled_for_node($node)) {
      $this->logger->debug('AI disabled for node @nid jurisdiction, skipping attribute filling.', [
        '@nid' => $nid,
      ]);
      return;
    }

    try {
      $result = $this->attributeFillingService->fillAttributes($node);

      if ($result !== NULL) {
        $this->logger->debug('Filled @count attributes for node @nid.', [
          '@count' => count($result['attributes']),
          '@nid' => $nid,
        ]);
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fill attributes for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);

      // Re-throw to allow queue to retry.
      throw $e;
    }
  }

}
