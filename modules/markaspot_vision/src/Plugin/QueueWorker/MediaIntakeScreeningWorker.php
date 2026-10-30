<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Screens imported images independently of the intake request.
 */
#[QueueWorker(
  id: MediaIntakeScreening::QUEUE,
  title: new TranslatableMarkup('Imported image privacy screening'),
  cron: ['time' => 60],
)]
final class MediaIntakeScreeningWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue plugin.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected MediaIntakeScreening $screening) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('markaspot_vision.media_intake'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $mid = is_array($data) ? (int) ($data['media_id'] ?? 0) : 0;
    if ($mid > 0) {
      $this->screening->process($mid);
    }
  }

}
