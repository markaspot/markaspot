<?php

namespace Drupal\markaspot_uuid;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Extend the base serviveprovider.
 */
class MarkaspotUuidServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Overrides uuid class to provide customizable uuids.
    $definition = $container->getDefinition('uuid');
    $definition->setClass('Drupal\markaspot_uuid\Plugin\Uuid\MarkaspotUuid');
  }

}
