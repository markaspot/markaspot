<?php

namespace Drupal\markaspot_open311;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Override Rest Request Filter with a georeport aware filter.
 */
class MarkaspotOpen311ServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   *
   * Check https://www.drupal.org/node/2026959.
   */
  public function register(ContainerBuilder $container) {

    $container->register('request_format_route_filter')
      ->addArgument(new Reference('path.current'));
    $definition = $container->getDefinition('request_format_route_filter');
    $definition->setClass('Drupal\markaspot_open311\Routing\GeoreportRequestFormatRouteFilter');
  }

}
