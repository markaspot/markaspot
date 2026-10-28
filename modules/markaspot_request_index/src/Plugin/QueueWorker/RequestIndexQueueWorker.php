<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markaspot_request_index\RequestIndexRebuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued request index re-projections on cron.
 */
#[QueueWorker(
  id: RequestIndexRebuilder::QUEUE,
  title: new TranslatableMarkup('Mark-a-Spot request index re-projection'),
  cron: ['time' => 60],
)]
final class RequestIndexQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\markaspot_request_index\RequestIndexRebuilder $rebuilder
   *   The request index rebuilder.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RequestIndexRebuilder $rebuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('markaspot_request_index.rebuilder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->rebuilder->processItem(is_array($data) ? $data : []);
  }

}
