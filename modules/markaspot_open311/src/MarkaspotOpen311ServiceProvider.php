<?php

namespace Drupal\markaspot_open311;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 *
 */
class MarkaspotOpen311ServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   *
   * Check https://www.drupal.org/node/2026959.
   */
  public function register(ContainerBuilder $container) {

    // Overrides Request Format Filter.
    $definition = $container->getDefinition('rest.resource_response.subscriber');
    $definition->setClass('Drupal\markaspot_open311\EventSubscriber\GeoReportResourceResponseSubscriber');

    // Overrides Request Format Filter.
    $definition = $container->getDefinition('request_format_route_filter');
    $definition->setClass('Drupal\markaspot_open311\Routing\GeoreportRequestFormatRouteFilter');
  }

}
