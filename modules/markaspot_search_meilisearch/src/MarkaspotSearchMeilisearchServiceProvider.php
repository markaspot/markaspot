<?php

namespace Drupal\markaspot_search_meilisearch;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\markaspot_search_meilisearch\Api\RestrictedMeilisearchApiService;
use Drupal\markaspot_search_meilisearch\Client\TimedMeilisearchClientFactory;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Swaps in the restricted API service and the bounded client factory.
 */
class MarkaspotSearchMeilisearchServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('search_api_meilisearch.api')) {
      $container->getDefinition('search_api_meilisearch.api')
        ->setClass(RestrictedMeilisearchApiService::class)
        ->addMethodCall('setRestriction', [new Reference('markaspot_search_meilisearch.search_restriction')])
        ->addMethodCall('setState', [new Reference('state')]);
    }
    if ($container->hasDefinition('search_api_meilisearch.client_factory')) {
      $container->getDefinition('search_api_meilisearch.client_factory')
        ->setClass(TimedMeilisearchClientFactory::class);
    }
  }

}
